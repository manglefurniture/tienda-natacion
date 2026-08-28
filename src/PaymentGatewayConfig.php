<?php
declare(strict_types=1);

final class PaymentGatewayConfig
{
    private const PROVIDER_MERCADO_PAGO = 'MERCADO_PAGO';
    private static array $credentialRefSupport = [];

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

        $credentialRef = self::credentialRefProjection($db);
        $stmt = $db->prepare(
            'SELECT id, proveedor, ' . $credentialRef . ', ambiente, public_key,
                    access_token_enc, webhook_secret_enc, cuenta_id, cuenta_label, created_at
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
        $credentialRef = self::credentialRefProjection($db);
        $stmt = $db->prepare(
            'SELECT id, proveedor, ' . $credentialRef . ', ambiente, public_key,
                    access_token_enc, webhook_secret_enc, cuenta_id, cuenta_label, created_at
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
                $currentCredentialId = self::insertLegacyCredential(
                    $db,
                    $fallback['environment'],
                    $fallback['public_key'],
                    $fallback['access_token'],
                    $fallback['webhook_secret'],
                    $actor !== '' ? $actor : 'legacy-env'
                );

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
        $credentialRef = self::hasCredentialRefColumn($db)
            ? 'v.credential_ref AS credential_ref'
            : 'NULL AS credential_ref';

        try {
            $stmt = $db->prepare(
                'SELECT c.proveedor, c.configurado, c.activo, c.credencial_actual_id, c.updated_at,
                        v.id, ' . $credentialRef . ', v.ambiente, v.public_key,
                        v.access_token_enc, v.webhook_secret_enc,
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
                'credential_ref' => null,
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
        $provider = trim((string) ($row['proveedor'] ?? self::PROVIDER_MERCADO_PAGO));
        $credentialRef = trim((string) ($row['credential_ref'] ?? ''));

        $accessToken = self::decryptStored(
            (string) ($row['access_token_enc'] ?? ''),
            $provider,
            $credentialRef,
            'access_token',
            false
        );
        $webhookSecret = self::decryptStored(
            (string) ($row['webhook_secret_enc'] ?? ''),
            $provider,
            $credentialRef,
            'webhook_secret',
            true
        );

        return [
            'provider' => $provider,
            'credential_id' => (int) ($row['id'] ?? 0) ?: null,
            'credential_ref' => $credentialRef !== '' ? $credentialRef : null,
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

        self::requireCredentialRefColumn($db);
        $credentialRef = self::newCredentialRef();
        $cipher = self::cipher();

        $stmt = $db->prepare(
            'INSERT INTO pasarelas_pago_credenciales
             (proveedor, credential_ref, ambiente, public_key, access_token_enc, webhook_secret_enc,
              cuenta_id, cuenta_label, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?)'
        );
        $stmt->execute([
            self::PROVIDER_MERCADO_PAGO,
            $credentialRef,
            $environment,
            $publicKey !== '' ? $publicKey : null,
            $cipher->encrypt($accessToken, self::PROVIDER_MERCADO_PAGO, $credentialRef, 'access_token'),
            $webhookSecret !== ''
                ? $cipher->encrypt($webhookSecret, self::PROVIDER_MERCADO_PAGO, $credentialRef, 'webhook_secret')
                : null,
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

        self::requireCredentialRefColumn($db);
        $credentialRef = self::newCredentialRef();
        $cipher = self::cipher();

        $stmt = $db->prepare(
            'INSERT INTO pasarelas_pago_credenciales
             (proveedor, credential_ref, ambiente, public_key, access_token_enc, webhook_secret_enc,
              cuenta_id, cuenta_label, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            self::PROVIDER_MERCADO_PAGO,
            $credentialRef,
            $environment,
            $publicKey !== '' ? $publicKey : null,
            $cipher->encrypt($accessToken, self::PROVIDER_MERCADO_PAGO, $credentialRef, 'access_token'),
            $cipher->encrypt($webhookSecret, self::PROVIDER_MERCADO_PAGO, $credentialRef, 'webhook_secret'),
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
            'credential_ref' => null,
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

    private static function decryptStored(
        string $payload,
        string $provider,
        string $credentialRef,
        string $purpose,
        bool $optional
    ): string {
        if (trim($payload) === '') {
            if ($optional) {
                return '';
            }
            throw new RuntimeException('La credencial cifrada obligatoria está vacía.');
        }

        $cipher = self::cipher();
        if ($credentialRef === '') {
            return $cipher->decryptLegacyV1($payload);
        }

        return $cipher->decrypt($payload, $provider, $credentialRef, $purpose);
    }

    private static function cipher(): PaymentCredentialCipher
    {
        return new PaymentCredentialCipher(self::encryptionSecret());
    }

    private static function encryptionSecret(): string
    {
        $secret = trim((string) env('PAYMENT_GATEWAY_CONFIG_KEY'));
        if ($secret === '') {
            throw new RuntimeException('PAYMENT_GATEWAY_CONFIG_KEY no está configurada en el servidor.');
        }

        return $secret;
    }

    private static function sameSecret(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return $left === $right;
        }

        return hash_equals($left, $right);
    }

    private static function hasCredentialRefColumn(PDO $db): bool
    {
        $key = spl_object_id($db);
        if (array_key_exists($key, self::$credentialRefSupport)) {
            return self::$credentialRefSupport[$key];
        }

        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'pasarelas_pago_credenciales'
                   AND COLUMN_NAME = 'credential_ref'"
            );
            $stmt->execute();
            self::$credentialRefSupport[$key] = (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            self::$credentialRefSupport[$key] = false;
        }

        return self::$credentialRefSupport[$key];
    }

    private static function credentialRefProjection(PDO $db): string
    {
        return self::hasCredentialRefColumn($db)
            ? 'credential_ref'
            : 'NULL AS credential_ref';
    }

    private static function requireCredentialRefColumn(PDO $db): void
    {
        if (!self::hasCredentialRefColumn($db)) {
            throw new RuntimeException(
                'Falta la migración 006 de credenciales de pago. Aplícala antes de guardar una versión nueva.'
            );
        }
    }

    private static function newCredentialRef(): string
    {
        return 'cred_' . bin2hex(random_bytes(16));
    }

    private static function isMissingTableError(PDOException $e): bool
    {
        return (string) $e->getCode() === '42S02';
    }
}
