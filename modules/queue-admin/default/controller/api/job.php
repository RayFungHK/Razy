<?php

/**
 * razymod/queue-admin — public API command 'job'.
 */

use Razy\Database;
use Razy\Module\queueadmin\QueueAdminService;
use Razy\Queue\DatabaseStore;

return function (int|string $id): array {
    $db = Database::getSharedInstance();

    if ($db === null) {
        return ['ok' => false, 'error' => 'no database connection available'];
    }

    require_once __DIR__ . '/../support/QueueAdminService.php';

    try {
        $service = new QueueAdminService(new DatabaseStore($db));
        $job = $service->job($id);

        return ['ok' => $job !== null, 'job' => $job];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
    }
};
