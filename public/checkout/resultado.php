<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

$db = Database::connection();
$orderNumber = trim((string) ($_GET['pedido'] ?? $_GET['external_reference'] ?? ''));
$paymentId = trim((string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? ''));

if ($orderNumber !== '' && $paymentId !== '' && trim((string) env('MERCADOPAGO_ACCESS_TOKEN')) !== '') {
    try {
        $payment = (new MercadoPago())->getPayment($paymentId);
        OrderService::applyPayment($db, $payment);
    } catch (Throwable $e) {
        error_log('[tienda-natacion][payment-return] ' . $e->getMessage());
    }
}

$order = null;
if ($orderNumber !== '') {
    $stmt = $db->prepare('SELECT * FROM pedidos WHERE numero_pedido = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch() ?: null;
}

$status = $order['estado'] ?? 'pending_payment';
$isPaid = in_array($status, ['paid', 'completed'], true);
$title = match ($status) {
    'paid' => 'Pago confirmado',
    'completed' => 'Pedido completado',
    'cancelled' => 'Pago no completado',
    default => 'Estamos confirmando tu pago',
};
$message = match ($status) {
    'paid', 'completed' => 'Tu pedido quedó registrado correctamente. Nos pondremos en contacto contigo para coordinar la entrega.',
    'cancelled' => 'El pedido no se cobró. Puedes volver a la tienda e intentarlo nuevamente.',
    default => 'Mercado Pago todavía no confirma el resultado. Puedes actualizar esta página en unos segundos.',
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#123b5d">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Hache Natación</title>
  <style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7f9;color:#172331;font-family:Arial,Helvetica,sans-serif}.card{width:min(560px,100%);padding:34px;background:#fff;border:1px solid #dce5eb;border-radius:22px;box-shadow:0 18px 50px rgba(18,59,93,.09);text-align:center}.mark{display:grid;place-items:center;width:62px;height:62px;margin:0 auto 20px;border-radius:50%;background:#eaf6fb;color:#1976a8;font-size:30px;font-weight:900}.card h1{margin:0;color:#123b5d;font-size:36px;letter-spacing:-.03em}.card p{margin:14px auto 0;max-width:440px;color:#6b7785;line-height:1.6}.order{margin:22px 0;padding:14px;border-radius:13px;background:#f4f7f9;color:#123b5d;font-weight:800}.actions{display:grid;gap:10px}.actions a{padding:13px 16px;border-radius:12px;text-decoration:none;font-weight:800}.primary{background:#1976a8;color:#fff}.secondary{border:1px solid #dce5eb;color:#123b5d}.incident{margin-top:16px;padding:12px;border-radius:10px;background:#fff4e6;color:#8a5817;font-size:13px;text-align:left}
  </style>
</head>
<body>
  <main class="card">
    <div class="mark" aria-hidden="true"><?= $isPaid ? '✓' : ($status === 'cancelled' ? '×' : '…') ?></div>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($order !== null): ?>
      <div class="order">Pedido <?= htmlspecialchars((string) $order['numero_pedido'], ENT_QUOTES, 'UTF-8') ?> · $<?= number_format((float) $order['total'], 2) ?></div>
      <?php if (!empty($order['incidencia'])): ?>
        <div class="incident">Tu pago requiere una revisión manual. Ya tenemos registrado el pedido.</div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="actions">
      <?php if ($status === 'pending_payment'): ?>
        <a class="primary" href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8') ?>">Actualizar estado</a>
      <?php endif; ?>
      <a class="secondary" href="/">Volver a la tienda</a>
    </div>
  </main>
  <?php if ($isPaid): ?>
    <script>localStorage.removeItem('hache_tienda_cart_v2');</script>
  <?php endif; ?>
</body>
</html>
