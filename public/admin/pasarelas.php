<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

$db = Database::connection();
$error = null;
$success = null;

try {
    $config = PaymentGatewayConfig::mercadoPago($db);
} catch (Throwable $e) {
    $config = [
        'active' => false,
        'environment' => 'PRODUCTION',
        'public_key' => '',
        'configured_access_token' => false,
        'configured_webhook_secret' => false,
        'source' => 'error',
        'updated_at' => null,
        'access_token' => '',
        'webhook_secret' => '',
    ];
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf($_POST['csrf'] ?? null);
    $action = (string) ($_POST['action'] ?? 'save');

    try {
        if ($action === 'test') {
            $postedToken = trim((string) ($_POST['access_token'] ?? ''));
            $token = $postedToken !== '' ? $postedToken : trim((string) ($config['access_token'] ?? ''));
            if ($token === '') {
                throw new RuntimeException('Agrega un Access Token o guarda uno antes de probar la conexión.');
            }
            $account = (new MercadoPago($token))->getCurrentUser();
            $accountId = trim((string) ($account['id'] ?? ''));
            $nickname = trim((string) ($account['nickname'] ?? ''));
            $label = $nickname !== '' ? $nickname : ($accountId !== '' ? 'ID ' . $accountId : 'cuenta Mercado Pago');
            $success = 'Conexión correcta con ' . $label . '.';
        } else {
            PaymentGatewayConfig::saveMercadoPago(
                $db,
                [
                    'active' => isset($_POST['active']),
                    'environment' => $_POST['environment'] ?? 'PRODUCTION',
                    'public_key' => $_POST['public_key'] ?? '',
                    'access_token' => $_POST['access_token'] ?? '',
                    'webhook_secret' => $_POST['webhook_secret'] ?? '',
                ],
                (string) ($_SESSION['admin_username'] ?? 'admin')
            );
            admin_flash('success', 'Configuración de Mercado Pago guardada.');
            admin_redirect('/admin/pasarelas.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    try {
        $config = PaymentGatewayConfig::mercadoPago($db);
    } catch (Throwable $e) {
        $error ??= $e->getMessage();
    }
}

$flash = admin_take_flash();
if ($flash !== null && ($flash['type'] ?? '') !== 'error') {
    $success = (string) ($flash['message'] ?? '');
}

$keyConfigured = trim((string) env('PAYMENT_GATEWAY_CONFIG_KEY')) !== '';
$sourceLabel = ($config['source'] ?? '') === 'database' ? 'Panel / base de datos' : 'Variables del servidor (.env)';
$appUrl = rtrim((string) env('APP_URL', 'https://tienda.hnatacion.com'), '/');
$webhookUrl = $appUrl . '/webhooks/mercadopago.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Pasarelas de pago | Administración</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
  <link rel="stylesheet" href="/admin/admin-extra.css?v=2">
</head>
<body>
<header class="admin-header">
  <a class="admin-brand" href="/admin/">Hache Natación <span>Tienda</span></a>
  <nav class="admin-nav">
    <a href="/admin/">Productos</a>
    <a href="/admin/pedidos.php">Pedidos</a>
    <a class="is-active" href="/admin/pasarelas.php">Pagos</a>
  </nav>
  <div class="admin-header-actions">
    <a class="admin-secondary-button" href="/" target="_blank" rel="noopener">Ver tienda</a>
    <form method="post" action="/admin/logout.php">
      <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
      <button class="admin-link-button" type="submit">Salir</button>
    </form>
  </div>
</header>

<main class="admin-shell admin-editor-shell">
  <section class="admin-page-heading compact">
    <div>
      <span class="admin-eyebrow">Cobros en línea</span>
      <h1>Pasarelas de pago</h1>
      <p>Cambia la cuenta de Mercado Pago sin editar código ni exponer credenciales al navegador.</p>
    </div>
  </section>

  <?php if ($success !== null && $success !== ''): ?>
    <div class="admin-alert admin-alert-success"><?= admin_e($success) ?></div>
  <?php endif; ?>
  <?php if ($error !== null && $error !== ''): ?>
    <div class="admin-alert admin-alert-error"><?= admin_e($error) ?></div>
  <?php endif; ?>
  <?php if (!$keyConfigured): ?>
    <div class="admin-alert admin-alert-error">
      Falta <code>PAYMENT_GATEWAY_CONFIG_KEY</code> en el servidor. El checkout actual puede seguir usando el .env, pero no se pueden guardar secretos nuevos de forma segura hasta configurarla.
    </div>
  <?php endif; ?>

  <section class="admin-panel editor-section">
    <div class="editor-section-heading">
      <div>
        <span class="admin-eyebrow">Mercado Pago</span>
        <h2>Cuenta y credenciales</h2>
        <p class="form-help">Fuente actual: <strong><?= admin_e($sourceLabel) ?></strong>. Access Token: <strong><?= !empty($config['configured_access_token']) ? 'configurado' : 'pendiente' ?></strong>. Webhook Secret: <strong><?= !empty($config['configured_webhook_secret']) ? 'configurado' : 'pendiente' ?></strong>.</p>
      </div>
      <span class="status-pill <?= !empty($config['active']) ? 'status-active' : 'status-inactive' ?>"><?= !empty($config['active']) ? 'Activo' : 'Desactivado' ?></span>
    </div>

    <form method="post" class="form-grid two-cols" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">

      <label class="toggle-field field-wide">
        <input type="checkbox" name="active" value="1" <?= !empty($config['active']) ? 'checked' : '' ?>>
        <span><strong>Mercado Pago activo</strong><small>Si lo apagas, el checkout bloqueará temporalmente los pagos en línea.</small></span>
      </label>

      <label>
        <span>Tipo de credenciales</span>
        <select name="environment" style="width:100%;padding:12px 13px;border:1px solid #dce5eb;border-radius:11px;background:#fff">
          <option value="PRODUCTION" <?= ($config['environment'] ?? '') === 'PRODUCTION' ? 'selected' : '' ?>>Producción</option>
          <option value="TEST" <?= ($config['environment'] ?? '') === 'TEST' ? 'selected' : '' ?>>Pruebas</option>
        </select>
        <p class="form-help">Es una etiqueta operativa. Mercado Pago determina si el pago es real o de prueba por las credenciales utilizadas, no por una URL distinta.</p>
      </label>

      <label>
        <span>Public Key</span>
        <input type="text" name="public_key" maxlength="255" value="<?= admin_e((string) ($config['public_key'] ?? '')) ?>" placeholder="APP_USR-...">
      </label>

      <label class="field-wide">
        <span>Nuevo Access Token</span>
        <input type="password" name="access_token" maxlength="1000" value="" placeholder="Déjalo vacío para conservar el actual" autocomplete="new-password">
        <p class="form-help">Por seguridad el token guardado nunca se vuelve a mostrar. Si cambias este token, debes introducir también el Webhook Secret de la misma integración.</p>
      </label>

      <label class="field-wide">
        <span>Nuevo Webhook Secret</span>
        <input type="password" name="webhook_secret" maxlength="1000" value="" placeholder="Déjalo vacío para conservar el actual" autocomplete="new-password">
        <p class="form-help">Se cifra antes de almacenarse y no se envía de vuelta al navegador.</p>
      </label>

      <div class="field-wide" style="padding:14px;border:1px solid #dce5eb;border-radius:12px;background:#fbfdfe">
        <strong style="display:block;color:#123b5d;margin-bottom:6px">Webhook</strong>
        <code style="word-break:break-all"><?= admin_e($webhookUrl) ?></code>
        <p class="form-help">Esta URL no cambia aunque cambies de cuenta; solo actualiza el secreto correspondiente en Mercado Pago y aquí.</p>
      </div>

      <div class="editor-actions field-wide">
        <button class="admin-secondary-button" type="submit" name="action" value="test">Probar conexión</button>
        <button class="admin-primary-button" type="submit" name="action" value="save" <?= !$keyConfigured ? 'disabled' : '' ?>>Guardar configuración</button>
      </div>
    </form>
  </section>
</main>
</body>
</html>
