<?php
/**
 * CLI Command: runapp
 *
 * Launches an interactive shell (REPL) for a distributor, bypassing the
 * normal sites.inc.php configuration. Allows developers to directly interact
 * with a distributor's modules, routes, and API commands from the terminal.
 *
 * Usage:
 *   php Razy.phar runapp <dist_code[@tag]>
 *
 * Arguments:
 *   dist_code  The distributor code (folder name in sites/)
 *   @tag       Optional tag (e.g., @dev, @1.0.0, defaults to *)
 *
 * Shell Commands:
 *   help       Show available commands
 *   routes     List all registered routes
 *   modules    List loaded modules
 *   api        List API modules
 *   run <path> Execute a route (e.g., run /demo/hello)
 *   call <api> <cmd>  Call an API command
 *   info       Show distributor info
 *   clear      Clear screen
 *   exit       Exit the shell
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;
return function (string $distIdentifier = '') {
    // Display usage information if no distributor identifier was provided
    if (!$distIdentifier) {
        $this->writeLineLogging('{@c:cyan}Razy App Container{@reset} - Interactive Shell');
        $this->writeLineLogging('');
        $this->writeLineLogging('{@c:yellow}Usage:{@reset}');
        $this->writeLineLogging('  php Razy.phar runapp <dist_code[@tag]> [options]', true);
        $this->writeLineLogging('');
        $this->writeLineLogging('{@c:yellow}Arguments:{@reset}');
        $this->writeLineLogging('  {@c:green}dist_code{@reset}     The distributor code (folder name in sites/)', true);
        $this->writeLineLogging('  {@c:green}@tag{@reset}          Optional tag (e.g., @dev, @1.0.0, defaults to *)', true);
        $this->writeLineLogging('');
        $this->writeLineLogging('{@c:yellow}Examples:{@reset}');
        $this->writeLineLogging('  php Razy.phar runapp mysite', true);
        $this->writeLineLogging('  php Razy.phar runapp mysite@dev', true);
        $this->writeLineLogging('  php Razy.phar runapp testsite@1.0.0', true);
        $this->writeLineLogging('');
        $this->writeLineLogging('{@c:yellow}Shell Commands:{@reset}');
        $this->writeLineLogging('  {@c:green}help{@reset}          Show available commands', true);
        $this->writeLineLogging('  {@c:green}routes{@reset}        List all registered routes', true);
        $this->writeLineLogging('  {@c:green}modules{@reset}       List loaded modules', true);
        $this->writeLineLogging('  {@c:green}api{@reset}           List API modules', true);
        $this->writeLineLogging('  {@c:green}run <path>{@reset}    Execute a route (e.g., run /demo/hello)', true);
        $this->writeLineLogging('  {@c:green}exit{@reset}          Exit the shell', true);
        return false;
    }

    // Split the identifier into distributor code and optional tag (e.g., mysite@dev)
    [$distCode, $tag] = explode('@', $distIdentifier . '@', 2);
    $tag = $tag ?: '*';

    // Validate the distributor code format (alphanumeric with hyphens/underscores)
    if (!preg_match('/^[a-z][\w\-]*$/i', $distCode)) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid distributor code format: ' . $distCode, true);
        return false;
    }

    // Verify the distributor folder exists in the sites directory
    $distPath = PathUtil::append(SITES_FOLDER, $distCode);
    if (!is_dir($distPath)) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Distributor folder not found: ' . $distPath, true);
        $this->writeLineLogging('Available distributors:', true);
        
        // List available distributors
        $sitesFolder = SITES_FOLDER;
        if (is_dir($sitesFolder)) {
            $dirs = scandir($sitesFolder);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                if (is_dir(PathUtil::append($sitesFolder, $dir)) && is_file(PathUtil::append($sitesFolder, $dir, 'dist.php'))) {
                    $this->writeLineLogging('  {@c:green}' . $dir . '{@reset}', true);
                }
            }
        }
        return false;
    }

    // Ensure dist.php exists as the distributor configuration entry point
    if (!is_file(PathUtil::append($distPath, 'dist.php'))) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Missing dist.php in: ' . $distPath, true);
        return false;
    }

    $this->writeLineLogging('{@c:cyan}Razy App Container{@reset}', true);
    $this->writeLineLogging('Initializing distributor: {@c:green}' . $distCode . '{@reset}' . ($tag !== '*' ? ' {@c:yellow}@' . $tag . '{@reset}' : ''), true);

    try {
        // Instantiate and initialize the distributor directly (bypasses sites.inc.php)
        $distributor = new Distributor($distCode, $tag, null, '', '/');
        $distributor->initialize();

        // Define DIST_CODE constant if not already defined
        if (!defined('DIST_CODE')) {
            define('DIST_CODE', $distributor->getCode());
        }

        $loadedModules = $distributor->getRegistry()->getLoadedModulesInfo();
        $routes = $distributor->getRouter()->getRoutes();

        $this->writeLineLogging('{@c:green}[OK]{@reset} Distributor initialized', true);
        $this->writeLineLogging('  Modules loaded: {@c:cyan}' . count($loadedModules) . '{@reset}', true);
        $this->writeLineLogging('  Routes registered: {@c:cyan}' . count($routes) . '{@reset}', true);
        $this->writeLineLogging('');
        $this->writeLineLogging('Type {@c:yellow}help{@reset} for available commands, {@c:yellow}exit{@reset} to quit.', true);
        $this->writeLineLogging('');

    } catch (\Throwable $e) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Failed to initialize distributor: ' . $e->getMessage(), true);
        return false;
    }

    // Build the shell prompt prefix showing distributor and tag
    $promptPrefix = ($tag !== '*') ? $distCode . '@' . $tag : $distCode;

    // Detect interactive vs piped input for graceful EOF handling
    $isInteractive = function_exists('stream_isatty') ? stream_isatty(STDIN) : true;
    $emptyCount = 0;
    $maxEmptyReads = 3; // Allow some empty reads before exiting non-interactive mode

    // Main interactive shell loop: reads commands and dispatches them
    while (true) {
        // Check for end-of-input before reading
        if (feof(STDIN)) {
            break;
        }

        // Display prompt
        echo Terminal::Format('{@c:green}[' . $promptPrefix . ']{@reset}> ');
        
        // Read input - use fgets for better EOF handling
        $input = fgets(STDIN);
        
        if ($input === false || feof(STDIN)) {
            // EOF or error - clean exit
            echo "\n";
            break;
        }

        $input = trim($input);
        
        // Strip UTF-8 BOM that PowerShell may prepend to piped input
        if (str_starts_with($input, "\xEF\xBB\xBF")) {
            $input = substr($input, 3);
        }
        
        if ($input === '') {
            if (!$isInteractive) {
                $emptyCount++;
                if ($emptyCount >= $maxEmptyReads) {
                    break;
                }
            }
            continue;
        }
        
        // Reset empty counter on valid input
        $emptyCount = 0;

        // Parse the user input into command and arguments
        $parts = preg_split('/\s+/', $input, 2);
        $command = strtolower($parts[0]);
        $args = $parts[1] ?? '';

        switch ($command) {
            case 'exit':
            case 'quit':
            case 'q':
                $this->writeLineLogging('{@c:cyan}Goodbye!{@reset}', true);
                return true;

            case 'help':
            case '?':
                $this->writeLineLogging('');
                $this->writeLineLogging('{@c:cyan}Available Commands:{@reset}', true);
                $this->writeLineLogging('  {@c:green}help{@reset}           Show this help', true);
                $this->writeLineLogging('  {@c:green}routes{@reset}         List all registered routes', true);
                $this->writeLineLogging('  {@c:green}modules{@reset}        List loaded modules', true);
                $this->writeLineLogging('  {@c:green}api{@reset}            List API modules', true);
                $this->writeLineLogging('  {@c:green}run <path>{@reset}     Execute a route (e.g., run /demo/hello)', true);
                $this->writeLineLogging('  {@c:green}call <api> <cmd>{@reset}  Call API command', true);
                $this->writeLineLogging('  {@c:green}info{@reset}           Show distributor info', true);
                $this->writeLineLogging('  {@c:green}clear{@reset}          Clear screen', true);
                $this->writeLineLogging('  {@c:green}exit{@reset}           Exit the shell', true);
                $this->writeLineLogging('', true);
                break;

            case 'routes':
                // List all registered routes with their owning module and handler type
                $routes = $distributor->getRouter()->getRoutes();
                $this->writeLineLogging('');
                $this->writeLineLogging('{@c:cyan}Registered Routes ({@c:yellow}' . count($routes) . '{@c:cyan}):{@reset}', true);
                if (empty($routes)) {
                    $this->writeLineLogging('  {@c:darkgray}(no routes registered){@reset}', true);
                } else {
                    foreach ($routes as $route => $info) {
                        $module = $info['module'] ?? null;
                        $moduleCode = $module instanceof \Razy\Module ? $module->getModuleInfo()->getCode() : 'unknown';
                        $path = $info['path'] ?? '';
                        $type = $info['type'] ?? '';
                        $this->writeLineLogging('  {@c:green}' . $route . '{@reset} => {@c:yellow}' . $moduleCode . '{@reset}::' . $path . ' {@c:darkgray}[' . $type . ']{@reset}', true);
                    }
                }
                $this->writeLineLogging('', true);
                break;

            case 'modules':
                // List all loaded modules with version and author metadata
                $modules = $distributor->getRegistry()->getLoadedModulesInfo();
                $this->writeLineLogging('');
                $this->writeLineLogging('{@c:cyan}Loaded Modules ({@c:yellow}' . count($modules) . '{@c:cyan}):{@reset}', true);
                if (empty($modules)) {
                    $this->writeLineLogging('  {@c:darkgray}(no modules loaded){@reset}', true);
                } else {
                    foreach ($modules as $moduleCode => $info) {
                        $version = $info['version'] ?? 'default';
                        $module = $info['module'] ?? null;
                        $author = $module instanceof \Razy\Module ? $module->getModuleInfo()->getAuthor() : '';
                        $authorStr = $author ? ' by {@c:darkgray}' . $author . '{@reset}' : '';
                        $this->writeLineLogging('  {@c:green}' . $moduleCode . '{@reset} ({@c:yellow}' . $version . '{@reset})' . $authorStr, true);
                    }
                }
                $this->writeLineLogging('', true);
                break;

            case 'api':
                // Collect modules that expose an API name and list them
                $apiModules = [];
                $modules = $distributor->getRegistry()->getLoadedModulesInfo();
                foreach ($modules as $moduleCode => $info) {
                    if (!empty($info['api_name'])) {
                        $apiModules[$info['api_name']] = $moduleCode;
                    }
                }
                $this->writeLineLogging('');
                $this->writeLineLogging('{@c:cyan}API Modules ({@c:yellow}' . count($apiModules) . '{@c:cyan}):{@reset}', true);
                if (empty($apiModules)) {
                    $this->writeLineLogging('  {@c:darkgray}(no API modules registered){@reset}', true);
                } else {
                    foreach ($apiModules as $apiName => $moduleCode) {
                        $this->writeLineLogging('  {@c:green}' . $apiName . '{@reset} => {@c:yellow}' . $moduleCode . '{@reset}', true);
                    }
                }
                $this->writeLineLogging('', true);
                break;

            case 'info':
                $this->writeLineLogging('');
                $this->writeLineLogging('{@c:cyan}Distributor Info:{@reset}', true);
                $this->writeLineLogging('  Code: {@c:green}' . $distributor->getCode() . '{@reset}', true);
                $this->writeLineLogging('  Tag: {@c:yellow}' . ($tag === '*' ? 'default' : $tag) . '{@reset}', true);
                $this->writeLineLogging('  Identifier: {@c:cyan}' . $distributor->getIdentifier() . '{@reset}', true);
                $this->writeLineLogging('  Path: ' . $distPath, true);
                $this->writeLineLogging('  Modules: {@c:cyan}' . count($distributor->getRegistry()->getLoadedModulesInfo()) . '{@reset}', true);
                $this->writeLineLogging('  Routes: {@c:cyan}' . count($distributor->getRouter()->getRoutes()) . '{@reset}', true);
                $this->writeLineLogging('', true);
                break;

            case 'run':
                // Execute a route path inside the distributor (e.g., run /demo/hello)
                if (!$args) {
                    $this->writeLineLogging('{@c:red}[Error]{@reset} Usage: run <path>', true);
                    $this->writeLineLogging('  Example: run /demo/hello', true);
                    break;
                }
                
                $urlQuery = '/' . ltrim($args, '/');
                $this->writeLineLogging('{@c:cyan}Executing:{@reset} ' . $urlQuery, true);
                $this->writeLineLogging('', true);
                
                try {
                    // Capture output
                    ob_start();
                    
                    // Match and execute route
                    if ($distributor->matchRoute()) {
                        $result = $distributor->execute();
                    } else {
                        echo 'No route matched for: ' . $urlQuery;
                    }
                    
                    $output = ob_get_clean();
                    
                    if ($output) {
                        echo $output;
                        echo PHP_EOL;
                    }
                } catch (\Throwable $e) {
                    ob_end_clean();
                    $this->writeLineLogging('{@c:red}[Error]{@reset} ' . $e->getMessage(), true);
                }
                $this->writeLineLogging('', true);
                break;

            case 'call':
                // Invoke an API command on a loaded module (e.g., call vendor/module getData)
                $callParts = preg_split('/\s+/', $args, 2);
                $apiModule = $callParts[0] ?? '';
                $apiCommand = $callParts[1] ?? '';
                
                if (!$apiModule || !$apiCommand) {
                    $this->writeLineLogging('{@c:red}[Error]{@reset} Usage: call <module> <command> [args...]', true);
                    $this->writeLineLogging('  Example: call vendor/module getData', true);
                    break;
                }
                
                $this->writeLineLogging('{@c:cyan}Calling API:{@reset} ' . $apiModule . '->' . $apiCommand . '()', true);
                
                try {
                    $module = $distributor->getRegistry()->getLoadedAPIModule($apiModule);
                    if (!$module) {
                        $module = $distributor->getRegistry()->getLoadedModule($apiModule);
                    }
                    
                    if ($module) {
                        // Parse additional arguments as JSON if provided after command name
                        $cmdParts = preg_split('/\s+/', $apiCommand, 2);
                        $cmdName = $cmdParts[0];
                        $cmdArgs = [];
                        if (!empty($cmdParts[1])) {
                            $decoded = json_decode($cmdParts[1], true);
                            $cmdArgs = is_array($decoded) ? $decoded : [$cmdParts[1]];
                        }
                        
                        $result = $module->execute($module->getModuleInfo(), $cmdName, $cmdArgs);
                        
                        if ($result !== null) {
                            $this->writeLineLogging('{@c:green}Result:{@reset}', true);
                            if (is_array($result) || is_object($result)) {
                                echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                            } else {
                                echo $result . PHP_EOL;
                            }
                        } else {
                            $this->writeLineLogging('{@c:yellow}(null result){@reset}', true);
                        }
                    } else {
                        $this->writeLineLogging('{@c:red}[Error]{@reset} Module not found: ' . $apiModule, true);
                    }
                } catch (\Throwable $e) {
                    $this->writeLineLogging('{@c:red}[Error]{@reset} ' . $e->getMessage(), true);
                }
                $this->writeLineLogging('', true);
                break;

            case 'clear':
            case 'cls':
                // Clear screen (works on most terminals)
                echo "\033[2J\033[H";
                break;

            default:
                // Try to execute as a route path directly
                if (str_starts_with($command, '/')) {
                    $urlQuery = $command . ($args ? ' ' . $args : '');
                    $this->writeLineLogging('{@c:cyan}Executing:{@reset} ' . $urlQuery, true);
                    
                    try {
                        // Create new distributor with the URL query
                        $execDist = new Distributor($distCode, $tag, null, '', $urlQuery);
                        $execDist->initialize();
                        
                        ob_start();
                        if ($execDist->matchRoute()) {
                            $execDist->execute();
                        } else {
                            echo 'No route matched for: ' . $urlQuery;
                        }
                        $output = ob_get_clean();
                        
                        if ($output) {
                            echo $output . PHP_EOL;
                        }
                    } catch (\Throwable $e) {
                        ob_end_clean();
                        $this->writeLineLogging('{@c:red}[Error]{@reset} ' . $e->getMessage(), true);
                    }
                } else {
                    $this->writeLineLogging('{@c:red}[Error]{@reset} Unknown command: ' . $command, true);
                    $this->writeLineLogging('Type {@c:yellow}help{@reset} for available commands.', true);
                }
                break;
        }
    }

    return true;
};
