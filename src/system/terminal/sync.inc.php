<?php

/**
 * CLI Command: sync.
 *
 * Synchronizes modules for a distributor from its repository.inc.php
 * configuration. Reads the module definitions (with version requirements),
 * resolves each module from configured repositories, and downloads/installs
 * missing or outdated modules. Supports shared and distributor-specific installation.
 *
 * Usage:
 *   php Razy.phar sync [distributor] [options]
 *
 * Arguments:
 *   distributor  Distributor code (prompted if omitted)
 *
 * Options:
 *   -v, --verbose    Show detailed information
 *   --dry-run        Preview changes without installing
 *   -y, --yes        Auto-confirm all installations
 *
 * Distributor repository.inc.php format:
 *   return [
 *       'repositories' => ['https://github.com/owner/repo/' => 'main'],
 *       'modules' => [
 *           'vendor/module' => ['version' => '1.0.0', 'is_shared' => false],
 *           'vendor/module2' => 'latest',
 *       ],
 *   ];
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Util\PathUtil;

return function (string $distCode = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Module Sync', true);
    $this->writeLineLogging('Sync modules from distributor repository configuration', true);
    $this->writeLineLogging('', true);

    // Treat leading-dash first argument as an option, not a distributor code
    if (\str_starts_with($distCode, '-')) {
        \array_unshift($options, $distCode);
        $distCode = '';
    }

    // Parse options
    $verbose = false;
    $dryRun = false;
    $autoConfirm = false;

    foreach ($options as $option) {
        if ($option === '-v' || $option === '--verbose') {
            $verbose = true;
        } elseif ($option === '--dry-run') {
            $dryRun = true;
        } elseif ($option === '-y' || $option === '--yes') {
            $autoConfirm = true;
        }
    }

    // If no distributor specified, list distributors with repository.inc.php and prompt
    if (!$distCode) {
        $sitesPath = PathUtil::append(SYSTEM_ROOT, 'sites');
        $distributors = [];

        if (\is_dir($sitesPath)) {
            $dirs = \glob(PathUtil::append($sitesPath, '*'), GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $distName = \basename($dir);
                $repoConfig = PathUtil::append($dir, 'repository.inc.php');
                if (\is_file($repoConfig)) {
                    $distributors[] = $distName;
                }
            }
        }

        if (empty($distributors)) {
            $this->writeLineLogging('{@c:red}[ERROR] No distributors with repository.inc.php found.{@reset}', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Create a repository.inc.php in your distributor directory:', true);
            $this->writeLineLogging('  {@c:cyan}sites/mysite/repository.inc.php{@reset}', true);
            exit(1);
        }

        $this->writeLineLogging('{@c:yellow}[SELECT] Which distributor to sync?{@reset}', true);
        $this->writeLineLogging('', true);

        $index = 1;
        foreach ($distributors as $dist) {
            $this->writeLineLogging('  {@c:cyan}[' . $index . ']{@reset} ' . $dist, true);
            $index++;
        }
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Enter selection: ', false);

        $handle = \fopen('php://stdin', 'r');
        $selection = \trim(\fgets($handle));
        \fclose($handle);
        $this->writeLineLogging('', true);

        if (\is_numeric($selection) && $selection > 0 && $selection <= \count($distributors)) {
            $distCode = $distributors[(int) $selection - 1];
        } else {
            $this->writeLineLogging('{@c:red}[ERROR] Invalid selection{@reset}', true);
            exit(1);
        }
    }

    // Verify the distributor directory exists
    $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
    if (!\is_dir($distPath)) {
        $this->writeLineLogging('{@c:red}[ERROR] Distributor not found: ' . $distCode . '{@reset}', true);
        exit(1);
    }

    // Load and validate the distributor's repository.inc.php
    $repoConfigPath = PathUtil::append($distPath, 'repository.inc.php');
    if (!\is_file($repoConfigPath)) {
        $this->writeLineLogging('{@c:red}[ERROR] repository.inc.php not found for distributor: ' . $distCode . '{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Expected at: {@c:cyan}' . $repoConfigPath . '{@reset}', true);
        exit(1);
    }

    $config = require $repoConfigPath;
    if (!\is_array($config)) {
        $this->writeLineLogging('{@c:red}[ERROR] repository.inc.php must return an array{@reset}', true);
        exit(1);
    }

    $this->writeLineLogging('Distributor: {@c:cyan}' . $distCode . '{@reset}', true);
    if ($dryRun) {
        $this->writeLineLogging('Mode: {@c:yellow}DRY RUN - No changes will be made{@reset}', true);
    }
    $this->writeLineLogging('', true);

    // Extract repository sources and module definitions from the config
    $repositories = $config['repositories'] ?? $config;
    $modules = $config['modules'] ?? [];

    // If the config uses the legacy format (no 'modules' key), show migration guidance
    if (empty($modules) && !isset($config['repositories'])) {
        $this->writeLineLogging('{@c:yellow}[INFO] No modules defined in repository.inc.php{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('To sync modules, add a modules section:', true);
        $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    \'repositories\' => [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}        \'https://github.com/owner/repo/\' => \'main\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    ],{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    \'modules\' => [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}        \'vendor/module\' => \'latest\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}        \'vendor/module2\' => [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}            \'version\' => \'1.0.0\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}            \'is_shared\' => true,{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}        ],{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    ],{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}];{@reset}', true);
        exit(0);
    }

    // Remove the 'modules' key from the repository list if accidentally included
    if (isset($repositories['modules'])) {
        unset($repositories['modules']);
    }
    if (isset($repositories['repositories'])) {
        $repositories = $repositories['repositories'];
    }

    if (empty($repositories)) {
        $this->writeLineLogging('{@c:red}[ERROR] No repositories configured{@reset}', true);
        exit(1);
    }

    $this->writeLineLogging('[{@c:blue}REPOS{@reset}] Repositories configured:', true);
    foreach ($repositories as $url => $branch) {
        $this->writeLineLogging('    ' . $url . ' ({@c:cyan}' . $branch . '{@reset})', true);
    }
    $this->writeLineLogging('', true);

    // Initialize RepositoryManager
    $repoManager = new RepositoryManager($repositories);

    // Process modules
    $this->writeLineLogging('[{@c:blue}MODULES{@reset}] Modules to sync: {@c:cyan}' . \count($modules) . '{@reset}', true);
    $this->writeLineLogging('', true);

    $installCount = 0;
    $skipCount = 0;
    $errorCount = 0;

    foreach ($modules as $moduleCode => $moduleConfig) {
        // Normalize config
        if (\is_string($moduleConfig)) {
            $moduleConfig = ['version' => $moduleConfig];
        }

        $version = $moduleConfig['version'] ?? 'latest';
        $isShared = $moduleConfig['is_shared'] ?? false;

        $this->writeLineLogging('[{@c:yellow}CHECK{@reset}] ' . $moduleCode, true);

        // Get module info from repository
        $moduleInfo = $repoManager->getModuleInfo($moduleCode);
        if (!$moduleInfo) {
            $this->writeLineLogging('    {@c:red}[ERROR] Module not found in configured repositories{@reset}', true);
            $errorCount++;
            continue;
        }

        // Resolve version: convert 'latest' to the actual latest version string
        $targetVersion = $version;
        if ($version === 'latest') {
            $targetVersion = $moduleInfo['latest'] ?? null;
            if (!$targetVersion) {
                $this->writeLineLogging('    {@c:red}[ERROR] No latest version available{@reset}', true);
                $errorCount++;
                continue;
            }
        }

        // Check if module/version is already installed
        // Determine installation path (shared/ vs distributor-specific sites/<code>/)
        $targetPath = $isShared
            ? PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $moduleCode)
            : PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, $moduleCode);

        // Check if the exact version is already installed at the target path
        $alreadyInstalled = false;
        if (\is_dir($targetPath)) {
            // Check installed version
            $installedConfigPath = PathUtil::append($targetPath, 'module.php');
            if (\is_file($installedConfigPath)) {
                $installedConfig = require $installedConfigPath;
                $installedVersion = $installedConfig['version'] ?? null;
                if ($installedVersion === $targetVersion) {
                    $alreadyInstalled = true;
                }
            }
        }

        $targetLabel = $isShared ? 'shared' : $distCode;

        if ($alreadyInstalled) {
            $this->writeLineLogging('    {@c:green}[INSTALLED]{@reset} v' . $targetVersion . ' already installed (' . $targetLabel . ')', true);
            $skipCount++;
            continue;
        }

        if ($verbose) {
            $this->writeLineLogging('    Version: {@c:cyan}' . $targetVersion . '{@reset}', true);
            $this->writeLineLogging('    Target: {@c:cyan}' . $targetLabel . '{@reset}', true);
        }

        if ($dryRun) {
            $this->writeLineLogging('    {@c:yellow}[DRY RUN]{@reset} Would install v' . $targetVersion . ' to ' . $targetLabel, true);
            $installCount++;
            continue;
        }

        // Confirm installation
        if (!$autoConfirm) {
            $this->writeLineLogging('    Install ' . $moduleCode . '@' . $targetVersion . ' to ' . $targetLabel . '? (y/N): ', false);
            $handle = \fopen('php://stdin', 'r');
            $response = \strtolower(\trim(\fgets($handle)));
            \fclose($handle);

            if ($response !== 'y' && $response !== 'yes') {
                $this->writeLineLogging('    {@c:yellow}[SKIP]{@reset} Skipped by user', true);
                $skipCount++;
                continue;
            }
        }

        // Fetch the download URL from the repository manager
        $downloadUrl = $repoManager->getDownloadUrl($moduleCode, $targetVersion);
        if (!$downloadUrl) {
            $this->writeLineLogging('    {@c:red}[ERROR] Could not get download URL{@reset}', true);
            $errorCount++;
            continue;
        }

        // Download the phar package via cURL
        $this->writeLineLogging('    [{@c:yellow}DOWNLOAD{@reset}] ' . \basename($downloadUrl), true);

        $ch = \curl_init($downloadUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Razy-Installer');

        $pharContent = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $downloadSize = \curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        \curl_close($ch);

        if ($httpCode !== 200 || $pharContent === false) {
            $this->writeLineLogging('    {@c:red}[ERROR] Download failed (HTTP ' . $httpCode . '){@reset}', true);
            $errorCount++;
            continue;
        }

        $sizeInKB = \round($downloadSize / 1024, 2);

        // Write the downloaded content to a temporary file and extract into target
        $tempPhar = \sys_get_temp_dir() . '/razy_' . \md5(\microtime()) . '.phar';
        \file_put_contents($tempPhar, $pharContent);

        try {
            // Ensure the target directory exists before extraction
            if (!\is_dir($targetPath)) {
                \mkdir($targetPath, 0755, true);
            }

            // Extract the phar archive contents into the module directory
            $phar = new Phar($tempPhar);
            $phar->extractTo($targetPath, null, true);

            $this->writeLineLogging('    [{@c:green}✓{@reset}] Installed v' . $targetVersion . ' (' . $sizeInKB . ' KB) to ' . $targetLabel, true);
            $installCount++;
        } catch (Exception $e) {
            $this->writeLineLogging('    {@c:red}[ERROR] Extract failed: ' . $e->getMessage() . '{@reset}', true);
            $errorCount++;
        }

        @\unlink($tempPhar);
    }

    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@s:bu}Summary', true);
    $this->writeLineLogging('  Installed: {@c:green}' . $installCount . '{@reset}', true);
    $this->writeLineLogging('  Skipped: {@c:yellow}' . $skipCount . '{@reset}', true);
    if ($errorCount > 0) {
        $this->writeLineLogging('  Errors: {@c:red}' . $errorCount . '{@reset}', true);
    }
    $this->writeLineLogging('', true);

    if ($errorCount > 0) {
        exit(1);
    }
    exit(0);
};
