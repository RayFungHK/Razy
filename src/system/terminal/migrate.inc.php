<?php

/**
 * CLI Command: migrate.
 *
 * The deploy-time migration surface (dossier MIGRATION-GOVERNANCE.md M2):
 * one command to see and move the schema state of EVERY module in a
 * distributor. Each module's rows live under its own scope (M0) and file
 * checksums are verified before anything runs (M1) — this CLI is simply the
 * first consumer of the manager's scoped surface (verifyChecksums,
 * getAppliedWithChecksum, scoped getStatus/rollback).
 *
 * Policy (maintainer-decided, Q-M2): WEB REQUESTS NEVER MIGRATE. Boot-time
 * DDL is a per-request cost with concurrent ALTER races; migrations belong
 * to the deploy pipeline, which is this command.
 *
 * Usage:
 *   php Razy.phar migrate <dist>                 Apply pending (all modules)
 *   php Razy.phar migrate <dist> <module_code>   Apply pending (one module)
 *   php Razy.phar migrate <dist> --status        Read-only state (never writes)
 *   php Razy.phar migrate <dist> <module_code> --rollback[=n]
 *                                                Rollback last n batches of ONE
 *                                                module (explicit module required —
 *                                                mass rollback is not a deploy verb)
 *
 * Options:
 *   --status          Print per-module applied/pending/drift table; exit non-zero
 *                     when any checksum drift is detected (deploy gate)
 *   --rollback[=n]    Roll back n batches (default 1); requires a module code
 *   --force           Pass through to migrate(): proceed despite checksum drift
 *                     (operator decision only — never wire automation into it)
 *   --domain=<name>   Domain tag for the distributor (default: *)
 *
 * Examples:
 *   php Razy.phar migrate mysite --status
 *   php Razy.phar migrate mysite
 *   php Razy.phar migrate mysite razymod/permissions --rollback=1
 *
 * Module database resolution follows the config-connect contract
 * (dossier §4.2 option 1, pioneered by razymod/permissions): each module's
 * per-distributor config declares {database: {type, connection[, name]}};
 * a missing/unreachable target is reported per module and fails the run —
 * never guessed, never ambient.
 *
 * @license MIT
 */

namespace Razy;

use Razy\Database\MigrationManager;
use Razy\Util\PathUtil;
use Throwable;

return function () {
    /** @var list<string> $args */
    $args = \func_get_args();

    $distCode = null;
    $moduleFilter = null;
    $options = [];

    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--')) {
            $parts = \explode('=', \substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        } elseif ($distCode === null) {
            $distCode = $arg;
        } elseif ($moduleFilter === null) {
            $moduleFilter = $arg;
        }
    }

    $usage = function (): void {
        $this->writeLineLogging('{@s:b}Migrate — deploy-time module schema management{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar migrate <dist> [module_code] [--status|--rollback=n] [--force]{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:green}--status{@reset}         Show applied/pending/drift per module (read-only)', true);
        $this->writeLineLogging('  {@c:green}--rollback[=n]{@reset}   Rollback n batches of ONE module (module code required)', true);
        $this->writeLineLogging('  {@c:green}--force{@reset}          Proceed despite checksum drift (operator only)', true);
        $this->writeLineLogging('  {@c:green}--domain=<name>{@reset}  Domain tag (default: *)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  Policy: web requests never migrate; this CLI is the migration surface.', true);
        $this->writeLineLogging('', true);
    };

    if ($distCode === null) {
        $usage();
        exit(1);
    }

    $domain = (string) ($options['domain'] ?? '*');
    $statusOnly = isset($options['status']);
    $force = isset($options['force']);

    $rollbackSteps = null;

    if (isset($options['rollback'])) {
        $rollbackSteps = \is_string($options['rollback']) ? (int) $options['rollback'] : 1;

        if ($rollbackSteps < 1) {
            $this->writeLineLogging('{@c:red}[ERROR]{@reset} --rollback expects a positive number of batches', true);
            exit(1);
        }

        if ($moduleFilter === null) {
            $this->writeLineLogging('{@c:red}[ERROR]{@reset} --rollback requires an explicit module code (mass rollback is not a deploy verb)', true);
            exit(1);
        }
    }

    $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);

    if (!\is_dir($distPath)) {
        $this->writeLineLogging("{@c:red}[ERROR]{@reset} Distributor folder not found: {$distPath}", true);
        exit(1);
    }

    try {
        $distributor = new Distributor($distCode, $domain);
        // init-phase only (RZ-009): __onInit/__onLoad/__onRequire register,
        // they do not touch databases — safe to boot for a migration pass
        $distributor->initialize();
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR]{@reset} Failed to initialize distributor: {$e->getMessage()}", true);
        exit(1);
    }

    $modulesInfo = $distributor->getRegistry()->getLoadedModulesInfo();

    $this->writeLineLogging("{@c:cyan}Distributor:{@reset} {$distCode}  {@c:cyan}Modules:{@reset} " . \count($modulesInfo), true);
    $this->writeLineLogging('', true);

    $failures = 0;
    $considered = 0;

    /**
     * Config-connect (dossier §4.2 option 1): the module's per-distributor
     * config declares its database; failures are explicit, never ambient.
     */
    $connectModuleDatabase = function (Module $module, string $code) use (&$failures) {
        $config = $module->loadConfig()->array();
        $dbConfig = $config['database'] ?? null;

        if (!\is_array($dbConfig) || !isset($dbConfig['type'], $dbConfig['connection']) || !\is_array($dbConfig['connection'])) {
            $failures++;
            $this->writeLineLogging('  {@c:red}[FAIL]{@reset} no config-connect database declared '
                . "(config: 'database' => ['type' => ..., 'connection' => [...]])", true);

            return null;
        }

        $name = (string) ($dbConfig['name'] ?? 'cli_migrate_' . \preg_replace('/[^a-zA-Z0-9]+/', '_', $code));
        $db = new Database($name);

        try {
            if (!$db->connectWithDriver((string) $dbConfig['type'], $dbConfig['connection'])) {
                $failures++;
                $this->writeLineLogging('  {@c:red}[FAIL]{@reset} database connection refused', true);

                return null;
            }
        } catch (Throwable $e) {
            $failures++;
            $this->writeLineLogging('  {@c:red}[FAIL]{@reset} database connection error: ' . $e->getMessage(), true);

            return null;
        }

        return $db;
    };

    /**
     * Read-only --status render; returns true when checksum drift is present
     * (the deploy gate: command exit code reflects it).
     */
    $renderStatus = function (MigrationManager $manager): bool {
        $drift = $manager->verifyChecksums();
        $applied = 0;
        $pending = 0;

        foreach ($manager->getStatus() as $row) {
            $marker = $row['applied'] ? '{@c:green}applied{@reset}' : '{@c:yellow}pending{@reset}';

            if ($row['applied']) {
                $applied++;
            } else {
                $pending++;
            }

            $note = '';

            if (isset($drift[$row['name']])) {
                $note = '  {@c:red}[DRIFT]{@reset}';
            } elseif ($row['applied'] && $row['checksum'] === '') {
                $note = '  {@c:gray}(pre-M1, unverifiable){@reset}';
            }

            $this->writeLineLogging(\sprintf(
                '  %s %-46s batch %-3s %s%s',
                $marker,
                $row['name'],
                $row['batch'] ?? '-',
                $row['executed_at'] ?? '',
                $note
            ), true);
        }

        foreach ($drift as $name => $message) {
            $this->writeLineLogging("  {@c:red}[DRIFT]{@reset} {$name}: {$message}", true);
        }

        $this->writeLineLogging(\sprintf('  {@c:cyan}summary{@reset} applied=%d pending=%d drift=%d', $applied, $pending, \count($drift)), true);

        return $drift !== [];
    };

    $codes = \array_keys($modulesInfo);
    \sort($codes);

    foreach ($codes as $code) {
        if ($moduleFilter !== null && $code !== $moduleFilter) {
            continue;
        }

        /** @var Module $module */
        $module = $modulesInfo[$code]['module'];
        $migrationDir = PathUtil::append($module->getModuleInfo()->getPath(), 'migration');

        if (!\is_dir($migrationDir)) {
            continue; // modules without migrations are simply not part of this pass
        }

        $considered++;
        $this->writeLineLogging("{@s:b}Module: {$code}{@reset}", true);

        $db = $connectModuleDatabase($module, $code);

        if ($db === null) {
            continue; // failure already counted
        }

        $manager = new MigrationManager($db, $code);
        $manager->addPath($migrationDir);

        if ($statusOnly) {
            $failures += $renderStatus($manager) ? 1 : 0;

            continue;
        }

        try {
            if ($rollbackSteps !== null) {
                $rolled = $manager->rollback($rollbackSteps);
                $this->writeLineLogging('  {@c:yellow}rolled back {@reset}' . \implode(', ', $rolled ?: ['(nothing to roll back)']), true);
            } else {
                $executed = $manager->migrate($force);
                $this->writeLineLogging('  {@c:green}applied {@reset}' . \implode(', ', $executed ?: ['(up to date)']), true);
            }
        } catch (Throwable $e) {
            $failures++;
            $this->writeLineLogging('  {@c:red}[FAIL]{@reset} ' . $e->getMessage(), true);
        }
    }

    if ($considered === 0) {
        $this->writeLineLogging($moduleFilter !== null
            ? "{@c:red}[ERROR]{@reset} No migratable module matched '{$moduleFilter}' (does it have a migration/ dir?)"
            : '{@c:yellow}No module in this distributor carries a migration/ directory.{@reset}', true);
        exit($moduleFilter !== null ? 1 : 0);
    }

    $this->writeLineLogging('', true);

    if ($failures > 0) {
        $this->writeLineLogging("{@c:red}[FAIL]{@reset} {$failures} module(s) reported problems (see above)", true);
        exit(1);
    }

    $this->writeLineLogging('{@c:green}[OK]{@reset} Migration pass complete.', true);
};
