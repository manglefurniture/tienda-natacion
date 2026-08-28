<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

try {
    $db = Database::connection();
    $credentials = PaymentGatewayConfig::mercadoPagoCredentialCandidates($db);
} catch (Throwable $e) {
    error_log('[tienda-natacion][mercadopago-webhook-config] ' . $e->getMessage());
    http_response_code(500);
    exit;
}

$signature = trim((string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$requestId = trim((string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$dataId = trim((string) ($_GET['data.id'] ?? $_GET['data_id'] ?? ''));

if ($credentials === [] || $signature === '' || $dataId === '') {
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

if ($v1 === '') {
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

    $payment = null;
    $matchedCredential = null;
    $signatureMatched = false;
    $lastFetchError = null;

    foreach ($credentials as $credential) {
        $secret = trim((string) ($credential['webhook_secret'] ?? ''));
        $accessToken = trim((string) ($credential['access_token'] ?? ''));
        if ($secret === '' || $accessToken === '') {
            continue;
        }

        $expected = hash_hmac('sha256', $manifest, $secret);
        if (!hash_equals($expected, $v1)) {
            continue;
        }

        $signatureMatched = true;
        try {
            $candidatePayment = (new MercadoPago($accessToken))->getPayment($dataId);
            $payment = $candidatePayment;
            $matchedCredential = $credential;
            break;
        } catch (Throwable $fetchError) {
            // Dos versiones pueden compartir secreto. Si el token de esta versión
            // no puede leer el pago, continúa con las demás antes de fallar.
            $lastFetchError = $fetchError;
        }
    }

    if (!$signatureMatched) {
        http_response_code(401);
        exit;
    }
    if (!is_array($payment)) {
        if ($lastFetchError !== null) {
            throw $lastFetchError;
        }
        throw new RuntimeException('No se pudo resolver la versión de credenciales del pago.');
    }

    $orderId = OrderService::applyPayment($db, $payment);

    if ($orderId !== null && !empty($matchedCredential['credential_id'])) {
        $bind = $db->prepare(
            'UPDATE pedidos SET mp_credencial_id = COALESCE(mp_credencial_id, ?) WHERE id = ?'
        );
        $bind->execute([(int) $matchedCredential['credential_id'], $orderId]);
    }

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
