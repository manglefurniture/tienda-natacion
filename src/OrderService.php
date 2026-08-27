<?php
declare(strict_types=1);

final class OrderService
{
    public static function releaseExpiredReservations(PDO $db): int
    {
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->query(
                "SELECT id FROM pedidos
                 WHERE estado = 'pending_payment'
                   AND stock_reservado = 1
                   AND reserva_expira_en IS NOT NULL
                   AND reserva_expira_en < NOW()
                 FOR UPDATE"
            );
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

            foreach ($ids as $orderId) {
                self::restoreReservation($db, $orderId);
                $update = $db->prepare(
                    "UPDATE pedidos
                     SET estado = 'cancelled', stock_reservado = 0,
                         incidencia = COALESCE(incidencia, 'Reserva liberada automáticamente por expiración.')
                     WHERE id = ?"
                );
                $update->execute([$orderId]);
            }

            if ($ownTransaction) {
                $db->commit();
            }
            return count($ids);
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function releaseReservation(PDO $db, int $orderId, string $state = 'cancelled', ?string $reason = null): void
    {
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare('SELECT stock_reservado FROM pedidos WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new RuntimeException('Pedido no encontrado.');
            }

            if ((int) $order['stock_reservado'] === 1) {
                self::restoreReservation($db, $orderId);
            }

            $update = $db->prepare(
                'UPDATE pedidos SET estado = ?, stock_reservado = 0, reserva_expira_en = NULL,
                 incidencia = COALESCE(?, incidencia) WHERE id = ?'
            );
            $update->execute([$state, $reason, $orderId]);

            if ($ownTransaction) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function applyPayment(PDO $db, array $payment): ?int
    {
        $externalReference = trim((string) ($payment['external_reference'] ?? ''));
        $paymentId = trim((string) ($payment['id'] ?? ''));
        $status = trim((string) ($payment['status'] ?? ''));
        $amount = (float) ($payment['transaction_amount'] ?? 0);
        $currency = strtoupper(trim((string) ($payment['currency_id'] ?? 'MXN')));

        if ($externalReference === '' || $paymentId === '') {
            return null;
        }

        $db->beginTransaction();
        try {
            $orderStmt = $db->prepare('SELECT * FROM pedidos WHERE numero_pedido = ? FOR UPDATE');
            $orderStmt->execute([$externalReference]);
            $order = $orderStmt->fetch();
            if (!$order) {
                $db->commit();
                return null;
            }

            $orderId = (int) $order['id'];
            $mappedPaymentStatus = match ($status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                'cancelled', 'cancelled_by_collector' => 'cancelled',
                'refunded', 'charged_back' => 'refunded',
                default => 'pending',
            };

            $paymentSql =
                'INSERT INTO pagos
                 (pedido_id, proveedor, proveedor_pago_id, referencia_externa, importe, moneda, estado, proveedor_estado)
                 VALUES (?, \'mercadopago\', ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   pedido_id = VALUES(pedido_id), referencia_externa = VALUES(referencia_externa),
                   importe = VALUES(importe), moneda = VALUES(moneda), estado = VALUES(estado),
                   proveedor_estado = VALUES(proveedor_estado)';
            $paymentStmt = $db->prepare($paymentSql);
            $paymentStmt->execute([
                $orderId,
                $paymentId,
                $externalReference,
                $amount,
                $currency,
                $mappedPaymentStatus,
                $status,
            ]);

            $amountMatches = abs($amount - (float) $order['total']) < 0.01;
            $currencyMatches = $currency === strtoupper((string) $order['moneda']);

            if ($status === 'approved') {
                if (!$amountMatches || !$currencyMatches) {
                    $incident = 'Pago aprobado con monto o moneda distinta al pedido. Revisión manual requerida.';
                    $update = $db->prepare('UPDATE pedidos SET incidencia = ? WHERE id = ?');
                    $update->execute([$incident, $orderId]);
                } else {
                    if ((int) $order['stock_reservado'] !== 1 && (string) $order['estado'] !== 'paid') {
                        if (!self::reserveFromExistingItems($db, $orderId)) {
                            $incident = 'Pago aprobado, pero ya no hay stock suficiente. Revisión manual requerida.';
                            $update = $db->prepare(
                                "UPDATE pedidos SET estado = 'paid', incidencia = ?, reserva_expira_en = NULL WHERE id = ?"
                            );
                            $update->execute([$incident, $orderId]);
                            $db->commit();
                            return $orderId;
                        }
                    }

                    $update = $db->prepare(
                        "UPDATE pedidos
                         SET estado = 'paid', stock_reservado = 0, reserva_expira_en = NULL, incidencia = NULL
                         WHERE id = ?"
                    );
                    $update->execute([$orderId]);
                }
            } elseif (in_array($status, ['rejected', 'cancelled', 'cancelled_by_collector'], true)) {
                if ((int) $order['stock_reservado'] === 1) {
                    self::restoreReservation($db, $orderId);
                }
                $update = $db->prepare(
                    "UPDATE pedidos SET estado = 'cancelled', stock_reservado = 0, reserva_expira_en = NULL WHERE id = ?"
                );
                $update->execute([$orderId]);
            } elseif (in_array($status, ['refunded', 'charged_back'], true)) {
                if ((string) $order['estado'] === 'paid' || (string) $order['estado'] === 'completed') {
                    self::restoreReservation($db, $orderId);
                }
                $update = $db->prepare(
                    "UPDATE pedidos SET estado = 'cancelled', stock_reservado = 0, reserva_expira_en = NULL,
                     incidencia = 'Pago reembolsado o contracargado; stock reintegrado.' WHERE id = ?"
                );
                $update->execute([$orderId]);
            }

            $db->commit();
            return $orderId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function restoreReservation(PDO $db, int $orderId): void
    {
        $itemsStmt = $db->prepare(
            'SELECT producto_id, producto_variante_id, cantidad FROM pedido_items WHERE pedido_id = ?'
        );
        $itemsStmt->execute([$orderId]);

        foreach ($itemsStmt->fetchAll() as $item) {
            $productId = (int) ($item['producto_id'] ?? 0);
            $variantId = (int) ($item['producto_variante_id'] ?? 0);
            $quantity = (int) $item['cantidad'];

            if ($variantId > 0) {
                $variantUpdate = $db->prepare('UPDATE producto_variantes SET stock = stock + ? WHERE id = ?');
                $variantUpdate->execute([$quantity, $variantId]);
            }
            if ($productId > 0) {
                $productUpdate = $db->prepare('UPDATE productos SET stock = stock + ? WHERE id = ?');
                $productUpdate->execute([$quantity, $productId]);
            }
        }
    }

    private static function reserveFromExistingItems(PDO $db, int $orderId): bool
    {
        $itemsStmt = $db->prepare(
            'SELECT producto_id, producto_variante_id, cantidad FROM pedido_items WHERE pedido_id = ? ORDER BY id ASC'
        );
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $item) {
            $productId = (int) ($item['producto_id'] ?? 0);
            $variantId = (int) ($item['producto_variante_id'] ?? 0);
            $quantity = (int) $item['cantidad'];

            if ($productId <= 0 || $quantity <= 0) {
                return false;
            }

            $productStmt = $db->prepare('SELECT stock FROM productos WHERE id = ? FOR UPDATE');
            $productStmt->execute([$productId]);
            $product = $productStmt->fetch();
            if (!$product || (int) $product['stock'] < $quantity) {
                return false;
            }

            if ($variantId > 0) {
                $variantStmt = $db->prepare('SELECT stock FROM producto_variantes WHERE id = ? AND producto_id = ? FOR UPDATE');
                $variantStmt->execute([$variantId, $productId]);
                $variant = $variantStmt->fetch();
                if (!$variant || (int) $variant['stock'] < $quantity) {
                    return false;
                }
            }
        }

        foreach ($items as $item) {
            $productId = (int) $item['producto_id'];
            $variantId = (int) ($item['producto_variante_id'] ?? 0);
            $quantity = (int) $item['cantidad'];

            if ($variantId > 0) {
                $variantUpdate = $db->prepare('UPDATE producto_variantes SET stock = stock - ? WHERE id = ?');
                $variantUpdate->execute([$quantity, $variantId]);
            }
            $productUpdate = $db->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');
            $productUpdate->execute([$quantity, $productId]);
        }

        return true;
    }
}
