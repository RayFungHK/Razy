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
 *   php Razy.phar migrate <dist>                 Apply pending (bulk = modules
 *                                                declaring 'migration' => 'deploy')
 *   php Razy.phar migrate <dist> <module_code>   Apply pending (one module,
 *                                                naming it IS the manual sign-off)
 *   php Razy.phar migrate <dist> --status        Read-only state (never writes)
 *   php Razy.phar migrate <dist> <module_code> --rollback[=n]
 *                                                Rollback last n batches of ONE
 *                                                module (explicit module required —
 *                                                mass rollback is not a deploy verb)
 *
 * Declaration (M3): package.php carries 'migration' => 'deploy' | 'manual'
 * (default manual = the historic, developer-invoked behaviour). Bulk passes
 * never touch 'manual' modules; --status shows every module and its mode so
 * nothing is invisible. A suspect declaration (anything other than
 * deploy/manual) degrades to manual WITH a loud warning, never silently.
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
use Razy\Database\ModuleDatabaseConnector;
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
        $this->writeLineLogging('  Bulk apply only runs modules declaring \'migration\' => \'deploy\' (package.php);', true);
        $this->writeLineLogging('  naming a module explicitly is the manual sign-off. --status always shows all.', true);
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
    /** @var list<string> $skippedManual */
    $skippedManual = [];

    /**
     * Config-connect (dossier §4.2 option 1): the module's per-distributor
     * config declares its database — resolved through the ONE connector door
     * (shared with the readiness predicate, MODULE-LIFECYCLE.md L1); failures
     * are explicit here, never ambient.
     */
    $connectModuleDatabase = function (Module $module, string $code) use (&$failures) {
        try {
            return ModuleDatabaseConnector::connect($module, $code, 'cli_migrate');
        } catch (Throwable $e) {
            $failures++;
            $this->writeLineLogging('  {@c:red}[FAIL]{@reset} ' . $e->getMessage(), true);

            return null;
        }
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

        // M3 declaration gate. Status mode always shows every module; the
        // APPLY pass is bulk-gated by the 'migration' => 'deploy' declaration
        // — an unnamed bulk run never touches 'manual' modules, and naming a
        // module explicitly IS the manual sign-off.
        $mode = $module->getModuleInfo()->getMigrationMode();
        $declared = $module->getModuleInfo()->getMigrationDeclared();

        if ($declared !== '' && !\in_array($declared, ['deploy', 'manual'], true)) {
            $this->writeLineLogging("  {@c:yellow}[WARN]{@reset} suspect migration declaration '{$declared}' "
                . "(expected 'deploy' or 'manual') - treated as manual", true);
        }

        if (!$statusOnly && $moduleFilter === null && $mode !== 'deploy') {
            $skippedManual[] = $code;

            continue;
        }

        $considered++;
        $this->writeLineLogging("{@s:b}Module: {$code}{@reset} {@c:gray}[{$mode}]{@reset}", true);

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
        if ($moduleFilter !== null) {
            $this->writeLineLogging("{@c:red}[ERROR]{@reset} No migratable module matched '{$moduleFilter}' (does it have a migration/ dir?)", true);
            exit(1);
        }

        if ($skippedManual !== []) {
            $this->writeLineLogging('{@c:yellow}No module declared migration => deploy; '
                . \count($skippedManual) . ' manual module(s) carry migrations: ' . \implode(', ', $skippedManual), true);
            $this->writeLineLogging('  name a module explicitly to run it, or declare it in package.php.', true);
            exit(0); // nothing failed — declaring is the operator's call
        }

        $this->writeLineLogging('{@c:yellow}No module in this distributor carries a migration/ directory.{@reset}', true);
        exit(0);
    }

    if ($skippedManual !== []) {
        $this->writeLineLogging('{@c:gray}skipped (declared manual; name them explicitly to run): '
            . \implode(', ', $skippedManual) . '{@reset}', true);
    }

    $this->writeLineLogging('', true);

    if ($failures > 0) {
        $this->writeLineLogging("{@c:red}[FAIL]{@reset} {$failures} module(s) reported problems (see above)", true);
        exit(1);
    }

    $this->writeLineLogging('{@c:green}[OK]{@reset} Migration pass complete.', true);
};
