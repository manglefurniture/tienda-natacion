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

require_once $root . '/src/PaymentCredentialCipher.php';
require_once $root . '/src/PaymentGatewayConfig.php';

$configSource = test_source('src/PaymentGatewayConfig.php');
$cipherSource = test_source('src/PaymentCredentialCipher.php');
$migratorSource = test_source('src/PaymentGatewayCredentialMigrator.php');
$panelSource = test_source('public/admin/pasarelas.php');
$checkoutSource = test_source('public/api/checkout.php');
$returnSource = test_source('public/checkout/resultado.php');
$webhookSource = test_source('public/webhooks/mercadopago.php');
$migrationSource = test_source('database/006_payment_gateway_credentials_hardening.sql');

test_ok(str_contains($cipherSource, "private const CIPHER = 'aes-256-gcm'"), 'El cifrado debe seguir usando AES-256-GCM.');
test_ok(str_contains($cipherSource, 'private const GCM_TAG_LENGTH = 16'), 'AES-GCM debe exigir tag completo de 16 bytes.');
test_ok(str_contains($cipherSource, 'hache-payment-credential-aad-v1'), 'El sobre v2 debe usar AAD contextual.');
test_ok(str_contains($cipherSource, "'credential_ref' => \$credentialRef"), 'El AAD debe ligar la referencia inmutable.');
test_ok(str_contains($cipherSource, "'purpose' => \$purpose"), 'El AAD debe ligar el propósito del secreto.');
test_ok(str_contains($configSource, 'PAYMENT_GATEWAY_CONFIG_KEY'), 'La clave maestra debe permanecer fuera de la base de datos.');
test_ok(str_contains($configSource, 'mercadoPagoCredentialCandidates'), 'Webhook debe poder resolver versiones históricas.');
test_ok(str_contains($configSource, 'credentialById'), 'Debe poder recuperarse una versión concreta por pedido.');
test_ok(str_contains($configSource, 'LOCK IN SHARE MODE'), 'Checkout debe serializar la lectura inicial contra cambios concurrentes.');
test_ok(str_contains($configSource, 'FOR UPDATE'), 'El cambio de configuración debe bloquear la fila actual durante la transición.');
test_ok(str_contains($configSource, 'credential_ref'), 'Las nuevas versiones deben incluir credential_ref.');
test_ok(str_contains($configSource, 'decryptLegacyV1'), 'Solo debe conservarse lectura transitoria de sobres legacy v1.');

test_ok(str_contains($migratorSource, 'uq_pasarela_credenciales_proveedor_ref'), 'La migración debe exigir referencia única por proveedor.');
test_ok(str_contains($migratorSource, 'FOREIGN KEY (proveedor, credencial_actual_id)'), 'La configuración debe usar FK compuesta por proveedor.');
test_ok(str_contains($migratorSource, 'ON UPDATE RESTRICT ON DELETE RESTRICT'), 'La FK de configuración debe impedir renombrados en cascada.');
test_ok(str_contains($migratorSource, 'BEFORE UPDATE ON pasarelas_pago_credenciales'), 'Todas las versiones históricas deben ser inmutables en MariaDB.');
test_ok(str_contains($migrationSource, 'ADD COLUMN IF NOT EXISTS credential_ref'), 'La migración 006 debe preparar credential_ref sin romper filas v1.');

test_ok(str_contains($panelSource, '(new MercadoPago($newAccessToken))->getCurrentUser()'), 'Un Access Token nuevo debe validarse con Mercado Pago antes de guardarse.');
test_ok(!preg_match('/value\s*=\s*["\'][^"\']*<\?=.*(?:access_token|webhook_secret)/i', $panelSource), 'El panel no debe renderizar secretos almacenados.');

test_ok(str_contains($checkoutSource, 'PaymentGatewayConfig::mercadoPagoForCheckout($db)'), 'Checkout debe adquirir la configuración con lock compartido.');
test_ok(str_contains($checkoutSource, 'mp_credencial_id'), 'Checkout debe persistir la versión usada en el pedido.');
test_ok(str_contains($checkoutSource, "'sandbox_init_point' : 'init_point'"), 'TEST debe redirigir al sandbox y producción al checkout real.');
test_ok(!str_contains($checkoutSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'Checkout no debe leer credenciales directamente del entorno.');

test_ok(str_contains($returnSource, 'PaymentGatewayConfig::credentialById($db, $credentialId)'), 'El retorno debe usar la versión ligada al pedido.');
test_ok(!str_contains($returnSource, "env('MERCADOPAGO_ACCESS_TOKEN')"), 'El retorno no debe depender del Access Token legacy.');

test_ok(str_contains($webhookSource, 'PaymentGatewayConfig::mercadoPagoCredentialCandidates($db)'), 'Webhook debe evaluar versiones históricas completas.');
test_ok(str_contains($webhookSource, 'hash_equals($expected, $v1)'), 'Webhook debe validar la firma antes de consultar el pago.');
test_ok(!str_contains($webhookSource, 'PaymentGatewayConfig::mercadoPago($db)'), 'Webhook no debe limitarse a la credencial activa.');

$cipher = new PaymentCredentialCipher($_ENV['PAYMENT_GATEWAY_CONFIG_KEY']);
$plain = 'TEST_SECRET_' . bin2hex(random_bytes(8));
$provider = 'MERCADO_PAGO';
$credentialRef = 'cred_' . bin2hex(random_bytes(16));
$purpose = 'access_token';

$encrypted = $cipher->encrypt($plain, $provider, $credentialRef, $purpose);
test_ok($cipher->envelopeVersion($encrypted) === 2, 'Las nuevas credenciales deben escribirse como sobre v2.');
test_ok($cipher->decrypt($encrypted, $provider, $credentialRef, $purpose) === $plain, 'El sobre v2 debe hacer round-trip.');

$emptyRejected = false;
try {
    $cipher->decrypt('   ', $provider, $credentialRef, $purpose);
} catch (Throwable) {
    $emptyRejected = true;
}
test_ok($emptyRejected, 'Un payload cifrado vacío debe rechazarse.');

foreach ([
    ['OTHER_PROVIDER', $credentialRef, $purpose],
    [$provider, 'cred_other', $purpose],
    [$provider, $credentialRef, 'webhook_secret'],
] as [$badProvider, $badRef, $badPurpose]) {
    $rejected = false;
    try {
        $cipher->decrypt($encrypted, $badProvider, $badRef, $badPurpose);
    } catch (Throwable) {
        $rejected = true;
    }
    test_ok($rejected, 'El AAD debe rechazar proveedor, versión o propósito incorrectos.');
}

$envelope = json_decode((string) base64_decode($encrypted, true), true, 512, JSON_THROW_ON_ERROR);
$tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
test_ok(is_string($tag) && strlen($tag) === 16, 'El tag GCM generado debe tener 16 bytes.');
$envelope['tag'] = base64_encode(substr($tag, 0, 1));
$truncated = base64_encode(json_encode($envelope, JSON_THROW_ON_ERROR));

$truncatedRejected = false;
try {
    $cipher->decrypt($truncated, $provider, $credentialRef, $purpose);
} catch (Throwable) {
    $truncatedRejected = true;
}
test_ok($truncatedRejected, 'Un tag GCM truncado debe rechazarse.');

$legacyIvLength = openssl_cipher_iv_length('aes-256-gcm');
test_ok(is_int($legacyIvLength) && $legacyIvLength > 0, 'OpenSSL debe exponer IV válido.');
$legacyIv = random_bytes($legacyIvLength);
$legacyTag = '';
$legacyData = openssl_encrypt(
    $plain,
    'aes-256-gcm',
    hash('sha256', $_ENV['PAYMENT_GATEWAY_CONFIG_KEY'], true),
    OPENSSL_RAW_DATA,
    $legacyIv,
    $legacyTag
);
test_ok(is_string($legacyData), 'Debe poder construirse fixture legacy.');
$legacy = base64_encode(json_encode([
    'v' => 1,
    'iv' => base64_encode($legacyIv),
    'tag' => base64_encode($legacyTag),
    'data' => base64_encode($legacyData),
], JSON_THROW_ON_ERROR));
test_ok($cipher->decryptLegacyV1($legacy) === $plain, 'La migración debe poder leer sobres v1 existentes.');

echo "Payment gateway regressions: OK\n";
