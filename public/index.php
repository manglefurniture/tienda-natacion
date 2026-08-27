<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

$products = [];
$catalogError = false;

try {
    $db = Database::connection();
    $stmt = $db->query(
        'SELECT id, slug, nombre, descripcion, precio, stock, imagen_url
         FROM productos
         WHERE activo = 1
         ORDER BY id DESC'
    );
    $products = $stmt->fetchAll();

    $variantStmt = $db->prepare(
        'SELECT id, codigo, nombre, rango_mx, stock
         FROM producto_variantes
         WHERE producto_id = ? AND activo = 1
         ORDER BY id ASC'
    );
    $imageStmt = $db->prepare(
        'SELECT url, alt_text
         FROM producto_imagenes
         WHERE producto_id = ?
         ORDER BY orden ASC, id ASC'
    );

    foreach ($products as &$product) {
        $variantStmt->execute([(int) $product['id']]);
        $product['variantes'] = $variantStmt->fetchAll();

        $imageStmt->execute([(int) $product['id']]);
        $product['imagenes'] = $imageStmt->fetchAll();
    }
    unset($product);
} catch (Throwable $e) {
    $catalogError = true;
    error_log('[tienda-natacion] catalog error: ' . $e->getMessage());
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(float $value): string
{
    return '$' . number_format($value, 2, '.', ',');
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#123b5d">
  <meta name="description" content="Tienda oficial de Hache Natación. Accesorios y productos para natación en Cancún.">
  <title>Tienda | Hache Natación</title>
  <link rel="stylesheet" href="/assets/store.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="https://hnatacion.com" aria-label="Hache Natación">
    <span class="brand-mark" aria-hidden="true">H</span>
    <span><strong>Hache Natación</strong><small>Tienda</small></span>
  </a>
  <button class="cart-button" type="button" id="openCart" aria-controls="cartPanel" aria-expanded="false">
    Carrito <span id="cartCount" class="cart-count">0</span>
  </button>
</header>

<main>
  <section class="hero">
    <div>
      <span class="eyebrow">Tienda oficial</span>
      <h1>Todo para seguir nadando.</h1>
      <p>Accesorios seleccionados por Hache Natación. Compra en línea y coordina tu entrega en Cancún.</p>
      <a class="hero-link" href="#productos">Ver productos</a>
    </div>
  </section>

  <section class="catalog" id="productos">
    <div class="section-heading">
      <div>
        <span class="eyebrow">Catálogo</span>
        <h2>Productos disponibles</h2>
      </div>
      <p><?= count($products) ?> producto<?= count($products) === 1 ? '' : 's' ?></p>
    </div>

    <?php if ($catalogError): ?>
      <div class="empty-state">
        <h3>Estamos actualizando la tienda</h3>
        <p>El catálogo volverá a estar disponible en unos minutos.</p>
      </div>
    <?php elseif ($products === []): ?>
      <div class="empty-state">
        <h3>Muy pronto</h3>
        <p>Estamos preparando los primeros productos de la tienda.</p>
      </div>
    <?php else: ?>
      <div class="product-grid">
        <?php foreach ($products as $product): ?>
          <?php
            $price = (float) $product['precio'];
            $variants = $product['variantes'] ?? [];
            $images = $product['imagenes'] ?? [];
            $stock = $variants !== []
                ? array_sum(array_map(static fn(array $variant): int => (int) $variant['stock'], $variants))
                : (int) $product['stock'];
            $mainImage = $images[0]['url'] ?? trim((string) ($product['imagen_url'] ?? ''));
          ?>
          <article class="product-card" data-product-card>
            <div class="product-media">
              <?php if ($mainImage !== ''): ?>
                <img
                  src="<?= e($mainImage) ?>"
                  alt="<?= e($images[0]['alt_text'] ?? $product['nombre']) ?>"
                  loading="lazy"
                  data-main-image
                >
              <?php else: ?>
                <div class="image-placeholder" aria-hidden="true">H</div>
              <?php endif; ?>
              <?php if ($stock <= 0): ?><span class="stock-badge">Agotado</span><?php endif; ?>
            </div>

            <?php if (count($images) > 1): ?>
              <div class="product-thumbnails" aria-label="Más imágenes de <?= e($product['nombre']) ?>">
                <?php foreach ($images as $index => $image): ?>
                  <button
                    class="product-thumbnail<?= $index === 0 ? ' is-active' : '' ?>"
                    type="button"
                    data-product-image="<?= e($image['url']) ?>"
                    data-product-alt="<?= e($image['alt_text'] ?? $product['nombre']) ?>"
                    aria-label="Ver imagen <?= $index + 1 ?> de <?= e($product['nombre']) ?>"
                  >
                    <img src="<?= e($image['url']) ?>" alt="" loading="lazy">
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="product-body">
              <h3><?= e($product['nombre']) ?></h3>
              <?php if (!empty($product['descripcion'])): ?>
                <p><?= e($product['descripcion']) ?></p>
              <?php endif; ?>

              <?php if ($variants !== []): ?>
                <label class="variant-picker">
                  <span>Selecciona talla</span>
                  <select data-variant-select <?= $stock <= 0 ? 'disabled' : '' ?>>
                    <?php foreach ($variants as $variant): ?>
                      <?php $variantStock = (int) $variant['stock']; ?>
                      <option
                        value="<?= (int) $variant['id'] ?>"
                        data-stock="<?= $variantStock ?>"
                        data-label="<?= e($variant['nombre'] . ($variant['rango_mx'] ? ' · ' . $variant['rango_mx'] : '')) ?>"
                        <?= $variantStock <= 0 ? 'disabled' : '' ?>
                      >
                        <?= e($variant['nombre']) ?><?= $variant['rango_mx'] ? ' · ' . e($variant['rango_mx']) : '' ?> — <?= $variantStock ?> disp.
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
              <?php endif; ?>

              <div class="product-footer">
                <div>
                  <strong><?= money($price) ?></strong>
                  <small class="stock-copy"><?= $stock ?> en existencia</small>
                </div>
                <button
                  class="add-button"
                  type="button"
                  <?= $stock <= 0 ? 'disabled' : '' ?>
                  data-add-product
                  data-id="<?= (int) $product['id'] ?>"
                  data-name="<?= e($product['nombre']) ?>"
                  data-price="<?= e(number_format($price, 2, '.', '')) ?>"
                  data-stock="<?= $stock ?>"
                ><?= $stock <= 0 ? 'Sin stock' : 'Agregar' ?></button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<div class="cart-backdrop" id="cartBackdrop" hidden></div>
<aside class="cart-panel" id="cartPanel" aria-hidden="true" aria-label="Carrito de compra">
  <div class="cart-header">
    <div><span class="eyebrow">Tu compra</span><h2>Carrito</h2></div>
    <button type="button" class="icon-button" id="closeCart" aria-label="Cerrar carrito">×</button>
  </div>
  <div id="cartItems" class="cart-items"></div>
  <div class="cart-empty" id="cartEmpty">Tu carrito está vacío.</div>
  <div class="cart-summary">
    <div><span>Total</span><strong id="cartTotal">$0.00</strong></div>
    <button type="button" class="checkout-button" disabled>Pago en línea · siguiente etapa</button>
    <small>El precio, la talla y el stock se validarán nuevamente antes de pagar.</small>
  </div>
</aside>

<footer class="site-footer">
  <strong>Hache Natación</strong>
  <span>Natación en Cancún · México</span>
</footer>

<script src="/assets/store.js" defer></script>
</body>
</html>
