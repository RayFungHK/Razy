<?php
/**
 * razymod/queue-admin — queue-store resolver (own-module support file).
 *
 * Resolution mirrors the `queue` CLI exactly (src/system/terminal/queue.inc.php:52-56):
 * Database::getSharedInstance() -> DatabaseStore. RedisQueueStore stays
 * unavailable from module scope until the framework grows a Redis connection
 * layer (none exists today; Cache/Session/Scheduler all take an injected
 * client) — stated honestly rather than faking a config format.
 *
 * Returns null when no database is reachable; callers MUST degrade explicitly
 * (the nullable discipline of Controller::api()).
 */

use Razy\Database;
use Razy\Queue\DatabaseStore;
use Razy\Queue\QueueStoreInterface;

return function (): ?QueueStoreInterface {
    $db = Database::getSharedInstance();

    return $db === null ? null : new DatabaseStore($db);
};
