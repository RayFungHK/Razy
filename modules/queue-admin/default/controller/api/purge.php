<?php
/**
 * razymod/queue-admin — public API command 'purge' (clear finished/buried of a queue).
 */

use Razy\Database;
use Razy\Module\queueadmin\QueueAdminService;
use Razy\Queue\DatabaseStore;

return function (string $queue = 'default'): array {
    $db = Database::getSharedInstance();

    if ($db === null) {
        return ['ok' => false, 'error' => 'no database connection available'];
    }

    require_once __DIR__ . '/../support/QueueAdminService.php';

    try {
        $service = new QueueAdminService(new DatabaseStore($db));

        return $service->purge($queue);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
    }
};
