<?php

declare(strict_types=1);

namespace Razy\Database;

use Razy\Database;
use Razy\Exception\DatabaseException;
use Razy\Module;
use Throwable;

/**
 * The ONE config-connect database resolution policy (dossier
 * MIGRATION-GOVERNANCE.md §4.2 option 1): a module declares its database in
 * its per-distributor config, and every consumer that needs to run or read
 * the module's schema ledger resolves it through this single door — the
 * `migrate` CLI, the readiness predicate (MODULE-LIFECYCLE.md L1), and the
 * wizard runner that lands at L4. Failures carry their cause and throw;
 * callers decide between counting (CLI) and propagating (predicates). No
 * caller gets to improvise its own ambient-DB fallback.
 */
final class ModuleDatabaseConnector
{
    /**
     * @param string $code Module code, for messages and the default
     *                     connection-instance name
     * @param string $namePrefix Instance-name prefix, so two consumers of the
     *                           same module config never share one Database
     *                           instance ('cli_migrate', 'module_ready', …)
     *
     * @throws DatabaseException With the named cause: undeclared config,
     *                           refused connection, or connection error
     */
    public static function connect(Module $module, string $code, string $namePrefix = 'cli_migrate'): Database
    {
        $config = $module->loadConfig()->array();
        $dbConfig = $config['database'] ?? null;

        if (!\is_array($dbConfig) || !isset($dbConfig['type'], $dbConfig['connection']) || !\is_array($dbConfig['connection'])) {
            throw new DatabaseException("Module '{$code}': no config-connect database declared "
                . "(config: 'database' => ['type' => ..., 'connection' => [...]])");
        }

        $name = (string) ($dbConfig['name'] ?? $namePrefix . '_' . \preg_replace('/[^a-zA-Z0-9]+/', '_', $code));
        $db = new Database($name);

        try {
            if (!$db->connectWithDriver((string) $dbConfig['type'], $dbConfig['connection'])) {
                throw new DatabaseException("Module '{$code}': database connection refused");
            }
        } catch (DatabaseException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DatabaseException("Module '{$code}': database connection error: " . $e->getMessage(), 0, $e);
        }

        return $db;
    }
}
