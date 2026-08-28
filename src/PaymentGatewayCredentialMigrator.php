<?php
declare(strict_types=1);

final class PaymentGatewayCredentialMigrator
{
    private const TABLE = 'pasarelas_pago_credenciales';
    private const CONFIG_TABLE = 'pasarelas_pago_config';
    private const IMMUTABLE_TRIGGER = 'trg_pasarelas_pago_credenciales_immutable';

    public static function migrate(PDO $db, PaymentCredentialCipher $cipher): array
    {
        self::ensureCredentialRefColumn($db);

        $rows = $db->query(
            'SELECT id, proveedor, credential_ref, access_token_enc, webhook_secret_enc
             FROM pasarelas_pago_credenciales
             ORDER BY id'
        )->fetchAll();

        $needsRowMigration = false;
        foreach ($rows as $row) {
            $ref = trim((string) ($row['credential_ref'] ?? ''));
            $accessPayload = (string) ($row['access_token_enc'] ?? '');
            if ($ref === '' || $cipher->envelopeVersion($accessPayload) !== 2) {
                $needsRowMigration = true;
                break;
            }

            $webhookPayload = trim((string) ($row['webhook_secret_enc'] ?? ''));
            if ($webhookPayload !== '' && $cipher->envelopeVersion($webhookPayload) !== 2) {
                $needsRowMigration = true;
                break;
            }
        }

        if ($needsRowMigration && self::triggerExists($db, self::IMMUTABLE_TRIGGER)) {
            $db->exec('DROP TRIGGER ' . self::IMMUTABLE_TRIGGER);
        }

        $migrated = 0;
        if ($needsRowMigration) {
            $db->beginTransaction();
            try {
                $update = $db->prepare(
                    'UPDATE pasarelas_pago_credenciales
                     SET credential_ref = ?, access_token_enc = ?, webhook_secret_enc = ?
                     WHERE id = ?'
                );

                foreach ($rows as $row) {
                    $provider = trim((string) ($row['proveedor'] ?? ''));
                    if ($provider === '') {
                        throw new RuntimeException('Existe una versión de credenciales sin proveedor.');
                    }

                    $credentialRef = trim((string) ($row['credential_ref'] ?? ''));
                    if ($credentialRef === '') {
                        $credentialRef = self::newCredentialRef();
                    }

                    $accessPayload = (string) ($row['access_token_enc'] ?? '');
                    $accessToken = self::decryptForMigration(
                        $cipher,
                        $accessPayload,
                        $provider,
                        $credentialRef,
                        'access_token'
                    );

                    $webhookPayload = trim((string) ($row['webhook_secret_enc'] ?? ''));
                    $webhookSecret = $webhookPayload === ''
                        ? null
                        : self::decryptForMigration(
                            $cipher,
                            $webhookPayload,
                            $provider,
                            $credentialRef,
                            'webhook_secret'
                        );

                    $accessV2 = $cipher->encrypt(
                        $accessToken,
                        $provider,
                        $credentialRef,
                        'access_token'
                    );
                    $webhookV2 = $webhookSecret === null
                        ? null
                        : $cipher->encrypt(
                            $webhookSecret,
                            $provider,
                            $credentialRef,
                            'webhook_secret'
                        );

                    $alreadyV2 = trim((string) ($row['credential_ref'] ?? '')) === $credentialRef
                        && $cipher->envelopeVersion($accessPayload) === 2
                        && ($webhookPayload === '' || $cipher->envelopeVersion($webhookPayload) === 2);

                    if (!$alreadyV2) {
                        $update->execute([
                            $credentialRef,
                            $accessV2,
                            $webhookV2,
                            (int) $row['id'],
                        ]);
                        $migrated++;
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
        }

        self::assertProviderConsistency($db);
        self::ensureIndex(
            $db,
            self::TABLE,
            'uq_pasarela_credenciales_proveedor_id',
            'UNIQUE',
            '(proveedor, id)'
        );
        self::ensureIndex(
            $db,
            self::TABLE,
            'uq_pasarela_credenciales_proveedor_ref',
            'UNIQUE',
            '(proveedor, credential_ref)'
        );

        $db->exec(
            'ALTER TABLE pasarelas_pago_credenciales
             MODIFY credential_ref VARCHAR(64) NOT NULL'
        );

        self::ensureIndex(
            $db,
            self::CONFIG_TABLE,
            'idx_pasarela_config_actual',
            '',
            '(proveedor, credencial_actual_id)'
        );

        self::replaceConfigForeignKey($db);
        self::ensureImmutableTrigger($db);

        return [
            'rows_seen' => count($rows),
            'rows_migrated' => $migrated,
        ];
    }

    private static function decryptForMigration(
        PaymentCredentialCipher $cipher,
        string $payload,
        string $provider,
        string $credentialRef,
        string $purpose
    ): string {
        $version = $cipher->envelopeVersion($payload);
        return match ($version) {
            1 => $cipher->decryptLegacyV1($payload),
            2 => $cipher->decrypt($payload, $provider, $credentialRef, $purpose),
            default => throw new RuntimeException('Versión de sobre cifrado no soportada durante la migración.'),
        };
    }

    private static function ensureCredentialRefColumn(PDO $db): void
    {
        if (self::columnExists($db, self::TABLE, 'credential_ref')) {
            return;
        }

        $db->exec(
            'ALTER TABLE pasarelas_pago_credenciales
             ADD COLUMN credential_ref VARCHAR(64) NULL AFTER proveedor'
        );
    }

    private static function assertProviderConsistency(PDO $db): void
    {
        $stmt = $db->query(
            'SELECT c.proveedor AS config_provider, v.proveedor AS credential_provider, c.credencial_actual_id
             FROM pasarelas_pago_config c
             JOIN pasarelas_pago_credenciales v ON v.id = c.credencial_actual_id
             WHERE c.credencial_actual_id IS NOT NULL
               AND c.proveedor <> v.proveedor
             LIMIT 1'
        );
        $mismatch = $stmt->fetch();
        if ($mismatch) {
            throw new RuntimeException(
                'La configuración apunta a una credencial de otro proveedor; corrige la inconsistencia antes de endurecer la FK.'
            );
        }
    }

    private static function replaceConfigForeignKey(PDO $db): void
    {
        if (self::foreignKeyExists($db, self::CONFIG_TABLE, 'fk_pasarela_config_credencial')) {
            $db->exec(
                'ALTER TABLE pasarelas_pago_config
                 DROP FOREIGN KEY fk_pasarela_config_credencial'
            );
        }

        $db->exec(
            'ALTER TABLE pasarelas_pago_config
             ADD CONSTRAINT fk_pasarela_config_credencial
             FOREIGN KEY (proveedor, credencial_actual_id)
             REFERENCES pasarelas_pago_credenciales(proveedor, id)
             ON UPDATE RESTRICT ON DELETE RESTRICT'
        );
    }

    private static function ensureImmutableTrigger(PDO $db): void
    {
        if (self::triggerExists($db, self::IMMUTABLE_TRIGGER)) {
            return;
        }

        $db->exec(
            "CREATE TRIGGER " . self::IMMUTABLE_TRIGGER . "
             BEFORE UPDATE ON pasarelas_pago_credenciales
             FOR EACH ROW
             SIGNAL SQLSTATE '45000'
             SET MESSAGE_TEXT = 'Las versiones históricas de credenciales de pago son inmutables'"
        );
    }

    private static function ensureIndex(
        PDO $db,
        string $table,
        string $index,
        string $modifier,
        string $columns
    ): void {
        if (self::indexExists($db, $table, $index)) {
            return;
        }

        $prefix = trim($modifier) === '' ? '' : trim($modifier) . ' ';
        $db->exec(sprintf(
            'CREATE %sINDEX %s ON %s %s',
            $prefix,
            $index,
            $table,
            $columns
        ));
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function indexExists(PDO $db, string $table, string $index): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function foreignKeyExists(PDO $db, string $table, string $constraint): bool
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function triggerExists(PDO $db, string $trigger): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
        );
        $stmt->execute([$trigger]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function newCredentialRef(): string
    {
        return 'cred_' . bin2hex(random_bytes(16));
    }
}
