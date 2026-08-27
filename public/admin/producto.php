<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$isEdit = $id > 0;

$product = [
    'id' => 0,
    'sku' => '',
    'slug' => '',
    'nombre' => '',
    'descripcion' => '',
    'precio' => '0.00',
    'stock' => 0,
    'activo' => 1,
];
$variants = [];
$images = [];

if ($isEdit) {
    $stmt = $db->prepare('SELECT id, sku, slug, nombre, descripcion, precio, stock, activo FROM productos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Producto no encontrado.');
    }
    $product = $found;

    $variantStmt = $db->prepare(
        'SELECT id, codigo, nombre, rango_mx, stock, activo
         FROM producto_variantes WHERE producto_id = ? ORDER BY id ASC'
    );
    $variantStmt->execute([$id]);
    $variants = $variantStmt->fetchAll();

    $imageStmt = $db->prepare(
        'SELECT id, url, alt_text, orden
         FROM producto_imagenes WHERE producto_id = ? ORDER BY orden ASC, id ASC'
    );
    $imageStmt->execute([$id]);
    $images = $imageStmt->fetchAll();
}

$usesVariants = $variants !== [];
$flash = admin_take_flash();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title><?= $isEdit ? 'Editar producto' : 'Nuevo producto' ?> | Administración</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
  <div class="admin-header-actions">
    <a class="admin-secondary-button" href="/admin/">Volver</a>
  </div>
</header>

<main class="admin-shell admin-editor-shell">
  <section class="admin-page-heading compact">
    <div>
      <span class="admin-eyebrow">Catálogo</span>
      <h1><?= $isEdit ? 'Editar producto' : 'Nuevo producto' ?></h1>
      <p><?= $isEdit ? 'Los cambios se reflejan en la tienda al guardar.' : 'Completa los datos principales y publica cuando esté listo.' ?></p>
    </div>
  </section>

  <?php if ($flash !== null): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>">
      <?= admin_e((string) ($flash['message'] ?? '')) ?>
    </div>
  <?php endif; ?>

  <form class="admin-editor-form" method="post" action="/admin/guardar-producto.php" enctype="multipart/form-data" data-product-form>
    <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">

    <section class="admin-panel editor-section">
      <div class="editor-section-heading">
        <div><span class="admin-eyebrow">Información</span><h2>Datos del producto</h2></div>
      </div>

      <div class="form-grid two-cols">
        <label class="field-wide">
          <span>Nombre</span>
          <input type="text" name="nombre" value="<?= admin_e($product['nombre']) ?>" maxlength="180" required>
        </label>
        <label>
          <span>SKU <small>opcional</small></span>
          <input type="text" name="sku" value="<?= admin_e($product['sku']) ?>" maxlength="64" placeholder="Ej. GORRO-SILICONA-AZUL">
        </label>
        <label>
          <span>Precio</span>
          <div class="money-input"><span>$</span><input type="number" name="precio" value="<?= admin_e((string) $product['precio']) ?>" min="0" step="0.01" required></div>
        </label>
        <label class="field-wide">
          <span>Descripción</span>
          <textarea name="descripcion" rows="4" maxlength="3000" placeholder="Descripción breve para el cliente."><?= admin_e($product['descripcion']) ?></textarea>
        </label>
        <label class="toggle-field field-wide">
          <input type="checkbox" name="activo" value="1" <?= (int) $product['activo'] === 1 ? 'checked' : '' ?>>
          <span><strong>Producto publicado</strong><small>Si lo desactivas, deja de aparecer en la tienda.</small></span>
        </label>
      </div>
    </section>

    <section class="admin-panel editor-section">
      <div class="editor-section-heading variants-heading">
        <div><span class="admin-eyebrow">Inventario</span><h2>Stock y variantes</h2></div>
        <label class="switch-control">
          <input type="checkbox" name="usa_variantes" value="1" data-variants-toggle <?= $usesVariants ? 'checked' : '' ?>>
          <span>Usa tallas o variantes</span>
        </label>
      </div>

      <div data-simple-stock <?= $usesVariants ? 'hidden' : '' ?>>
        <label class="stock-field">
          <span>Stock disponible</span>
          <input type="number" name="stock_simple" value="<?= (int) $product['stock'] ?>" min="0" step="1">
        </label>
      </div>

      <div class="variants-editor" data-variants-editor <?= !$usesVariants ? 'hidden' : '' ?>>
        <div class="variants-columns-labels"><span>Variante</span><span>Rango / detalle</span><span>Stock</span><span></span></div>
        <div data-variant-list>
          <?php foreach ($variants as $variant): ?>
            <div class="variant-row" data-variant-row>
              <input type="hidden" name="variant_id[]" value="<?= (int) $variant['id'] ?>">
              <input type="hidden" name="variant_codigo[]" value="<?= admin_e($variant['codigo']) ?>">
              <input type="text" name="variant_nombre[]" value="<?= admin_e($variant['nombre']) ?>" maxlength="80" placeholder="Ej. Talla M">
              <input type="text" name="variant_rango[]" value="<?= admin_e($variant['rango_mx']) ?>" maxlength="80" placeholder="Ej. 22 a 24 MX">
              <input type="number" name="variant_stock[]" value="<?= (int) $variant['stock'] ?>" min="0" step="1">
              <button class="remove-row-button" type="button" data-remove-variant aria-label="Eliminar variante">×</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="admin-secondary-button add-variant-button" type="button" data-add-variant>+ Agregar variante</button>
        <p class="form-help">El stock total se calcula automáticamente sumando las variantes.</p>
      </div>
    </section>

    <section class="admin-panel editor-section">
      <div class="editor-section-heading">
        <div><span class="admin-eyebrow">Galería</span><h2>Fotos del producto</h2></div>
      </div>

      <?php if ($images !== []): ?>
        <div class="admin-image-grid">
          <?php foreach ($images as $index => $image): ?>
            <article class="admin-image-card">
              <img src="<?= admin_e($image['url']) ?>" alt="<?= admin_e($image['alt_text']) ?>">
              <div class="admin-image-controls">
                <label class="image-radio">
                  <input type="radio" name="imagen_principal" value="<?= (int) $image['id'] ?>" <?= $index === 0 ? 'checked' : '' ?>>
                  <span>Principal</span>
                </label>
                <label class="image-remove">
                  <input type="checkbox" name="eliminar_imagen[]" value="<?= (int) $image['id'] ?>">
                  <span>Eliminar</span>
                </label>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="admin-image-empty">Este producto todavía no tiene fotos.</div>
      <?php endif; ?>

      <label class="upload-box">
        <span class="upload-icon">＋</span>
        <strong>Agregar fotos</strong>
        <small>JPG, PNG o WebP · hasta 8 MB por imagen</small>
        <input type="file" name="imagenes[]" accept="image/jpeg,image/png,image/webp" multiple>
      </label>
      <p class="form-help">Las nuevas fotos se guardan desde este panel. Ya no necesitas subirlas a GitHub.</p>
    </section>

    <div class="editor-actions">
      <a class="admin-secondary-button" href="/admin/">Cancelar</a>
      <button class="admin-primary-button" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear producto' ?></button>
    </div>
  </form>
</main>

<template id="variantRowTemplate">
  <div class="variant-row" data-variant-row>
    <input type="hidden" name="variant_id[]" value="0">
    <input type="hidden" name="variant_codigo[]" value="">
    <input type="text" name="variant_nombre[]" value="" maxlength="80" placeholder="Ej. Talla M">
    <input type="text" name="variant_rango[]" value="" maxlength="80" placeholder="Ej. 22 a 24 MX">
    <input type="number" name="variant_stock[]" value="0" min="0" step="1">
    <button class="remove-row-button" type="button" data-remove-variant aria-label="Eliminar variante">×</button>
  </div>
</template>

<script src="/admin/admin.js?v=1" defer></script>
</body>
</html>
