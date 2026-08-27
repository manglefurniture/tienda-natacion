<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$secret = trim((string) env('MERCADOPAGO_WEBHOOK_SECRET'));
$signature = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$dataId = trim((string) ($_GET['data.id'] ?? $_GET['data_id'] ?? ''));

if ($secret === '' || $signature === '' || $dataId === '') {
    http_response_code(401);
    exit;
}

$ts = '';
$v1 = '';
foreach (explode(',', $signature) as $part) {
    [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
    if ($key === 'ts') {
        $ts = $value;
    } elseif ($key === 'v1') {
        $v1 = $value;
    }
}

$manifestParts = [];
if ($dataId !== '') {
    $manifestParts[] = 'id:' . $dataId;
}
if ($requestId !== '') {
    $manifestParts[] = 'request-id:' . $requestId;
}
if ($ts !== '') {
    $manifestParts[] = 'ts:' . $ts;
}
$manifest = implode(';', $manifestParts) . ';';
$expected = hash_hmac('sha256', $manifest, $secret);

if ($v1 === '' || !hash_equals($expected, $v1)) {
    http_response_code(401);
    exit;
}

try {
    $body = json_decode((string) file_get_contents('php://input'), true);
    $type = is_array($body) ? (string) ($body['type'] ?? '') : '';
    if ($type !== '' && $type !== 'payment') {
        http_response_code(200);
        exit;
    }

    $db = Database::connection();
    $payment = (new MercadoPago())->getPayment($dataId);
    $orderId = OrderService::applyPayment($db, $payment);

    if ($orderId !== null && (string) ($payment['status'] ?? '') === 'approved') {
        try {
            OrderNotificationService::notifyPaidOrder($db, $orderId);
        } catch (Throwable $notificationError) {
            error_log('[tienda-natacion][order-notification] ' . $notificationError->getMessage());
        }
    }

    http_response_code(200);
} catch (Throwable $e) {
    error_log('[tienda-natacion][mercadopago-webhook] ' . $e->getMessage());
    http_response_code(500);
}
