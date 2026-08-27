<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hache_tienda_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function admin_is_authenticated(): bool
{
    return ($_SESSION['admin_authenticated'] ?? false) === true;
}

function admin_require_auth(): void
{
    if (!admin_is_authenticated()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function admin_csrf_token(): string
{
    if (!isset($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf'];
}

function admin_verify_csrf(?string $token): void
{
    $expected = $_SESSION['admin_csrf'] ?? '';
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(419);
        exit('La sesión expiró. Regresa al panel e inténtalo otra vez.');
    }
}

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

function admin_take_flash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    return is_array($flash) ? $flash : null;
}

function admin_redirect(string $location): never
{
    header('Location: ' . $location);
    exit;
}

function admin_slugify(string $value): string
{
    $value = trim($value);
    if (function_exists('transliterator_transliterate')) {
        $converted = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        if (is_string($converted)) {
            $value = $converted;
        }
    } else {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            $value = strtolower($converted);
        } else {
            $value = strtolower($value);
        }
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'producto';
}

function admin_unique_slug(PDO $db, string $name, ?int $excludeId = null): string
{
    $base = admin_slugify($name);
    $candidate = $base;
    $suffix = 2;

    while (true) {
        $sql = 'SELECT id FROM productos WHERE slug = ?';
        $params = [$candidate];
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            return $candidate;
        }

        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

function admin_upload_dir(): string
{
    return rtrim((string) env('UPLOAD_DIR', dirname(__DIR__) . '/uploads/productos'), '/');
}

function admin_upload_url(): string
{
    return rtrim((string) env('UPLOAD_URL', '/uploads/productos'), '/');
}

function admin_uploaded_file_path(string $url): ?string
{
    $baseUrl = admin_upload_url() . '/';
    if (!str_starts_with($url, $baseUrl)) {
        return null;
    }

    $name = basename($url);
    return admin_upload_dir() . '/' . $name;
}

if (admin_is_authenticated()) {
    ImageOptimizer::optimizeDirectory(admin_upload_dir());
}
