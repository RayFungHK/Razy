<?php

/**
 * razymod/queue-admin — public API command 'act' (release|bury|delete).
 *
 * Mutating command: kind is allow-listed INSIDE QueueAdminService before any
 * store touch; the __onAPICall gate additionally restricts callers.
 */

use Razy\Database;
use Razy\Module\queueadmin\QueueAdminService;
use Razy\Queue\DatabaseStore;

return function (int|string $id, string $kind, int $retryDelay = 0): array {
    $db = Database::getSharedInstance();

    if ($db === null) {
        return ['ok' => false, 'error' => 'no database connection available'];
    }

    require_once __DIR__ . '/../support/QueueAdminService.php';

    try {
        $service = new QueueAdminService(new DatabaseStore($db));

        return $service->action($id, $kind, $retryDelay);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
    }
};
