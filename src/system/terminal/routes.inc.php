<?php
/**
 * CLI Command: routes
 *
 * Lists all registered routes, closures, and optionally API commands
 * for a given distributor. Supports filtering by module, displaying
 * regex patterns, and showing API commands alongside regular routes.
 *
 * Usage:
 *   php Razy.phar routes <distributor_code> [module_code] [options]
 *
 * Arguments:
 *   distributor_code  Code of the distributor to inspect
 *   module_code       Optional: filter by module code
 *
 * Options:
 *   -v, --verbose        Show detailed route information
 *   -r, --regex          Show generated regex pattern for each route
 *   -a, --api            Show API commands and their closures
 *   --domain=<name>      Domain tag to use (default: *)
 *   --module=<code>      Filter by module code
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;
return function (string $distCode = '', ...$args) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Route List', true);
    $this->writeLineLogging('List all registered routes and closures', true);
    $this->writeLineLogging('', true);

    // Parse options
    $verbose = false;
    $domain = '*';
    $moduleFilter = '';
    $showRegex = false;
    $showAPI = false;

    foreach ($args as $arg) {
        if ($arg === '--verbose' || $arg === '-v') {
            $verbose = true;
        } elseif ($arg === '--regex' || $arg === '-r') {
            $showRegex = true;
        } elseif ($arg === '--api' || $arg === '-a') {
            $showAPI = true;
        } elseif (str_starts_with($arg, '--domain=')) {
            $domain = substr($arg, 9);
        } elseif (str_starts_with($arg, '--module=')) {
            $moduleFilter = substr($arg, 9);
        } elseif (!str_starts_with($arg, '-') && !$moduleFilter) {
            $moduleFilter = $arg;
        }
    }

    // Validate required parameters
    $distCode = trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:red}[ERROR] Distributor code is required.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar routes <distributor_code> [module_code] [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Arguments:', true);
        $this->writeLineLogging('  {@c:green}distributor_code{@reset}     Code of the distributor', true);
        $this->writeLineLogging('  {@c:green}module_code{@reset}          Optional: filter by module (e.g., demo/route_demo)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  {@c:green}-v, --verbose{@reset}        Show detailed route information', true);
        $this->writeLineLogging('  {@c:green}-r, --regex{@reset}          Show generated regex pattern for each route', true);
        $this->writeLineLogging('  {@c:green}-a, --api{@reset}            Show API commands and their closures', true);
        $this->writeLineLogging('  {@c:green}--domain=<name>{@reset}      Domain tag to use (default: *)', true);
        $this->writeLineLogging('  {@c:green}--module=<code>{@reset}      Filter by module code', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  {@c:cyan}# List all routes{@reset}', true);
        $this->writeLineLogging('  php Razy.phar routes mysite', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# List routes for specific module{@reset}', true);
        $this->writeLineLogging('  php Razy.phar routes mysite demo/route_demo', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Show regex patterns and API commands{@reset}', true);
        $this->writeLineLogging('  php Razy.phar routes mysite -r -a', true);
        $this->writeLineLogging('', true);

        exit(1);
    }

    try {
        // Verify the distributor directory exists
        $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
        if (!is_dir($distPath)) {
            $this->writeLineLogging("{@c:red}[ERROR] Distributor folder not found: {$distPath}{@reset}", true);
            exit(1);
        }

        $this->writeLineLogging("{@c:cyan}Distributor:{@reset} {$distCode}", true);
        $this->writeLineLogging("{@c:cyan}Domain:{@reset} {$domain}", true);
        if ($moduleFilter) {
            $this->writeLineLogging("{@c:cyan}Module filter:{@reset} {$moduleFilter}", true);
        }
        $this->writeLineLogging('', true);

        // Initialize the distributor to trigger module loading
        $this->writeLineLogging("{@c:yellow}Loading modules...{@reset}", true);
        
        $distributor = new Distributor($distCode, $domain);
        $distributor->initialize();
        
        $this->writeLineLogging("{@c:green}Modules loaded{@reset}", true);
        $this->writeLineLogging('', true);

        // Retrieve all registered routes and loaded module info
        $routes = $distributor->getRouter()->getRoutes();
        $modulesInfo = $distributor->getRegistry()->getLoadedModulesInfo();
        
        // Group routes by module code for organized display
        $routesByModule = [];
        foreach ($routes as $routePath => $routeInfo) {
            /** @var Module $module */
            $module = $routeInfo['module'];
            $moduleCode = $module->getModuleInfo()->getCode();
            
            // Apply module filter
            if ($moduleFilter && $moduleCode !== $moduleFilter) {
                continue;
            }
            
            if (!isset($routesByModule[$moduleCode])) {
                $routesByModule[$moduleCode] = [
                    'module' => $module,
                    'routes' => [],
                    'api' => [],
                ];
            }
            $routesByModule[$moduleCode]['routes'][$routePath] = $routeInfo;
        }
        
        // Collect API commands for each module
        foreach ($modulesInfo as $code => $info) {
            if ($moduleFilter && $code !== $moduleFilter) {
                continue;
            }
            
            /** @var Module $module */
            $module = $info['module'];
            $apiCommands = $module->getAPICommands();
            
            if (!empty($apiCommands)) {
                if (!isset($routesByModule[$code])) {
                    $routesByModule[$code] = [
                        'module' => $module,
                        'routes' => [],
                        'api' => [],
                    ];
                }
                $routesByModule[$code]['api'] = $apiCommands;
            }
        }

        if (empty($routesByModule)) {
            if ($moduleFilter) {
                $this->writeLineLogging("{@c:yellow}No routes found for module: {$moduleFilter}{@reset}", true);
            } else {
                $this->writeLineLogging("{@c:yellow}No routes registered{@reset}", true);
            }
            exit(0);
        }

        // Display routes
        $totalRoutes = 0;
        $totalAPI = 0;
        foreach ($routesByModule as $code => $data) {
            /** @var Module $module */
            $module = $data['module'];
            $moduleInfo = $module->getModuleInfo();
            
            $this->writeLineLogging("{@s:b}Module: {$code}{@reset}", true);
            $this->writeLineLogging("  {@c:cyan}Alias:{@reset} {$moduleInfo->getAlias()}", true);
            
            // Sort routes by path
            ksort($data['routes']);
            
            foreach ($data['routes'] as $routePath => $routeInfo) {
                $totalRoutes++;
                $closurePath = $routeInfo['path'];
                $routeType = $routeInfo['type'] ?? 'standard';
                $typeLabel = match($routeType) {
                    'lazy' => '{@c:blue}[lazy]{@reset}',
                    'standard' => '{@c:green}[std]{@reset}',
                    'script' => '{@c:magenta}[cli]{@reset}',
                    default => "[{$routeType}]",
                };
                
                // Get closure path string
                $closureStr = ($closurePath instanceof Route) 
                    ? '{@c:yellow}<inline handler>{@reset}'
                    : $closurePath;
                
                // Check if closure exists
                if (!($closurePath instanceof Route)) {
                    $closure = $module->getClosure($closurePath);
                    if ($closure === null) {
                        $closureStr = "{@c:red}{$closurePath} (MISSING){@reset}";
                    }
                }
                
                $this->writeLineLogging("  {$typeLabel} {$routePath}", true);
                $this->writeLineLogging("       → {$closureStr}", true);
                
                if ($showRegex && $routeType === 'standard') {
                    // Generate regex like Distributor does
                    $regex = preg_replace_callback('/\\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|(\[[^\\[\\]]+]))({\d+,?\d*})?/', function ($matches) {
                        $r = (strlen($matches[2] ?? '')) > 0 ? $matches[2] : (('a' === $matches[1]) ? '[^/]' : '\\' . $matches[1]);
                        return $r . ((0 !== strlen($matches[3] ?? '')) ? $matches[3] : '+');
                    }, $routePath);
                    $regex = '/^(' . preg_replace('/\\\\.(*SKIP)(*FAIL)|\//', '\\/', $regex) . ')((?:.+)?)/';
                    $this->writeLineLogging("       {@c:dim}Regex: {$regex}{@reset}", true);
                }
                
                if ($verbose && isset($routeInfo['target'])) {
                    /** @var Module $target */
                    $target = $routeInfo['target'];
                    $this->writeLineLogging("       {@c:dim}Shadow route to: {$target->getModuleInfo()->getCode()}{@reset}", true);
                }
            }
            
            // Show API commands if --api flag is set
            if ($showAPI && !empty($data['api'])) {
                $this->writeLineLogging("  {@c:magenta}API Commands:{@reset}", true);
                ksort($data['api']);
                
                foreach ($data['api'] as $command => $closurePath) {
                    $totalAPI++;
                    
                    // Check if closure exists
                    $closure = $module->getClosure($closurePath);
                    $closureStr = ($closure === null) 
                        ? "{@c:red}{$closurePath} (MISSING){@reset}"
                        : $closurePath;
                    
                    $this->writeLineLogging("  {@c:magenta}[api]{@reset} {$command}", true);
                    $this->writeLineLogging("       → {$closureStr}", true);
                }
            }
            
            $this->writeLineLogging('', true);
        }

        // Summary
        $this->writeLineLogging('{@s:b}Summary{@reset}', true);
        $this->writeLineLogging("Total modules: " . count($routesByModule), true);
        $this->writeLineLogging("Total routes: {$totalRoutes}", true);
        if ($showAPI) {
            $this->writeLineLogging("Total API commands: {$totalAPI}", true);
        }

        exit(0);

    } catch (\Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR] {$e->getMessage()}{@reset}", true);
        if ($verbose) {
            $this->writeLineLogging("{@c:red}Stack trace:{@reset}", true);
            $this->writeLineLogging($e->getTraceAsString(), true);
        }
        exit(1);
    }
};
