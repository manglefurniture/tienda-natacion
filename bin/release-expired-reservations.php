<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap.php';

try {
    $released = OrderService::releaseExpiredReservations(Database::connection());
    fwrite(STDOUT, "Reservas liberadas: {$released}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
