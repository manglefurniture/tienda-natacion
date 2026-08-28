<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
OrderService::releaseExpiredReservations($db);
$products = $db->query(
    'SELECT p.id, p.nombre, p.precio, p.stock, p.activo,
            (SELECT COUNT(*) FROM producto_variantes v WHERE v.producto_id = p.id AND v.activo = 1) AS variantes,
            (SELECT MIN(v.stock) FROM producto_variantes v WHERE v.producto_id = p.id AND v.activo = 1) AS min_variante_stock,
            (SELECT COUNT(*) FROM producto_imagenes i WHERE i.producto_id = p.id) AS imagenes
     FROM productos p
     ORDER BY p.actualizado_en DESC, p.id DESC'
)->fetchAll();
$pendingOrders = (int) $db->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'pending_payment'")->fetchColumn();
$paidOrders = (int) $db->query("SELECT COUNT(*) FROM pedidos WHERE estado = 'paid'")->fetchColumn();
$flash = admin_take_flash();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Productos | Administración</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
  <link rel="stylesheet" href="/admin/admin-extra.css?v=2">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
  <nav class="admin-nav">
    <a class="is-active" href="/admin/">Productos</a>
    <a href="/admin/pedidos.php">Pedidos<?= $pendingOrders + $paidOrders > 0 ? ' · ' . ($pendingOrders + $paidOrders) : '' ?></a>
    <a href="/admin/pasarelas.php">Pagos</a>
  </nav>
  <div class="admin-header-actions">
    <a class="admin-secondary-button" href="/" target="_blank" rel="noopener">Ver tienda</a>
    <form method="post" action="/admin/logout.php">
      <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
      <button class="admin-link-button" type="submit">Salir</button>
    </form>
  </div>
</header>

<main class="admin-shell">
  <section class="admin-page-heading">
    <div>
      <span class="admin-eyebrow">Catálogo</span>
      <h1>Productos</h1>
      <p>Edita precios, stock, variantes e imágenes sin tocar GitHub.</p>
    </div>
    <a class="admin-primary-button" href="/admin/producto.php">+ Nuevo producto</a>
  </section>

  <div class="admin-stat-strip">
    <span class="admin-stat"><strong><?= count($products) ?></strong> productos</span>
    <a class="admin-stat admin-stat-link" href="/admin/pedidos.php" aria-label="Ver <?= $pendingOrders ?> pedidos pendientes"><strong><?= $pendingOrders ?></strong> pedidos pendientes <span aria-hidden="true">→</span></a>
    <a class="admin-stat admin-stat-link" href="/admin/pedidos.php" aria-label="Ver <?= $paidOrders ?> pedidos por entregar"><strong><?= $paidOrders ?></strong> por entregar <span aria-hidden="true">→</span></a>
    <span class="admin-stat"><strong><?= extension_loaded('gd') ? 'Activa' : 'Pendiente' ?></strong> optimización de fotos</span>
  </div>

  <?php if ($flash !== null): ?>
    <div class="admin-alert <?= ($flash['type'] ?? '') === 'error' ? 'admin-alert-error' : 'admin-alert-success' ?>">
      <?= admin_e((string) ($flash['message'] ?? '')) ?>
    </div>
  <?php endif; ?>

  <section class="admin-panel">
    <?php if ($products === []): ?>
      <div class="admin-empty">
        <h2>Aún no hay productos</h2>
        <p>Crea el primero desde el botón de arriba.</p>
      </div>
    <?php else: ?>
      <div class="product-admin-list">
        <?php foreach ($products as $product): ?>
          <?php
            $images = (int) $product['imagenes'];
            $stock = (int) $product['stock'];
            $variantCount = (int) $product['variantes'];
            $variantMinimum = $product['min_variante_stock'] !== null ? (int) $product['min_variante_stock'] : null;
            $active = (int) $product['activo'] === 1;
            $isOut = $stock === 0 || ($variantCount > 0 && $variantMinimum === 0);
            $isLow = !$isOut && ($variantCount > 0 ? $variantMinimum !== null && $variantMinimum <= 2 : $stock <= 2);
          ?>
          <article class="product-admin-row">
            <div class="product-admin-main">
              <div class="product-admin-name">
                <strong><?= admin_e($product['nombre']) ?></strong>
                <span class="status-pill <?= $active ? 'status-active' : 'status-inactive' ?>">
                  <?= $active ? 'Publicado' : 'Oculto' ?>
                </span>
              </div>
              <div class="product-admin-meta">
                <span>$<?= number_format((float) $product['precio'], 2) ?></span>
                <span><?= $stock ?> unidades</span>
                <span><?= $variantCount ?> variantes</span>
                <span><?= $images ?> fotos</span>
              </div>
              <?php if ($images === 0 || $isOut || $isLow): ?>
                <div class="catalog-badges">
                  <?php if ($images === 0): ?><span class="status-pill status-warning">Sin foto</span><?php endif; ?>
                  <?php if ($isOut): ?><span class="status-pill status-danger">Sin stock</span><?php elseif ($isLow): ?><span class="status-pill status-warning">Stock bajo</span><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="quick-actions">
              <form method="post" action="/admin/toggle-producto.php">
                <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                <button class="admin-mini-button" type="submit"><?= $active ? 'Ocultar' : 'Publicar' ?></button>
              </form>
              <a class="admin-edit-button" href="/admin/producto.php?id=<?= (int) $product['id'] ?>">Editar</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
