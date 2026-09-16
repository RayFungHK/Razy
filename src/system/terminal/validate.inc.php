<?php

/**
 * CLI Command: validate.
 *
 * Validates that all route and API closure files exist for modules in a
 * given distributor. The command initializes the distributor, inspects
 * registered routes and API commands, and checks that each referenced
 * closure file is loadable. Optionally generates stub files for missing closures.
 *
 * Also runs the FM-2 coexistence route audit (Route Coexistence Phase 3,
 * `Razy\Routing\RouteAudit`): catch-all / foreign-absorbing findings are
 * ADVISORY warnings — they never change the exit code (errors still do).
 *
 * Usage:
 *   php Razy.phar validate <distributor_code> [module_code] [options]
 *
 * Arguments:
 *   distributor_code  Code of the distributor to validate
 *   module_code       Optional specific module to validate (e.g., demo/event_demo)
 *
 * Options:
 *   -g, --generate       Auto-generate dummy closure files for missing handlers
 *   -v, --verbose        Show detailed validation information per route/API
 *   --domain=<name>      Domain tag to use for distributor init (default: *)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Routing\ExcludePaths;
use Razy\Routing\RouteAudit;
use Razy\Util\PathUtil;
use Throwable;

return function (string $distCode = '', ...$args) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Module Validator', true);
    $this->writeLineLogging('Validate module files and registered routes', true);
    $this->writeLineLogging('', true);

    // Parse options and module code from args
    $generateDummies = false;
    $verbose = false;
    $domain = '*';
    $moduleCode = '';

    foreach ($args as $arg) {
        if ($arg === '--generate' || $arg === '-g') {
            $generateDummies = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        } elseif (\str_starts_with($arg, '--domain=')) {
            $domain = \substr($arg, 9);
        } elseif (!\str_starts_with($arg, '-') && !$moduleCode) {
            // First non-option argument after distCode is moduleCode
            $moduleCode = $arg;
        }
    }

    // Validate required parameters
    $distCode = \trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:red}[ERROR] Distributor code is required.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar validate <distributor_code> [module_code] [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Arguments:', true);
        $this->writeLineLogging('  {@c:green}distributor_code{@reset}     Code of the distributor to validate', true);
        $this->writeLineLogging('  {@c:green}module_code{@reset}          Optional: specific module to validate (e.g., demo/event_demo)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  {@c:green}-g, --generate{@reset}       Auto-generate dummy files for missing closures', true);
        $this->writeLineLogging('  {@c:green}-v, --verbose{@reset}        Show detailed validation information', true);
        $this->writeLineLogging('  {@c:green}--domain=<name>{@reset}      Domain tag to use (default: *)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  {@c:cyan}# Validate all modules in distributor "mysite"{@reset}', true);
        $this->writeLineLogging('  php Razy.phar validate mysite', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Validate specific module{@reset}', true);
        $this->writeLineLogging('  php Razy.phar validate mysite demo/event_demo', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Generate dummy files for missing closures{@reset}', true);
        $this->writeLineLogging('  php Razy.phar validate mysite --generate', true);
        $this->writeLineLogging('', true);

        exit(1);
    }

    try {
        // Check distributor folder exists
        $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
        if (!\is_dir($distPath)) {
            $this->writeLineLogging("{@c:red}[ERROR] Distributor folder not found: {$distPath}{@reset}", true);
            exit(1);
        }

        $this->writeLineLogging("{@c:cyan}Distributor:{@reset} {$distCode}", true);
        $this->writeLineLogging("{@c:cyan}Domain:{@reset} {$domain}", true);
        $this->writeLineLogging("{@c:cyan}Path:{@reset} {$distPath}", true);
        $this->writeLineLogging('', true);

        // Instantiate the distributor with the given code and domain tag
        $this->writeLineLogging('{@c:yellow}Initializing modules...{@reset}', true);

        $distributor = new Distributor($distCode, $domain);
        // Trigger full lifecycle (__onInit, __onLoad, __onRequire) to register routes
        $distributor->initialize();

        $this->writeLineLogging('{@c:green}Modules initialized successfully{@reset}', true);
        $this->writeLineLogging('', true);

        // Retrieve all routes and module metadata registered during initialization
        $routes = $distributor->getRouter()->getRoutes();
        $modulesInfo = $distributor->getRegistry()->getLoadedModulesInfo();

        if ($verbose) {
            $this->writeLineLogging('{@c:cyan}Total routes found:{@reset} ' . \count($routes), true);
            $this->writeLineLogging('{@c:cyan}Total modules loaded:{@reset} ' . \count($modulesInfo), true);
            $this->writeLineLogging('', true);
        }

        $totalErrors = 0;
        $totalWarnings = 0;
        $generatedFiles = 0;
        $validatedRoutes = 0;
        $validatedAPICommands = 0;
        $validatedModules = 0;

        // Group routes by their owning module for per-module validation
        $routesByModule = [];
        foreach ($routes as $routePath => $routeInfo) {
            /** @var Module $module */
            $module = $routeInfo['module'];
            $moduleCodeKey = $module->getModuleInfo()->getCode();

            // Filter by module code if specified
            if ($moduleCode && $moduleCodeKey !== $moduleCode) {
                continue;
            }

            if (!isset($routesByModule[$moduleCodeKey])) {
                $routesByModule[$moduleCodeKey] = [
                    'module' => $module,
                    'routes' => [],
                ];
            }
            $routesByModule[$moduleCodeKey]['routes'][$routePath] = $routeInfo;
        }

        // Iterate each module, validating its route and API closure files exist
        foreach ($modulesInfo as $code => $info) {
            // Filter by module code if specified
            if ($moduleCode && $code !== $moduleCode) {
                continue;
            }

            $validatedModules++;
            /** @var Module $module */
            $module = $info['module'];
            $moduleInfo = $module->getModuleInfo();

            $this->writeLineLogging("{@s:b}Module: {$code}{@reset}", true);
            $this->writeLineLogging("  Alias: {$info['alias']}", true);

            // Unknown package.php keys fail loud (MODULE-LIFECYCLE.md L0): the
            // framework only reads its closed key set, so a misspelled
            // dependency key ('requires'/'required') ships a module whose
            // dependencies were never enforced — the task/appform production
            // finding that motivated this gate.
            $unknownKeys = \array_values(\array_diff($moduleInfo->getPackageKeys(), ModuleInfo::PACKAGE_KEYS));
            foreach ($unknownKeys as $unknownKey) {
                $suggest = '';
                $bestDistance = 4;
                foreach (ModuleInfo::PACKAGE_KEYS as $knownKey) {
                    $distance = \levenshtein($unknownKey, $knownKey);
                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $suggest = " — did you mean '{@c:green}{$knownKey}{@reset}'?";
                    }
                }
                $this->writeLineLogging("  {@c:red}✗ Unknown package.php key: '{$unknownKey}'{$suggest}{@reset}", true);
                $this->writeLineLogging('    Unknown keys are never parsed; a dependency-typo means dependencies silently never applied.', true);
                $totalErrors++;
            }

            // Suspect provision declaration (MODULE-LIFECYCLE.md L1/Q2): the
            // runtime degrades an unknown value to 'deploy' — the web-never-
            // migrates side — but a typo'd 'wizard' must never sit silently in
            // a manifest, because the day L4 lands, its value decides whether
            // a web door exists for this module. Surface it now.
            $provisionDeclared = $moduleInfo->getProvisionDeclared();

            if ($provisionDeclared !== '' && !\in_array($provisionDeclared, ['deploy', 'wizard', 'none'], true)) {
                $this->writeLineLogging("  {@c:red}✗ Suspect provision declaration: '{$provisionDeclared}' "
                    . "(expected 'deploy', 'wizard' or 'none'; treated as 'deploy'){@reset}", true);
                $totalErrors++;
            }

            // A wizard door with nothing behind it (dossier Q2 rail): declaring
            // 'wizard' promises the runner a migration run — a module without a
            // migration/ directory promises an empty door, which is exactly the
            // kind of declared-lie validate exists to catch. (Backfilled at L5;
            // the CLI mint refuses the same shape at the other door.)
            if ($provisionDeclared === 'wizard' && !\is_dir(PathUtil::append($moduleInfo->getPath(), 'migration'))) {
                $this->writeLineLogging("  {@c:red}✗ Declares 'wizard' provision but ships no migration/ directory — "
                    . 'nothing for the runner to run{@reset}', true);
                $totalErrors++;
            }

            // Get routes and API commands for this module
            $moduleRoutes = $routesByModule[$code]['routes'] ?? [];
            $apiCommands = $module->getAPICommands();

            $this->writeLineLogging('  Routes: ' . \count($moduleRoutes) . ', API Commands: ' . \count($apiCommands), true);

            // Validate routes
            foreach ($moduleRoutes as $routePath => $routeInfo) {
                $validatedRoutes++;
                $closurePath = $routeInfo['path'];
                $routeType = $routeInfo['type'] ?? 'standard';

                if ($verbose) {
                    $this->writeLineLogging("  {@c:cyan}Route:{@reset} {$routePath} [{$routeType}]", true);
                }

                // Handle Route object vs string path
                if ($closurePath instanceof Route) {
                    if ($verbose) {
                        $this->writeLineLogging('    {@c:green}✓ Route object (inline handler){@reset}', true);
                    }
                    continue;
                }

                // Check if closure file exists
                $closure = $module->getClosure($closurePath);
                if ($closure === null) {
                    $this->writeLineLogging("  {@c:red}✗ Route missing closure: {$closurePath}{@reset}", true);
                    $this->writeLineLogging("    Route: {$routePath}", true);
                    $totalErrors++;

                    if ($generateDummies) {
                        $controllerDir = PathUtil::append($moduleInfo->getPath(), $moduleInfo->getVersion(), 'controller');
                        $closureFile = PathUtil::append($controllerDir, $closurePath . '.php');
                        generateClosureFile($closureFile, $closurePath);
                        $generatedFiles++;
                        $this->writeLineLogging("    {@c:green}→ Generated {$closurePath}.php{@reset}", true);
                    }
                } elseif ($verbose) {
                    $this->writeLineLogging("    {@c:green}✓ closure: {$closurePath}{@reset}", true);
                }
            }

            // Validate API commands
            foreach ($apiCommands as $command => $closurePath) {
                $validatedAPICommands++;

                if ($verbose) {
                    $this->writeLineLogging("  {@c:magenta}API:{@reset} {$command}", true);
                }

                // Check if closure file exists
                $closure = $module->getClosure($closurePath);
                if ($closure === null) {
                    $this->writeLineLogging("  {@c:red}✗ API missing closure: {$closurePath}{@reset}", true);
                    $this->writeLineLogging("    Command: {$command}", true);
                    $totalErrors++;

                    if ($generateDummies) {
                        $controllerDir = PathUtil::append($moduleInfo->getPath(), $moduleInfo->getVersion(), 'controller');
                        $closureFile = PathUtil::append($controllerDir, $closurePath . '.php');
                        generateClosureFile($closureFile, $closurePath);
                        $generatedFiles++;
                        $this->writeLineLogging("    {@c:green}→ Generated {$closurePath}.php{@reset}", true);
                    }
                } elseif ($verbose) {
                    $this->writeLineLogging("    {@c:green}✓ closure: {$closurePath}{@reset}", true);
                }
            }

            $this->writeLineLogging('', true);
        }

        // ── CSRF posture (CSRF-RAIL.md L1) ────────────────────────────
        // The upgrade-neutral 'off' default is only neutral when it is
        // LOUD: an unarmed dist keeps today's exact behavior, but `validate`
        // refuses to be quiet about every mutating route accepting
        // cross-site form submissions. Armed reads as a green posture line;
        // anything but the two declared strings is a config lie → ✗.
        $this->writeLineLogging('{@s:b}CSRF Posture{@reset}', true);

        $csrfRaw = null;
        $csrfDistConfigPath = PathUtil::append($distPath, 'dist.php');

        if (\is_file($csrfDistConfigPath)) {
            $csrfParsed = require $csrfDistConfigPath;
            $csrfRaw = (\is_array($csrfParsed) ? $csrfParsed : [])['csrf'] ?? null;
        }

        if ('on' === $csrfRaw) {
            $this->writeLineLogging("  {@c:green}✓ armed (dist.php 'csrf' => 'on'){@reset}", true);
        } elseif (null === $csrfRaw || 'off' === $csrfRaw) {
            $this->writeLineLogging('  {@c:yellow}⚠ UNARMED — mutating routes accept cross-site form submissions{@reset}', true);
            $this->writeLineLogging('      to arm: dist.php \'csrf\' => \'on\''
                . '   (forms: Controller::csrfField(); XHR: X-CSRF-TOKEN header)', true);
            ++$totalWarnings;
        } else {
            $this->writeLineLogging("  {@c:red}✗ 'csrf' must be the string 'on' or 'off' (boot would refuse){@reset}", true);
            ++$totalErrors;
        }

        $this->writeLineLogging('', true);

        // ── FM-2 route audit (coexistence Phase 3, dossier option (f)) ──
        // Functional probes over the route table's COMPILED regexes. Foreign
        // namespaces = declared exclude_paths + sibling mounts on domains this
        // dist also serves. Advisory by design: findings count as warnings.
        $this->writeLineLogging('{@s:b}Route Audit (coexistence FM-2){@reset}', true);

        try {
            $app = new Application();
            $siteConfig = $app->loadSiteConfig();

            $foreign = [];

            foreach (ExcludePaths::normalize((array) ($siteConfig['exclude_paths'] ?? [])) as $prefix) {
                $foreign[$prefix] = 'declared exclusion ' . $prefix;
            }

            foreach ((array) ($siteConfig['domains'] ?? []) as $domainName => $distPaths) {
                $selfMounted = false;

                foreach ((array) $distPaths as $mountPath => $identifier) {
                    if (\is_string($identifier) && \explode('@', $identifier)[0] === $distCode) {
                        $selfMounted = true;

                        break;
                    }
                }

                if (!$selfMounted) {
                    continue; // siblings on unrelated hosts are not this dist's URL space
                }

                foreach ((array) $distPaths as $mountPath => $identifier) {
                    // '/' skipped: a sibling root mount is FM-1's generator problem,
                    // and any route absorbing it is already caught by the root-claim probe.
                    if (!\is_string($identifier) || '/' === $mountPath || \explode('@', $identifier)[0] === $distCode) {
                        continue;
                    }

                    $foreign[(string) $mountPath] ??= 'sibling mount ' . $mountPath . ' (' . $identifier . ') on ' . $domainName;
                }
            }

            $allow = [];
            $distConfigPath = PathUtil::append($distPath, 'dist.php');

            if (\is_file($distConfigPath)) {
                $distConfig = require $distConfigPath;
                $allow = \array_values(\array_filter(
                    (array) ((\is_array($distConfig) ? $distConfig : [])['route_audit_allow'] ?? []),
                    'is_string',
                ));
            }

            $audit = RouteAudit::run($routes, $foreign, $allow);

            if ([] === $audit['findings']) {
                $this->writeLineLogging('  {@c:green}✓ no catch-all or foreign-absorbing routes found{@reset}'
                    . ($audit['suppressed'] > 0 ? ' (' . $audit['suppressed'] . ' suppressed by route_audit_allow)' : ''), true);
            }

            foreach ($audit['findings'] as $finding) {
                $this->writeLineLogging(\sprintf(
                    '  {@c:yellow}⚠ [%s]{@reset} {@c:white}%s{@reset} "%s"%s',
                    $finding['rule'],
                    $finding['module_code'],
                    $finding['route'],
                    '' !== $finding['prefix'] ? ' ↔ ' . $finding['prefix'] : '',
                ), true);
                $this->writeLineLogging('      ' . $finding['message'], true);
                $this->writeLineLogging(\sprintf(
                    "      allow if intentional: dist.php route_audit_allow[] = '%s:%s'",
                    $finding['module_code'],
                    $finding['route'],
                ), true);
                ++$totalWarnings;
            }

            if ($audit['suppressed'] > 0 && [] !== $audit['findings']) {
                $this->writeLineLogging('  (' . $audit['suppressed'] . ' suppressed by route_audit_allow)', true);
            }
        } catch (Throwable $e) {
            $this->writeLineLogging('  {@c:yellow}⚠ audit skipped: ' . $e->getMessage() . '{@reset}', true);
            ++$totalWarnings;
        }

        $this->writeLineLogging('', true);

        // Summary
        $this->writeLineLogging('{@s:b}Validation Summary{@reset}', true);
        $this->writeLineLogging("Modules validated: {$validatedModules}", true);
        $this->writeLineLogging("Routes validated: {$validatedRoutes}", true);
        $this->writeLineLogging("API commands validated: {$validatedAPICommands}", true);

        if ($totalErrors > 0) {
            $this->writeLineLogging("{@c:red}Errors: {$totalErrors}{@reset}", true);
        } else {
            $this->writeLineLogging('{@c:green}Errors: 0{@reset}', true);
        }

        if ($totalWarnings > 0) {
            $this->writeLineLogging("{@c:yellow}Warnings: {$totalWarnings}{@reset}", true);
        } else {
            $this->writeLineLogging('Warnings: 0', true);
        }

        if ($generateDummies && $generatedFiles > 0) {
            $this->writeLineLogging("{@c:green}Files generated: {$generatedFiles}{@reset}", true);
        }

        exit($totalErrors > 0 ? 1 : 0);
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR] {$e->getMessage()}{@reset}", true);
        if ($verbose) {
            $this->writeLineLogging('{@c:red}Stack trace:{@reset}', true);
            $this->writeLineLogging($e->getTraceAsString(), true);
        }
        exit(1);
    }
};

/**
 * Generate a dummy closure file at the given path.
 *
 * Creates a stub PHP file that returns a closure with a TODO marker,
 * allowing the framework to load without errors while signalling
 * that the handler still needs a real implementation.
 *
 * @param string $path Absolute path where the file will be created
 * @param string $closurePath Logical closure path (used in docblock/handler name)
 */
function generateClosureFile(string $path, string $closurePath): void
{
    $dir = \dirname($path);
    if (!\is_dir($dir)) {
        \mkdir($dir, 0755, true);
    }

    $name = \basename($closurePath);

    $content = <<<PHP
<?php
/**
 * Closure file: {$closurePath}
 * Auto-generated by Razy validate command
 */

return function (...\$args) {
    // TODO: Implement {$name} handler
    return [
        'handler' => '{$name}',
        'status' => 'not_implemented',
        'args' => \$args,
    ];
};
PHP;

    \file_put_contents($path, $content);
}
