<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$envFile = $root . '/.env';

if (is_file($envFile) && is_readable($envFile)) {
    $values = parse_ini_file($envFile, false, INI_SCANNER_RAW);
    if (is_array($values)) {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
            }
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

require_once $root . '/src/Database.php';
require_once $root . '/src/PaymentCredentialCipher.php';
require_once $root . '/src/PaymentGatewayCredentialMigrator.php';
require_once $root . '/src/PaymentGatewayConfig.php';
require_once $root . '/src/MercadoPago.php';
require_once $root . '/src/OrderService.php';
require_once $root . '/src/ImageOptimizer.php';
require_once $root . '/src/ResendMailer.php';
require_once $root . '/src/OrderNotificationService.php';

// Snapshot of Hache Base P2-04 upload extension, source commit 572a9512eadf5a8e3d3a9b7df404aa667eac0a04.
require_once $root . '/src/HacheBase/Upload/UploadRejected.php';
require_once $root . '/src/HacheBase/Upload/UploadPolicy.php';
require_once $root . '/src/HacheBase/Upload/UploadScanner.php';
require_once $root . '/src/HacheBase/Upload/UploadStorage.php';
require_once $root . '/src/HacheBase/Upload/LocalUploadStorage.php';
require_once $root . '/src/HacheBase/Upload/PhpUploadAdapter.php';
require_once $root . '/src/HacheBase/Upload/UploadService.php';
