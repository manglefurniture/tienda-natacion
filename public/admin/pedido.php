<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
$id = max(0, (int) ($_GET['id'] ?? 0));
$stmt = $db->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) {
    http_response_code(404);
    exit('Pedido no encontrado.');
}

$itemStmt = $db->prepare('SELECT * FROM pedido_items WHERE pedido_id = ? ORDER BY id ASC');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();
$paymentStmt = $db->prepare('SELECT * FROM pagos WHERE pedido_id = ? ORDER BY actualizado_en DESC, id DESC');
$paymentStmt->execute([$id]);
$payments = $paymentStmt->fetchAll();
$flash = admin_take_flash();

$state = (string) $order['estado'];
$stateLabel = match ($state) {
    'paid' => 'Listo para entregar',
    'completed' => 'Entregado',
    'cancelled' => 'Cancelado',
    default => 'Pendiente de pago',
};

function payment_state_label(string $state): string
{
    return match ($state) {
        'approved' => 'Aprobado',
        'rejected' => 'Rechazado',
        'cancelled' => 'Cancelado',
        'refunded' => 'Reembolsado',
        default => ucfirst($state),
    };
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title><?= admin_e($order['numero_pedido']) ?> | Pedido</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
  <link rel="stylesheet" href="/admin/admin-extra.css?v=3">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
  <nav class="admin-nav"><a href="/admin/">Productos</a><a class="is-active" href="/admin/pedidos.php">Pedidos</a></nav>
  <div class="admin-header-actions"><a class="admin-secondary-button" href="/admin/pedidos.php">← Pedidos</a></div>
</header>

<main class="admin-shell">
  <section class="admin-page-heading compact">
    <div><span class="admin-eyebrow">Pedido</span><h1><?= admin_e($order['numero_pedido']) ?></h1><p><?= admin_e(date('d/m/Y H:i', strtotime((string) $order['creado_en']))) ?></p></div>
    <span class="status-pill order-status order-status-<?= admin_e($state === 'pending_payment' ? 'pending' : $state) ?>"><?= admin_e($stateLabel) ?></span>
  </section>

  <?php if ($state === 'paid'): ?>
    <div class="ready-banner">
      <div><strong>Pago confirmado</strong><span>Este pedido ya puede prepararse para entrega.</span></div>
      <?php if (!empty($order['notificacion_pago_en'])): ?><span class="ready-banner-note">Aviso de venta enviado</span><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($flash !== null): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>"><?= admin_e((string) ($flash['message'] ?? '')) ?></div>
  <?php endif; ?>
  <?php if (!empty($order['incidencia'])): ?><div class="incident-box"><strong>Revisión:</strong> <?= admin_e($order['incidencia']) ?></div><?php endif; ?>

  <div class="order-detail-grid">
    <section class="order-card">
      <h2>Artículos</h2>
      <div class="order-items">
        <?php foreach ($items as $item): ?>
          <div class="order-item">
            <div><strong><?= admin_e($item['producto_nombre']) ?></strong><?php if (!empty($item['variante_nombre'])): ?><small><?= admin_e($item['variante_nombre']) ?></small><?php endif; ?><small><?= (int) $item['cantidad'] ?> × $<?= number_format((float) $item['precio_unitario'], 2) ?></small></div>
            <strong>$<?= number_format((float) $item['total_linea'], 2) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="order-summary-row"><span>Total</span><strong>$<?= number_format((float) $order['total'], 2) ?> <?= admin_e($order['moneda']) ?></strong></div>
    </section>

    <aside class="order-card">
      <h2>Cliente</h2>
      <div class="order-summary-row"><span>Nombre</span><strong><?= admin_e($order['cliente_nombre']) ?></strong></div>
      <div class="order-summary-row"><span>Teléfono</span><strong><?= admin_e($order['cliente_telefono']) ?></strong></div>
      <div class="order-summary-row"><span>Correo</span><strong><?= admin_e($order['cliente_email'] ?: '—') ?></strong></div>
      <?php if ($payments !== []): ?>
        <h2 style="margin-top:24px">Pago</h2>
        <?php foreach ($payments as $payment): ?>
          <?php $providerState = (string) ($payment['proveedor_estado'] ?: $payment['estado']); ?>
          <div class="order-summary-row"><span><?= admin_e(ucfirst((string) $payment['proveedor'])) ?></span><strong><?= admin_e(payment_state_label($providerState)) ?></strong></div>
          <div class="order-summary-row"><span>Importe</span><strong>$<?= number_format((float) $payment['importe'], 2) ?> <?= admin_e((string) $payment['moneda']) ?></strong></div>
          <div class="order-summary-row"><span>ID de pago</span><strong class="payment-id"><?= admin_e($payment['proveedor_pago_id'] ?: '—') ?></strong></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if (in_array($state, ['pending_payment', 'paid'], true)): ?>
        <form method="post" action="/admin/actualizar-pedido.php" style="margin-top:22px">
          <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
          <?php if ($state === 'paid'): ?>
            <input type="hidden" name="accion" value="completar">
            <button class="admin-primary-button" type="submit" style="width:100%">Marcar como entregado</button>
          <?php else: ?>
            <input type="hidden" name="accion" value="cancelar">
            <button class="admin-secondary-button" type="submit" style="width:100%">Cancelar pedido</button>
          <?php endif; ?>
        </form>
      <?php endif; ?>
    </aside>
  </div>
</main>
</body>
</html>
