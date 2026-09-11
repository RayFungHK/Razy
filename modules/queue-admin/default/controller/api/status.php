<?php
/**
 * razymod/queue-admin — public API command 'status'.
 *
 * API closures RETURN data (the return value is the caller's result,
 * ClosureLoader.php:140) — never echo/superglobals (RZ-003).
 */

use Razy\Database;
use Razy\Module\queueadmin\QueueAdminService;
use Razy\Queue\DatabaseStore;

return function (array $queues = ['default']): array {
    $db = Database::getSharedInstance();

    if ($db === null) {
        return ['ok' => false, 'error' => 'no database connection available'];
    }

    require_once __DIR__ . '/../support/QueueAdminService.php';

    try {
        $service = new QueueAdminService(new DatabaseStore($db));

        return ['ok' => true, 'status' => $service->status(array_values(array_map('strval', $queues)))];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
    }
};
