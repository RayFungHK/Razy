<?php
/**
 * CLI Command: standalone
 *
 * Scaffolds a standalone (lite-mode) application structure. Creates the
 * ultra-flat standalone/ folder with a main controller and index template —
 * no module.php, no package.php, no distributor config, no domain restrictions.
 *
 * Standalone mode is the DEFAULT for new Razy projects. It activates
 * automatically when standalone/ exists and multisite is not enabled.
 * To enable multisite, set 'multiple_site' => true in config.inc.php
 * or set the RAZY_MULTIPLE_SITE=true environment variable.
 *
 * Usage:
 *   php Razy.phar standalone [options]
 *
 * Options:
 *   -f <path>     Target directory (default: current directory)
 *   --force       Overwrite existing standalone/ folder
 *
 * Examples:
 *   php Razy.phar standalone
 *   php Razy.phar standalone -f /var/www/myapp
 *   php Razy.phar standalone --force
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;

return function (string ...$args) use (&$parameters) {
    // Helper: write a file and log the result
    $writeFile = function (string $path, string $content): bool {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            $this->writeLineLogging('{@c:red}  [FAIL]{@reset} ' . $path . ' - failed to create directory', true);
            return false;
        }
        if (false !== file_put_contents($path, $content)) {
            $this->writeLineLogging('{@c:green}  [OK]{@reset} ' . basename($path), true);
            return true;
        }
        $this->writeLineLogging('{@c:red}  [FAIL]{@reset} ' . $path . ' - write failed', true);
        return false;
    };

    $this->writeLineLogging('{@s:bu}Razy Standalone Setup', true);
    $this->writeLineLogging('Create an ultra-flat standalone application', true);
    $this->writeLineLogging('', true);

    // Determine target directory
    $targetPath = defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT;
    $standalonePath = PathUtil::append($targetPath, 'standalone');
    $force = isset($parameters['force']) || isset($parameters['-force']);

    // Check if standalone/ already exists
    if (is_dir($standalonePath) && !$force) {
        $this->writeLineLogging('{@c:yellow}[WARN]{@reset} standalone/ folder already exists at:', true);
        $this->writeLineLogging('  ' . $standalonePath, true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Use --force to overwrite existing files.', true);
        return;
    }

    // Check if multisite is enabled — warn if so
    $configPath = PathUtil::append($targetPath, 'config.inc.php');
    $configData = is_file($configPath) ? (require $configPath) : [];
    if (!empty($configData['multiple_site']) || getenv('RAZY_MULTIPLE_SITE') === 'true') {
        $this->writeLineLogging('{@c:yellow}[NOTE]{@reset} Multisite is enabled — standalone mode will NOT activate.', true);
        $this->writeLineLogging('  Set \'multiple_site\' => false in config.inc.php to enable standalone mode.', true);
        $this->writeLineLogging('', true);
    }

    $this->writeLineLogging('{@c:cyan}Creating standalone structure...{@reset}', true);
    $this->writeLineLogging('  Target: ' . $standalonePath, true);
    $this->writeLineLogging('', true);

    // Read template files from phar asset
    $assetPath = PathUtil::append(PHAR_PATH, 'asset', 'standalone');

    $files = [
        'controller/app.php' => PathUtil::append($assetPath, 'controller', 'app.php'),
        'controller/app.index.php' => PathUtil::append($assetPath, 'controller', 'app.index.php'),
        'view/index.tpl' => PathUtil::append($assetPath, 'view', 'index.tpl'),
    ];

    $created = 0;
    foreach ($files as $relativePath => $sourceFile) {
        $destFile = PathUtil::append($standalonePath, $relativePath);
        if (is_file($sourceFile)) {
            $content = file_get_contents($sourceFile);
        } else {
            // Fallback: generate content inline if asset is missing
            $content = match ($relativePath) {
                'controller/app.php' => $this->generateController(),
                'controller/app.index.php' => $this->generateIndexHandler(),
                'view/index.tpl' => $this->generateIndexTemplate(),
                default => '',
            };
        }

        if ($writeFile($destFile, $content)) {
            $created++;
        }
    }

    $this->writeLineLogging('', true);

    if ($created === count($files)) {
        $this->writeLineLogging('{@c:green}Standalone app created successfully!{@reset}', true);
    } else {
        $this->writeLineLogging('{@c:yellow}Standalone app created with some warnings.{@reset}', true);
    }

    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@c:cyan}Structure:{@reset}', true);
    $this->writeLineLogging('  standalone/', true);
    $this->writeLineLogging('    controller/', true);
    $this->writeLineLogging('      app.php           # Main controller (routes)', true);
    $this->writeLineLogging('      app.index.php     # Index route handler', true);
    $this->writeLineLogging('    view/', true);
    $this->writeLineLogging('      index.tpl         # Index page template', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@c:cyan}Next steps:{@reset}', true);
    $this->writeLineLogging('  1. Edit controller/app.php to add routes', true);
    $this->writeLineLogging('  2. Add route handlers as controller/app.{route}.php', true);
    $this->writeLineLogging('  3. Add templates in view/', true);
    $this->writeLineLogging('  4. Start your server — standalone mode activates automatically', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@c:yellow}Tip:{@reset} To switch to multisite, set \'multiple_site\' => true in config.inc.php', true);
};
