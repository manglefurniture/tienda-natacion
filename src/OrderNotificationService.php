<?php
declare(strict_types=1);

final class OrderNotificationService
{
    public static function notifyPaidOrder(PDO $db, int $orderId): bool
    {
        $to = trim((string) env('ORDER_NOTIFICATION_EMAIL'));
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $claim = $db->prepare(
            "UPDATE pedidos
             SET notificacion_pago_en = NOW()
             WHERE id = ? AND estado = 'paid' AND notificacion_pago_en IS NULL"
        );
        $claim->execute([$orderId]);
        if ($claim->rowCount() !== 1) {
            return false;
        }

        try {
            $orderStmt = $db->prepare(
                'SELECT id, numero_pedido, cliente_nombre, cliente_telefono, cliente_email, total, moneda, creado_en
                 FROM pedidos WHERE id = ? LIMIT 1'
            );
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new RuntimeException('Pedido no encontrado para notificación.');
            }

            $itemsStmt = $db->prepare(
                'SELECT producto_nombre, variante_nombre, cantidad, precio_unitario, total_linea
                 FROM pedido_items WHERE pedido_id = ? ORDER BY id ASC'
            );
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll();

            $from = trim((string) env('ORDER_NOTIFICATION_FROM', 'noreply@tienda.hnatacion.com'));
            if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                $from = 'noreply@tienda.hnatacion.com';
            }

            $subjectText = 'Nueva venta pagada · ' . (string) $order['numero_pedido'];
            $subject = function_exists('mb_encode_mimeheader')
                ? mb_encode_mimeheader($subjectText, 'UTF-8')
                : $subjectText;

            $lines = [
                'Nueva venta pagada en Hache Natación Tienda',
                '',
                'Pedido: ' . (string) $order['numero_pedido'],
                'Cliente: ' . (string) $order['cliente_nombre'],
                'Teléfono: ' . (string) $order['cliente_telefono'],
                'Correo: ' . ((string) ($order['cliente_email'] ?? '') !== '' ? (string) $order['cliente_email'] : '—'),
                'Total: $' . number_format((float) $order['total'], 2) . ' ' . (string) $order['moneda'],
                '',
                'Artículos:',
            ];

            foreach ($items as $item) {
                $label = (string) $item['producto_nombre'];
                if (!empty($item['variante_nombre'])) {
                    $label .= ' · ' . (string) $item['variante_nombre'];
                }
                $lines[] = sprintf(
                    '- %d × %s — $%s',
                    (int) $item['cantidad'],
                    $label,
                    number_format((float) $item['total_linea'], 2)
                );
            }

            $appUrl = rtrim((string) env('APP_URL', 'https://tienda.hnatacion.com'), '/');
            $lines[] = '';
            $lines[] = 'Abrir pedido: ' . $appUrl . '/admin/pedido.php?id=' . $orderId;

            $headers = [
                'From: Hache Natación Tienda <' . $from . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: HacheNatacionTienda',
            ];

            $sent = @mail($to, $subject, implode("\r\n", $lines), implode("\r\n", $headers));
            if (!$sent) {
                throw new RuntimeException('El servidor no pudo enviar el correo de notificación.');
            }

            return true;
        } catch (Throwable $e) {
            $release = $db->prepare(
                "UPDATE pedidos SET notificacion_pago_en = NULL
                 WHERE id = ? AND estado = 'paid'"
            );
            $release->execute([$orderId]);
            throw $e;
        }
    }
}
