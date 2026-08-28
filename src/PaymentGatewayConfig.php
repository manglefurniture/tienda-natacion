<?php
declare(strict_types=1);

final class PaymentGatewayConfig
{
    private const PROVIDER_MERCADO_PAGO = 'MERCADO_PAGO';
    private const CIPHER = 'aes-256-gcm';

    public static function mercadoPago(PDO $db): array
    {
        return self::mercadoPagoInternal($db, false);
    }

    public static function mercadoPagoForCheckout(PDO $db): array
    {
        return self::mercadoPagoInternal($db, true);
    }

    public static function credentialById(PDO $db, int $credentialId): array
    {
        if ($credentialId <= 0) {
            throw new InvalidArgumentException('La versión de credenciales no es válida.');
        }

        $stmt = $db->prepare(
            'SELECT id, proveedor, ambiente, public_key, access_token_enc, webhook_secret_enc,
                    cuenta_id, cuenta_label, created_at
             FROM pasarelas_pago_credenciales
             WHERE id = ? AND proveedor = ? LIMIT 1'
        );
        $stmt->execute([$credentialId, self::PROVIDER_MERCADO_PAGO]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('No se encontró la versión de credenciales de Mercado Pago.');
        }

        return self::hydrateCredential($row, false, 'database-history');
    }

    public static function mercadoPagoCredentialCandidates(PDO $db): array
    {
        $fallback = self::mercadoPagoFromEnvironment();

        try {
            $configStmt = $db->prepare(
                'SELECT configurado, credencial_actual_id FROM pasarelas_pago_config
                 WHERE proveedor = ? LIMIT 1'
            );
            $configStmt->execute([self::PROVIDER_MERCADO_PAGO]);
            $config = $configStmt->fetch();
        } catch (PDOException $e) {
            if (self::isMissingTableError($e)) {
                return $fallback['access_token'] !== '' && $fallback['webhook_secret'] !== '' ? [$fallback] : [];
            }
            throw $e;
        }

        if (!$config || (int) ($config['configurado'] ?? 0) !== 1) {
            return $fallback['access_token'] !== '' && $fallback['webhook_secret'] !== '' ? [$fallback] : [];
        }

        $currentId = (int) ($config['credencial_actual_id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT id, proveedor, ambiente, public_key, access_token_enc, webhook_secret_enc,
                    cuenta_id, cuenta_label, created_at
             FROM pasarelas_pago_credenciales
             WHERE proveedor = ?
             ORDER BY (id = ?) DESC, id DESC'
        );
        $stmt->execute([self::PROVIDER_MERCADO_PAGO, $currentId]);

        $credentials = [];
        foreach ($stmt->fetchAll() as $row) {
            $credential = self::hydrateCredential(
                $row,
                (int) $row['id'] === $currentId,
                'database-history'
            );

            // Una versión histórica puede conservar solo el Access Token del
            // despliegue legacy. Sirve para reconciliar retornos por pedido,
            // pero sin Webhook Secret nunca debe participar en la validación
            // de firmas entrantes.
            if (trim((string) ($credential['access_token'] ?? '')) === ''
                || trim((string) ($credential['webhook_secret'] ?? '')) === '') {
                continue;
            }

            $credentials[] = $credential;
        }

        return $credentials;
    }

    public static function saveMercadoPago(PDO $db, array $input, string $actor): void
    {
        $environment = strtoupper(trim((string) ($input['environment'] ?? 'PRODUCTION')));
        if (!in_array($environment, ['TEST', 'PRODUCTION'], true)) {
            throw new InvalidArgumentException('El modo de credenciales de Mercado Pago no es válido.');
        }

        $publicKey = trim((string) ($input['public_key'] ?? ''));
        $postedAccessToken = trim((string) ($input['access_token'] ?? ''));
        $postedWebhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
        $accountId = trim((string) ($input['account_id'] ?? ''));
        $accountLabel = trim((string) ($input['account_label'] ?? ''));
        $active = !empty($input['active']) ? 1 : 0;
        $actor = mb_substr($actor, 0, 120, 'UTF-8');

        if (mb_strlen($publicKey, 'UTF-8') > 255) {
            throw new InvalidArgumentException('La Public Key es demasiado larga.');
        }
        if (mb_strlen($postedAccessToken, 'UTF-8') > 1000 || mb_strlen($postedWebhookSecret, 'UTF-8') > 1000) {
            throw new InvalidArgumentException('Una credencial es demasiado larga.');
        }
        if (mb_strlen($accountId, 'UTF-8') > 80 || mb_strlen($accountLabel, 'UTF-8') > 190) {
            throw new InvalidArgumentException('Los datos de la cuenta de Mercado Pago son demasiado largos.');
        }
        if ($postedAccessToken !== '' && $postedWebhookSecret === '') {
            throw new InvalidArgumentException('Al cambiar el Access Token, ingresa también el Webhook Secret de esa integración.');
        }

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $configStmt = $db->prepare(
                'SELECT proveedor, configurado, activo, credencial_actual_id
                 FROM pasarelas_pago_config WHERE proveedor = ? FOR UPDATE'
            );
            $configStmt->execute([self::PROVIDER_MERCADO_PAGO]);
            $config = $configStmt->fetch();
            if (!$config) {
                throw new RuntimeException('La migración de configuración de pagos no está aplicada.');
            }

            $fallback = self::mercadoPagoFromEnvironment();
            $currentCredentialId = (int) ($config['credencial_actual_id'] ?? 0);
            $current = null;

            if ((int) ($config['configurado'] ?? 0) !== 1
                && $currentCredentialId <= 0
                && $fallback['access_token'] !== '') {
                // Conserva el token legacy incluso si ese despliegue nunca tuvo
                // Webhook Secret. Los retornos de preferencias ya creadas aún
                // necesitan ese token para consultar Mercado Pago después del cutover.
                $currentCredentialId = self::insertLegacyCredential(
                    $db,
                    $fallback['environment'],
                    $fallback['public_key'],
                    $fallback['access_token'],
                    $fallback['webhook_secret'],
                    $actor !== '' ? $actor : 'legacy-env'
                );

                // Todo pedido previo o checkout que ya alcanzó a crearse con el .env
                // queda ligado a la versión legacy antes de activar una versión nueva.
                $assignLegacy = $db->prepare(
                    'UPDATE pedidos SET mp_credencial_id = ? WHERE mp_credencial_id IS NULL'
                );
                $assignLegacy->execute([$currentCredentialId]);
                $current = self::credentialById($db, $currentCredentialId);
            } elseif ($currentCredentialId > 0) {
                $current = self::credentialById($db, $currentCredentialId);
            }

            $currentAccessToken = trim((string) ($current['access_token'] ?? $fallback['access_token']));
            $currentWebhookSecret = trim((string) ($current['webhook_secret'] ?? $fallback['webhook_secret']));
            $effectiveAccessToken = $postedAccessToken !== '' ? $postedAccessToken : $currentAccessToken;
            $effectiveWebhookSecret = $postedWebhookSecret !== '' ? $postedWebhookSecret : $currentWebhookSecret;

            if ($active === 1 && $effectiveAccessToken === '') {
                throw new InvalidArgumentException('Agrega un Access Token antes de activar Mercado Pago.');
            }
            if ($active === 1 && $effectiveWebhookSecret === '') {
                throw new InvalidArgumentException('Agrega un Webhook Secret antes de activar Mercado Pago.');
            }

            $currentEnvironment = strtoupper(trim((string) ($current['environment'] ?? $fallback['environment'])));
            $currentPublicKey = trim((string) ($current['public_key'] ?? $fallback['public_key']));
            $credentialsChanged = $currentCredentialId <= 0
                || !self::sameSecret($currentAccessToken, $effectiveAccessToken)
                || !self::sameSecret($currentWebhookSecret, $effectiveWebhookSecret)
                || $currentEnvironment !== $environment
                || $currentPublicKey !== $publicKey;

            if ($credentialsChanged) {
                if ($effectiveAccessToken === '' || $effectiveWebhookSecret === '') {
                    throw new InvalidArgumentException('Completa Access Token y Webhook Secret para crear la nueva versión.');
                }

                $currentCredentialId = self::insertCredential(
                    $db,
                    $environment,
                    $publicKey,
                    $effectiveAccessToken,
                    $effectiveWebhookSecret,
                    $accountId !== '' ? $accountId : (string) ($current['account_id'] ?? ''),
                    $accountLabel !== '' ? $accountLabel : (string) ($current['account_label'] ?? ''),
                    $actor
                );
            }

            if ($active === 1 && $currentCredentialId <= 0) {
                throw new RuntimeException('No existe una versión válida de credenciales para activar Mercado Pago.');
            }

            $update = $db->prepare(
                'UPDATE pasarelas_pago_config
                 SET configurado = 1, activo = ?, credencial_actual_id = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE proveedor = ?'
            );
            $update->execute([
                $active,
                $currentCredentialId > 0 ? $currentCredentialId : null,
                $actor,
                self::PROVIDER_MERCADO_PAGO,
            ]);

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

    private static function mercadoPagoInternal(PDO $db, bool $lockForCheckout): array
    {
        $fallback = self::mercadoPagoFromEnvironment();
        $lockClause = $lockForCheckout && $db->inTransaction() ? ' LOCK IN SHARE MODE' : '';

        try {
            $stmt = $db->prepare(
                'SELECT c.proveedor, c.configurado, c.activo, c.credencial_actual_id, c.updated_at,
                        v.id, v.ambiente, v.public_key, v.access_token_enc, v.webhook_secret_enc,
                        v.cuenta_id, v.cuenta_label, v.created_at
                 FROM pasarelas_pago_config c
                 LEFT JOIN pasarelas_pago_credenciales v ON v.id = c.credencial_actual_id
                 WHERE c.proveedor = ? LIMIT 1' . $lockClause
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

        if ((int) ($row['credencial_actual_id'] ?? 0) <= 0 || empty($row['id'])) {
            return [
                'provider' => self::PROVIDER_MERCADO_PAGO,
                'credential_id' => null,
                'active' => false,
                'environment' => 'PRODUCTION',
                'public_key' => '',
                'access_token' => '',
                'webhook_secret' => '',
                'configured_access_token' => false,
                'configured_webhook_secret' => false,
                'source' => 'database',
                'updated_at' => $row['updated_at'] ?? null,
                'account_id' => '',
                'account_label' => '',
            ];
        }

        $credential = self::hydrateCredential($row, true, 'database');
        $credential['active'] = (int) $row['activo'] === 1;
        $credential['updated_at'] = $row['updated_at'] ?? null;
        return $credential;
    }

    private static function hydrateCredential(array $row, bool $active, string $source): array
    {
        $accessToken = self::decryptOptional((string) ($row['access_token_enc'] ?? ''));
        $webhookSecret = self::decryptOptional((string) ($row['webhook_secret_enc'] ?? ''));

        return [
            'provider' => self::PROVIDER_MERCADO_PAGO,
            'credential_id' => (int) ($row['id'] ?? 0) ?: null,
            'active' => $active,
            'environment' => in_array((string) ($row['ambiente'] ?? ''), ['TEST', 'PRODUCTION'], true)
                ? (string) $row['ambiente']
                : 'PRODUCTION',
            'public_key' => trim((string) ($row['public_key'] ?? '')),
            'access_token' => $accessToken,
            'webhook_secret' => $webhookSecret,
            'configured_access_token' => $accessToken !== '',
            'configured_webhook_secret' => $webhookSecret !== '',
            'source' => $source,
            'updated_at' => $row['created_at'] ?? null,
            'account_id' => trim((string) ($row['cuenta_id'] ?? '')),
            'account_label' => trim((string) ($row['cuenta_label'] ?? '')),
        ];
    }

    private static function insertLegacyCredential(
        PDO $db,
        string $environment,
        string $publicKey,
        string $accessToken,
        string $webhookSecret,
        string $actor
    ): int {
        if ($accessToken === '') {
            throw new InvalidArgumentException('La versión legacy requiere un Access Token.');
        }

        $stmt = $db->prepare(
            'INSERT INTO pasarelas_pago_credenciales
             (proveedor, ambiente, public_key, access_token_enc, webhook_secret_enc,
              cuenta_id, cuenta_label, created_by)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
        );
        $stmt->execute([
            self::PROVIDER_MERCADO_PAGO,
            $environment,
            $publicKey !== '' ? $publicKey : null,
            self::encrypt($accessToken),
            $webhookSecret !== '' ? self::encrypt($webhookSecret) : null,
            'Credenciales legacy (.env)',
            $actor !== '' ? mb_substr($actor, 0, 120, 'UTF-8') : null,
        ]);

        return (int) $db->lastInsertId();
    }

    private static function insertCredential(
        PDO $db,
        string $environment,
        string $publicKey,
        string $accessToken,
        string $webhookSecret,
        string $accountId,
        string $accountLabel,
        string $actor
    ): int {
        if ($accessToken === '' || $webhookSecret === '') {
            throw new InvalidArgumentException('Las versiones activas de Mercado Pago requieren Access Token y Webhook Secret.');
        }

        $stmt = $db->prepare(
            'INSERT INTO pasarelas_pago_credenciales
             (proveedor, ambiente, public_key, access_token_enc, webhook_secret_enc,
              cuenta_id, cuenta_label, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::PROVIDER_MERCADO_PAGO,
            $environment,
            $publicKey !== '' ? $publicKey : null,
            self::encrypt($accessToken),
            self::encrypt($webhookSecret),
            $accountId !== '' ? mb_substr($accountId, 0, 80, 'UTF-8') : null,
            $accountLabel !== '' ? mb_substr($accountLabel, 0, 190, 'UTF-8') : null,
            $actor !== '' ? mb_substr($actor, 0, 120, 'UTF-8') : null,
        ]);

        return (int) $db->lastInsertId();
    }

    private static function mercadoPagoFromEnvironment(): array
    {
        $accessToken = trim((string) env('MERCADOPAGO_ACCESS_TOKEN'));
        $webhookSecret = trim((string) env('MERCADOPAGO_WEBHOOK_SECRET'));

        return [
            'provider' => self::PROVIDER_MERCADO_PAGO,
            'credential_id' => null,
            'active' => $accessToken !== '',
            'environment' => 'PRODUCTION',
            'public_key' => trim((string) env('MERCADOPAGO_PUBLIC_KEY')),
            'access_token' => $accessToken,
            'webhook_secret' => $webhookSecret,
            'configured_access_token' => $accessToken !== '',
            'configured_webhook_secret' => $webhookSecret !== '',
            'source' => 'environment',
            'updated_at' => null,
            'account_id' => '',
            'account_label' => '',
        ];
    }

    private static function sameSecret(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return $left === $right;
        }

        return hash_equals($left, $right);
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

            $plainText = openssl_decrypt(
                $data,
                self::CIPHER,
                self::encryptionKey(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
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
