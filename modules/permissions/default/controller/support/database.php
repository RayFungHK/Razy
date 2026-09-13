<?php

/**
 * razymod/permissions — config-connect database resolver (own-module support).
 *
 * Doctrine (manual/04:16, 48-51; dossier §4.2 option 1): no auto-connect, no
 * ambient-singleton hoping — the app DECLARES the database in this module's
 * per-distributor config, and every failure degrades explicitly to null
 * (callers MUST handle null; Controller::api() null-safety completes the
 * fail-closed chain).
 *
 * Module config shape (config/<dist>/permissions.php):
 *   'database' => [
 *       'type'       => 'mysql' | 'pgsql' | 'sqlite' | <registered custom>,
 *       'connection' => [...driver config, verbatim to connectWithDriver();
 *                        omitted = the DRIVER's own defaults, e.g. sqlite
 *                        ':memory:' (Database/Driver/SQLite.php:48)],
 *       'name'       => 'razymod_permissions',   // optional instance label
 *   ]
 *
 * Deliberately NOT taken (each evidence-backed):
 * - Database::getInstance() — @deprecated lazy-create registry (Database.php:115)
 * - getSharedInstance() reuse — that slot belongs to the APP ('main'); a
 *   module silently borrowing the app's handle is exactly the ambient-magic
 *   class of bug the phantom bug class taught us (§4.4).
 * - api('razit')->getDB() live-object passing — RZ-008-adjacent (§4.2 row 2).
 *
 * A fresh new Database() per call is intentional: "treat the static registry
 * as legacy" (manual/04:48); MySQL reconnects cheaply via persistent PDO
 * (manual/04:55-57), file/sqlite opens are local. 'sqlite :memory:' therefore
 * only makes sense per-call (no persistence across calls) — stated, not faked.
 */

use Razy\Database;

return function (mixed $config): ?Database {
    if (!\is_array($config)) {
        return null;
    }

    $type = $config['type'] ?? null;
    $connection = $config['connection'] ?? [];
    $name = $config['name'] ?? 'razymod_permissions';

    if (!\is_string($type) || $type === '' || !\is_array($connection) || !\is_string($name) || $name === '') {
        return null;
    }

    try {
        $db = new Database($name);

        return $db->connectWithDriver($type, $connection) ? $db : null;
    } catch (Throwable) {
        // Driver construction/config errors are configuration failures, not
        // runtime surprises — degrade like any other unreachable database.
        return null;
    }
};
