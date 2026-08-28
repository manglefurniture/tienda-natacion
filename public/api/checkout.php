<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function checkout_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    checkout_json(405, ['message' => 'Método no permitido.']);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hache_tienda_checkout');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$expectedToken = $_SESSION['checkout_csrf'] ?? '';
$receivedToken = (string) ($_SERVER['HTTP_X_CHECKOUT_TOKEN'] ?? '');
if (!is_string($expectedToken) || $expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    checkout_json(419, ['message' => 'Tu sesión de compra expiró. Recarga la página.']);
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    checkout_json(400, ['message' => 'Los datos del pedido no son válidos.']);
}

if (!is_array($payload)) {
    checkout_json(400, ['message' => 'Los datos del pedido no son válidos.']);
}

$name = trim((string) ($payload['nombre'] ?? ''));
$phone = trim((string) ($payload['telefono'] ?? ''));
$email = trim((string) ($payload['email'] ?? ''));
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 180) {
    checkout_json(422, ['message' => 'Escribe tu nombre completo.']);
}
if (!preg_match('/^[0-9+()\-\s]{8,32}$/', $phone)) {
    checkout_json(422, ['message' => 'Escribe un teléfono válido.']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    checkout_json(422, ['message' => 'El correo electrónico no es válido.']);
}
if ($items === [] || count($items) > 20) {
    checkout_json(422, ['message' => 'Tu carrito está vacío o tiene demasiados artículos.']);
}

try {
    $db = Database::connection();
    $gateway = PaymentGatewayConfig::mercadoPago($db);
} catch (Throwable $e) {
    error_log('[tienda-natacion][gateway-config] ' . $e->getMessage());
    checkout_json(503, ['message' => 'El pago en línea está temporalmente no disponible.']);
}

$accessToken = trim((string) ($gateway['access_token'] ?? ''));
if (empty($gateway['active']) || $accessToken === '') {
    checkout_json(503, ['message' => 'El pago en línea está en configuración. Inténtalo más tarde.']);
}

$orderId = 0;
$orderNumber = '';

try {
    OrderService::releaseExpiredReservations($db);
    $db->beginTransaction();

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('Uno de los artículos del carrito no es válido.');
        }
        $productId = max(0, (int) ($item['producto_id'] ?? 0));
        $variantId = max(0, (int) ($item['variante_id'] ?? 0));
        $quantity = max(0, (int) ($item['cantidad'] ?? 0));
        if ($productId <= 0 || $quantity <= 0 || $quantity > 20) {
            throw new RuntimeException('Una cantidad del carrito no es válida.');
        }

        $key = $productId . ':' . $variantId;
        if (!isset($normalized[$key])) {
            $normalized[$key] = ['producto_id' => $productId, 'variante_id' => $variantId, 'cantidad' => 0];
        }
        $normalized[$key]['cantidad'] += $quantity;
        if ($normalized[$key]['cantidad'] > 20) {
            throw new RuntimeException('La cantidad solicitada de un producto es demasiado alta.');
        }
    }

    $validatedItems = [];
    $subtotal = 0.0;

    foreach ($normalized as $item) {
        $productId = (int) $item['producto_id'];
        $variantId = (int) $item['variante_id'];
        $quantity = (int) $item['cantidad'];

        $productStmt = $db->prepare(
            'SELECT id, nombre, precio, stock, activo FROM productos WHERE id = ? FOR UPDATE'
        );
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();
        if (!$product || (int) $product['activo'] !== 1) {
            throw new RuntimeException('Uno de los productos ya no está disponible.');
        }

        $variantCountStmt = $db->prepare(
            'SELECT COUNT(*) FROM producto_variantes WHERE producto_id = ? AND activo = 1'
        );
        $variantCountStmt->execute([$productId]);
        $hasVariants = (int) $variantCountStmt->fetchColumn() > 0;

        $variantName = null;
        if ($hasVariants) {
            if ($variantId <= 0) {
                throw new RuntimeException('Selecciona una variante válida antes de pagar.');
            }
            $variantStmt = $db->prepare(
                'SELECT id, nombre, rango_mx, stock, activo
                 FROM producto_variantes WHERE id = ? AND producto_id = ? FOR UPDATE'
            );
            $variantStmt->execute([$variantId, $productId]);
            $variant = $variantStmt->fetch();
            if (!$variant || (int) $variant['activo'] !== 1) {
                throw new RuntimeException('La talla o variante seleccionada ya no está disponible.');
            }
            if ((int) $variant['stock'] < $quantity) {
                throw new RuntimeException('Ya no hay suficiente stock de ' . $product['nombre'] . ' · ' . $variant['nombre'] . '.');
            }
            $variantName = (string) $variant['nombre'];
            if (!empty($variant['rango_mx'])) {
                $variantName .= ' · ' . $variant['rango_mx'];
            }

            $variantUpdate = $db->prepare('UPDATE producto_variantes SET stock = stock - ? WHERE id = ?');
            $variantUpdate->execute([$quantity, $variantId]);
        } else {
            $variantId = 0;
            if ((int) $product['stock'] < $quantity) {
                throw new RuntimeException('Ya no hay suficiente stock de ' . $product['nombre'] . '.');
            }
        }

        if ((int) $product['stock'] < $quantity) {
            throw new RuntimeException('El stock cambió mientras preparábamos tu pedido. Inténtalo de nuevo.');
        }
        $productUpdate = $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
        $productUpdate->execute([$quantity, $productId]);

        $unitPrice = (float) $product['precio'];
        $lineTotal = round($unitPrice * $quantity, 2);
        $subtotal += $lineTotal;
        $validatedItems[] = [
            'producto_id' => $productId,
            'variante_id' => $variantId > 0 ? $variantId : null,
            'nombre' => (string) $product['nombre'],
            'variante_nombre' => $variantName,
            'precio' => $unitPrice,
            'cantidad' => $quantity,
            'total' => $lineTotal,
        ];
    }

    $subtotal = round($subtotal, 2);
    $orderNumber = 'HN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $orderStmt = $db->prepare(
        "INSERT INTO pedidos
         (numero_pedido, cliente_nombre, cliente_telefono, cliente_email, subtotal, total, moneda,
          estado, stock_reservado, reserva_expira_en)
         VALUES (?, ?, ?, ?, ?, ?, 'MXN', 'pending_payment', 1, DATE_ADD(NOW(), INTERVAL 45 MINUTE))"
    );
    $orderStmt->execute([$orderNumber, $name, $phone, $email !== '' ? $email : null, $subtotal, $subtotal]);
    $orderId = (int) $db->lastInsertId();

    $itemStmt = $db->prepare(
        'INSERT INTO pedido_items
         (pedido_id, producto_id, producto_variante_id, producto_nombre, variante_nombre,
          precio_unitario, cantidad, total_linea)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($validatedItems as $item) {
        $itemStmt->execute([
            $orderId,
            $item['producto_id'],
            $item['variante_id'],
            $item['nombre'],
            $item['variante_nombre'],
            $item['precio'],
            $item['cantidad'],
            $item['total'],
        ]);
    }

    $db->commit();

    $appUrl = rtrim((string) env('APP_URL', 'https://tienda.hnatacion.com'), '/');
    $preferenceItems = array_map(static function (array $item): array {
        return [
            'title' => $item['variante_nombre']
                ? $item['nombre'] . ' · ' . $item['variante_nombre']
                : $item['nombre'],
            'quantity' => $item['cantidad'],
            'unit_price' => $item['precio'],
            'currency_id' => 'MXN',
        ];
    }, $validatedItems);

    $preferencePayload = [
        'items' => $preferenceItems,
        'external_reference' => $orderNumber,
        'payer' => array_filter([
            'name' => $name,
            'email' => $email !== '' ? $email : null,
        ], static fn($value): bool => $value !== null && $value !== ''),
        'back_urls' => [
            'success' => $appUrl . '/checkout/resultado.php?pedido=' . rawurlencode($orderNumber) . '&retorno=success',
            'failure' => $appUrl . '/checkout/resultado.php?pedido=' . rawurlencode($orderNumber) . '&retorno=failure',
            'pending' => $appUrl . '/checkout/resultado.php?pedido=' . rawurlencode($orderNumber) . '&retorno=pending',
        ],
        'auto_return' => 'approved',
        'binary_mode' => true,
        'statement_descriptor' => 'HACHE NATACION',
        'metadata' => ['pedido' => $orderNumber],
    ];

    $mercadoPago = new MercadoPago($accessToken);
    $preference = $mercadoPago->createPreference($preferencePayload);
    $preferenceId = trim((string) ($preference['id'] ?? ''));
    $initPoint = trim((string) ($preference['init_point'] ?? ''));
    if ($preferenceId === '' || $initPoint === '') {
        throw new RuntimeException('Mercado Pago no devolvió un enlace de pago.');
    }

    $updatePreference = $db->prepare('UPDATE pedidos SET mp_preference_id = ? WHERE id = ?');
    $updatePreference->execute([$preferenceId, $orderId]);

    checkout_json(201, [
        'pedido' => $orderNumber,
        'init_point' => $initPoint,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($orderId > 0) {
        try {
            OrderService::releaseReservation(
                $db,
                $orderId,
                'cancelled',
                'No se pudo iniciar el pago en Mercado Pago.'
            );
        } catch (Throwable $releaseError) {
            error_log('[tienda-natacion][checkout] release error: ' . $releaseError->getMessage());
        }
    }
    error_log('[tienda-natacion][checkout] ' . $e->getMessage());
    $message = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'No pudimos preparar tu pedido. Inténtalo de nuevo.';
    checkout_json(422, ['message' => $message]);
}
