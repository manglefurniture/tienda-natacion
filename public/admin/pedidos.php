<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
OrderService::releaseExpiredReservations($db);

$allowedStates = ['pending_payment', 'paid', 'completed', 'cancelled'];
$stateFilter = trim((string) ($_GET['estado'] ?? ''));
if ($stateFilter !== '' && !in_array($stateFilter, $allowedStates, true)) {
    $stateFilter = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) {
    $q = substr($q, 0, 100);
}

$countRows = $db->query('SELECT estado, COUNT(*) AS total FROM pedidos GROUP BY estado')->fetchAll();
$counts = ['pending_payment' => 0, 'paid' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($countRows as $row) {
    $state = (string) $row['estado'];
    if (isset($counts[$state])) {
        $counts[$state] = (int) $row['total'];
    }
}

$sql = "SELECT p.id, p.numero_pedido, p.cliente_nombre, p.cliente_telefono, p.cliente_email,
               p.total, p.estado, p.incidencia, p.creado_en, p.notificacion_pago_en,
               (SELECT MAX(pg.actualizado_en) FROM pagos pg WHERE pg.pedido_id = p.id AND pg.estado = 'approved') AS pagado_en
        FROM pedidos p";
$where = [];
$params = [];
if ($stateFilter !== '') {
    $where[] = 'p.estado = ?';
    $params[] = $stateFilter;
}
if ($q !== '') {
    $where[] = '(p.numero_pedido LIKE ? OR p.cliente_nombre LIKE ? OR p.cliente_telefono LIKE ? OR p.cliente_email LIKE ?)';
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle, $needle);
}
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY CASE p.estado WHEN 'paid' THEN 0 WHEN 'pending_payment' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,
          p.creado_en DESC, p.id DESC LIMIT 200";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();
$flash = admin_take_flash();

function order_state_label(string $state): string
{
    return match ($state) {
        'paid' => 'Listo para entregar',
        'completed' => 'Entregado',
        'cancelled' => 'Cancelado',
        default => 'Pendiente de pago',
    };
}

function order_filter_url(string $state = '', string $q = ''): string
{
    $params = [];
    if ($state !== '') $params['estado'] = $state;
    if ($q !== '') $params['q'] = $q;
    return '/admin/pedidos.php' . ($params !== [] ? '?' . http_build_query($params) : '');
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
  <link rel="stylesheet" href="/admin/admin-extra.css?v=3">
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
    <div><span class="admin-eyebrow">Operación</span><h1>Pedidos</h1><p>Lo pagado aparece primero para que puedas preparar y entregar sin perder ventas de vista.</p></div>
  </section>

  <div class="admin-stat-strip order-filter-stats">
    <a class="admin-stat admin-stat-link <?= $stateFilter === '' ? 'is-selected' : '' ?>" href="<?= admin_e(order_filter_url('', $q)) ?>"><strong><?= array_sum($counts) ?></strong> todos</a>
    <a class="admin-stat admin-stat-link <?= $stateFilter === 'paid' ? 'is-selected' : '' ?>" href="<?= admin_e(order_filter_url('paid', $q)) ?>"><strong><?= $counts['paid'] ?></strong> por entregar</a>
    <a class="admin-stat admin-stat-link <?= $stateFilter === 'pending_payment' ? 'is-selected' : '' ?>" href="<?= admin_e(order_filter_url('pending_payment', $q)) ?>"><strong><?= $counts['pending_payment'] ?></strong> pendientes</a>
    <a class="admin-stat admin-stat-link <?= $stateFilter === 'completed' ? 'is-selected' : '' ?>" href="<?= admin_e(order_filter_url('completed', $q)) ?>"><strong><?= $counts['completed'] ?></strong> entregados</a>
    <a class="admin-stat admin-stat-link <?= $stateFilter === 'cancelled' ? 'is-selected' : '' ?>" href="<?= admin_e(order_filter_url('cancelled', $q)) ?>"><strong><?= $counts['cancelled'] ?></strong> cancelados</a>
  </div>

  <form class="order-search" method="get" action="/admin/pedidos.php">
    <?php if ($stateFilter !== ''): ?><input type="hidden" name="estado" value="<?= admin_e($stateFilter) ?>"><?php endif; ?>
    <label for="order-search-input">Buscar pedido</label>
    <div class="order-search-row">
      <input id="order-search-input" type="search" name="q" value="<?= admin_e($q) ?>" placeholder="Número, cliente, teléfono o correo" autocomplete="off">
      <button class="admin-primary-button" type="submit">Buscar</button>
      <?php if ($q !== ''): ?><a class="admin-secondary-button" href="<?= admin_e(order_filter_url($stateFilter)) ?>">Limpiar</a><?php endif; ?>
    </div>
  </form>

  <?php if ($flash !== null): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>"><?= admin_e((string) ($flash['message'] ?? '')) ?></div>
  <?php endif; ?>

  <section class="admin-panel">
    <?php if ($orders === []): ?>
      <div class="admin-empty"><h2>No encontramos pedidos</h2><p>Prueba otro filtro o término de búsqueda.</p></div>
    <?php else: ?>
      <div class="orders-list">
        <?php foreach ($orders as $order): ?>
          <?php $state = (string) $order['estado']; ?>
          <article class="order-row <?= $state === 'paid' ? 'order-row-ready' : '' ?>">
            <div>
              <div class="order-number"><?= admin_e($order['numero_pedido']) ?></div>
              <div class="order-date"><?= admin_e(date('d/m/Y H:i', strtotime((string) $order['creado_en']))) ?></div>
              <?php if ($state === 'paid' && !empty($order['pagado_en'])): ?><div class="order-paid-time">Pago confirmado <?= admin_e(date('d/m H:i', strtotime((string) $order['pagado_en']))) ?></div><?php endif; ?>
            </div>
            <div class="order-client"><strong><?= admin_e($order['cliente_nombre']) ?></strong><small><?= admin_e($order['cliente_telefono']) ?></small></div>
            <div class="order-total">$<?= number_format((float) $order['total'], 2) ?></div>
            <div><span class="status-pill order-status order-status-<?= admin_e($state === 'pending_payment' ? 'pending' : $state) ?>"><?= admin_e(order_state_label($state)) ?></span><?php if (!empty($order['incidencia'])): ?><div class="catalog-badges"><span class="status-pill status-warning">Revisar</span></div><?php endif; ?></div>
            <a class="admin-edit-button" href="/admin/pedido.php?id=<?= (int) $order['id'] ?>"><?= $state === 'paid' ? 'Preparar' : 'Ver' ?></a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
