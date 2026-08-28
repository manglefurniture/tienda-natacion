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
test_ok(str_contains($configSource, 'mercadoPagoCredentialCandidates'), 'Webhook debe poder resolver versiones históricas.');
test_ok(str_contains($configSource, 'credentialById'), 'Debe poder recuperarse una versión concreta por pedido.');
test_ok(str_contains($configSource, 'insertCredential'), 'Los cambios deben crear versiones inmutables de credenciales.');
test_ok(str_contains($configSource, 'insertLegacyCredential'), 'La transición debe preservar una versión legacy independiente.');
test_ok(str_contains($configSource, 'FOR UPDATE'), 'El cambio de configuración debe bloquear la fila actual durante la transición.');
test_ok(str_contains($configSource, 'LOCK IN SHARE MODE'), 'Checkout debe serializar la lectura inicial contra cambios concurrentes.');
test_ok(!str_contains($configSource, 'hasPayablePreferences'), 'El diseño no debe depender de bloquear cambios por estado temporal del pedido.');
test_ok(str_contains($configSource, 'UPDATE pedidos SET mp_credencial_id = ? WHERE mp_credencial_id IS NULL'), 'La primera transición debe vincular pedidos legacy a su versión histórica.');
test_ok(str_contains($configSource, '$fallback[\'access_token\'] !== \'\''), 'La preservación legacy debe depender del Access Token aunque falte Webhook Secret.');
test_ok(str_contains($configSource, '$webhookSecret !== \'\' ? self::encrypt($webhookSecret) : null'), 'Una versión legacy debe permitir Webhook Secret ausente.');
test_ok(str_contains($configSource, '$credential[\'webhook_secret\'] ?? \'\''), 'Las versiones sin Webhook Secret deben excluirse de candidatos de webhook.');

test_ok(str_contains($migrationSource, 'CREATE TABLE IF NOT EXISTS pasarelas_pago_credenciales'), 'La migración debe conservar historial de credenciales.');
test_ok(str_contains($migrationSource, 'credencial_actual_id'), 'La configuración debe apuntar a una versión actual.');
test_ok(str_contains($migrationSource, 'mp_credencial_id'), 'Cada pedido debe guardar la versión usada.');
test_ok(str_contains($migrationSource, 'webhook_secret_enc TEXT NULL'), 'El historial legacy debe admitir token sin Webhook Secret.');
test_ok(str_contains($migrationSource, 'ON UPDATE CASCADE ON DELETE RESTRICT'), 'Las credenciales históricas no deben poder borrarse si están referenciadas.');

test_ok(str_contains($panelSource, '(new MercadoPago($newAccessToken))->getCurrentUser()'), 'Un Access Token nuevo debe validarse con Mercado Pago antes de guardarse.');
test_ok(!preg_match('/value\s*=\s*["\'][^"\']*<\?=.*(?:access_token|webhook_secret)/i', $panelSource), 'El panel no debe renderizar secretos almacenados.');

test_ok(str_contains($checkoutSource, 'PaymentGatewayConfig::mercadoPagoForCheckout($db)'), 'Checkout debe adquirir la configuración con lock compartido.');
test_ok(str_contains($checkoutSource, 'mp_credencial_id'), 'Checkout debe persistir la versión usada en el pedido.');
test_ok(str_contains($checkoutSource, "'expires' => true"), 'Las preferencias deben expirar junto con la reserva de stock.');
test_ok(str_contains($checkoutSource, "'expiration_date_to'"), 'Checkout debe enviar fin de vigencia a Mercado Pago.');
test_ok(str_contains($checkoutSource, "'sandbox_init_point' : 'init_point'"), 'TEST debe redirigir al sandbox y producción al checkout real.');
test_ok(!str_contains($checkoutSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'Checkout no debe leer credenciales directamente del entorno.');

test_ok(str_contains($returnSource, 'PaymentGatewayConfig::credentialById($db, $credentialId)'), 'El retorno debe usar la versión ligada al pedido.');
test_ok(!str_contains($returnSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'El retorno no debe depender del Access Token legacy.');

test_ok(str_contains($webhookSource, 'PaymentGatewayConfig::mercadoPagoCredentialCandidates($db)'), 'Webhook debe evaluar versiones históricas completas.');
test_ok(str_contains($webhookSource, 'foreach ($credentials as $credential)'), 'Webhook debe probar cada versión hasta encontrar firma y token válidos.');
test_ok(str_contains($webhookSource, 'hash_equals($expected, $v1)'), 'Webhook debe validar la firma antes de consultar el pago.');
test_ok(!str_contains($webhookSource, 'PaymentGatewayConfig::mercadoPago($db)'), 'Webhook no debe limitarse a la credencial activa.');
test_ok(!str_contains($webhookSource, "env('MERCADOPAGO_WEBHOOK_SECRET')"), 'Webhook no debe leer secretos directamente del entorno.');

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
