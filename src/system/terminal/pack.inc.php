<?php

/**
 * CLI Command: pack.
 *
 * Packages a module as a .phar archive for distribution. Creates a compressed
 * .phar file containing the module's source files, optionally including assets.
 * Also generates manifest.json and latest.json metadata files for repository publishing.
 *
 * Usage:
 *   php Razy.phar pack <module_code> <version> [output_path] [options]
 *
 * Arguments:
 *   module_code   Module code (vendor/module or dist@vendor/module)
 *   version       Version to package (e.g., 1.0.0)
 *   output_path   Output directory (default: ./packages/)
 *
 * Options:
 *   --no-compress    Skip GZIP compression
 *   --no-assets      Exclude webassets folder
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Util\PathUtil;

return function (string $moduleCode = '', string $version = '', string $outputPath = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Module Packager', true);
    $this->writeLineLogging('Package modules as .phar files for distribution', true);
    $this->writeLineLogging('', true);

    // Parse options
    $noCompress = false;
    $includeAssets = true;

    foreach ($options as $option) {
        if ($option === '--no-compress') {
            $noCompress = true;
        } elseif ($option === '--no-assets') {
            $includeAssets = false;
        }
    }

    // Validate module code
    $moduleCode = \trim($moduleCode);
    if (!$moduleCode) {
        $this->writeLineLogging('{@c:red}[ERROR] Module code is required.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar pack <module_code> <version> [output_path] [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Arguments:', true);
        $this->writeLineLogging('  {@c:green}module_code{@reset}   Module code (vendor/module or dist@vendor/module)', true);
        $this->writeLineLogging('  {@c:green}version{@reset}       Version to package (e.g., 1.0.0)', true);
        $this->writeLineLogging('  {@c:green}output_path{@reset}   Output directory (default: ./packages/)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  {@c:green}--no-compress{@reset}    Skip GZIP compression', true);
        $this->writeLineLogging('  {@c:green}--no-assets{@reset}      Exclude webassets folder', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  {@c:cyan}# Pack shared module{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pack vendor/module 1.0.0', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Pack distributor module{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pack mysite@vendor/module 1.0.0', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Pack to specific directory{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pack vendor/module 1.0.0 ./releases/', true);
        $this->writeLineLogging('', true);

        exit(1);
    }

    // Ensure phar.readonly is disabled; phar creation requires write access
    if (\ini_get('phar.readonly') == 1) {
        $this->writeLineLogging('{@c:red}[ERROR] Cannot create .phar files.{@reset}', true);
        $this->writeLineLogging('{@c:red}        Set phar.readonly=0 in php.ini or use -d phar.readonly=0{@reset}', true);
        exit(1);
    }

    // Extract optional distributor prefix from module code (e.g., dist@vendor/module)
    $distCode = '';
    if (\str_contains($moduleCode, '@')) {
        [$distCode, $moduleCode] = \explode('@', $moduleCode, 2);
    }

    // Validate module code format
    if (!\preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$/i', $moduleCode)) {
        $this->writeLineLogging('{@c:red}[ERROR] Invalid module code format: ' . $moduleCode . '{@reset}', true);
        $this->writeLineLogging('        Expected format: vendor/module', true);
        exit(1);
    }

    // Validate version
    if (!$version) {
        $this->writeLineLogging('{@c:red}[ERROR] Version is required.{@reset}', true);
        exit(1);
    }

    if (!\preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([a-zA-Z0-9.]+))?(?:\+([a-zA-Z0-9.]+))?$/', $version)) {
        $this->writeLineLogging('{@c:red}[ERROR] Invalid version format: ' . $version . '{@reset}', true);
        $this->writeLineLogging('        Expected format: X.Y.Z or X.Y.Z-prerelease', true);
        exit(1);
    }

    // Resolve the module directory (shared or distributor-specific)
    $modulePath = SYSTEM_ROOT;
    if (!$distCode) {
        $modulePath = PathUtil::append($modulePath, 'shared', 'module', $moduleCode);
    } else {
        $modulePath = PathUtil::append($modulePath, 'sites', $distCode, $moduleCode);
    }

    // Verify module.php configuration file exists at the module path
    $moduleConfigPath = PathUtil::append($modulePath, 'module.php');
    if (!\is_file($moduleConfigPath)) {
        $this->writeLineLogging('{@c:red}[ERROR] Module not found: ' . $moduleCode . '{@reset}', true);
        $this->writeLineLogging('        Expected at: ' . $modulePath, true);
        exit(1);
    }

    // Load and validate the module.php configuration
    try {
        $config = require $moduleConfigPath;
        $config['module_code'] = $config['module_code'] ?? '';
        $config['author'] = $config['author'] ?? '';
        $config['description'] = $config['description'] ?? '';

        if (!\preg_match(ModuleInfo::REGEX_MODULE_CODE, $config['module_code'])) {
            throw new Exception('Invalid module_code in module.php');
        }
    } catch (Exception $e) {
        $this->writeLineLogging('{@c:red}[ERROR] Failed to load module config: ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }

    // Use the 'default' package subdirectory as the source
    $packagePath = PathUtil::append($modulePath, 'default');
    if (!\is_dir($packagePath)) {
        $this->writeLineLogging('{@c:red}[ERROR] Default package not found: ' . $packagePath . '{@reset}', true);
        exit(1);
    }

    // Load package.php for additional metadata
    $packageConfig = [];
    $packageConfigPath = PathUtil::append($packagePath, 'package.php');
    if (\is_file($packageConfigPath)) {
        try {
            $packageConfig = require $packageConfigPath;
        } catch (Exception $e) {
            // Ignore, use defaults
        }
    }

    // Resolve output path: default to packages/<module_code>, or use user-specified path
    if (!$outputPath) {
        $outputPath = PathUtil::append(SYSTEM_ROOT, 'packages', $moduleCode);
    } elseif (!\preg_match('#^([a-z]:)?[/\\\]#i', $outputPath)) {
        $outputPath = PathUtil::append(SYSTEM_ROOT, $outputPath, $moduleCode);
    } else {
        $outputPath = PathUtil::append($outputPath, $moduleCode);
    }

    // Create output directory
    if (!\is_dir($outputPath)) {
        if (!\mkdir($outputPath, 0755, true)) {
            $this->writeLineLogging('{@c:red}[ERROR] Cannot create output directory: ' . $outputPath . '{@reset}', true);
            exit(1);
        }
    }

    $this->writeLineLogging('Module: {@c:cyan}' . $moduleCode . '{@reset}', true);
    $this->writeLineLogging('Version: {@c:cyan}' . $version . '{@reset}', true);
    $this->writeLineLogging('Source: {@c:cyan}' . $packagePath . '{@reset}', true);
    $this->writeLineLogging('Output: {@c:cyan}' . $outputPath . '{@reset}', true);
    $this->writeLineLogging('', true);

    try {
        // Build the .phar archive from the package directory
        $pharFile = PathUtil::append($outputPath, $version . '.phar');

        // Remove existing phar
        if (\is_file($pharFile)) {
            \unlink($pharFile);
        }

        $this->writeLineLogging('[{@c:yellow}PACK{@reset}] Creating .phar archive...', true);

        $phar = new Phar($pharFile);
        $phar->startBuffering();

        // Build exclusion pattern for webassets if needed
        if ($includeAssets) {
            // Include everything
            $phar->buildFromDirectory($packagePath);
        } else {
            // Exclude webassets
            $pattern = '/^(?!' . \preg_quote($packagePath . DIRECTORY_SEPARATOR . 'webassets', '/') . ')(.*)/';
            $phar->buildFromDirectory($packagePath, $pattern);
        }

        // Include module.php at the root level of the phar
        $phar->addFile($moduleConfigPath, 'module.php');

        $phar->stopBuffering();

        // Apply GZIP compression to reduce phar file size
        if (!$noCompress) {
            $this->writeLineLogging('[{@c:yellow}COMPRESS{@reset}] Compressing with GZIP...', true);
            $phar->compressFiles(Phar::GZ);
        }

        $pharSize = \filesize($pharFile);
        $this->writeLineLogging('[{@c:green}✓{@reset}] Created: ' . \basename($pharFile) . ' (' . \round($pharSize / 1024, 2) . ' KB)', true);

        // Copy webassets folder separately if the module has web assets
        $assetsPath = PathUtil::append($packagePath, 'webassets');
        if ($includeAssets && \is_dir($assetsPath) && \count(\glob($assetsPath . '/*')) > 0) {
            $assetsOutputPath = PathUtil::append($outputPath, $version . '-assets');
            $this->writeLineLogging('[{@c:yellow}ASSETS{@reset}] Copying webassets...', true);

            if (\is_dir($assetsOutputPath)) {
                // Remove existing
                $this->removeDirectory($assetsOutputPath);
            }

            \xcopy($assetsPath, $assetsOutputPath);
            $this->writeLineLogging('[{@c:green}✓{@reset}] Assets copied to: ' . \basename($assetsOutputPath) . '/', true);
        }

        // Create or update manifest.json with module metadata and version history
        $manifestPath = PathUtil::append($outputPath, 'manifest.json');
        $manifest = [];
        if (\is_file($manifestPath)) {
            $existing = \json_decode(\file_get_contents($manifestPath), true);
            if (\json_last_error() === JSON_ERROR_NONE) {
                $manifest = $existing;
            }
        }

        // Update manifest
        $manifest['module_code'] = $moduleCode;
        $manifest['description'] = $config['description'] ?? $packageConfig['description'] ?? '';
        $manifest['author'] = $config['author'] ?? $packageConfig['author'] ?? '';
        $manifest['latest'] = $version;
        $manifest['versions'] = $manifest['versions'] ?? [];
        if (!\in_array($version, $manifest['versions'])) {
            $manifest['versions'][] = $version;
            // Sort versions (newest first)
            \usort($manifest['versions'], 'version_compare');
            $manifest['versions'] = \array_reverse($manifest['versions']);
        }
        $manifest['updated'] = \date('Y-m-d H:i:s');

        // Add version-specific info
        $manifest['releases'] = $manifest['releases'] ?? [];
        $manifest['releases'][$version] = [
            'file' => $version . '.phar',
            'size' => $pharSize,
            'checksum' => \hash_file('sha256', $pharFile),
            'created' => \date('Y-m-d H:i:s'),
            'php_version' => $packageConfig['php_version'] ?? '8.0',
            'razy_version' => $packageConfig['razy_version'] ?? RAZY_VERSION,
        ];

        \file_put_contents($manifestPath, \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeLineLogging('[{@c:green}✓{@reset}] Updated: manifest.json', true);

        // Write latest.json pointer file for quick version resolution
        $latestPath = PathUtil::append($outputPath, 'latest.json');
        $latestData = [
            'version' => $version,
            'file' => $version . '.phar',
            'checksum' => \hash_file('sha256', $pharFile),
        ];
        \file_put_contents($latestPath, \json_encode($latestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeLineLogging('[{@c:green}✓{@reset}] Updated: latest.json', true);

        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:green}[SUCCESS] Module packaged successfully!{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Files created:', true);
        $this->writeLineLogging('  {@c:cyan}' . $pharFile . '{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}' . $manifestPath . '{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}' . $latestPath . '{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Next steps:', true);
        $this->writeLineLogging('  1. Commit packages/ to your repository', true);
        $this->writeLineLogging('  2. Run {@c:cyan}php Razy.phar publish{@reset} to update repository index', true);

        exit(0);
    } catch (Exception $e) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}[ERROR] Failed to create package: ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }
};

/**
 * Recursively removes a directory and all its contents.
 *
 * @param string $directory The absolute path to the directory to remove
 *
 * @return bool True if the directory was successfully removed
 */
function removeDirectory(string $directory): bool
{
    if (!\is_dir($directory)) {
        return false;
    }

    $files = \array_diff(\scandir($directory), ['.', '..']);
    foreach ($files as $file) {
        $path = $directory . DIRECTORY_SEPARATOR . $file;
        if (\is_dir($path)) {
            removeDirectory($path);
        } else {
            \unlink($path);
        }
    }

    return \rmdir($directory);
}
