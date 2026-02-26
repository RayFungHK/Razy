<?php

/**
 * CLI Command: install.
 *
 * Downloads and installs modules from GitHub repositories or custom repository URLs.
 * Supports multiple repository formats including GitHub shorthand (owner/repo),
 * full GitHub URLs, and direct ZIP/archive URLs. Can install to shared modules
 * or a specific distributor. Also supports installing from configured repositories
 * defined in repository.inc.php.
 *
 * Usage:
 *   php Razy.phar install <repository> [target_path] [options]
 *
 * Arguments:
 *   repository    Repository URL or GitHub shorthand (owner/repo[@version])
 *   target_path   Installation path (optional, auto-detected from repo name)
 *
 * Options:
 *   -l, --latest         Download latest release (including pre-release)
 *   -s, --stable         Download latest stable release
 *   -v, --version=VER    Specify version/tag (e.g., v1.0.0)
 *   -b, --branch=NAME    Specify branch name (default: main)
 *   -r, --from-repo      Install from configured repositories
 *   -n, --name=NAME      Module name (for modules directory)
 *   -d, --dist=CODE      Install to distributor's modules directory
 *   --token=TOKEN        Authentication token (for private repos)
 *   -y, --yes            Auto-confirm all prompts
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Util\PathUtil;

return function (string $repository = '', string $targetPath = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Repository Module Installer', true);
    $this->writeLineLogging('Download and install modules from GitHub or custom repositories', true);
    $this->writeLineLogging('', true);

    // Handle case where targetPath is actually an option (starts with -)
    if (\str_starts_with($targetPath, '-')) {
        \array_unshift($options, $targetPath);
        $targetPath = '';
    }

    // Parse options
    $version = null;
    $authToken = null;
    $moduleName = null;
    $distCode = null;
    $fromRepo = false;
    $autoConfirm = false;

    foreach ($options as $option) {
        if ($option === '--latest' || $option === '-l') {
            $version = RepoInstaller::VERSION_LATEST;
        } elseif ($option === '--stable' || $option === '-s') {
            $version = RepoInstaller::VERSION_STABLE;
        } elseif (\str_starts_with($option, '--version=') || \str_starts_with($option, '-v=')) {
            $version = \substr($option, \strpos($option, '=') + 1);
        } elseif (\str_starts_with($option, '--branch=') || \str_starts_with($option, '-b=')) {
            $version = \substr($option, \strpos($option, '=') + 1);
        } elseif (\str_starts_with($option, '--token=')) {
            $authToken = \substr($option, 8);
        } elseif (\str_starts_with($option, '--name=') || \str_starts_with($option, '-n=')) {
            $moduleName = \substr($option, \strpos($option, '=') + 1);
        } elseif (\str_starts_with($option, '--dist=') || \str_starts_with($option, '-d=')) {
            $distCode = \substr($option, \strpos($option, '=') + 1);
        } elseif ($option === '--from-repo' || $option === '-r') {
            $fromRepo = true;
        } elseif ($option === '--yes' || $option === '-y') {
            $autoConfirm = true;
        }
    }

    // Validate required parameters
    $repository = \trim($repository);
    if (!$repository) {
        $this->writeLineLogging('{@c:red}[ERROR] Repository is required.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar install <repository> [target_path] [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Arguments:', true);
        $this->writeLineLogging('  {@c:green}repository{@reset}    Repository URL or GitHub shorthand (owner/repo[@version])', true);
        $this->writeLineLogging('  {@c:green}target_path{@reset}   Installation path (optional, auto-detected from repo name)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Repository Formats:', true);
        $this->writeLineLogging('  {@c:cyan}owner/repo{@reset}                   GitHub repository (uses main branch)', true);
        $this->writeLineLogging('  {@c:cyan}owner/repo@version{@reset}           GitHub repo with specific version/branch/tag', true);
        $this->writeLineLogging('  {@c:cyan}https://github.com/owner/repo{@reset}  Full GitHub URL', true);
        $this->writeLineLogging('  {@c:cyan}https://example.com/repo.zip{@reset}   Custom repository URL', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  {@c:green}-l, --latest{@reset}         Download latest release (any, including pre-release)', true);
        $this->writeLineLogging('  {@c:green}-s, --stable{@reset}         Download latest stable release (non-prerelease)', true);
        $this->writeLineLogging('  {@c:green}-v, --version=VER{@reset}    Specify version/tag (e.g., v1.0.0)', true);
        $this->writeLineLogging('  {@c:green}-b, --branch=NAME{@reset}    Specify branch name (default: main)', true);
        $this->writeLineLogging('  {@c:green}-r, --from-repo{@reset}      Install from configured repositories', true);
        $this->writeLineLogging('  {@c:green}-n, --name=NAME{@reset}      Module name (for modules directory)', true);
        $this->writeLineLogging('  {@c:green}-d, --dist=CODE{@reset}      Install to distributor\'s modules directory', true);
        $this->writeLineLogging('  {@c:green}--token=TOKEN{@reset}        Authentication token (for private repos)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  {@c:cyan}# Install from main branch{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install specific version/tag{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo@v1.0.0', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo --version=v1.0.0', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install latest stable release{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo --stable', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install latest release (including pre-release){@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo --latest', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install from custom URL{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install https://example.com/module.zip ./modules/mymodule', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install as module for distributor{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/repo-module --dist=mysite --name=MyModule', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install from private repository{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install owner/private-repo --token=ghp_yourtoken', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install from configured repositories{@reset}', true);
        $this->writeLineLogging('  php Razy.phar install vendor/module --from-repo', true);
        $this->writeLineLogging('  php Razy.phar install vendor/module@1.0.0 --from-repo', true);
        $this->writeLineLogging('', true);

        exit(1);
    }

    try {
        // Handle --from-repo: install from configured repositories
        if ($fromRepo) {
            // Load repository configuration
            $repositoryConfig = SYSTEM_ROOT . '/repository.inc.php';
            if (!\is_file($repositoryConfig)) {
                $this->writeLineLogging('{@c:red}[ERROR] No repository.inc.php found.{@reset}', true);
                $this->writeLineLogging('', true);
                $this->writeLineLogging('Create repository.inc.php in your project root:', true);
                $this->writeLineLogging('  {@c:cyan}<?php{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}    \'https://github.com/username/repo/\' => \'main\',{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}];{@reset}', true);
                exit(1);
            }

            $repositories = include $repositoryConfig;
            if (!\is_array($repositories) || empty($repositories)) {
                $this->writeLineLogging('{@c:red}[ERROR] No repositories configured.{@reset}', true);
                exit(1);
            }

            // Parse module code and optional version from vendor/module@version format
            $moduleCode = $repository;
            $requestedVersion = $version;

            if (\str_contains($repository, '@')) {
                [$moduleCode, $requestedVersion] = \explode('@', $repository, 2);
            }

            // Look up the module in configured repositories
            $this->writeLineLogging('[{@c:yellow}SEARCH{@reset}] Looking for module: {@c:cyan}' . $moduleCode . '{@reset}', true);

            // Initialize RepositoryManager with the configured repository list and query
            $repoManager = new RepositoryManager($repositories);
            $moduleInfo = $repoManager->getModuleInfo($moduleCode);

            if (!$moduleInfo) {
                $this->writeLineLogging('{@c:red}[ERROR] Module "' . $moduleCode . '" not found in configured repositories.{@reset}', true);
                $this->writeLineLogging('', true);
                $this->writeLineLogging('Use search command to find available modules:', true);
                $this->writeLineLogging('  {@c:cyan}php Razy.phar search ' . $moduleCode . '{@reset}', true);
                exit(1);
            }

            $this->writeLineLogging('[{@c:green}✓{@reset}] Found module: {@c:cyan}' . $moduleCode . '{@reset}', true);

            // Show module information
            if (!empty($moduleInfo['description'])) {
                $this->writeLineLogging('    Description: ' . $moduleInfo['description'], true);
            }
            if (!empty($moduleInfo['author'])) {
                $this->writeLineLogging('    Author: {@c:blue}' . $moduleInfo['author'] . '{@reset}', true);
            }

            // Determine version to download
            if (!$requestedVersion) {
                $requestedVersion = $moduleInfo['latest'] ?? null;
            }

            if (!$requestedVersion) {
                $this->writeLineLogging('{@c:red}[ERROR] No version available for module.{@reset}', true);
                exit(1);
            }

            // Show available versions
            $versions = $moduleInfo['versions'] ?? [$requestedVersion];
            $this->writeLineLogging('    Available versions: ' . \implode(', ', \array_slice($versions, 0, 5)) . (\count($versions) > 5 ? '...' : ''), true);
            $this->writeLineLogging('[{@c:green}✓{@reset}] Selected version: {@c:cyan}' . $requestedVersion . '{@reset}', true);
            $this->writeLineLogging('', true);

            // If no dist specified, prompt user to select installation target (or default to shared if --yes)
            if (!$distCode) {
                if ($autoConfirm) {
                    // Default to shared modules when auto-confirming
                    $this->writeLineLogging('[{@c:green}AUTO{@reset}] Installing to shared modules (default)', true);
                    $distCode = '';
                } else {
                    $this->writeLineLogging('{@c:yellow}[SELECT] Where to install?{@reset}', true);
                    $this->writeLineLogging('', true);

                    // List available distributors
                    $sitesPath = PathUtil::append(SYSTEM_ROOT, 'sites');
                    $distributors = [];
                    if (\is_dir($sitesPath)) {
                        $dirs = \glob(PathUtil::append($sitesPath, '*'), GLOB_ONLYDIR);
                        foreach ($dirs as $dir) {
                            $distributors[] = \basename($dir);
                        }
                    }

                    $this->writeLineLogging('  {@c:cyan}[0]{@reset} Shared modules (shared/module/)', true);
                    $index = 1;
                    foreach ($distributors as $dist) {
                        $this->writeLineLogging('  {@c:cyan}[' . $index . ']{@reset} Distributor: ' . $dist, true);
                        $index++;
                    }
                    $this->writeLineLogging('', true);
                    $this->writeLineLogging('Enter selection (default: 0): ', false);

                    $handle = \fopen('php://stdin', 'r');
                    $selection = \trim(\fgets($handle));
                    \fclose($handle);
                    $this->writeLineLogging('', true);

                    if ($selection === '' || $selection === '0') {
                        // Shared modules (default)
                        $distCode = '';
                    } elseif (\is_numeric($selection) && $selection > 0 && $selection <= \count($distributors)) {
                        $distCode = $distributors[(int) $selection - 1];
                    } else {
                        $this->writeLineLogging('{@c:red}[ERROR] Invalid selection{@reset}', true);
                        exit(1);
                    }
                }
            }

            // Get repository URL for fetching disclaimer/terms
            $repoUrl = $moduleInfo['repository'] ?? null;
            $repoBranch = $moduleInfo['branch'] ?? 'main';

            // Fetch and display disclaimer.txt from the repository if present
            if ($repoUrl) {
                // Fetch and display disclaimer.txt
                $disclaimerUrl = $repoManager->buildRawUrl($repoUrl, $repoBranch, $moduleCode . '/disclaimer.txt');
                $disclaimerContent = @\file_get_contents($disclaimerUrl);
                if ($disclaimerContent !== false && \trim($disclaimerContent)) {
                    $this->writeLineLogging('{@c:yellow}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('{@c:yellow}                        DISCLAIMER{@reset}', true);
                    $this->writeLineLogging('{@c:yellow}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('', true);
                    foreach (\explode("\n", \trim($disclaimerContent)) as $line) {
                        $this->writeLineLogging($line, true);
                    }
                    $this->writeLineLogging('', true);
                    $this->writeLineLogging('{@c:yellow}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('', true);
                }

                // Fetch and require acceptance of terms.txt
                $termsUrl = $repoManager->buildRawUrl($repoUrl, $repoBranch, $moduleCode . '/terms.txt');
                $termsContent = @\file_get_contents($termsUrl);
                if ($termsContent !== false && \trim($termsContent)) {
                    $this->writeLineLogging('{@c:red}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('{@c:red}                    TERMS AND CONDITIONS{@reset}', true);
                    $this->writeLineLogging('{@c:red}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('', true);
                    foreach (\explode("\n", \trim($termsContent)) as $line) {
                        $this->writeLineLogging($line, true);
                    }
                    $this->writeLineLogging('', true);
                    $this->writeLineLogging('{@c:red}═══════════════════════════════════════════════════════════════{@reset}', true);
                    $this->writeLineLogging('', true);
                    $this->writeLineLogging('{@c:yellow}You must accept the terms and conditions to continue.{@reset}', true);
                    $this->writeLineLogging('Type {@c:green}yes{@reset} or {@c:green}agree{@reset} to accept: ', false);

                    $handle = \fopen('php://stdin', 'r');
                    $response = \strtolower(\trim(\fgets($handle)));
                    \fclose($handle);

                    if ($response !== 'yes' && $response !== 'agree') {
                        $this->writeLineLogging('', true);
                        $this->writeLineLogging('{@c:red}[CANCELLED] You must accept the terms to install this module.{@reset}', true);
                        exit(1);
                    }
                    $this->writeLineLogging('[{@c:green}✓{@reset}] Terms accepted', true);
                    $this->writeLineLogging('', true);
                }
            }

            // Prompt user to confirm installation
            if (!$autoConfirm) {
                $this->writeLineLogging('Install {@c:cyan}' . $moduleCode . '@' . $requestedVersion . '{@reset}? (y/N): ', false);
                $handle = \fopen('php://stdin', 'r');
                $response = \strtolower(\trim(\fgets($handle)));
                \fclose($handle);

                if ($response !== 'y' && $response !== 'yes') {
                    $this->writeLineLogging('{@c:yellow}Installation cancelled.{@reset}', true);
                    exit(0);
                }
                $this->writeLineLogging('', true);
            }

            $downloadUrl = $repoManager->getDownloadUrl($moduleCode, $requestedVersion);
            if (!$downloadUrl) {
                $this->writeLineLogging('{@c:red}[ERROR] Could not determine download URL for ' . $moduleCode . '@' . $requestedVersion . '{@reset}', true);
                exit(1);
            }

            $this->writeLineLogging('[{@c:green}✓{@reset}] Download URL: {@c:cyan}' . $downloadUrl . '{@reset}', true);

            // Determine target path for module installation
            if ($distCode) {
                // Install to distributor's modules directory
                $targetPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, $moduleCode);
                $this->writeLineLogging('Installing to distributor: {@c:cyan}' . $distCode . '{@reset}', true);
            } else {
                // Install to shared modules directory
                $targetPath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $moduleCode);
                $this->writeLineLogging('Installing to shared modules', true);
            }
            $this->writeLineLogging('Target: {@c:cyan}' . $targetPath . '{@reset}', true);
            $this->writeLineLogging('', true);

            // Download the .phar file from the resolved URL
            $this->writeLineLogging('[{@c:yellow}DOWNLOAD{@reset}] Starting download...', true);

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
                $this->writeLineLogging('{@c:red}[ERROR] Failed to download module (HTTP ' . $httpCode . '){@reset}', true);
                exit(1);
            }

            $sizeInKB = \round($downloadSize / 1024, 2);
            $this->writeLineLogging('[{@c:green}✓{@reset}] Downloaded ({@c:green}' . $sizeInKB . ' KB{@reset})', true);

            // Save the downloaded content to a temp file and extract it to the target path
            $tempPhar = \sys_get_temp_dir() . '/razy_' . \md5(\microtime()) . '.phar';
            \file_put_contents($tempPhar, $pharContent);

            $this->writeLineLogging('[{@c:yellow}EXTRACT{@reset}] Extracting module...', true);

            try {
                // Create target directory
                if (!\is_dir($targetPath)) {
                    \mkdir($targetPath, 0755, true);
                }

                // Extract phar to target
                $phar = new Phar($tempPhar);
                $phar->extractTo($targetPath, null, true);

                $this->writeLineLogging('[{@c:green}✓{@reset}] Extracted to: {@c:cyan}' . $targetPath . '{@reset}', true);
            } catch (Exception $e) {
                $this->writeLineLogging('{@c:red}[ERROR] Failed to extract: ' . $e->getMessage() . '{@reset}', true);
                @\unlink($tempPhar);
                exit(1);
            }

            @\unlink($tempPhar);

            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:green}[SUCCESS] Module installed!{@reset}', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Module: {@c:cyan}' . $moduleCode . '@' . $requestedVersion . '{@reset}', true);
            $this->writeLineLogging('Location: {@c:cyan}' . $targetPath . '{@reset}', true);

            // Check for required modules and install them
            $moduleConfigPath = PathUtil::append($targetPath, 'default', 'module.php');
            if (!\is_file($moduleConfigPath)) {
                $moduleConfigPath = PathUtil::append($targetPath, 'module.php');
            }

            if (\is_file($moduleConfigPath)) {
                try {
                    $moduleConfig = require $moduleConfigPath;
                    $requiredModules = $moduleConfig['require'] ?? [];

                    if (!empty($requiredModules) && \is_array($requiredModules)) {
                        $this->writeLineLogging('', true);
                        $this->writeLineLogging('{@c:yellow}[DEPENDENCIES] This module requires ' . \count($requiredModules) . ' other module(s){@reset}', true);

                        foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                            $this->writeLineLogging('  - {@c:cyan}' . $reqModuleCode . '{@reset} (' . $reqVersion . ')', true);

                            // Check if module already installed
                            $reqModulePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                            if ($distCode) {
                                $reqModulePathDist = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                if (\is_dir($reqModulePathDist)) {
                                    $this->writeLineLogging('    {@c:green}[INSTALLED] Already installed in distributor{@reset}', true);
                                    continue;
                                }
                            }
                            if (\is_dir($reqModulePath)) {
                                $this->writeLineLogging('    {@c:green}[INSTALLED] Already installed in shared modules{@reset}', true);
                                continue;
                            }
                        }

                        // Ask user if they want to install dependencies
                        $this->writeLineLogging('', true);
                        $installDeps = $autoConfirm;
                        if (!$autoConfirm) {
                            $this->writeLineLogging('Install required modules? (y/N): ', false);
                            $handle = \fopen('php://stdin', 'r');
                            $response = \strtolower(\trim(\fgets($handle)));
                            \fclose($handle);
                            $installDeps = ($response === 'y' || $response === 'yes');
                        }

                        if ($installDeps) {
                            $this->writeLineLogging('', true);

                            foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                                // Skip if already installed
                                $reqModulePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                                if ($distCode) {
                                    $reqModulePathDist = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                    if (\is_dir($reqModulePathDist)) {
                                        continue;
                                    }
                                }
                                if (\is_dir($reqModulePath)) {
                                    continue;
                                }

                                $this->writeLineLogging('{@c:yellow}[INSTALL]{@reset} Installing dependency: {@c:cyan}' . $reqModuleCode . '{@reset}', true);

                                // Get module info from repository
                                $depInfo = $repoManager->getModuleInfo($reqModuleCode);
                                if (!$depInfo) {
                                    $this->writeLineLogging('  {@c:red}[NOT FOUND] Module not found in repositories{@reset}', true);
                                    $this->writeLineLogging('  Install manually: {@c:cyan}php Razy.phar install ' . $reqModuleCode . '{@reset}', true);
                                    continue;
                                }

                                // Determine version to install
                                $depVersion = $depInfo['latest'] ?? null;
                                if (!$depVersion) {
                                    $this->writeLineLogging('  {@c:red}[ERROR] No version available{@reset}', true);
                                    continue;
                                }

                                $depUrl = $repoManager->getDownloadUrl($reqModuleCode, $depVersion);
                                if (!$depUrl) {
                                    $this->writeLineLogging('  {@c:red}[ERROR] Could not get download URL{@reset}', true);
                                    continue;
                                }

                                // Download and extract dependency
                                $this->writeLineLogging('  [DOWNLOAD] ' . $depUrl, true);

                                $ch = \curl_init($depUrl);
                                \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                                \curl_setopt($ch, CURLOPT_USERAGENT, 'Razy-Installer');

                                $depContent = \curl_exec($ch);
                                $depHttpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                \curl_close($ch);

                                if ($depHttpCode !== 200 || !$depContent) {
                                    $this->writeLineLogging('  {@c:red}[ERROR] Download failed (HTTP ' . $depHttpCode . '){@reset}', true);
                                    continue;
                                }

                                // Determine target path for dependency
                                $depTargetPath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                                if ($distCode) {
                                    $depTargetPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                }

                                // Extract dependency
                                $depTempPhar = \sys_get_temp_dir() . '/razy_dep_' . \md5($reqModuleCode . \microtime()) . '.phar';
                                \file_put_contents($depTempPhar, $depContent);

                                try {
                                    if (!\is_dir($depTargetPath)) {
                                        \mkdir($depTargetPath, 0755, true);
                                    }
                                    $depPhar = new Phar($depTempPhar);
                                    $depPhar->extractTo($depTargetPath, null, true);
                                    $this->writeLineLogging('  {@c:green}[✓] Installed ' . $reqModuleCode . '@' . $depVersion . '{@reset}', true);
                                } catch (Exception $e) {
                                    $this->writeLineLogging('  {@c:red}[ERROR] Failed to extract: ' . $e->getMessage() . '{@reset}', true);
                                }

                                @\unlink($depTempPhar);
                            }
                        } else {
                            $this->writeLineLogging('', true);
                            $this->writeLineLogging('To install dependencies later, run:', true);
                            foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                                $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $reqModuleCode . ' --from-repo{@reset}', true);
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Silently ignore if module.php can't be parsed
                }
            }

            exit(0);
        }

        // --- Non --from-repo installation: download directly from GitHub/URL ---

        // Determine target path based on repository name or user-specified path
        if (!$targetPath) {
            // Auto-detect path based on repository name
            if (\preg_match('#([^/]+)/([^/@]+)#', $repository, $matches)) {
                $repoName = $matches[2];

                if ($distCode) {
                    // Install as module for specific distributor
                    $app = new Application();
                    $app->loadSiteConfig();

                    if (!$app->hasDistributor($distCode)) {
                        $this->writeLineLogging('{@c:red}[ERROR] Distributor "' . $distCode . '" not found.{@reset}', true);
                        exit(1);
                    }

                    $moduleName = $moduleName ?: $repoName;
                    $targetPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', $moduleName);
                    $this->writeLineLogging('Installing to distributor {@c:cyan}' . $distCode . '{@reset} as module {@c:cyan}' . $moduleName . '{@reset}', true);
                } else {
                    // Install to default modules directory
                    $moduleName = $moduleName ?: $repoName;
                    $targetPath = PathUtil::append(SYSTEM_ROOT, 'modules', $moduleName);
                    $this->writeLineLogging('Installing to default modules directory as {@c:cyan}' . $moduleName . '{@reset}', true);
                }
            } else {
                $this->writeLineLogging('{@c:red}[ERROR] Cannot determine target path. Please specify target_path.{@reset}', true);
                exit(1);
            }
        } else {
            // Use provided path (relative to SYSTEM_ROOT or absolute)
            if (!\preg_match('#^([a-z]:)?[/\\\]#i', $targetPath)) {
                $targetPath = PathUtil::append(SYSTEM_ROOT, $targetPath);
            }
        }

        $this->writeLineLogging('Repository: {@c:green}' . $repository . '{@reset}', true);
        $this->writeLineLogging('Target path: {@c:green}' . $targetPath . '{@reset}', true);

        if ($version === RepoInstaller::VERSION_LATEST) {
            $this->writeLineLogging('Mode: {@c:yellow}Latest Release{@reset}', true);
        } elseif ($version === RepoInstaller::VERSION_STABLE) {
            $this->writeLineLogging('Mode: {@c:yellow}Stable Release{@reset}', true);
        } elseif ($version) {
            $this->writeLineLogging('Mode: {@c:yellow}Version/Tag (' . $version . '){@reset}', true);
        } else {
            $this->writeLineLogging('Mode: {@c:yellow}Branch (main){@reset}', true);
        }
        $this->writeLineLogging('', true);

        // Create a RepoInstaller instance with progress callback for download/extract
        $installer = new RepoInstaller(
            $repository,
            $targetPath,
            function (string $type, ...$data) {
                switch ($type) {
                    case RepoInstaller::TYPE_INFO:
                        $this->writeLineLogging('[{@c:blue}INFO{@reset}] ' . $data[0] . ': {@c:cyan}' . $data[1] . '{@reset}', true);
                        break;

                    case RepoInstaller::TYPE_DOWNLOAD_START:
                        $this->writeLineLogging('[{@c:yellow}DOWNLOAD{@reset}] Starting download...', true);
                        break;

                    case RepoInstaller::TYPE_PROGRESS:
                        [$size, $downloaded, $percentage] = $data;
                        $sizeInMB = \round($size / 1048576, 2);
                        $downloadedInMB = \round($downloaded / 1048576, 2);
                        echo $this->format(
                            '{@clear} - Progress: {@c:green}' . $percentage . '%{@reset} (' .
                            $downloadedInMB . ' MB / ' . $sizeInMB . ' MB)',
                            true
                        );
                        break;

                    case RepoInstaller::TYPE_DOWNLOAD_COMPLETE:
                        echo PHP_EOL;
                        $sizeInMB = \round($data[0] / 1048576, 2);
                        $this->writeLineLogging('[{@c:green}✓{@reset}] Download complete ({@c:green}' . $sizeInMB . ' MB{@reset})', true);
                        break;

                    case RepoInstaller::TYPE_EXTRACT_START:
                        $this->writeLineLogging('[{@c:yellow}EXTRACT{@reset}] Extracting archive...', true);
                        break;

                    case RepoInstaller::TYPE_EXTRACT_COMPLETE:
                        $this->writeLineLogging('[{@c:green}✓{@reset}] Extraction complete', true);
                        $this->writeLineLogging('[{@c:green}✓{@reset}] Files installed to: {@c:cyan}' . $data[0] . '{@reset}', true);
                        break;

                    case RepoInstaller::TYPE_INSTALL_COMPLETE:
                        $this->writeLineLogging('', true);
                        $this->writeLineLogging('{@c:green}[SUCCESS] Module installed successfully!{@reset}', true);
                        $this->writeLineLogging('Repository: {@c:cyan}' . $data[0] . '{@reset}', true);
                        $this->writeLineLogging('Location: {@c:cyan}' . $data[1] . '{@reset}', true);
                        break;

                    case RepoInstaller::TYPE_ERROR:
                        $this->writeLineLogging('', true);
                        $this->writeLineLogging('{@c:red}[ERROR] ' . $data[0] . '{@reset}', true);
                        if (isset($data[1])) {
                            $this->writeLineLogging('{@c:red}        ' . $data[1] . '{@reset}', true);
                        }
                        break;
                }
            },
            $version,
            $authToken
        );

        // Validate that the repository exists and is accessible
        $this->writeLineLogging('[{@c:yellow}VALIDATE{@reset}] Checking repository...', true);
        if (!$installer->validate()) {
            $this->writeLineLogging('{@c:red}[ERROR] Repository not found or not accessible.{@reset}', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Make sure:', true);
            $this->writeLineLogging('  1. Repository exists: {@c:cyan}' . $repository . '{@reset}', true);
            $this->writeLineLogging('  2. Repository is public (or provide --token for private repos)', true);
            $this->writeLineLogging('  3. You have internet connection', true);
            exit(1);
        }
        $this->writeLineLogging('[{@c:green}✓{@reset}] Repository validated', true);
        $this->writeLineLogging('', true);

        // Warn if target directory already exists and contains files
        if (\is_dir($targetPath)) {
            $files = \array_diff(\scandir($targetPath), ['.', '..']);
            if (\count($files) > 0) {
                $this->writeLineLogging('{@c:yellow}[WARNING] Target directory already exists and is not empty:{@reset}', true);
                $this->writeLineLogging('{@c:yellow}          ' . $targetPath . '{@reset}', true);
                $this->writeLineLogging('', true);

                if (!$autoConfirm) {
                    $this->writeLineLogging('Files will be overwritten. Continue? (y/N): ', false);

                    $handle = \fopen('php://stdin', 'r');
                    $line = \fgets($handle);
                    \fclose($handle);

                    if (\strtolower(\trim($line)) !== 'y') {
                        $this->writeLineLogging('{@c:yellow}Installation cancelled.{@reset}', true);
                        exit(0);
                    }
                    $this->writeLineLogging('', true);
                } else {
                    $this->writeLineLogging('[{@c:yellow}AUTO{@reset}] Overwriting existing files...', true);
                }
            }
        }

        // Perform installation
        if ($installer->install()) {
            $this->writeLineLogging('', true);

            // Check for README or documentation
            $readmePath = PathUtil::append($targetPath, 'README.md');
            if (\is_file($readmePath)) {
                $this->writeLineLogging('{@c:cyan}[TIP] Check README.md for installation instructions{@reset}', true);
            }

            // Check for composer.json
            $composerPath = PathUtil::append($targetPath, 'composer.json');
            if (\is_file($composerPath)) {
                $this->writeLineLogging('{@c:cyan}[TIP] Run "composer install" in the module directory if needed{@reset}', true);
            }

            // Check for required modules and install them
            $moduleConfigPath = PathUtil::append($targetPath, 'default', 'module.php');
            if (!\is_file($moduleConfigPath)) {
                $moduleConfigPath = PathUtil::append($targetPath, 'module.php');
            }

            if (\is_file($moduleConfigPath)) {
                try {
                    $moduleConfig = require $moduleConfigPath;
                    $requiredModules = $moduleConfig['require'] ?? [];

                    if (!empty($requiredModules) && \is_array($requiredModules)) {
                        $this->writeLineLogging('', true);
                        $this->writeLineLogging('{@c:yellow}[DEPENDENCIES] This module requires ' . \count($requiredModules) . ' other module(s){@reset}', true);

                        // Load repository config for dependency resolution
                        $repositoryConfig = SYSTEM_ROOT . '/repository.inc.php';
                        $hasRepoConfig = \is_file($repositoryConfig);

                        foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                            $this->writeLineLogging('  - {@c:cyan}' . $reqModuleCode . '{@reset} (' . $reqVersion . ')', true);

                            // Check if module already installed
                            $reqModulePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                            if ($distCode) {
                                $reqModulePathDist = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                if (\is_dir($reqModulePathDist)) {
                                    $this->writeLineLogging('    {@c:green}[INSTALLED] Already installed in distributor{@reset}', true);
                                    continue;
                                }
                            }
                            if (\is_dir($reqModulePath)) {
                                $this->writeLineLogging('    {@c:green}[INSTALLED] Already installed in shared modules{@reset}', true);
                                continue;
                            }
                        }

                        // Ask user if they want to install dependencies
                        $this->writeLineLogging('', true);
                        $installDeps = $autoConfirm;
                        if (!$autoConfirm) {
                            $this->writeLineLogging('Install required modules? (y/N): ', false);
                            $handle = \fopen('php://stdin', 'r');
                            $response = \strtolower(\trim(\fgets($handle)));
                            \fclose($handle);
                            $installDeps = ($response === 'y' || $response === 'yes');
                        }

                        if ($installDeps) {
                            $this->writeLineLogging('', true);

                            if (!$hasRepoConfig) {
                                $this->writeLineLogging('{@c:yellow}[WARNING] No repository.inc.php found.{@reset}', true);
                                $this->writeLineLogging('Dependency installation requires a configured repository.', true);
                                $this->writeLineLogging('Create repository.inc.php and run:', true);
                                foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                                    $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $reqModuleCode . ' --from-repo{@reset}', true);
                                }
                            } else {
                                $repositories = include $repositoryConfig;
                                $repoManager = new RepositoryManager($repositories);

                                foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                                    // Skip if already installed
                                    $reqModulePath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                                    if ($distCode) {
                                        $reqModulePathDist = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                        if (\is_dir($reqModulePathDist)) {
                                            continue;
                                        }
                                    }
                                    if (\is_dir($reqModulePath)) {
                                        continue;
                                    }

                                    $this->writeLineLogging('{@c:yellow}[INSTALL]{@reset} Installing dependency: {@c:cyan}' . $reqModuleCode . '{@reset}', true);

                                    // Get module info from repository
                                    $depInfo = $repoManager->getModuleInfo($reqModuleCode);
                                    if (!$depInfo) {
                                        $this->writeLineLogging('  {@c:red}[NOT FOUND] Module not found in repositories{@reset}', true);
                                        $this->writeLineLogging('  Install manually: {@c:cyan}php Razy.phar install ' . $reqModuleCode . '{@reset}', true);
                                        continue;
                                    }

                                    // Determine version to install
                                    $depVersion = $depInfo['latest'] ?? null;
                                    if (!$depVersion) {
                                        $this->writeLineLogging('  {@c:red}[ERROR] No version available{@reset}', true);
                                        continue;
                                    }

                                    $depUrl = $repoManager->getDownloadUrl($reqModuleCode, $depVersion);
                                    if (!$depUrl) {
                                        $this->writeLineLogging('  {@c:red}[ERROR] Could not get download URL{@reset}', true);
                                        continue;
                                    }

                                    // Determine target path for dependency
                                    $depTargetPath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module', $reqModuleCode);
                                    if ($distCode) {
                                        $depTargetPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode, 'modules', \basename($reqModuleCode));
                                    }

                                    // Install dependency
                                    $depInstaller = new RepoInstaller($depUrl, $depTargetPath, function ($type, ...$data) {
                                        // Minimal output for dependencies
                                        if ($type === RepoInstaller::TYPE_INSTALL_COMPLETE) {
                                            $this->writeLineLogging('  {@c:green}[✓] Installed{@reset}', true);
                                        } elseif ($type === RepoInstaller::TYPE_ERROR) {
                                            $this->writeLineLogging('  {@c:red}[ERROR] ' . $data[0] . '{@reset}', true);
                                        }
                                    });

                                    if ($depInstaller->validate()) {
                                        $depInstaller->install();
                                    } else {
                                        $this->writeLineLogging('  {@c:red}[ERROR] Failed to validate repository{@reset}', true);
                                    }
                                }
                            }
                        } else {
                            $this->writeLineLogging('', true);
                            $this->writeLineLogging('To install dependencies later, run:', true);
                            foreach ($requiredModules as $reqModuleCode => $reqVersion) {
                                $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $reqModuleCode . ' --from-repo{@reset}', true);
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Silently ignore if module.php can't be parsed
                }
            }

            exit(0);
        } else {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:red}[ERROR] Installation failed.{@reset}', true);
            exit(1);
        }
    } catch (Exception $e) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}[ERROR] ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }
};
