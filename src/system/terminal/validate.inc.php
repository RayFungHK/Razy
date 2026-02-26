<?php
/**
 * CLI Command: validate
 *
 * Validates that all route and API closure files exist for modules in a
 * given distributor. The command initializes the distributor, inspects
 * registered routes and API commands, and checks that each referenced
 * closure file is loadable. Optionally generates stub files for missing closures.
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
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;
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
        } elseif (str_starts_with($arg, '--domain=')) {
            $domain = substr($arg, 9);
        } elseif (!str_starts_with($arg, '-') && !$moduleCode) {
            // First non-option argument after distCode is moduleCode
            $moduleCode = $arg;
        }
    }

    // Validate required parameters
    $distCode = trim($distCode);
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
        if (!is_dir($distPath)) {
            $this->writeLineLogging("{@c:red}[ERROR] Distributor folder not found: {$distPath}{@reset}", true);
            exit(1);
        }

        $this->writeLineLogging("{@c:cyan}Distributor:{@reset} {$distCode}", true);
        $this->writeLineLogging("{@c:cyan}Domain:{@reset} {$domain}", true);
        $this->writeLineLogging("{@c:cyan}Path:{@reset} {$distPath}", true);
        $this->writeLineLogging('', true);

        // Instantiate the distributor with the given code and domain tag
        $this->writeLineLogging("{@c:yellow}Initializing modules...{@reset}", true);
        
        $distributor = new Distributor($distCode, $domain);
        // Trigger full lifecycle (__onInit, __onLoad, __onRequire) to register routes
        $distributor->initialize();
        
        $this->writeLineLogging("{@c:green}Modules initialized successfully{@reset}", true);
        $this->writeLineLogging('', true);

        // Retrieve all routes and module metadata registered during initialization
        $routes = $distributor->getRouter()->getRoutes();
        $modulesInfo = $distributor->getRegistry()->getLoadedModulesInfo();
        
        if ($verbose) {
            $this->writeLineLogging("{@c:cyan}Total routes found:{@reset} " . count($routes), true);
            $this->writeLineLogging("{@c:cyan}Total modules loaded:{@reset} " . count($modulesInfo), true);
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
            
            // Get routes and API commands for this module
            $moduleRoutes = $routesByModule[$code]['routes'] ?? [];
            $apiCommands = $module->getAPICommands();
            
            $this->writeLineLogging("  Routes: " . count($moduleRoutes) . ", API Commands: " . count($apiCommands), true);
            
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
                        $this->writeLineLogging("    {@c:green}✓ Route object (inline handler){@reset}", true);
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

        // Summary
        $this->writeLineLogging('{@s:b}Validation Summary{@reset}', true);
        $this->writeLineLogging("Modules validated: {$validatedModules}", true);
        $this->writeLineLogging("Routes validated: {$validatedRoutes}", true);
        $this->writeLineLogging("API commands validated: {$validatedAPICommands}", true);
        
        if ($totalErrors > 0) {
            $this->writeLineLogging("{@c:red}Errors: {$totalErrors}{@reset}", true);
        } else {
            $this->writeLineLogging("{@c:green}Errors: 0{@reset}", true);
        }
        
        if ($totalWarnings > 0) {
            $this->writeLineLogging("{@c:yellow}Warnings: {$totalWarnings}{@reset}", true);
        } else {
            $this->writeLineLogging("Warnings: 0", true);
        }
        
        if ($generateDummies && $generatedFiles > 0) {
            $this->writeLineLogging("{@c:green}Files generated: {$generatedFiles}{@reset}", true);
        }

        exit($totalErrors > 0 ? 1 : 0);

    } catch (\Throwable $e) {
        $this->writeLineLogging("{@c:red}[ERROR] {$e->getMessage()}{@reset}", true);
        if ($verbose) {
            $this->writeLineLogging("{@c:red}Stack trace:{@reset}", true);
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
 * @param string $path        Absolute path where the file will be created
 * @param string $closurePath Logical closure path (used in docblock/handler name)
 */
function generateClosureFile(string $path, string $closurePath): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $name = basename($closurePath);
    
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

    file_put_contents($path, $content);
}
