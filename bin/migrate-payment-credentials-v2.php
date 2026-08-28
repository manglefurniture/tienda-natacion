<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando solo puede ejecutarse desde CLI.\n");
    exit(1);
}

try {
    $secret = trim((string) env('PAYMENT_GATEWAY_CONFIG_KEY'));
    if ($secret === '') {
        throw new RuntimeException('PAYMENT_GATEWAY_CONFIG_KEY no está configurada.');
    }

    $result = PaymentGatewayCredentialMigrator::migrate(
        Database::connection(),
        new PaymentCredentialCipher($secret)
    );

    fwrite(
        STDOUT,
        sprintf(
            "Credenciales de pago endurecidas: %d revisadas, %d migradas a v2.\n",
            (int) $result['rows_seen'],
            (int) $result['rows_migrated']
        )
    );
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
