<?php
declare(strict_types=1);

final class PaymentGatewayConfig
{
    private const PROVIDER_MERCADO_PAGO = 'MERCADO_PAGO';
    private const CIPHER = 'aes-256-gcm';

    public static function mercadoPago(PDO $db): array
    {
        $fallback = self::mercadoPagoFromEnvironment();

        try {
            $stmt = $db->prepare(
                'SELECT proveedor, configurado, activo, ambiente, public_key, access_token_enc, webhook_secret_enc, updated_at
                 FROM pasarelas_pago_config WHERE proveedor = ? LIMIT 1'
            );
            $stmt->execute([self::PROVIDER_MERCADO_PAGO]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            if (self::isMissingTableError($e)) {
                return $fallback;
            }
            throw $e;
        }

        if (!$row || (int) ($row['configurado'] ?? 0) !== 1) {
            return $fallback;
        }

        $accessToken = self::decryptOptional((string) ($row['access_token_enc'] ?? ''));
        $webhookSecret = self::decryptOptional((string) ($row['webhook_secret_enc'] ?? ''));

        return [
            'provider' => self::PROVIDER_MERCADO_PAGO,
            'active' => (int) $row['activo'] === 1,
            'environment' => in_array((string) $row['ambiente'], ['TEST', 'PRODUCTION'], true)
                ? (string) $row['ambiente']
                : 'PRODUCTION',
            'public_key' => trim((string) ($row['public_key'] ?? '')),
            'access_token' => $accessToken,
            'webhook_secret' => $webhookSecret,
            'configured_access_token' => $accessToken !== '',
            'configured_webhook_secret' => $webhookSecret !== '',
            'source' => 'database',
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public static function saveMercadoPago(PDO $db, array $input, string $actor): void
    {
        $environment = strtoupper(trim((string) ($input['environment'] ?? 'PRODUCTION')));
        if (!in_array($environment, ['TEST', 'PRODUCTION'], true)) {
            throw new InvalidArgumentException('El modo de credenciales de Mercado Pago no es válido.');
        }

        $publicKey = trim((string) ($input['public_key'] ?? ''));
        $accessToken = trim((string) ($input['access_token'] ?? ''));
        $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
        $active = !empty($input['active']) ? 1 : 0;

        if (mb_strlen($publicKey, 'UTF-8') > 255) {
            throw new InvalidArgumentException('La Public Key es demasiado larga.');
        }
        if (mb_strlen($accessToken, 'UTF-8') > 1000 || mb_strlen($webhookSecret, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Una credencial es demasiado larga.');
        }

        $existing = self::mercadoPagoRow($db);
        $encryptedAccessToken = $existing['access_token_enc'] ?? null;
        $encryptedWebhookSecret = $existing['webhook_secret_enc'] ?? null;

        if ($accessToken !== '' && $webhookSecret === '') {
            throw new InvalidArgumentException('Al cambiar el Access Token, ingresa también el Webhook Secret de esa integración.');
        }

        if ($accessToken !== '') {
            $encryptedAccessToken = self::encrypt($accessToken);
        }
        if ($webhookSecret !== '') {
            $encryptedWebhookSecret = self::encrypt($webhookSecret);
        }

        if ($active === 1 && trim((string) $encryptedAccessToken) === '') {
            throw new InvalidArgumentException('Agrega un Access Token antes de activar Mercado Pago.');
        }
        if ($active === 1 && trim((string) $encryptedWebhookSecret) === '') {
            throw new InvalidArgumentException('Agrega un Webhook Secret antes de activar Mercado Pago.');
        }

        $stmt = $db->prepare(
            'INSERT INTO pasarelas_pago_config
             (proveedor, configurado, activo, ambiente, public_key, access_token_enc, webhook_secret_enc, updated_by)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               configurado = 1, activo = VALUES(activo), ambiente = VALUES(ambiente), public_key = VALUES(public_key),
               access_token_enc = VALUES(access_token_enc), webhook_secret_enc = VALUES(webhook_secret_enc),
               updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            self::PROVIDER_MERCADO_PAGO,
            $active,
            $environment,
            $publicKey !== '' ? $publicKey : null,
            $encryptedAccessToken,
            $encryptedWebhookSecret,
            mb_substr($actor, 0, 120, 'UTF-8'),
        ]);
    }

    public static function clearMercadoPagoSecret(PDO $db, string $secret): void
    {
        $column = match ($secret) {
            'access_token' => 'access_token_enc',
            'webhook_secret' => 'webhook_secret_enc',
            default => throw new InvalidArgumentException('Secreto no válido.'),
        };
        $db->exec("UPDATE pasarelas_pago_config SET {$column} = NULL, updated_at = CURRENT_TIMESTAMP WHERE proveedor = 'MERCADO_PAGO'");
    }

    private static function mercadoPagoFromEnvironment(): array
    {
        $accessToken = trim((string) env('MERCADOPAGO_ACCESS_TOKEN'));
        $webhookSecret = trim((string) env('MERCADOPAGO_WEBHOOK_SECRET'));
        return [
            'provider' => self::PROVIDER_MERCADO_PAGO,
            'active' => $accessToken !== '',
            'environment' => 'PRODUCTION',
            'public_key' => trim((string) env('MERCADOPAGO_PUBLIC_KEY')),
            'access_token' => $accessToken,
            'webhook_secret' => $webhookSecret,
            'configured_access_token' => $accessToken !== '',
            'configured_webhook_secret' => $webhookSecret !== '',
            'source' => 'environment',
            'updated_at' => null,
        ];
    }

    private static function mercadoPagoRow(PDO $db): array
    {
        $stmt = $db->prepare('SELECT access_token_enc, webhook_secret_enc FROM pasarelas_pago_config WHERE proveedor = ? LIMIT 1');
        $stmt->execute([self::PROVIDER_MERCADO_PAGO]);
        return $stmt->fetch() ?: [];
    }

    private static function encrypt(string $plainText): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL es obligatorio para guardar credenciales de pago.');
        }
        $key = self::encryptionKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (!is_int($ivLength) || $ivLength <= 0) {
            throw new RuntimeException('No se pudo inicializar el cifrado de credenciales.');
        }
        $iv = random_bytes($ivLength);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('No se pudo cifrar la credencial.');
        }

        return base64_encode(json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipherText),
        ], JSON_THROW_ON_ERROR));
    }

    private static function decryptOptional(string $payload): string
    {
        if (trim($payload) === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL es obligatorio para leer credenciales de pago.');
        }

        try {
            $decoded = json_decode(base64_decode($payload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || (int) ($decoded['v'] ?? 0) !== 1) {
                throw new RuntimeException('Formato de credencial cifrada no válido.');
            }
            $iv = base64_decode((string) ($decoded['iv'] ?? ''), true);
            $tag = base64_decode((string) ($decoded['tag'] ?? ''), true);
            $data = base64_decode((string) ($decoded['data'] ?? ''), true);
            if ($iv === false || $tag === false || $data === false) {
                throw new RuntimeException('Credencial cifrada dañada.');
            }
            $plainText = openssl_decrypt($data, self::CIPHER, self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
            if ($plainText === false) {
                throw new RuntimeException('No se pudo descifrar la credencial.');
            }
            return $plainText;
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer la credencial cifrada.', 0, $e);
        }
    }

    private static function encryptionKey(): string
    {
        $secret = trim((string) env('PAYMENT_GATEWAY_CONFIG_KEY'));
        if ($secret === '') {
            throw new RuntimeException('PAYMENT_GATEWAY_CONFIG_KEY no está configurada en el servidor.');
        }
        return hash('sha256', $secret, true);
    }

    private static function isMissingTableError(PDOException $e): bool
    {
        return (string) $e->getCode() === '42S02';
    }
}
