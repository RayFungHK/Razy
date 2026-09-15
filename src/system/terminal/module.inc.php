<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * CLI Command: module.
 *
 * The operator surface for module lifecycle (dossier MODULE-LIFECYCLE.md L2):
 * see what the framework derived, and flip the enable-list — nothing else.
 * There is deliberately NO `module install` or `module uninstall`: schema
 * moves only at `php Razy.phar migrate` (deploy door), uninstall is out of
 * the doctrine's scope (Q6), and neither is reachable from here by design.
 *
 * Usage:
 *   php Razy.phar module status <dist>
 *   php Razy.phar module enable <dist> <vendor/module>
 *   php Razy.phar module disable <dist> <vendor/module> [--force]
 *
 * Arguments:
 *   status            Table: code, version, provision, enabled, pending, ready.
 *                     Exit non-zero when anything is declared-but-pending or
 *                     its ledger is unreachable (deploy-gate parity with
 *                     `migrate --status`: the gate refuses what it cannot see).
 *   enable | disable  Write config/<dist>/modules.php (the Q5 enable-list,
 *                     absent = every listed module is enabled). disable
 *                     refuses when a live module `require`s the target — the
 *                     dependents are named; --force is the operator override.
 *
 * Options:
 *   --force           Override the dependent-refusal of `disable` (operator
 *                     decision only — the dependents will fail their require
 *                     next boot, loudly, through the L0 warning)
 *   --domain=<name>   Domain tag for distributor init (default: *)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Database\MigrationManager;
use Razy\Database\ModuleDatabaseConnector;
use Razy\Module\ModuleStatus;
use Razy\Util\PathUtil;
use Throwable;

return function (string $sub = '', string $distCode = '', string $moduleCode = '', ...$args) {
    $options = [];

    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--')) {
            $parts = \explode('=', \substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        }
    }

    $usage = function (): void {
        $this->writeLineLogging('{@s:b}Module — lifecycle operator surface (L2){@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar module status <dist>{@reset}                  derived state table (deploy-gate exit)', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar module enable <dist> <vendor/module>{@reset}    write the enable-list', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar module disable <dist> <vendor/module> {@reset}( {@c:green}--force{@reset} )', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  Ready is DERIVED from the migration ledger, never stored. There is no', true);
        $this->writeLineLogging('  install/uninstall verb: schema moves at `php Razy.phar migrate <dist>`', true);
        $this->writeLineLogging('  only; disabling never drops data (dossier MODULE-LIFECYCLE.md Q4/Q6).', true);
        $this->writeLineLogging('', true);
    };

    $distCode = \trim($distCode);

    if ($sub === '' || $distCode === '') {
        $usage();
        exit(1);
    }

    $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);

    if (!\is_dir($distPath)) {
        $this->writeLineLogging("{@c:red}[ERROR]{@reset} Distributor folder not found: {$distPath}", true);
        exit(1);
    }

    $domain = (string) ($options['domain'] ?? '*');

    try {
        $distributor = new Distributor($distCode, $domain);
        // init-phase only (RZ-009): registration hooks never touch databases —
        // same boot discipline the migrate command documents inline
        $distributor->initialize();
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR]{@reset} Failed to initialize distributor: {$e->getMessage()}", true);
        exit(1);
    }

    /** @var list<Module> $modules */
    $modules = \array_values($distributor->getRegistry()->getModules());
    $enableListPath = PathUtil::append(SYSTEM_ROOT, 'config', $distCode, 'modules.php');

    // ── enable / disable: the ONE file, written through Configuration ─────
    if ($sub === 'enable' || $sub === 'disable') {
        $moduleCode = \trim($moduleCode);

        if ($moduleCode === '') {
            $this->writeLineLogging("{@c:red}[ERROR]{@reset} {$sub} requires a module code.", true);
            $usage();
            exit(1);
        }

        $target = null;

        foreach ($modules as $module) {
            if ($module->getModuleInfo()->getCode() === $moduleCode) {
                $target = $module;

                break;
            }
        }

        if ($target === null) {
            $this->writeLineLogging("{@c:red}[ERROR]{@reset} '{$moduleCode}' is not a module of dist '{$distCode}' "
                . '(typo, uninstalled, or renamed — enable-list ghosts are never written).', true);
            exit(1);
        }

        if ($sub === 'disable') {
            // Dependent-refusal (Q5 rail): disabling a required module would
            // only produce L0 warnings for its dependents next boot. Name
            // them, refuse, and let --force be a human decision in history.
            $dependents = [];

            foreach ($modules as $module) {
                $code = $module->getModuleInfo()->getCode();

                if ($code !== $moduleCode && \array_key_exists($moduleCode, $module->getModuleInfo()->getRequire())) {
                    $dependents[] = $code;
                }
            }

            if ($dependents !== [] && !isset($options['force'])) {
                $this->writeLineLogging("{@c:red}[REFUSED]{@reset} '{$moduleCode}' is required by: "
                    . \implode(', ', $dependents), true);
                $this->writeLineLogging('  Those modules would be left unloaded next boot (their require would fail — loudly).', true);
                $this->writeLineLogging('  Disable the dependents first, or pass {@c:green}--force{@reset} as an explicit operator decision.', true);
                exit(1);
            }
        }

        try {
            $config = new Configuration($enableListPath);
            $config->offsetSet($moduleCode, $sub === 'enable');
            $config->save();
        } catch (Throwable $e) {
            $this->writeLineLogging("{@c:red}[ERROR]{@reset} Could not write enable-list: {$e->getMessage()}", true);
            exit(1);
        }

        $this->writeLineLogging("{@c:green}[OK]{@reset} {$moduleCode} marked " . ($sub === 'enable' ? 'enabled' : 'DISABLED')
            . " in config/{$distCode}/modules.php", true);
        $this->writeLineLogging('  Takes effect at the next boot of the distributor (the enable-list is read after scan).', true);

        if ($sub === 'disable') {
            $this->writeLineLogging('  Data is untouched: disable never drops schema or rows (MODULE-LIFECYCLE.md Q4).', true);
        }

        exit(0);
    }

    if ($sub !== 'status') {
        $usage();
        exit(1);
    }

    // ── status: the derived-state table (no stored flag to display) ───────
    $enableList = \is_file($enableListPath) ? (new Configuration($enableListPath))->array() : [];

    $this->writeLineLogging("{@c:cyan}Distributor:{@reset} {$distCode}  {@c:cyan}Modules:{@reset} " . \count($modules), true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging(\sprintf('  {@c:gray}%-28s %-9s %-8s %-8s %-8s %s{@reset}', 'MODULE', 'VERSION', 'PROVISION', 'ENABLED', 'PENDING', 'READY'), true);

    $gate = 0;

    foreach ($modules as $module) {
        $info = $module->getModuleInfo();
        $code = $info->getCode();
        $enabled = ($enableList[$code] ?? true) !== false;
        $provision = $info->getProvision();
        $declared = $info->getProvisionDeclared();
        $provisionLabel = $declared !== '' && $declared !== $provision ? $declared . '!' : $provision;

        $pendingLabel = '-';
        $readyLabel = 'yes';

        if (!$enabled || !\in_array($module->getStatus(), [ModuleStatus::InQueue, ModuleStatus::Loaded], true)) {
            // Same POSITIVE whitelist as Distributor::moduleReady(): a module
            // blocked mid-require sits in Processing and is NOT ready (caught
            // in the dogfood run: a dependent blocked by a disabled peer
            // showed 'yes' until this line matched the predicate it renders).
            $readyLabel = 'no'; // not enabled, or not loaded: no DB touch to ask
        } else {
            $migrationDir = PathUtil::append($info->getPath(), 'migration');

            if (\is_dir($migrationDir)) {
                try {
                    $db = ModuleDatabaseConnector::connect($module, $code, 'module_status');
                    $manager = new MigrationManager($db, $code);
                    $manager->addPath($migrationDir);
                    $pending = \count($manager->getPending());

                    if ($pending > 0) {
                        $pendingLabel = (string) $pending;
                        $readyLabel = 'NO';
                        $gate++;
                    }
                } catch (Throwable $e) {
                    // Unreachable ledger is NOT rendered as ready — the gate
                    // refuses what it cannot see (deploy-gate honesty).
                    $pendingLabel = '???';
                    $readyLabel = 'UNREACHABLE';
                    $gate++;
                    $this->writeLineLogging('    {@c:gray}' . $e->getMessage() . '{@reset}', true);
                }
            }
        }

        $this->writeLineLogging(\sprintf(
            '  %-28s %-9s %-8s %-8s %-8s %s',
            $code,
            $info->getVersion(),
            $provisionLabel,
            $enabled ? 'yes' : 'no',
            $pendingLabel,
            $readyLabel,
        ), true);
    }

    $this->writeLineLogging('', true);
    $this->writeLineLogging('  {@c:gray}ready is derived per boot from the migration ledger (never stored); '
        . 'pending>0 or', true);
    $this->writeLineLogging('  unreachable ledger => non-zero exit (deploy gate). '
        . 'Fix: php Razy.phar migrate ' . $distCode . '{@reset}', true);

    exit($gate > 0 ? 1 : 0);
};
