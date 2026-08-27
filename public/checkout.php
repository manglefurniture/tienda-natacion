<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

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

if (!isset($_SESSION['checkout_csrf']) || !is_string($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
}

$cssVersion = (string) (@filemtime(__DIR__ . '/assets/checkout.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/assets/checkout.js') ?: time());
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#123b5d">
  <meta name="robots" content="noindex,nofollow">
  <meta name="checkout-token" content="<?= htmlspecialchars($_SESSION['checkout_csrf'], ENT_QUOTES, 'UTF-8') ?>">
  <title>Finalizar compra | Hache Natación</title>
  <link rel="stylesheet" href="/assets/checkout.css?v=<?= rawurlencode($cssVersion) ?>">
</head>
<body>
<header class="checkout-header">
  <a href="/" class="checkout-brand">Hache Natación <span>Tienda</span></a>
  <a href="/" class="back-link">← Seguir comprando</a>
</header>

<main class="checkout-shell">
  <section class="checkout-copy">
    <span class="eyebrow">Finalizar compra</span>
    <h1>Revisa tu pedido</h1>
    <p>Confirmaremos nuevamente precio, talla y stock antes de enviarte a Mercado Pago.</p>
  </section>

  <div class="checkout-grid">
    <section class="checkout-card">
      <h2>Tus datos</h2>
      <form id="checkoutForm" novalidate>
        <label>
          <span>Nombre</span>
          <input type="text" name="nombre" autocomplete="name" maxlength="180" required>
        </label>
        <label>
          <span>Teléfono</span>
          <input type="tel" name="telefono" autocomplete="tel" maxlength="32" placeholder="10 dígitos" required>
        </label>
        <label>
          <span>Correo <small>opcional</small></span>
          <input type="email" name="email" autocomplete="email" maxlength="190">
        </label>
        <div id="checkoutError" class="checkout-error" hidden></div>
        <button type="submit" class="pay-button" id="payButton">Continuar a Mercado Pago</button>
        <small class="secure-copy">Tu pedido se crea en nuestro servidor antes de abrir el pago.</small>
      </form>
    </section>

    <aside class="checkout-card summary-card">
      <div class="summary-heading">
        <h2>Resumen</h2>
        <span id="summaryCount">0 artículos</span>
      </div>
      <div id="checkoutItems" class="checkout-items"></div>
      <div id="checkoutEmpty" class="checkout-empty" hidden>
        <strong>Tu carrito está vacío</strong>
        <a href="/">Volver a la tienda</a>
      </div>
      <div class="checkout-total">
        <span>Total</span>
        <strong id="checkoutTotal">$0.00</strong>
      </div>
    </aside>
  </div>
</main>

<script src="/assets/checkout.js?v=<?= rawurlencode($jsVersion) ?>" defer></script>
</body>
</html>
