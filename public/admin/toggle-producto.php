<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
admin_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

admin_verify_csrf($_POST['csrf'] ?? null);
$id = max(0, (int) ($_POST['id'] ?? 0));
$db = Database::connection();

$stmt = $db->prepare('UPDATE productos SET activo = IF(activo = 1, 0, 1) WHERE id = ?');
$stmt->execute([$id]);
admin_flash('success', 'Visibilidad del producto actualizada.');
admin_redirect('/admin/');
