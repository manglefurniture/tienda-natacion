<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
OrderService::releaseExpiredReservations($db);
$orders = $db->query(
    "SELECT id, numero_pedido, cliente_nombre, cliente_telefono, total, estado, incidencia, creado_en
     FROM pedidos
     ORDER BY creado_en DESC, id DESC
     LIMIT 200"
)->fetchAll();
$flash = admin_take_flash();

$counts = ['pending_payment' => 0, 'paid' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($orders as $order) {
    $state = (string) $order['estado'];
    if (isset($counts[$state])) $counts[$state]++;
}

function order_state_label(string $state): string
{
    return match ($state) {
        'paid' => 'Pagado',
        'completed' => 'Entregado',
        'cancelled' => 'Cancelado',
        default => 'Pendiente de pago',
    };
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Pedidos | Administración</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
  <link rel="stylesheet" href="/admin/admin-extra.css?v=2">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
  <nav class="admin-nav"><a href="/admin/">Productos</a><a class="is-active" href="/admin/pedidos.php">Pedidos</a></nav>
  <div class="admin-header-actions">
    <a class="admin-secondary-button" href="/" target="_blank" rel="noopener">Ver tienda</a>
    <form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>"><button class="admin-link-button" type="submit">Salir</button></form>
  </div>
</header>

<main class="admin-shell">
  <section class="admin-page-heading">
    <div><span class="admin-eyebrow">Operación</span><h1>Pedidos</h1><p>Pagos, clientes, artículos y estado de entrega desde un solo lugar.</p></div>
  </section>

  <div class="admin-stat-strip">
    <span class="admin-stat"><strong><?= $counts['pending_payment'] ?></strong> pendientes</span>
    <span class="admin-stat"><strong><?= $counts['paid'] ?></strong> pagados</span>
    <span class="admin-stat"><strong><?= $counts['completed'] ?></strong> entregados</span>
    <span class="admin-stat"><strong><?= $counts['cancelled'] ?></strong> cancelados</span>
  </div>

  <?php if ($flash !== null): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>"><?= admin_e((string) ($flash['message'] ?? '')) ?></div>
  <?php endif; ?>

  <section class="admin-panel">
    <?php if ($orders === []): ?>
      <div class="admin-empty"><h2>Aún no hay pedidos</h2><p>Los pedidos aparecerán aquí cuando alguien finalice una compra.</p></div>
    <?php else: ?>
      <div class="orders-list">
        <?php foreach ($orders as $order): ?>
          <?php $state = (string) $order['estado']; ?>
          <article class="order-row">
            <div><div class="order-number"><?= admin_e($order['numero_pedido']) ?></div><div class="order-date"><?= admin_e(date('d/m/Y H:i', strtotime((string) $order['creado_en']))) ?></div></div>
            <div class="order-client"><strong><?= admin_e($order['cliente_nombre']) ?></strong><small><?= admin_e($order['cliente_telefono']) ?></small></div>
            <div class="order-total">$<?= number_format((float) $order['total'], 2) ?></div>
            <div><span class="status-pill order-status order-status-<?= admin_e($state === 'pending_payment' ? 'pending' : $state) ?>"><?= admin_e(order_state_label($state)) ?></span><?php if (!empty($order['incidencia'])): ?><div class="catalog-badges"><span class="status-pill status-warning">Revisar</span></div><?php endif; ?></div>
            <a class="admin-edit-button" href="/admin/pedido.php?id=<?= (int) $order['id'] ?>">Ver</a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
