<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function test_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function test_ok(bool $condition, string $message): void
{
    if (!$condition) test_fail($message);
}

function test_source(string $relative): string
{
    global $root;
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) test_fail("No se pudo leer {$relative}");
    return $source;
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') return $default;
        return (string) $value;
    }
}

$_ENV['PAYMENT_GATEWAY_CONFIG_KEY'] = 'ci-payment-key-2026-long-and-stable';
require_once $root . '/src/PaymentGatewayConfig.php';

$configSource = test_source('src/PaymentGatewayConfig.php');
$panelSource = test_source('public/admin/pasarelas.php');
$checkoutSource = test_source('public/api/checkout.php');
$returnSource = test_source('public/checkout/resultado.php');
$webhookSource = test_source('public/webhooks/mercadopago.php');
$migrationSource = test_source('database/005_payment_gateway_config.sql');

test_ok(str_contains($configSource, "private const CIPHER = 'aes-256-gcm'"), 'El cifrado debe seguir usando AES-256-GCM.');
test_ok(str_contains($configSource, 'PAYMENT_GATEWAY_CONFIG_KEY'), 'La clave maestra debe permanecer fuera de la base de datos.');
test_ok(str_contains($configSource, 'if (!$row || (int) ($row[\'configurado\'] ?? 0) !== 1)'), 'El .env solo debe ser fallback antes del primer guardado.');
test_ok(!str_contains($configSource, ': $fallback[\'access_token\']'), 'Una configuración guardada no debe mezclar Access Token con el .env.');
test_ok(!str_contains($configSource, ': $fallback[\'webhook_secret\']'), 'Una configuración guardada no debe mezclar Webhook Secret con el .env.');
test_ok(str_contains($configSource, 'Al cambiar el Access Token, ingresa también el Webhook Secret'), 'Cambiar de cuenta debe exigir un par coherente de credenciales.');
test_ok(str_contains($configSource, 'Agrega un Webhook Secret antes de activar Mercado Pago.'), 'No debe activarse la pasarela sin webhook firmado.');
test_ok(str_contains($configSource, 'hasPayablePreferences($db)'), 'Cambiar credenciales debe revisar preferencias todavía cobrables.');
test_ok(str_contains($configSource, "estado = 'pending_payment'"), 'La protección de cambio debe observar pedidos pendientes.');
test_ok(str_contains($configSource, 'reserva_expira_en >= NOW()'), 'La protección debe respetar la ventana real de vigencia del pago.');

test_ok(str_contains($panelSource, '(new MercadoPago($newAccessToken))->getCurrentUser()'), 'Un Access Token nuevo debe validarse con Mercado Pago antes de guardarse.');
test_ok(str_contains($panelSource, 'OrderService::releaseExpiredReservations($db)'), 'El panel debe limpiar reservas vencidas antes de evaluar un cambio de credenciales.');

test_ok(str_contains($checkoutSource, 'PaymentGatewayConfig::mercadoPago($db)'), 'Checkout debe consumir la configuración centralizada.');
test_ok(str_contains($checkoutSource, "'expires' => true"), 'Las preferencias deben expirar junto con la reserva de stock.');
test_ok(str_contains($checkoutSource, "'expiration_date_from'"), 'Checkout debe enviar inicio de vigencia a Mercado Pago.');
test_ok(str_contains($checkoutSource, "'expiration_date_to'"), 'Checkout debe enviar fin de vigencia a Mercado Pago.');
test_ok(str_contains($checkoutSource, "'sandbox_init_point' : 'init_point'"), 'TEST debe redirigir al sandbox y producción al checkout real.');

test_ok(str_contains($returnSource, 'PaymentGatewayConfig::mercadoPago($db)'), 'El retorno del comprador debe usar la configuración centralizada.');
test_ok(!str_contains($returnSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'El retorno no debe depender del Access Token legacy.');
test_ok(str_contains($returnSource, 'new MercadoPago($accessToken)'), 'El retorno debe reconciliar con el token resuelto por la configuración centralizada.');

test_ok(str_contains($webhookSource, 'PaymentGatewayConfig::mercadoPago($db)'), 'Webhook debe consumir la misma configuración centralizada.');
test_ok(!str_contains($checkoutSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'Checkout no debe leer credenciales directamente del entorno.');
test_ok(!str_contains($webhookSource, "env('MERCADOPAGO_WEBHOOK_SECRET')"), 'Webhook no debe leer secretos directamente del entorno.');
test_ok(str_contains($migrationSource, "VALUES ('MERCADO_PAGO', 0, 0, 'PRODUCTION')"), 'La migración no debe activar ni tomar control de Mercado Pago automáticamente.');
test_ok(!preg_match('/value\s*=\s*["\'][^"\']*<\?=.*(?:access_token|webhook_secret)/i', $panelSource), 'El panel no debe renderizar secretos almacenados.');

$reflection = new ReflectionClass(PaymentGatewayConfig::class);
$encrypt = $reflection->getMethod('encrypt');
$decrypt = $reflection->getMethod('decryptOptional');
$encrypt->setAccessible(true);
$decrypt->setAccessible(true);

$plain = 'TEST_SECRET_' . bin2hex(random_bytes(8));
$encrypted = $encrypt->invoke(null, $plain);
test_ok(is_string($encrypted) && $encrypted !== '' && !str_contains($encrypted, $plain), 'El secreto cifrado no debe contener el texto plano.');
$roundTrip = $decrypt->invoke(null, $encrypted);
test_ok($roundTrip === $plain, 'El cifrado debe poder descifrarse con la misma clave maestra.');

$envelope = json_decode((string) base64_decode($encrypted, true), true);
test_ok(is_array($envelope) && isset($envelope['data']), 'El sobre cifrado debe ser válido.');
$cipherBytes = base64_decode((string) $envelope['data'], true);
test_ok(is_string($cipherBytes) && $cipherBytes !== '', 'El ciphertext debe existir.');
$cipherBytes[0] = chr(ord($cipherBytes[0]) ^ 1);
$envelope['data'] = base64_encode($cipherBytes);
$tampered = base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR));
$rejected = false;
try {
    $decrypt->invoke(null, $tampered);
} catch (Throwable) {
    $rejected = true;
}
test_ok($rejected, 'AES-GCM debe rechazar un ciphertext alterado.');

echo "Payment gateway regressions: OK\n";
