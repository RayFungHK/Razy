<?php

/**
 * CLI Command: publish.
 *
 * Generates a repository index from packaged modules and optionally pushes
 * to GitHub. Scans the packages/ directory for .phar files, updates manifest.json
 * for each module, generates a master index.json, and can create GitHub Releases
 * with .phar assets attached.
 *
 * Usage:
 *   php Razy.phar publish [packages_path] [options]
 *
 * Options:
 *   --push              Push index and metadata to GitHub repository
 *   --branch=NAME       Branch to push to (default: main)
 *   --dist=CODE         Distributor code to scan for source modules
 *   --include-shared    Include shared modules when scanning with --scan
 *   --scan              Scan source modules and auto-pack new versions
 *   --cleanup           Remove old .phar files from repo (moved to Releases)
 *   -v, --verbose       Show detailed information
 *   --dry-run           Preview changes without modifying files
 *   --force             Force push even if version exists
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

return function (string $packagesPath = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Repository Publisher', true);
    $this->writeLineLogging('Generate repository index from packaged modules', true);
    $this->writeLineLogging('', true);

    // Treat leading-dash first argument as an option, not a path
    if (\str_starts_with($packagesPath, '-')) {
        \array_unshift($options, $packagesPath);
        $packagesPath = '';
    }

    // Parse options
    $verbose = false;
    $dryRun = false;
    $push = false;
    $force = false;
    $cleanup = false;
    $branch = 'main';
    $distCode = '';
    $includeShared = false;
    $scan = false;

    foreach ($options as $option) {
        if ($option === '-v' || $option === '--verbose') {
            $verbose = true;
        } elseif ($option === '--dry-run') {
            $dryRun = true;
        } elseif ($option === '--push') {
            $push = true;
        } elseif ($option === '--force') {
            $force = true;
        } elseif ($option === '--cleanup') {
            $cleanup = true;
        } elseif (\str_starts_with($option, '--branch=')) {
            $branch = \substr($option, 9);
        } elseif (\str_starts_with($option, '--dist=')) {
            $distCode = \substr($option, 7);
        } elseif ($option === '--include-shared') {
            $includeShared = true;
        } elseif ($option === '--scan') {
            $scan = true;
        }
    }

    // Resolve packages directory (default: SYSTEM_ROOT/packages)
    if (!$packagesPath) {
        $packagesPath = PathUtil::append(SYSTEM_ROOT, 'packages');
    } elseif (!\preg_match('#^([a-z]:)?[/\\\]#i', $packagesPath)) {
        $packagesPath = PathUtil::append(SYSTEM_ROOT, $packagesPath);
    }

    if (!\is_dir($packagesPath)) {
        $this->writeLineLogging('{@c:red}[ERROR] Packages directory not found: ' . $packagesPath . '{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar publish [packages_path]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('First, package your modules using:', true);
        $this->writeLineLogging('  php Razy.phar pack vendor/module 1.0.0', true);
        exit(1);
    }

    // Load GitHub credentials from publish.inc.php when pushing
    $token = null;
    $repo = null;

    if ($push) {
        // Require a publish configuration file with token and repo
        $publishConfigPath = PathUtil::append($packagesPath, 'publish.inc.php');
        if (!\is_file($publishConfigPath)) {
            $this->writeLineLogging('{@c:red}[ERROR] publish.inc.php not found in packages directory{@reset}', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Create {@c:cyan}' . $publishConfigPath . '{@reset}:', true);
            $this->writeLineLogging('  {@c:cyan}<?php{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}    \'token\' => \'ghp_your_token\',{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}    \'repo\' => \'owner/repo\',{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}];{@reset}', true);
            exit(1);
        }

        $publishConfig = require $publishConfigPath;
        if (!\is_array($publishConfig)) {
            $this->writeLineLogging('{@c:red}[ERROR] publish.inc.php must return an array{@reset}', true);
            exit(1);
        }

        $token = $publishConfig['token'] ?? null;
        $repo = $publishConfig['repo'] ?? null;

        if (!$token) {
            $this->writeLineLogging('{@c:red}[ERROR] Missing \'token\' in publish.inc.php{@reset}', true);
            exit(1);
        }
        if (!$repo) {
            $this->writeLineLogging('{@c:red}[ERROR] Missing \'repo\' in publish.inc.php{@reset}', true);
            exit(1);
        }
        if (!\preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+$#', $repo)) {
            $this->writeLineLogging('{@c:red}[ERROR] Invalid repository format. Expected: owner/repo{@reset}', true);
            exit(1);
        }

        $this->writeLineLogging('Config loaded from: {@c:cyan}publish.inc.php{@reset}', true);
        $this->writeLineLogging('Repository: {@c:cyan}' . $repo . '{@reset} (branch: {@c:cyan}' . $branch . '{@reset})', true);
        $this->writeLineLogging('', true);
    }

    // Verify phar.readonly is disabled when --scan requires creating .phar files
    if ($scan && \ini_get('phar.readonly') == 1) {
        $this->writeLineLogging('{@c:red}[ERROR] Cannot create .phar files with --scan.{@reset}', true);
        $this->writeLineLogging('{@c:red}        Set phar.readonly=0 in php.ini or use -d phar.readonly=0{@reset}', true);
        exit(1);
    }

    // Auto-pack: scan distributor/shared modules and create .phar for new versions
    if ($scan) {
        // If no dist specified and not include-shared, prompt user to select
        if (!$distCode && !$includeShared) {
            $this->writeLineLogging('{@c:yellow}[SELECT] Which modules to scan?{@reset}', true);
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

            $this->writeLineLogging('  {@c:cyan}[0]{@reset} Shared modules only', true);
            $index = 1;
            foreach ($distributors as $dist) {
                $this->writeLineLogging('  {@c:cyan}[' . $index . ']{@reset} Distributor: ' . $dist, true);
                $index++;
            }
            $this->writeLineLogging('  {@c:cyan}[A]{@reset} All (shared + all distributors)', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Enter selection: ', false);

            $handle = \fopen('php://stdin', 'r');
            $selection = \strtolower(\trim(\fgets($handle)));
            \fclose($handle);
            $this->writeLineLogging('', true);

            if ($selection === '0') {
                $includeShared = true;
            } elseif ($selection === 'a' || $selection === 'all') {
                $includeShared = true;
                // Set distCode to scan all distributors
                $distCode = '*';
            } elseif (\is_numeric($selection) && $selection > 0 && $selection <= \count($distributors)) {
                $distCode = $distributors[(int) $selection - 1];
            } else {
                $this->writeLineLogging('{@c:red}[ERROR] Invalid selection{@reset}', true);
                exit(1);
            }
        }

        $this->writeLineLogging('{@s:bu}Scanning Source Modules', true);
        if ($distCode === '*') {
            $this->writeLineLogging('Distributor: {@c:cyan}All distributors{@reset}', true);
        } elseif ($distCode) {
            $this->writeLineLogging('Distributor: {@c:cyan}' . $distCode . '{@reset}', true);
        }
        if ($includeShared) {
            $this->writeLineLogging('Include shared modules: {@c:cyan}Yes{@reset}', true);
        }
        $this->writeLineLogging('', true);

        $modulesToPack = [];

        // Scan distributor modules
        if ($distCode) {
            // Handle wildcard for all distributors
            $distributorsToScan = [];
            if ($distCode === '*') {
                $sitesPath = PathUtil::append(SYSTEM_ROOT, 'sites');
                if (\is_dir($sitesPath)) {
                    $dirs = \glob(PathUtil::append($sitesPath, '*'), GLOB_ONLYDIR);
                    foreach ($dirs as $dir) {
                        $distributorsToScan[] = \basename($dir);
                    }
                }
            } else {
                $distributorsToScan[] = $distCode;
            }

            foreach ($distributorsToScan as $currentDist) {
                $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $currentDist);
                if (!\is_dir($distPath)) {
                    $this->writeLineLogging('{@c:yellow}[WARN] Distributor not found: ' . $currentDist . '{@reset}', true);
                    continue;
                }

                // Scan vendor directories under distributor
                $distVendors = \glob(PathUtil::append($distPath, '*'), GLOB_ONLYDIR);
                foreach ($distVendors as $vendorPath) {
                    $vendor = \basename($vendorPath);
                    // Skip system directories
                    if (\in_array($vendor, ['config', 'plugins', 'shared', 'tools'])) {
                        continue;
                    }

                    $modules = \glob(PathUtil::append($vendorPath, '*'), GLOB_ONLYDIR);
                    foreach ($modules as $modulePath) {
                        $module = \basename($modulePath);
                        $moduleCode = $vendor . '/' . $module;
                        $moduleConfigPath = PathUtil::append($modulePath, 'module.php');

                        if (\is_file($moduleConfigPath)) {
                            $modulesToPack[] = [
                                'code' => $moduleCode,
                                'dist' => $currentDist,
                                'path' => $modulePath,
                            ];
                        }
                    }
                }
            }
        }

        // Scan shared modules
        if ($includeShared) {
            $sharedPath = PathUtil::append(SYSTEM_ROOT, 'shared', 'module');
            if (\is_dir($sharedPath)) {
                $sharedVendors = \glob(PathUtil::append($sharedPath, '*'), GLOB_ONLYDIR);
                foreach ($sharedVendors as $vendorPath) {
                    $vendor = \basename($vendorPath);
                    $modules = \glob(PathUtil::append($vendorPath, '*'), GLOB_ONLYDIR);
                    foreach ($modules as $modulePath) {
                        $module = \basename($modulePath);
                        $moduleCode = $vendor . '/' . $module;
                        $moduleConfigPath = PathUtil::append($modulePath, 'module.php');

                        if (\is_file($moduleConfigPath)) {
                            $modulesToPack[] = [
                                'code' => $moduleCode,
                                'dist' => '',  // shared module
                                'path' => $modulePath,
                            ];
                        }
                    }
                }
            }
        }

        if (empty($modulesToPack)) {
            $this->writeLineLogging('{@c:yellow}[WARNING] No modules found to scan.{@reset}', true);
        } else {
            $packedCount = 0;
            $skippedCount = 0;

            foreach ($modulesToPack as $moduleData) {
                $moduleCode = $moduleData['code'];
                $moduleDist = $moduleData['dist'];
                $modulePath = $moduleData['path'];

                // Load module.php to get version
                try {
                    $config = require PathUtil::append($modulePath, 'module.php');
                    $version = $config['version'] ?? null;

                    if (!$version) {
                        if ($verbose) {
                            $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . ($moduleDist ? $moduleDist . '@' : '') . $moduleCode . ' (no version)', true);
                        }
                        $skippedCount++;
                        continue;
                    }

                    // Check if this version already exists in packages
                    $packageModulePath = PathUtil::append($packagesPath, $moduleCode);
                    $pharPath = PathUtil::append($packageModulePath, $version . '.phar');

                    if (\is_file($pharPath)) {
                        if ($verbose) {
                            $this->writeLineLogging('  [{@c:blue}EXISTS{@reset}] ' . ($moduleDist ? $moduleDist . '@' : '') . $moduleCode . ' v' . $version, true);
                        }
                        $skippedCount++;
                        continue;
                    }

                    // Pack the module using pack command logic
                    $this->writeLineLogging('  [{@c:yellow}PACK{@reset}] ' . ($moduleDist ? $moduleDist . '@' : '') . $moduleCode . ' v' . $version, true);

                    if (!$dryRun) {
                        $packResult = packModule($modulePath, $moduleCode, $version, $packagesPath, $config);
                        if ($packResult['success']) {
                            $this->writeLineLogging('    [{@c:green}OK{@reset}] ' . $packResult['pharFile'], true);
                            $packedCount++;
                        } else {
                            $this->writeLineLogging('    [{@c:red}FAIL{@reset}] ' . $packResult['error'], true);
                            $skippedCount++;
                        }
                    } else {
                        $packedCount++;
                    }
                } catch (Exception $e) {
                    if ($verbose) {
                        $this->writeLineLogging('  [{@c:red}ERROR{@reset}] ' . ($moduleDist ? $moduleDist . '@' : '') . $moduleCode . ' - ' . $e->getMessage(), true);
                    }
                    $skippedCount++;
                }
            }

            $this->writeLineLogging('', true);
            $this->writeLineLogging('Scan complete: {@c:green}' . $packedCount . ' packed{@reset}, {@c:yellow}' . $skippedCount . ' skipped{@reset}', true);
        }
        $this->writeLineLogging('', true);
    }

    $this->writeLineLogging('Scanning: {@c:cyan}' . $packagesPath . '{@reset}', true);
    if ($dryRun) {
        $this->writeLineLogging('{@c:yellow}[DRY RUN] No files will be modified{@reset}', true);
    }
    $this->writeLineLogging('', true);

    // Build the master index by scanning all vendor/module directories for .phar files
    $index = [];
    $moduleCount = 0;
    $versionCount = 0;
    $newVersions = [];

    // Iterate over vendor directories within the packages folder
    $vendors = \glob(PathUtil::append($packagesPath, '*'), GLOB_ONLYDIR);

    if (empty($vendors)) {
        $this->writeLineLogging('{@c:yellow}[WARNING] No vendor directories found.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Expected structure:', true);
        $this->writeLineLogging('  packages/', true);
        $this->writeLineLogging('   publish.inc.php', true);
        $this->writeLineLogging('   vendor/', true);
        $this->writeLineLogging('       module/', true);
        $this->writeLineLogging('           manifest.json', true);
        $this->writeLineLogging('           changelog/', true);
        $this->writeLineLogging('              1.0.0.txt', true);
        $this->writeLineLogging('           1.0.0.phar', true);
        exit(1);
    }

    // Fetch existing Git tags from GitHub to detect new versions
    $remoteVersions = [];
    if ($push && !$dryRun) {
        $this->writeLineLogging('[{@c:yellow}CHECK{@reset}] Fetching existing tags from GitHub...', true);
        $remoteVersions = githubGetTags($token, $repo);
        if ($verbose && !empty($remoteVersions)) {
            $this->writeLineLogging('    Existing tags: ' . \implode(', ', \array_slice($remoteVersions, 0, 5)) . (\count($remoteVersions) > 5 ? '...' : ''), true);
        }
        $this->writeLineLogging('', true);
    }

    foreach ($vendors as $vendorPath) {
        $vendor = \basename($vendorPath);

        if ($verbose) {
            $this->writeLineLogging('[{@c:blue}VENDOR{@reset}] ' . $vendor, true);
        }

        // Scan module directories
        $modules = \glob(PathUtil::append($vendorPath, '*'), GLOB_ONLYDIR);

        foreach ($modules as $modulePath) {
            $module = \basename($modulePath);
            $moduleCode = $vendor . '/' . $module;

            // Read manifest.json
            $manifestPath = PathUtil::append($modulePath, 'manifest.json');
            if (!\is_file($manifestPath)) {
                if ($verbose) {
                    $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . $moduleCode . ' (no manifest.json)', true);
                }
                continue;
            }

            $manifest = \json_decode(\file_get_contents($manifestPath), true);
            if (\json_last_error() !== JSON_ERROR_NONE) {
                if ($verbose) {
                    $this->writeLineLogging('  [{@c:red}ERROR{@reset}] ' . $moduleCode . ' (invalid manifest.json)', true);
                }
                continue;
            }

            // Verify phar files exist and standardize versions
            $validVersions = [];
            $pharFiles = \glob(PathUtil::append($modulePath, '*.phar'));
            foreach ($pharFiles as $pharFile) {
                $filename = \basename($pharFile, '.phar');
                $normalizedVersion = normalizeVersion($filename);
                if ($normalizedVersion) {
                    $validVersions[] = $normalizedVersion;

                    // Track for publishing
                    // Tag format: {vendor}-{module}-v{version} (e.g., demo-demo_module-v1.0.0)
                    $tagName = \str_replace('/', '-', $moduleCode) . '-v' . $normalizedVersion;
                    if ($push) {
                        if (\in_array($tagName, $remoteVersions)) {
                            if (!$force) {
                                $this->writeLineLogging('  [{@c:yellow}WARN{@reset}] Version ' . $normalizedVersion . ' already exists as tag ' . $tagName, true);
                            }
                        } else {
                            // Read changelog for commit message
                            $changelogPath = PathUtil::append($modulePath, 'changelog', $normalizedVersion . '.txt');
                            $commitMessage = 'Release ' . $moduleCode . ' v' . $normalizedVersion;
                            if (\is_file($changelogPath)) {
                                $commitMessage = \trim(\file_get_contents($changelogPath));
                                if ($verbose) {
                                    $this->writeLineLogging('    Changelog found for ' . $normalizedVersion, true);
                                }
                            } elseif ($verbose) {
                                $this->writeLineLogging('    {@c:yellow}No changelog for ' . $normalizedVersion . ' (using default message){@reset}', true);
                            }

                            $newVersions[] = [
                                'module' => $moduleCode,
                                'version' => $normalizedVersion,
                                'tag' => $tagName,
                                'message' => $commitMessage,
                                'pharPath' => \basename($pharFile),
                            ];
                        }
                    }
                }
            }

            if (empty($validVersions)) {
                if ($verbose) {
                    $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . $moduleCode . ' (no .phar files)', true);
                }
                continue;
            }

            // Sort versions (newest first)
            \usort($validVersions, 'version_compare');
            $validVersions = \array_reverse($validVersions);

            // Update manifest versions
            $manifest['versions'] = $validVersions;
            $manifest['latest'] = $validVersions[0];

            // Write updated manifest
            if (!$dryRun) {
                \file_put_contents($manifestPath, \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Add to index
            $index[$moduleCode] = [
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? '',
                'latest' => $manifest['latest'],
                'versions' => $manifest['versions'],
            ];

            $moduleCount++;
            $versionCount += \count($validVersions);

            $this->writeLineLogging('[{@c:green}{@reset}] ' . $moduleCode . ' ({@c:cyan}' . \count($validVersions) . ' versions{@reset})', true);
            if ($verbose) {
                $this->writeLineLogging('    Latest: {@c:green}' . $manifest['latest'] . '{@reset}', true);
                $this->writeLineLogging('    Versions: ' . \implode(', ', $validVersions), true);
            }
        }
    }

    $this->writeLineLogging('', true);

    if ($moduleCount === 0) {
        $this->writeLineLogging('{@c:yellow}[WARNING] No valid modules found.{@reset}', true);
        exit(1);
    }

    // Write index.json containing all modules and their version information
    $indexPath = PathUtil::append($packagesPath, 'index.json');
    if (!$dryRun) {
        $indexContent = \json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \file_put_contents($indexPath, $indexContent);
    }

    $this->writeLineLogging('[{@c:green}{@reset}] Generated: index.json', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@c:green}[SUCCESS] Repository index published!{@reset}', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('Summary:', true);
    $this->writeLineLogging('  Modules: {@c:cyan}' . $moduleCount . '{@reset}', true);
    $this->writeLineLogging('  Versions: {@c:cyan}' . $versionCount . '{@reset}', true);
    if ($push && \count($newVersions) > 0) {
        $this->writeLineLogging('  New versions to publish: {@c:cyan}' . \count($newVersions) . '{@reset}', true);
    }
    $this->writeLineLogging('', true);

    // Upload files to GitHub via API if --push is enabled
    if ($push && !$dryRun) {
        $this->writeLineLogging('{@c:yellow}[PUSH] Uploading to GitHub: ' . $repo . '{@reset}', true);
        $this->writeLineLogging('', true);

        $filesToUpload = [];

        // Collect all files to upload
        $filesToUpload['index.json'] = [
            'content' => \json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'message' => 'Update repository index',
        ];

        // Collect manifest.json and .phar files for each module
        foreach ($index as $moduleCode => $moduleInfo) {
            $modulePath = PathUtil::append($packagesPath, $moduleCode);

            // Add manifest.json
            $manifestPath = PathUtil::append($modulePath, 'manifest.json');
            if (\is_file($manifestPath)) {
                $filesToUpload[$moduleCode . '/manifest.json'] = [
                    'content' => \file_get_contents($manifestPath),
                    'message' => 'Update ' . $moduleCode . ' manifest',
                ];
            }

            // Add latest.json
            $latestPath = PathUtil::append($modulePath, 'latest.json');
            if (\is_file($latestPath)) {
                $filesToUpload[$moduleCode . '/latest.json'] = [
                    'content' => \file_get_contents($latestPath),
                    'message' => 'Update ' . $moduleCode . ' latest pointer',
                ];
            }

            // Add disclaimer.txt if exists
            $disclaimerPath = PathUtil::append($modulePath, 'disclaimer.txt');
            if (\is_file($disclaimerPath)) {
                $filesToUpload[$moduleCode . '/disclaimer.txt'] = [
                    'content' => \file_get_contents($disclaimerPath),
                    'message' => 'Update ' . $moduleCode . ' disclaimer',
                ];
            }

            // Add terms.txt if exists
            $termsPath = PathUtil::append($modulePath, 'terms.txt');
            if (\is_file($termsPath)) {
                $filesToUpload[$moduleCode . '/terms.txt'] = [
                    'content' => \file_get_contents($termsPath),
                    'message' => 'Update ' . $moduleCode . ' terms',
                ];
            }

            // Note: .phar files are now uploaded via GitHub Releases, not as repo content
        }

        $uploadCount = 0;
        $errorCount = 0;

        foreach ($filesToUpload as $filePath => $fileData) {
            $result = githubPutFile($token, $repo, $branch, $filePath, $fileData['content'], $fileData['message']);

            if ($result['success']) {
                $this->writeLineLogging('[{@c:green}{@reset}] ' . $filePath, true);
                $uploadCount++;
            } else {
                $this->writeLineLogging('[{@c:red}{@reset}] ' . $filePath . ' - ' . $result['error'], true);
                $errorCount++;
            }
        }

        // Create tags and releases for new versions
        if (!empty($newVersions)) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('[{@c:yellow}RELEASES{@reset}] Creating GitHub Releases...', true);

            // Get existing releases to avoid duplicates
            $existingReleases = githubGetReleases($token, $repo);

            foreach ($newVersions as $versionInfo) {
                $tagName = $versionInfo['tag'];
                $moduleCode = $versionInfo['module'];
                $pharFilename = $versionInfo['pharPath'];
                $pharLocalPath = PathUtil::append($packagesPath, $moduleCode, $pharFilename);

                // Create tag first
                $tagResult = githubCreateTag($token, $repo, $branch, $tagName, $versionInfo['message']);
                if (!$tagResult['success']) {
                    // Tag may already exist, that's okay
                    if (\strpos($tagResult['error'], 'Reference already exists') === false) {
                        $this->writeLineLogging('[{@c:red}{@reset}] Tag: ' . $tagName . ' - ' . $tagResult['error'], true);
                        continue;
                    }
                } else {
                    $this->writeLineLogging('[{@c:green}{@reset}] Tag: ' . $tagName, true);
                }

                // Check if release already exists
                if (isset($existingReleases[$tagName])) {
                    // Check if asset already exists
                    if (\in_array($pharFilename, $existingReleases[$tagName]['assets'])) {
                        $this->writeLineLogging('[{@c:cyan}SKIP{@reset}] Release: ' . $tagName . ' (asset exists)', true);
                        continue;
                    }
                    // Release exists but asset missing, upload asset
                    $releaseId = $existingReleases[$tagName]['id'];
                } else {
                    // Create release
                    $releaseName = $moduleCode . ' v' . $versionInfo['version'];
                    $releaseResult = githubCreateRelease($token, $repo, $tagName, $releaseName, $versionInfo['message']);

                    if (!$releaseResult['success']) {
                        $this->writeLineLogging('[{@c:red}{@reset}] Release: ' . $tagName . ' - ' . $releaseResult['error'], true);
                        continue;
                    }

                    $releaseId = $releaseResult['release_id'];
                    $this->writeLineLogging('[{@c:green}{@reset}] Release: ' . $tagName, true);
                }

                // Upload .phar as release asset
                if (\is_file($pharLocalPath)) {
                    $assetResult = githubUploadReleaseAsset($token, $repo, $releaseId, $pharLocalPath, $pharFilename);
                    if ($assetResult['success']) {
                        $this->writeLineLogging('[{@c:green}{@reset}] Asset: ' . $pharFilename, true);
                    } else {
                        $this->writeLineLogging('[{@c:red}{@reset}] Asset: ' . $pharFilename . ' - ' . $assetResult['error'], true);
                    }
                } else {
                    $this->writeLineLogging('[{@c:yellow}WARN{@reset}] Asset not found: ' . $pharLocalPath, true);
                }
            }
        }

        // Cleanup old .phar files from repo if --cleanup is enabled
        if ($cleanup) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('[{@c:yellow}CLEANUP{@reset}] Removing old .phar files from repository...', true);

            foreach ($index as $moduleCode => $moduleInfo) {
                foreach ($moduleInfo['versions'] as $version) {
                    $pharPath = $moduleCode . '/' . $version . '.phar';
                    $deleteResult = githubDeleteFile($token, $repo, $branch, $pharPath, 'Remove old .phar file (moved to GitHub Releases)');

                    if ($deleteResult['success']) {
                        $this->writeLineLogging('[{@c:green}{@reset}] Deleted: ' . $pharPath, true);
                    } elseif ($verbose) {
                        $this->writeLineLogging('[{@c:cyan}SKIP{@reset}] ' . $pharPath . ' (not found)', true);
                    }
                }

                // Also delete latest.json as it's no longer needed
                $latestPath = $moduleCode . '/latest.json';
                $deleteResult = githubDeleteFile($token, $repo, $branch, $latestPath, 'Remove latest.json (download URL now uses releases)');
                if ($deleteResult['success']) {
                    $this->writeLineLogging('[{@c:green}{@reset}] Deleted: ' . $latestPath, true);
                } elseif ($verbose) {
                    $this->writeLineLogging('[{@c:cyan}SKIP{@reset}] ' . $latestPath . ' (not found)', true);
                }
            }
        }

        $this->writeLineLogging('', true);
        if ($errorCount === 0) {
            $this->writeLineLogging('{@c:green}[SUCCESS] All files uploaded to GitHub!{@reset}', true);
        } else {
            $this->writeLineLogging('{@c:yellow}[WARNING] ' . $uploadCount . ' files uploaded, ' . $errorCount . ' failed{@reset}', true);
        }
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Repository URL: {@c:cyan}https://github.com/' . $repo . '{@reset}', true);
    } elseif (!$push) {
        // Show instructions for setting up push
        $this->writeLineLogging('To push to GitHub, create {@c:cyan}' . PathUtil::append($packagesPath, 'publish.inc.php') . '{@reset}:', true);
        $this->writeLineLogging('  {@c:cyan}<?php{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    \'token\' => \'ghp_your_token\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}    \'repo\' => \'owner/repo\',{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}];{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Then run: {@c:cyan}php Razy.phar publish --push{@reset}', true);
        $this->writeLineLogging('For prerelease: {@c:cyan}php Razy.phar publish --push --branch=prerelease{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Add changelog for versions in {@c:cyan}packages/vendor/module/changelog/<version>.txt{@reset}', true);
    }

    $this->writeLineLogging('', true);

    // Show sample repository.inc.php config
    $this->writeLineLogging('To use this repository, add to repository.inc.php:', true);
    $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
    if ($push && $repo) {
        $this->writeLineLogging('  {@c:cyan}    \'https://github.com/' . $repo . '/\' => \'' . $branch . '\',{@reset}', true);
    } else {
        $this->writeLineLogging('  {@c:cyan}    \'https://github.com/YOUR_USERNAME/YOUR_REPO/\' => \'main\',{@reset}', true);
    }
    $this->writeLineLogging('  {@c:cyan}];{@reset}', true);

    exit(0);
};

/**
 * Normalize a version string to standard X.Y.Z format.
 *
 * @param string $version The raw version string (may include 'v' prefix)
 *
 * @return string|null Normalized version string, or null if invalid
 */
function normalizeVersion(string $version): ?string
{
    // Remove 'v' prefix if present
    $version = \ltrim($version, 'vV');

    // Match version pattern
    if (\preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([a-zA-Z0-9.]+))?$/', $version, $matches)) {
        $major = $matches[1];
        $minor = $matches[2] ?? '0';
        $patch = $matches[3] ?? '0';
        $build = isset($matches[4]) ? '.' . $matches[4] : '';
        $prerelease = isset($matches[5]) ? '-' . $matches[5] : '';

        return $major . '.' . $minor . '.' . $patch . $build . $prerelease;
    }

    return null;
}

/**
 * Retrieve all tag names from a GitHub repository.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 *
 * @return array List of tag names
 */
function githubGetTags(string $token, string $repo): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/tags';

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $tags = \json_decode($response, true);
    if (!\is_array($tags)) {
        return [];
    }

    return \array_column($tags, 'name');
}

/**
 * Create a lightweight tag on a GitHub repository branch.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Branch to tag from
 * @param string $tagName The tag name to create
 * @param string $message The commit message for the tag
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubCreateTag(string $token, string $repo, string $branch, string $tagName, string $message): array
{
    // First, get the SHA of the branch
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/git/ref/heads/' . $branch;

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Could not get branch SHA'];
    }

    $refData = \json_decode($response, true);
    $sha = $refData['object']['sha'] ?? null;

    if (!$sha) {
        return ['success' => false, 'error' => 'Branch SHA not found'];
    }

    // Create the tag reference
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/git/refs';

    $data = [
        'ref' => 'refs/tags/' . $tagName,
        'sha' => $sha,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Delete a file from a GitHub repository via the Contents API.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Branch containing the file
 * @param string $path File path within the repository
 * @param string $message Commit message for the deletion
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubDeleteFile(string $token, string $repo, string $branch, string $path, string $message): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . $path;

    // First, get the file to get its SHA
    $ch = \curl_init($apiUrl . '?ref=' . $branch);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'File not found'];
    }

    $fileData = \json_decode($response, true);
    $sha = $fileData['sha'] ?? null;

    if (!$sha) {
        return ['success' => false, 'error' => 'Could not get file SHA'];
    }

    // Delete the file
    $data = [
        'message' => $message,
        'sha' => $sha,
        'branch' => $branch,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Upload or update a file in a GitHub repository via the Contents API.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Target branch
 * @param string $path File path within the repository
 * @param string $content File content to upload
 * @param string $message Commit message
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubPutFile(string $token, string $repo, string $branch, string $path, string $content, string $message): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . $path;

    // First, try to get the file to check if it exists (need SHA for update)
    $ch = \curl_init($apiUrl . '?ref=' . $branch);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    $sha = null;
    if ($httpCode === 200) {
        $fileData = \json_decode($response, true);
        $sha = $fileData['sha'] ?? null;
    }

    // Prepare the content
    $data = [
        'message' => $message,
        'content' => \base64_encode($content),
        'branch' => $branch,
    ];

    if ($sha) {
        $data['sha'] = $sha;
    }

    // Upload/update the file
    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Create a GitHub Release associated with a tag.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $tagName Tag to create the release from
 * @param string $name Release title
 * @param string $body Release notes / description
 * @param bool $prerelease Whether to mark as pre-release
 *
 * @return array Result with 'success', 'release_id', and 'upload_url'
 */
function githubCreateRelease(string $token, string $repo, string $tagName, string $name, string $body, bool $prerelease = false): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/releases';

    $data = [
        'tag_name' => $tagName,
        'name' => $name,
        'body' => $body,
        'draft' => false,
        'prerelease' => $prerelease,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        $releaseData = \json_decode($response, true);
        return ['success' => true, 'release_id' => $releaseData['id'], 'upload_url' => $releaseData['upload_url']];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Upload a binary asset to a GitHub Release.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param int $releaseId The Release ID to attach the asset to
 * @param string $assetPath Local filesystem path to the asset file
 * @param string $assetName Filename for the uploaded asset
 *
 * @return array Result with 'success' and optional 'download_url' or 'error'
 */
function githubUploadReleaseAsset(string $token, string $repo, int $releaseId, string $assetPath, string $assetName): array
{
    $uploadUrl = 'https://uploads.github.com/repos/' . $repo . '/releases/' . $releaseId . '/assets?name=' . \urlencode($assetName);

    $fileContent = \file_get_contents($assetPath);
    if ($fileContent === false) {
        return ['success' => false, 'error' => 'Cannot read file: ' . $assetPath];
    }

    $ch = \curl_init($uploadUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/octet-stream',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        $assetData = \json_decode($response, true);
        return ['success' => true, 'download_url' => $assetData['browser_download_url']];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Retrieve all existing releases and their assets from a GitHub repository.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 *
 * @return array Map of tag_name => ['id' => int, 'assets' => string[]]
 */
function githubGetReleases(string $token, string $repo): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/releases';

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $releases = \json_decode($response, true);
    if (!\is_array($releases)) {
        return [];
    }

    // Return map of tag_name => release info
    $result = [];
    foreach ($releases as $release) {
        $result[$release['tag_name']] = [
            'id' => $release['id'],
            'assets' => \array_column($release['assets'], 'name'),
        ];
    }

    return $result;
}

/**
 * Pack a module into a .phar file for distribution.
 *
 * @param string $modulePath Absolute path to the module directory
 * @param string $moduleCode Module code in vendor/module format
 * @param string $version Semver version string
 * @param string $outputBasePath Base output directory for packages
 * @param array $config Module configuration from module.php
 *
 * @return array Result with 'success' and 'pharFile' or 'error'
 */
function packModule(string $modulePath, string $moduleCode, string $version, string $outputBasePath, array $config): array
{
    try {
        // Determine output path
        $outputPath = PathUtil::append($outputBasePath, $moduleCode);

        // Create output directory
        if (!\is_dir($outputPath)) {
            if (!\mkdir($outputPath, 0755, true)) {
                return ['success' => false, 'error' => 'Cannot create output directory'];
            }
        }

        // Determine source package path
        $packagePath = PathUtil::append($modulePath, 'default');
        if (!\is_dir($packagePath)) {
            return ['success' => false, 'error' => 'Default package not found'];
        }

        // Create .phar file
        $pharFilename = $version . '.phar';
        $pharPath = PathUtil::append($outputPath, $pharFilename);

        // Remove existing file
        if (\is_file($pharPath)) {
            \unlink($pharPath);
        }

        $phar = new Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        // Add package files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packagePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = \substr($file->getPathname(), \strlen($packagePath) + 1);
            $relativePath = \str_replace('\\', '/', $relativePath);

            if ($file->isDir()) {
                $phar->addEmptyDir($relativePath);
            } else {
                $phar->addFile($file->getPathname(), $relativePath);
            }
        }

        // Add module.php
        $modulePhpPath = PathUtil::append($modulePath, 'module.php');
        if (\is_file($modulePhpPath)) {
            $phar->addFile($modulePhpPath, 'module.php');
        }

        // Add webassets if exists
        $webassetsPath = PathUtil::append($modulePath, 'webassets');
        if (\is_dir($webassetsPath)) {
            $webIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($webassetsPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($webIterator as $file) {
                $relativePath = 'webassets/' . \substr($file->getPathname(), \strlen($webassetsPath) + 1);
                $relativePath = \str_replace('\\', '/', $relativePath);

                if ($file->isDir()) {
                    $phar->addEmptyDir($relativePath);
                } else {
                    $phar->addFile($file->getPathname(), $relativePath);
                }
            }
        }

        $phar->stopBuffering();

        // Compress if possible
        if (Phar::canCompress(Phar::GZ)) {
            $phar->compressFiles(Phar::GZ);
        }

        // Create/update manifest.json
        $manifestPath = PathUtil::append($outputPath, 'manifest.json');
        $manifest = [];
        if (\is_file($manifestPath)) {
            $manifest = \json_decode(\file_get_contents($manifestPath), true) ?? [];
        }

        $manifest['module_code'] = $config['module_code'] ?? $moduleCode;
        $manifest['description'] = $config['description'] ?? '';
        $manifest['author'] = $config['author'] ?? '';

        // Add version to versions list
        if (!isset($manifest['versions']) || !\is_array($manifest['versions'])) {
            $manifest['versions'] = [];
        }
        if (!\in_array($version, $manifest['versions'])) {
            $manifest['versions'][] = $version;
            \usort($manifest['versions'], 'version_compare');
            $manifest['versions'] = \array_reverse($manifest['versions']);
        }
        $manifest['latest'] = $manifest['versions'][0];

        \file_put_contents($manifestPath, \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create latest.json
        $latestInfo = [
            'version' => $version,
            'checksum' => \hash_file('sha256', $pharPath),
            'size' => \filesize($pharPath),
            'timestamp' => \date('c'),
        ];
        \file_put_contents(PathUtil::append($outputPath, 'latest.json'), \json_encode($latestInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['success' => true, 'pharFile' => $pharFilename];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
