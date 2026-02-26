<?php

/**
 * CLI Command: inspect.
 *
 * Inspects a distributor's configuration, domain bindings, loaded modules,
 * and related metadata. Provides a detailed overview of a distributor's
 * setup including module status, version, type (shared/distribution),
 * aliases, and configuration settings.
 *
 * Usage:
 *   php Razy.phar inspect <distributor_code> [options]
 *
 * Arguments:
 *   distributor_code  Code of the distributor to inspect
 *
 * Options:
 *   -d, --details        Show detailed module information (author, alias, description, requires)
 *   -m, --modules-only   Show only module information
 *   --domains-only       Show only domain information
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Util\PathUtil;

return function (string $distCode = '', ...$options) use (&$parameters) {
    $this->writeLineLogging('{@s:bu}Distribution Inspector', true);
    $this->writeLineLogging('Check distributor configuration, domains, and modules', true);
    $this->writeLineLogging('', true);

    // Parse options
    $showDetails = false;
    $showModules = true;
    $showDomains = true;

    foreach ($options as $option) {
        if ($option === '--details' || $option === '-d') {
            $showDetails = true;
        } elseif ($option === '--modules-only' || $option === '-m') {
            $showDomains = false;
        } elseif ($option === '--domains-only') {
            $showModules = false;
        }
    }

    // Validate required parameters
    $distCode = \trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:red}[ERROR] Distributor code is required.{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  php Razy.phar inspect <distributor_code> [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Arguments:', true);
        $this->writeLineLogging('  {@c:green}distributor_code{@reset}     Code of the distributor to inspect', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Options:', true);
        $this->writeLineLogging('  {@c:green}-d, --details{@reset}        Show detailed module information', true);
        $this->writeLineLogging('  {@c:green}-m, --modules-only{@reset}   Show only module information', true);
        $this->writeLineLogging('  {@c:green}--domains-only{@reset}       Show only domain information', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Examples:', true);
        $this->writeLineLogging('  {@c:cyan}# Inspect distributor "mysite"{@reset}', true);
        $this->writeLineLogging('  php Razy.phar inspect mysite', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Show detailed module information{@reset}', true);
        $this->writeLineLogging('  php Razy.phar inspect mysite --details', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Show only modules{@reset}', true);
        $this->writeLineLogging('  php Razy.phar inspect mysite --modules-only', true);
        $this->writeLineLogging('', true);

        exit(1);
    }

    try {
        // Load application and verify the distributor exists in site config
        $app = new Application();
        $app->loadSiteConfig();

        if (!$app->hasDistributor($distCode)) {
            $this->writeLineLogging('{@c:red}[ERROR] Distributor "' . $distCode . '" not found.{@reset}', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Use {@c:cyan}php Razy.phar set{@reset} to create a new distributor.', true);
            exit(1);
        }

        // Get distributor information from application
        $app->updateSites();
        $distributorInfo = null;

        // Search all domain bindings for ones matching the given distributor code
        $config = $app->loadSiteConfig();
        $matchedDistributors = [];

        if (\is_array($config['domains'] ?? null)) {
            foreach ($config['domains'] as $domain => $distPaths) {
                foreach ($distPaths as $path => $distIdentifier) {
                    if (\is_string($distIdentifier)) {
                        [$code, $tag] = \explode('@', $distIdentifier . '@', 2);
                        if ($code === $distCode) {
                            $matchedDistributors[] = [
                                'domain' => $domain,
                                'path' => $path,
                                'identifier' => $distIdentifier,
                                'code' => $code,
                                'tag' => $tag ?: '*',
                            ];
                        }
                    }
                }
            }
        }

        if (empty($matchedDistributors)) {
            $this->writeLineLogging('{@c:red}[ERROR] No domain bindings found for distributor "' . $distCode . '".{@reset}', true);
            exit(1);
        }

        // Display distributor header
        $this->writeLineLogging('{@c:green}═══════════════════════════════════════════════════════════{@reset}', true);
        $this->writeLineLogging('{@c:green}  DISTRIBUTOR: ' . \strtoupper($distCode) . '{@reset}', true);
        $this->writeLineLogging('{@c:green}═══════════════════════════════════════════════════════════{@reset}', true);
        $this->writeLineLogging('', true);

        // Display domain bindings
        if ($showDomains) {
            $this->writeLineLogging('{@c:cyan}[DOMAIN BINDINGS]{@reset}', true);
            $this->writeLineLogging('', true);

            foreach ($matchedDistributors as $index => $dist) {
                $tag = $dist['tag'] === '*' ? 'default' : $dist['tag'];
                $this->writeLineLogging(\sprintf('  %d. {@c:yellow}%s{@reset}', $index + 1, $dist['domain']), true);
                $this->writeLineLogging(\sprintf('     Path:       {@c:white}%s{@reset}', $dist['path']), true);
                $this->writeLineLogging(\sprintf('     Identifier: {@c:white}%s{@reset}', $dist['identifier']), true);
                $this->writeLineLogging(\sprintf('     Tag:        {@c:white}%s{@reset}', $tag), true);

                // Check for domain aliases pointing to this domain
                $aliases = [];
                if (\is_array($config['alias'] ?? null)) {
                    foreach ($config['alias'] as $alias => $targetDomain) {
                        if ($targetDomain === $dist['domain']) {
                            $aliases[] = $alias;
                        }
                    }
                }

                if (!empty($aliases)) {
                    $this->writeLineLogging(\sprintf('     Aliases:    {@c:white}%s{@reset}', \implode(', ', $aliases)), true);
                }

                $this->writeLineLogging('', true);
            }
        }

        // Display module information
        if ($showModules) {
            $this->writeLineLogging('{@c:cyan}[MODULES]{@reset}', true);
            $this->writeLineLogging('', true);

            // Load the first matched distributor entry to enumerate its modules
            $firstDist = $matchedDistributors[0];

            try {
                $distributor = new Distributor($firstDist['code'], $firstDist['tag']);
                $distributor->initialize(true);
                $modules = $distributor->getRegistry()->getModules();

                if (empty($modules)) {
                    $this->writeLineLogging('  {@c:yellow}No modules found for this distributor.{@reset}', true);
                    $this->writeLineLogging('', true);
                } else {
                    $moduleCount = \count($modules);
                    $this->writeLineLogging(\sprintf('  {@c:green}Total Modules: %d{@reset}', $moduleCount), true);
                    $this->writeLineLogging('', true);

                    // Display table header
                    $this->writeLineLogging('  ┌─────┬──────────────────────────────┬─────────┬──────────────┬──────────────────┐', true);
                    $this->writeLineLogging(\sprintf(
                        '  │ {@c:green}%-2s{@reset}  │ {@c:green}%-28s{@reset} │ {@c:green}%-7s{@reset} │ {@c:green}%-12s{@reset} │ {@c:green}%-16s{@reset} │',
                        '#',
                        'Module Code',
                        'Version',
                        'Status',
                        'Type'
                    ), true);
                    $this->writeLineLogging('  ├─────┼──────────────────────────────┼─────────┼──────────────┼──────────────────┤', true);

                    $moduleIndex = 1;
                    foreach ($modules as $moduleCode => $module) {
                        $moduleInfo = $module->getModuleInfo();
                        $status = $module->getStatus();

                        // Determine module status label and color for display
                        $statusColor = match($status) {
                            Module\ModuleStatus::Pending => '{@c:yellow}',
                            Module\ModuleStatus::Processing => '{@c:blue}',
                            Module\ModuleStatus::InQueue => '{@c:cyan}',
                            Module\ModuleStatus::Loaded => '{@c:green}',
                            Module\ModuleStatus::Failed => '{@c:red}',
                            default => '{@c:white}',
                        };

                        $statusText = match($status) {
                            Module\ModuleStatus::Pending => 'PENDING',
                            Module\ModuleStatus::Processing => 'PROCESSING',
                            Module\ModuleStatus::InQueue => 'IN QUEUE',
                            Module\ModuleStatus::Loaded => 'LOADED',
                            Module\ModuleStatus::Failed => 'FAILED',
                            default => 'UNKNOWN',
                        };

                        // Determine if the module is shared or distribution-specific
                        $isShared = $moduleInfo->isShared();
                        $moduleTypeColor = $isShared ? '{@c:cyan}' : '{@c:white}';
                        $moduleTypeText = $isShared ? 'SHARED' : 'DISTRIBUTION';

                        // Format table row with aligned columns
                        $moduleCode = \substr($moduleInfo->getCode(), 0, 26);
                        $moduleVersion = \substr($moduleInfo->getVersion(), 0, 7);

                        $this->writeLineLogging(\sprintf(
                            '  │ %2d  │ %-28s │ %-7s │ %s%-12s{@reset} │ %s%-16s{@reset} │',
                            $moduleIndex,
                            $moduleCode,
                            $moduleVersion,
                            $statusColor,
                            $statusText,
                            $moduleTypeColor,
                            $moduleTypeText
                        ), true);

                        $moduleIndex++;

                        if ($showDetails) {
                            $this->writeLineLogging('  ├─────┼──────────────────────────────┼─────────┼──────────────┼──────────────────┤', true);
                            $author = $moduleInfo->getAuthor();
                            $this->writeLineLogging(\sprintf('  │ ... │ Author: {@c:white}%-20s{@reset}   │         │              │                  │', \substr($author, 0, 20)), true);

                            if ($moduleInfo->getAlias()) {
                                $alias = $moduleInfo->getAlias();
                                $this->writeLineLogging(\sprintf('  │ ... │ Alias:  {@c:white}%-20s{@reset}   │         │              │                  │', \substr($alias, 0, 20)), true);
                            }

                            if ($moduleInfo->getDescription()) {
                                $desc = \substr($moduleInfo->getDescription(), 0, 24);
                                $this->writeLineLogging(\sprintf('  │ ... │ Desc:   {@c:white}%-20s{@reset}   │         │              │                  │', $desc), true);
                            }

                            $apiName = $moduleInfo->getAPIName();
                            if ($apiName) {
                                $this->writeLineLogging(\sprintf('  │ ... │ API:    {@c:white}%-20s{@reset}   │         │              │                  │', \substr($apiName, 0, 20)), true);
                            }

                            $requires = $moduleInfo->getRequire();
                            if (!empty($requires)) {
                                $this->writeLineLogging('  │ ... │ Requires:                    │         │              │                  │', true);
                                foreach ($requires as $reqCode => $reqVersion) {
                                    $this->writeLineLogging(\sprintf('  │ ... │   {@c:white}%-23s{@reset} │         │              │                  │', \substr($reqCode . ' (' . $reqVersion . ')', 0, 23)), true);
                                }
                            }
                        }
                    }

                    $this->writeLineLogging('  └─────┴──────────────────────────────┴─────────┴──────────────┴──────────────────┘', true);
                }

                // Load and display distributor configuration from dist.php
                $this->writeLineLogging('{@c:cyan}[CONFIGURATION]{@reset}', true);
                $this->writeLineLogging('', true);

                $distPath = PathUtil::append(SYSTEM_ROOT, 'sites', $distCode);
                $distConfigPath = PathUtil::append($distPath, 'dist.php');

                if (\is_file($distConfigPath)) {
                    $distConfig = require $distConfigPath;

                    $globalModule = ($distConfig['global_module'] ?? false) ? 'Yes' : 'No';
                    $autoload = ($distConfig['autoload'] ?? false) ? 'Yes' : 'No';

                    $this->writeLineLogging(\sprintf('  Config File:     {@c:white}%s{@reset}', PathUtil::getRelativePath($distConfigPath, SYSTEM_ROOT)), true);
                    $this->writeLineLogging(\sprintf('  Global Modules:  {@c:white}%s{@reset}', $globalModule), true);
                    $this->writeLineLogging(\sprintf('  Autoload:        {@c:white}%s{@reset}', $autoload), true);

                    if (!empty($distConfig['data_mapping'])) {
                        $this->writeLineLogging('  Data Mapping:', true);
                        foreach ($distConfig['data_mapping'] as $path => $mapping) {
                            $this->writeLineLogging(\sprintf('    {@c:yellow}%s{@reset} → {@c:white}%s{@reset}', $path, $mapping), true);
                        }
                    }

                    $this->writeLineLogging('', true);
                }
            } catch (Exception $e) {
                $this->writeLineLogging('{@c:red}[ERROR] Failed to load distributor: ' . $e->getMessage() . '{@reset}', true);
                $this->writeLineLogging('', true);
                exit(1);
            }
        }

        // Summary
        $this->writeLineLogging('{@c:green}═══════════════════════════════════════════════════════════{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:green}[SUCCESS] Inspection complete!{@reset}', true);

        exit(0);
    } catch (Exception $e) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}[ERROR] ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }
};
