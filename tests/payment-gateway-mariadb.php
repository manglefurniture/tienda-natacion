<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

function db_fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function db_ok(bool $condition, string $message): void
{
    if (!$condition) db_fail($message);
}

$key = trim((string) env('PAYMENT_GATEWAY_CONFIG_KEY'));
db_ok($key !== '', 'PAYMENT_GATEWAY_CONFIG_KEY debe existir en CI.');

$db = Database::connection();
$cipher = new PaymentCredentialCipher($key);

$legacyPlainToken = 'legacy-access-' . bin2hex(random_bytes(6));
$legacyPlainWebhook = 'legacy-webhook-' . bin2hex(random_bytes(6));

$makeLegacy = static function (string $plainText) use ($key): string {
    $ivLength = openssl_cipher_iv_length('aes-256-gcm');
    if (!is_int($ivLength) || $ivLength <= 0) {
        throw new RuntimeException('IV inválido.');
    }
    $iv = random_bytes($ivLength);
    $tag = '';
    $data = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        hash('sha256', $key, true),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if (!is_string($data) || strlen($tag) !== 16) {
        throw new RuntimeException('No se pudo construir fixture v1.');
    }

    return base64_encode(json_encode([
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($data),
    ], JSON_THROW_ON_ERROR));
};

$insert = $db->prepare(
    "INSERT INTO pasarelas_pago_credenciales
     (proveedor, credential_ref, ambiente, public_key, access_token_enc, webhook_secret_enc, cuenta_label, created_by)
     VALUES ('MERCADO_PAGO', NULL, 'PRODUCTION', NULL, ?, ?, 'fixture legacy', 'ci')"
);
$insert->execute([$makeLegacy($legacyPlainToken), $makeLegacy($legacyPlainWebhook)]);
$legacyId = (int) $db->lastInsertId();

$bind = $db->prepare(
    "UPDATE pasarelas_pago_config
     SET configurado = 1, activo = 1, credencial_actual_id = ?
     WHERE proveedor = 'MERCADO_PAGO'"
);
$bind->execute([$legacyId]);

$result = PaymentGatewayCredentialMigrator::migrate($db, $cipher);
db_ok((int) $result['rows_migrated'] >= 1, 'La fila legacy debe migrarse a v2.');

$stmt = $db->prepare(
    'SELECT proveedor, credential_ref, access_token_enc, webhook_secret_enc
     FROM pasarelas_pago_credenciales WHERE id = ?'
);
$stmt->execute([$legacyId]);
$row = $stmt->fetch();
db_ok(is_array($row), 'La versión migrada debe seguir existiendo.');

$ref = trim((string) ($row['credential_ref'] ?? ''));
db_ok($ref !== '', 'La versión migrada debe tener credential_ref.');
db_ok($cipher->envelopeVersion((string) $row['access_token_enc']) === 2, 'Access Token debe quedar en v2.');
db_ok($cipher->envelopeVersion((string) $row['webhook_secret_enc']) === 2, 'Webhook Secret debe quedar en v2.');
db_ok(
    $cipher->decrypt((string) $row['access_token_enc'], 'MERCADO_PAGO', $ref, 'access_token') === $legacyPlainToken,
    'El Access Token migrado debe conservar su valor.'
);
db_ok(
    $cipher->decrypt((string) $row['webhook_secret_enc'], 'MERCADO_PAGO', $ref, 'webhook_secret') === $legacyPlainWebhook,
    'El Webhook Secret migrado debe conservar su valor.'
);

$immutableRejected = false;
try {
    $db->exec(
        "UPDATE pasarelas_pago_credenciales
         SET cuenta_label = 'mutated'
         WHERE id = " . $legacyId
    );
} catch (PDOException) {
    $immutableRejected = true;
}
db_ok($immutableRejected, 'MariaDB debe impedir UPDATE de una versión histórica.');

$otherRef = 'cred_' . bin2hex(random_bytes(16));
$otherInsert = $db->prepare(
    "INSERT INTO pasarelas_pago_credenciales
     (proveedor, credential_ref, ambiente, public_key, access_token_enc, webhook_secret_enc, cuenta_label, created_by)
     VALUES ('OTRO_PROVEEDOR', ?, 'PRODUCTION', NULL, ?, ?, 'other', 'ci')"
);
$otherInsert->execute([
    $otherRef,
    $cipher->encrypt('other-access', 'OTRO_PROVEEDOR', $otherRef, 'access_token'),
    $cipher->encrypt('other-webhook', 'OTRO_PROVEEDOR', $otherRef, 'webhook_secret'),
]);
$otherId = (int) $db->lastInsertId();

$crossProviderRejected = false;
try {
    $update = $db->prepare(
        "UPDATE pasarelas_pago_config
         SET credencial_actual_id = ?
         WHERE proveedor = 'MERCADO_PAGO'"
    );
    $update->execute([$otherId]);
} catch (PDOException) {
    $crossProviderRejected = true;
}
db_ok($crossProviderRejected, 'La FK compuesta debe impedir cruzar proveedores.');

$second = PaymentGatewayCredentialMigrator::migrate($db, $cipher);
db_ok((int) $second['rows_migrated'] === 0, 'La migración debe ser idempotente tras quedar en v2.');

echo "Payment gateway MariaDB regressions: OK\n";
