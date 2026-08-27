<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
$products = $db->query(
    'SELECT p.id, p.nombre, p.precio, p.stock, p.activo,
            (SELECT COUNT(*) FROM producto_variantes v WHERE v.producto_id = p.id AND v.activo = 1) AS variantes,
            (SELECT COUNT(*) FROM producto_imagenes i WHERE i.producto_id = p.id) AS imagenes
     FROM productos p
     ORDER BY p.actualizado_en DESC, p.id DESC'
)->fetchAll();
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
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
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
          <article class="product-admin-row">
            <div class="product-admin-main">
              <div class="product-admin-name">
                <strong><?= admin_e($product['nombre']) ?></strong>
                <span class="status-pill <?= (int) $product['activo'] === 1 ? 'status-active' : 'status-inactive' ?>">
                  <?= (int) $product['activo'] === 1 ? 'Publicado' : 'Oculto' ?>
                </span>
              </div>
              <div class="product-admin-meta">
                <span>$<?= number_format((float) $product['precio'], 2) ?></span>
                <span><?= (int) $product['stock'] ?> unidades</span>
                <span><?= (int) $product['variantes'] ?> variantes</span>
                <span><?= (int) $product['imagenes'] ?> fotos</span>
              </div>
            </div>
            <a class="admin-edit-button" href="/admin/producto.php?id=<?= (int) $product['id'] ?>">Editar</a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
