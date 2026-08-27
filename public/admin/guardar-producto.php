<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

admin_verify_csrf($_POST['csrf'] ?? null);

$db = Database::connection();
$id = max(0, (int) ($_POST['id'] ?? 0));
$isEdit = $id > 0;
$returnUrl = $isEdit ? '/admin/producto.php?id=' . $id : '/admin/producto.php';

$name = trim((string) ($_POST['nombre'] ?? ''));
$skuRaw = trim((string) ($_POST['sku'] ?? ''));
$sku = $skuRaw !== '' ? $skuRaw : null;
$descriptionRaw = trim((string) ($_POST['descripcion'] ?? ''));
$description = $descriptionRaw !== '' ? $descriptionRaw : null;
$priceRaw = (string) ($_POST['precio'] ?? '');
$price = is_numeric($priceRaw) ? round((float) $priceRaw, 2) : -1;
$active = isset($_POST['activo']) ? 1 : 0;
$usesVariants = isset($_POST['usa_variantes']);
$simpleStock = max(0, (int) ($_POST['stock_simple'] ?? 0));

if ($name === '' || strlen($name) > 360) {
    admin_flash('error', 'Escribe un nombre válido para el producto.');
    admin_redirect($returnUrl);
}
if ($price < 0 || $price > 99999999.99) {
    admin_flash('error', 'El precio no es válido.');
    admin_redirect($returnUrl);
}
if ($sku !== null && strlen($sku) > 64) {
    admin_flash('error', 'El SKU es demasiado largo.');
    admin_redirect($returnUrl);
}

$uploadedPaths = [];
$filesToDeleteAfterCommit = [];

try {
    $db->beginTransaction();

    $existingProduct = null;
    if ($isEdit) {
        $stmt = $db->prepare('SELECT id, slug FROM productos WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $existingProduct = $stmt->fetch();
        if (!$existingProduct) {
            throw new RuntimeException('El producto ya no existe.');
        }
    }

    if ($isEdit) {
        $slug = (string) $existingProduct['slug'];
        $stmt = $db->prepare(
            'UPDATE productos
             SET sku = ?, nombre = ?, descripcion = ?, precio = ?, stock = ?, activo = ?
             WHERE id = ?'
        );
        $stmt->execute([$sku, $name, $description, $price, $usesVariants ? 0 : $simpleStock, $active, $id]);
    } else {
        $slug = admin_unique_slug($db, $name);
        $stmt = $db->prepare(
            'INSERT INTO productos (sku, slug, nombre, descripcion, precio, stock, imagen_url, activo)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$sku, $slug, $name, $description, $price, $usesVariants ? 0 : $simpleStock, $active]);
        $id = (int) $db->lastInsertId();
        $returnUrl = '/admin/producto.php?id=' . $id;
    }

    $existingVariantStmt = $db->prepare(
        'SELECT id, codigo FROM producto_variantes WHERE producto_id = ? ORDER BY id ASC'
    );
    $existingVariantStmt->execute([$id]);
    $existingVariants = $existingVariantStmt->fetchAll();
    $existingVariantMap = [];
    $usedCodes = [];
    foreach ($existingVariants as $existingVariant) {
        $existingVariantMap[(int) $existingVariant['id']] = (string) $existingVariant['codigo'];
        $usedCodes[(string) $existingVariant['codigo']] = true;
    }

    $keptVariantIds = [];
    $variantStockTotal = 0;

    if ($usesVariants) {
        $ids = is_array($_POST['variant_id'] ?? null) ? $_POST['variant_id'] : [];
        $codes = is_array($_POST['variant_codigo'] ?? null) ? $_POST['variant_codigo'] : [];
        $names = is_array($_POST['variant_nombre'] ?? null) ? $_POST['variant_nombre'] : [];
        $ranges = is_array($_POST['variant_rango'] ?? null) ? $_POST['variant_rango'] : [];
        $stocks = is_array($_POST['variant_stock'] ?? null) ? $_POST['variant_stock'] : [];

        $validRows = 0;
        $rowCount = max(count($ids), count($names), count($stocks));
        for ($index = 0; $index < $rowCount; $index++) {
            $variantId = max(0, (int) ($ids[$index] ?? 0));
            $variantName = trim((string) ($names[$index] ?? ''));
            $variantRangeRaw = trim((string) ($ranges[$index] ?? ''));
            $variantRange = $variantRangeRaw !== '' ? $variantRangeRaw : null;
            $variantStock = max(0, (int) ($stocks[$index] ?? 0));

            if ($variantName === '') {
                continue;
            }
            if (strlen($variantName) > 160 || ($variantRange !== null && strlen($variantRange) > 160)) {
                throw new RuntimeException('Una de las variantes tiene un texto demasiado largo.');
            }

            $validRows++;
            $variantStockTotal += $variantStock;

            if ($variantId > 0 && isset($existingVariantMap[$variantId])) {
                $updateVariant = $db->prepare(
                    'UPDATE producto_variantes
                     SET nombre = ?, rango_mx = ?, stock = ?, activo = 1
                     WHERE id = ? AND producto_id = ?'
                );
                $updateVariant->execute([$variantName, $variantRange, $variantStock, $variantId, $id]);
                $keptVariantIds[] = $variantId;
                continue;
            }

            $requestedCode = trim((string) ($codes[$index] ?? ''));
            $baseCode = $requestedCode !== '' ? strtoupper(admin_slugify($requestedCode)) : strtoupper(admin_slugify($variantName));
            $baseCode = substr($baseCode !== '' ? $baseCode : 'VARIANTE', 0, 64);
            $code = $baseCode;
            $suffix = 2;
            while (isset($usedCodes[$code])) {
                $tail = '-' . $suffix;
                $code = substr($baseCode, 0, 64 - strlen($tail)) . $tail;
                $suffix++;
            }
            $usedCodes[$code] = true;

            $insertVariant = $db->prepare(
                'INSERT INTO producto_variantes (producto_id, codigo, nombre, rango_mx, stock, activo)
                 VALUES (?, ?, ?, ?, ?, 1)'
            );
            $insertVariant->execute([$id, $code, $variantName, $variantRange, $variantStock]);
            $keptVariantIds[] = (int) $db->lastInsertId();
        }

        if ($validRows === 0) {
            throw new RuntimeException('Agrega al menos una variante o desactiva la opción de tallas/variantes.');
        }

        if ($keptVariantIds !== []) {
            $placeholders = implode(',', array_fill(0, count($keptVariantIds), '?'));
            $deleteVariant = $db->prepare(
                "DELETE FROM producto_variantes WHERE producto_id = ? AND id NOT IN ($placeholders)"
            );
            $deleteVariant->execute(array_merge([$id], $keptVariantIds));
        }

        $updateStock = $db->prepare('UPDATE productos SET stock = ? WHERE id = ?');
        $updateStock->execute([$variantStockTotal, $id]);
    } else {
        $deleteVariants = $db->prepare('DELETE FROM producto_variantes WHERE producto_id = ?');
        $deleteVariants->execute([$id]);
    }

    $imageStmt = $db->prepare(
        'SELECT id, url, alt_text, orden FROM producto_imagenes WHERE producto_id = ? ORDER BY orden ASC, id ASC'
    );
    $imageStmt->execute([$id]);
    $existingImages = $imageStmt->fetchAll();

    $removeIdsRaw = is_array($_POST['eliminar_imagen'] ?? null) ? $_POST['eliminar_imagen'] : [];
    $removeIds = array_fill_keys(array_map('intval', $removeIdsRaw), true);
    $principalId = max(0, (int) ($_POST['imagen_principal'] ?? 0));

    $imageList = [];
    foreach ($existingImages as $image) {
        $imageId = (int) $image['id'];
        if (isset($removeIds[$imageId])) {
            $path = admin_uploaded_file_path((string) $image['url']);
            if ($path !== null) {
                $filesToDeleteAfterCommit[] = $path;
            }
            continue;
        }
        $imageList[] = [
            'id' => $imageId,
            'url' => (string) $image['url'],
            'alt_text' => (string) ($image['alt_text'] ?? $name),
        ];
    }

    if ($principalId > 0) {
        usort($imageList, static function (array $a, array $b) use ($principalId): int {
            if ($a['id'] === $principalId) return -1;
            if ($b['id'] === $principalId) return 1;
            return 0;
        });
    }

    $uploadDir = admin_upload_dir();
    $uploadUrl = admin_upload_url();
    $uploadNames = $_FILES['imagenes']['name'] ?? [];
    $uploadTmp = $_FILES['imagenes']['tmp_name'] ?? [];
    $uploadErrors = $_FILES['imagenes']['error'] ?? [];
    $uploadSizes = $_FILES['imagenes']['size'] ?? [];

    if (!is_array($uploadNames)) {
        $uploadNames = [];
    }

    $pendingUploads = 0;
    foreach ($uploadNames as $index => $originalName) {
        $error = (int) ($uploadErrors[$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $pendingUploads++;
        if ($pendingUploads > 6) {
            throw new RuntimeException('Puedes subir hasta 6 fotos a la vez.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Una de las fotos no pudo subirse. Inténtalo de nuevo.');
        }
        if ((int) ($uploadSizes[$index] ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('Cada foto debe pesar máximo 8 MB.');
        }

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('No se pudo crear la carpeta de imágenes en el servidor.');
        }
        if (!is_writable($uploadDir)) {
            throw new RuntimeException('La carpeta de imágenes no tiene permisos de escritura.');
        }

        $tmpPath = (string) ($uploadTmp[$index] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('La carga de una imagen no es válida.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime]) || @getimagesize($tmpPath) === false) {
            throw new RuntimeException('Solo se permiten imágenes JPG, PNG o WebP válidas.');
        }

        $fileName = sprintf('producto-%d-%s.%s', $id, bin2hex(random_bytes(8)), $allowed[$mime]);
        $destination = $uploadDir . '/' . $fileName;
        if (!move_uploaded_file($tmpPath, $destination)) {
            throw new RuntimeException('No se pudo guardar una de las imágenes.');
        }
        @chmod($destination, 0644);
        $uploadedPaths[] = $destination;
        $imageList[] = [
            'id' => 0,
            'url' => $uploadUrl . '/' . $fileName,
            'alt_text' => $name,
        ];
    }

    if (count($imageList) > 8) {
        throw new RuntimeException('Cada producto puede tener hasta 8 fotos en esta primera versión.');
    }

    $deleteImages = $db->prepare('DELETE FROM producto_imagenes WHERE producto_id = ?');
    $deleteImages->execute([$id]);

    $insertImage = $db->prepare(
        'INSERT INTO producto_imagenes (producto_id, url, alt_text, orden) VALUES (?, ?, ?, ?)'
    );
    foreach ($imageList as $index => $image) {
        $insertImage->execute([$id, $image['url'], $image['alt_text'], $index + 1]);
    }

    $mainImageUrl = $imageList[0]['url'] ?? null;
    $updateMainImage = $db->prepare('UPDATE productos SET imagen_url = ? WHERE id = ?');
    $updateMainImage->execute([$mainImageUrl, $id]);

    $db->commit();

    foreach ($filesToDeleteAfterCommit as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    admin_flash('success', $isEdit ? 'Producto actualizado correctamente.' : 'Producto creado correctamente.');
    admin_redirect('/admin/producto.php?id=' . $id);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($uploadedPaths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    error_log('[tienda-natacion][admin] save product error: ' . $e->getMessage());
    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'No se pudo guardar el producto. Revisa los datos e inténtalo otra vez.';
    admin_flash('error', $message);
    admin_redirect($returnUrl);
}
