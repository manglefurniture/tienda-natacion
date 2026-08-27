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
$action = (string) ($_POST['accion'] ?? '');
$db = Database::connection();

try {
    $stmt = $db->prepare('SELECT estado FROM pedidos WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $order = $stmt->fetch();
    if (!$order) {
        throw new RuntimeException('Pedido no encontrado.');
    }

    if ($action === 'completar' && (string) $order['estado'] === 'paid') {
        $update = $db->prepare("UPDATE pedidos SET estado = 'completed' WHERE id = ?");
        $update->execute([$id]);
        admin_flash('success', 'Pedido marcado como entregado.');
    } elseif ($action === 'cancelar' && (string) $order['estado'] === 'pending_payment') {
        OrderService::releaseReservation($db, $id, 'cancelled', 'Pedido cancelado desde administración.');
        admin_flash('success', 'Pedido cancelado y stock liberado.');
    } else {
        throw new RuntimeException('Ese cambio de estado no está permitido.');
    }
} catch (Throwable $e) {
    admin_flash('error', $e->getMessage());
}

admin_redirect('/admin/pedido.php?id=' . $id);
