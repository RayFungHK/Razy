<?php
/**
 * CLI Command: scaffold
 *
 * Generates a complete module skeleton from a single command, including
 * module.php, package.php, main controller, and index route handler.
 * Reduces the "6 files before anything works" barrier to one command.
 *
 * Usage:
 *   php Razy.phar scaffold <module_code> [options]
 *
 * Arguments:
 *   module_code  The module code (e.g., app/hello, myvendor/blog)
 *
 * Options:
 *   -d <dist>       Target distributor code (creates under sites/<dist>/)
 *   -s              Create as a shared module (under shared/module/)
 *   -n <name>       Module display name (default: derived from module_code)
 *   -a <author>     Author name (default: "Your Name")
 *   -desc <text>    Module description
 *   -v <version>    Module version (default: 1.0.0)
 *   --with-api      Include an API command example
 *   --with-template Include a template view example
 *   --with-event    Include an event listener/emitter example
 *   --full          Generate all optional files (api + template + event)
 *
 * Examples:
 *   php Razy.phar scaffold app/hello -d mysite
 *   php Razy.phar scaffold app/blog -d mysite --full
 *   php Razy.phar scaffold shared/auth -s -n "Auth Module"
 *   php Razy.phar scaffold app/hello -d mysite --with-template --with-api
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;

return function (string $moduleCode = '', ...$args) use (&$parameters) {
    // Helper: write a file and log the result
    $writeFile = function (string $path, string $content): bool {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            $this->writeLineLogging('{@c:red}  [FAIL]{@reset} ' . basename($path) . ' - failed to create directory', true);

            return false;
        }
        if (false !== file_put_contents($path, $content)) {
            $this->writeLineLogging('{@c:green}  [OK]{@reset} ' . basename($path), true);

            return true;
        }
        $this->writeLineLogging('{@c:red}  [FAIL]{@reset} ' . basename($path) . ' - write failed', true);

        return false;
    };

    $this->writeLineLogging('{@s:bu}Module Scaffolding Tool', true);
    $this->writeLineLogging('Generate a complete module skeleton from one command', true);
    $this->writeLineLogging('', true);

    // -- Parse module code -----------------------------------------------
    $moduleCode = trim($moduleCode);
    if (!$moduleCode) {
        $this->writeLineLogging('{@c:yellow}Usage:{@reset} php Razy.phar scaffold <module_code> [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:cyan}Arguments:{@reset}', true);
        $this->writeLineLogging('  module_code    The module code (e.g., app/hello, myvendor/blog)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:cyan}Options:{@reset}', true);
        $this->writeLineLogging('  -d <dist>       Target distributor code (creates under sites/<dist>/)', true);
        $this->writeLineLogging('  -s              Create as a shared module (under shared/module/)', true);
        $this->writeLineLogging('  -n <name>       Module display name', true);
        $this->writeLineLogging('  -a <author>     Author name (default: "Your Name")', true);
        $this->writeLineLogging('  -desc <text>    Module description', true);
        $this->writeLineLogging('  -v <version>    Module version (default: 1.0.0)', true);
        $this->writeLineLogging('  --with-api      Include an API command example', true);
        $this->writeLineLogging('  --with-template Include a template view example', true);
        $this->writeLineLogging('  --with-event    Include an event listener/emitter example', true);
        $this->writeLineLogging('  --full          Generate all optional files (api + template + event)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:cyan}Examples:{@reset}', true);
        $this->writeLineLogging('  php Razy.phar scaffold app/hello -d mysite', true);
        $this->writeLineLogging('  php Razy.phar scaffold app/blog -d mysite --full', true);
        $this->writeLineLogging('  php Razy.phar scaffold shared/auth -s -n "Auth Module"', true);

        return;
    }

    // Validate module code format: must be vendor/name (exactly one slash)
    $moduleCode = trim($moduleCode, '/');
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\/[a-zA-Z_][a-zA-Z0-9_]*$/', $moduleCode)) {
        $this->writeLineLogging('{@c:red}[ERROR]{@reset} Invalid module code: {@c:yellow}' . $moduleCode . '{@reset}', true);
        $this->writeLineLogging('Module code must be in vendor/name format (e.g., app/hello, myvendor/blog)', true);
        $this->writeLineLogging('Only alphanumeric characters and underscores are allowed.', true);

        return;
    }

    // -- Parse options ---------------------------------------------------
    $distCode  = null;
    $isShared  = isset($parameters['s']);
    $withApi   = isset($parameters['with-api']) || isset($parameters['full']);
    $withTpl   = isset($parameters['with-template']) || isset($parameters['full']);
    $withEvent = isset($parameters['with-event']) || isset($parameters['full']);

    // Parse -d <dist> from raw args
    foreach ($args as $i => $arg) {
        if ('-d' === $arg && isset($args[$i + 1])) {
            $distCode = $args[$i + 1];
        }
    }
    // Also check parameters array (from main.php parser)
    if (isset($parameters['d']) && is_string($parameters['d'])) {
        $distCode = $parameters['d'];
    }

    // Module metadata
    $parts          = explode('/', $moduleCode);
    $controllerName = $parts[1]; // Last segment = controller filename
    $moduleName     = $parameters['n'] ?? ucwords(str_replace('_', ' ', $controllerName)) . ' Module';
    $author         = $parameters['a'] ?? 'Your Name';
    $description    = $parameters['desc'] ?? 'A Razy module';
    $version        = $parameters['v'] ?? '1.0.0';

    // -- Determine target base path --------------------------------------
    if ($isShared) {
        $basePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module');
        $location = 'shared/module/' . $moduleCode;
    } elseif ($distCode) {
        $basePath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
        if (!is_dir($basePath)) {
            $this->writeLineLogging('{@c:red}[ERROR]{@reset} Distributor folder not found: {@c:yellow}' . $distCode . '{@reset}', true);
            $this->writeLineLogging('Create it first with: php Razy.phar set localhost/' . $distCode . ' ' . $distCode . ' -i', true);

            return;
        }
        $location = 'sites/' . $distCode . '/' . $moduleCode;
    } else {
        // Interactive: ask user where to place it
        $this->writeLineLogging('{@c:yellow}Where should this module be created?{@reset}', true);
        $this->writeLineLogging('  1) In a distributor (sites/<dist>/)', true);
        $this->writeLineLogging('  2) As a shared module (shared/module/)', true);
        Terminal::WriteLine('Choose [1/2]: ');
        $choice = trim(Terminal::read());

        if ('2' === $choice) {
            $isShared = true;
            $basePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module');
            $location = 'shared/module/' . $moduleCode;
        } else {
            Terminal::WriteLine('Distributor code: ');
            $distCode = trim(Terminal::read());
            if (!$distCode) {
                $this->writeLineLogging('{@c:red}[ERROR]{@reset} Distributor code is required.', true);

                return;
            }
            $basePath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
            if (!is_dir($basePath)) {
                $this->writeLineLogging('{@c:red}[ERROR]{@reset} Distributor folder not found: {@c:yellow}' . $distCode . '{@reset}', true);

                return;
            }
            $location = 'sites/' . $distCode . '/' . $moduleCode;
        }
    }

    // Module directory structure
    $moduleDir     = PathUtil::append($basePath, ...$parts);
    $versionDir    = PathUtil::append($moduleDir, 'default');
    $controllerDir = PathUtil::append($versionDir, 'controller');

    // Check if module already exists
    if (is_dir($moduleDir)) {
        $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Module directory already exists: {@c:cyan}' . $location . '{@reset}', true);
        Terminal::WriteLine('Overwrite? [y/N]: ');
        $confirm = strtolower(trim(Terminal::read()));
        if ('y' !== $confirm) {
            $this->writeLineLogging('Scaffold cancelled.', true);

            return;
        }
    }

    // -- Create directories ----------------------------------------------
    $this->writeLineLogging('{@c:cyan}Creating module:{@reset} ' . $moduleCode, true);
    $this->writeLineLogging('{@c:cyan}Location:{@reset}       ' . $location, true);
    $this->writeLineLogging('', true);

    $dirs = [$controllerDir];
    if ($withTpl) {
        $dirs[] = PathUtil::append($versionDir, 'view');
    }
    if ($withApi) {
        $dirs[] = PathUtil::append($controllerDir, 'api');
    }

    foreach ($dirs as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            $this->writeLineLogging('{@c:red}[ERROR]{@reset} Failed to create directory: ' . $dir, true);

            return;
        }
    }

    // -- Generate files from templates -----------------------------------
    $filesCreated = 0;
    $namespace    = str_replace('/', '_', $moduleCode);
    $tplDir       = PathUtil::append(PHAR_PATH, 'asset', 'setup', 'scaffold');

    // Common scaffold variables for Template Engine {$var} replacement
    $assigns = [
        'module_code'     => $moduleCode,
        'module_name'     => $moduleName,
        'author'          => $author,
        'description'     => $description,
        'version'         => $version,
        'namespace'       => $namespace,
        'controller_name' => $controllerName,
    ];

    // Helper: load a scaffold template via Template::loadFile() and render it
    $renderTemplate = function (string $filename) use ($tplDir, $assigns): string {
        $source = Template::loadFile(PathUtil::append($tplDir, $filename));
        $root   = $source->getRoot();
        $root->assign($assigns);

        return $source->output();
    };

    // 1) module.php
    if ($writeFile(PathUtil::append($moduleDir, 'module.php'), $renderTemplate('module.php.tpl'))) {
        $filesCreated++;
    }

    // 2) package.php
    if ($writeFile(PathUtil::append($versionDir, 'package.php'), $renderTemplate('package.php.tpl'))) {
        $filesCreated++;
    }

    // 3) Main controller -- activate optional blocks
    $source = Template::loadFile(PathUtil::append($tplDir, 'controller.php.tpl'));
    $root   = $source->getRoot();
    $root->assign($assigns);

    if ($withApi) {
        $root->newBlock('api_section')->assign($assigns);
    }
    if ($withEvent) {
        $root->newBlock('event_section')->assign($assigns);
    }

    if ($writeFile(PathUtil::append($controllerDir, $controllerName . '.php'), $source->output())) {
        $filesCreated++;
    }

    // 4) Index route handler
    $handlerTemplate = $withTpl ? 'handler.index.tpl.php.tpl' : 'handler.index.php.tpl';
    if ($writeFile(PathUtil::append($controllerDir, $controllerName . '.index.php'), $renderTemplate($handlerTemplate))) {
        $filesCreated++;
    }

    // 5) Optional: Template view file (raw copy -- contains Razy runtime {$var} syntax)
    if ($withTpl) {
        $viewContent = file_get_contents(PathUtil::append($tplDir, 'view.index.tpl'));
        if ($writeFile(PathUtil::append($versionDir, 'view', 'index.tpl'), $viewContent)) {
            $filesCreated++;
        }
    }

    // 6) Optional: API command handler
    if ($withApi) {
        if ($writeFile(PathUtil::append($controllerDir, 'api', $controllerName . '.hello.php'), $renderTemplate('api.hello.php.tpl'))) {
            $filesCreated++;
        }
    }

    // -- Summary ---------------------------------------------------------
    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@c:green}Scaffold complete!{@reset} Created {@c:yellow}' . $filesCreated . '{@reset} files.', true);
    $this->writeLineLogging('', true);

    // Show the generated tree
    $this->writeLineLogging('{@c:cyan}Generated structure:{@reset}', true);
    $this->writeLineLogging('  ' . $moduleCode . '/', true);
    $this->writeLineLogging('  +-- module.php', true);
    $this->writeLineLogging('  +-- default/', true);
    $this->writeLineLogging('      +-- package.php', true);
    if ($withTpl) {
        $this->writeLineLogging('      +-- view/', true);
        $this->writeLineLogging('      |   +-- index.tpl', true);
    }
    $this->writeLineLogging('      +-- controller/', true);
    $this->writeLineLogging('          +-- ' . $controllerName . '.php', true);
    $this->writeLineLogging('          +-- ' . $controllerName . '.index.php', true);
    if ($withApi) {
        $this->writeLineLogging('          +-- api/', true);
        $this->writeLineLogging('              +-- ' . $controllerName . '.hello.php', true);
    }
    $this->writeLineLogging('', true);

    // Show next steps
    $this->writeLineLogging('{@c:cyan}Next steps:{@reset}', true);
    if ($distCode) {
        $this->writeLineLogging('  1. Test it: {@c:green}php Razy.phar runapp ' . $distCode . '{@reset}', true);
        $this->writeLineLogging('     Then type: {@c:yellow}run /' . $controllerName . '/{@reset}', true);
    } elseif ($isShared) {
        $this->writeLineLogging('  1. Add this module to a distributor\'s dist.php, then test with runapp', true);
    }
    $this->writeLineLogging('  2. Edit {@c:yellow}controller/' . $controllerName . '.php{@reset} to add more routes', true);
    $this->writeLineLogging('  3. Edit {@c:yellow}controller/' . $controllerName . '.index.php{@reset} to customize the page', true);
    if ($withTpl) {
        $this->writeLineLogging('  4. Edit {@c:yellow}view/index.tpl{@reset} to customize the template', true);
    }
    $this->writeLineLogging('', true);
    $this->writeLineLogging('For more info: {@c:blue}https://github.com/RayFungHK/Razy/wiki/Getting-Started-Tutorial{@reset}', true);
};
