<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (admin_is_authenticated()) {
    admin_redirect('/admin/');
}

$error = null;
$configuredUser = env('ADMIN_USERNAME', 'admin');
$configuredHash = env('ADMIN_PASSWORD_HASH');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf($_POST['csrf'] ?? null);

    $blockedUntil = (int) ($_SESSION['admin_blocked_until'] ?? 0);
    if ($blockedUntil > time()) {
        $error = 'Demasiados intentos. Espera un momento y vuelve a intentar.';
    } elseif ($configuredHash === null) {
        $error = 'El acceso administrativo todavía no está configurado en el servidor.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $validUser = hash_equals((string) $configuredUser, $username);
        $validPassword = password_verify($password, $configuredHash);

        if ($validUser && $validPassword) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_username'] = $configuredUser;
            $_SESSION['admin_attempts'] = 0;
            unset($_SESSION['admin_blocked_until']);
            admin_redirect('/admin/');
        }

        $attempts = (int) ($_SESSION['admin_attempts'] ?? 0) + 1;
        $_SESSION['admin_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['admin_blocked_until'] = time() + 60;
            $_SESSION['admin_attempts'] = 0;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Administración | Tienda Hache Natación</title>
  <link rel="stylesheet" href="/admin/admin.css?v=1">
</head>
<body class="admin-login-body">
  <main class="login-card">
    <a class="admin-brand" href="/">Hache Natación <span>Tienda</span></a>
    <div class="login-copy">
      <span class="admin-eyebrow">Administración</span>
      <h1>Control de tienda</h1>
      <p>Productos, variantes, inventario e imágenes desde un solo lugar.</p>
    </div>

    <?php if ($error !== null): ?>
      <div class="admin-alert admin-alert-error"><?= admin_e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="admin-form">
      <input type="hidden" name="csrf" value="<?= admin_e(admin_csrf_token()) ?>">
      <label>
        <span>Usuario</span>
        <input type="text" name="username" autocomplete="username" required autofocus>
      </label>
      <label>
        <span>Contraseña</span>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="admin-primary-button" type="submit">Entrar</button>
    </form>
  </main>
</body>
</html>
