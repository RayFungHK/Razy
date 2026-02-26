<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Routing;

use Razy\Distributor;
use Razy\Template;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Compiles a Caddyfile from the multisite configuration for use with
 * FrankenPHP / Caddy server.
 *
 * Mirrors the role of RewriteRuleCompiler but targets Caddy's configuration
 * format instead of Apache .htaccess. Generates:
 * - Per-domain site blocks
 * - Module webasset file_server handlers
 * - Per-distributor data mapping handlers
 * - Shared module path handler
 * - FrankenPHP worker mode or standard php_server directive
 *
 * Usage (via CLI):
 *   php Razy.phar rewrite --caddy
 *
 *
 * @license MIT
 */
class CaddyfileCompiler
{
    /**
     * Compile and write the Caddyfile.
     *
     * @param array<string, array<string, string>> $multisite Domain => path => distributor mapping
     * @param array<string, string> $aliases Alias domain => canonical domain mapping
     * @param string $outputPath Path to write the Caddyfile
     * @param bool $workerMode Whether to generate worker mode directives
     * @param string $documentRoot Document root path for root directive
     *
     * @return bool True on success
     *
     * @throws Throwable
     */
    public function compile(
        array  $multisite,
        array  $aliases,
        string $outputPath,
        bool   $workerMode = true,
        string $documentRoot = '/app/public',
    ): bool {
        $source = Template::loadFile(PHAR_PATH . '/asset/setup/caddyfile.tpl');
        $rootBlock = $source->getRoot();

        // Resolve alias domains to their canonical domains
        $domainAliases = $this->buildAliasMap($aliases, $multisite);

        // Build per-domain site blocks
        $this->compileSiteBlocks($rootBlock, $multisite, $domainAliases, $workerMode, $documentRoot);

        \file_put_contents($outputPath, $source->output());

        return true;
    }

    /**
     * Build a map of canonical domain => [alias1, alias2, ...].
     *
     * @param array<string, string> $aliases Alias => canonical domain
     * @param array<string, array<string, string>> $multisite Domain config
     *
     * @return array<string, list<string>>
     */
    private function buildAliasMap(array $aliases, array $multisite): array
    {
        $map = [];

        foreach ($aliases as $alias => $canonical) {
            if (isset($multisite[$canonical]) || $canonical === '*') {
                $map[$canonical][] = $alias;
            }
        }

        return $map;
    }

    /**
     * Generate Caddy site blocks for each domain in the multisite config.
     *
     * In Caddy, each domain gets its own site block. If a domain has aliases,
     * they are listed as additional addresses in the same site block.
     *
     * @param mixed $rootBlock Template root block
     * @param array $multisite Domain => path => distributor mapping
     * @param array $domainAliases Canonical domain => alias list
     * @param bool $workerMode Use FrankenPHP worker mode
     * @param string $documentRoot Server document root
     */
    private function compileSiteBlocks(
        mixed  $rootBlock,
        array  $multisite,
        array  $domainAliases,
        bool   $workerMode,
        string $documentRoot,
    ): void {
        $addedWebAssets = [];

        foreach ($multisite as $domain => $paths) {
            // Build the domain address string (include aliases)
            $siteAddresses = $this->buildSiteAddress($domain, $domainAliases[$domain] ?? []);

            $siteBlock = $rootBlock->newBlock('site')->assign([
                'domain' => $siteAddresses,
                'document_root' => \rtrim($documentRoot, '/'),
            ]);

            foreach ($paths as $urlPath => $distIdentifier) {
                try {
                    [$code, $tag] = \explode('@', $distIdentifier . '@', 2);
                    $distributor = new Distributor($code, $tag ?: '*');
                    $distributor->initialize(true);
                    $modules = $distributor->getRegistry()->getModules();

                    $routePath = ($urlPath === '/') ? '' : \trim($urlPath, '/') . '/';

                    // ── Webasset handlers ──
                    $this->compileWebAssetHandlers($siteBlock, $modules, $routePath, $addedWebAssets);

                    // ── Data mapping handlers ──
                    $this->compileDataMappingHandlers($siteBlock, $distributor, $domain, $code, $routePath, $documentRoot);
                } catch (Throwable $e) {
                    \error_log('Warning: Failed to process distribution ' . $distIdentifier . ' for domain ' . $domain . ': ' . $e->getMessage());
                    continue;
                }
            }

            // Shared module path handler
            $siteBlock->newBlock('shared')->assign([
                'document_root' => \rtrim($documentRoot, '/'),
            ]);

            // PHP server mode
            if ($workerMode) {
                $siteBlock->newBlock('worker')->assign([
                    'document_root' => \rtrim($documentRoot, '/'),
                ]);
            } else {
                $siteBlock->newBlock('standard');
            }
        }
    }

    /**
     * Build the Caddy site address string.
     *
     * For a domain with aliases, returns "domain, alias1, alias2".
     * For wildcard domain, returns ":80" (catch-all).
     *
     * @param string $domain Canonical domain
     * @param string[] $aliases Alias domains
     *
     * @return string
     */
    private function buildSiteAddress(string $domain, array $aliases): string
    {
        if ($domain === '*') {
            // Wildcard: listen on all interfaces (Caddy default site)
            $addresses = [':80'];
        } else {
            $addresses = [$domain];
        }

        foreach ($aliases as $alias) {
            $addresses[] = $alias;
        }

        return \implode(', ', $addresses);
    }

    /**
     * Compile webasset file_server handlers for module static assets.
     *
     * Each module with a webassets/ directory gets a named matcher and
     * handler that strips the URL prefix and serves files from the
     * module's container path.
     *
     * @param mixed $siteBlock Template site block
     * @param array $modules Loaded modules
     * @param string $routePath URL route path prefix
     * @param array &$addedWebAssets Tracking array for deduplication
     */
    private function compileWebAssetHandlers(
        mixed  $siteBlock,
        array  $modules,
        string $routePath,
        array  &$addedWebAssets,
    ): void {
        foreach ($modules as $module) {
            $moduleInfo = $module->getModuleInfo();
            $modulePath = $moduleInfo->getPath();

            if (!empty($modulePath) && \is_dir($modulePath)) {
                $webassetPath = PathUtil::append($modulePath, 'webassets');

                if (\is_dir($webassetPath)) {
                    $alias = $moduleInfo->getAlias();
                    $webAssetKey = $alias . '::' . $routePath;

                    if (!isset($addedWebAssets[$webAssetKey])) {
                        $addedWebAssets[$webAssetKey] = true;

                        $containerPathRel = $moduleInfo->getContainerPath(true);
                        $containerPathRel = \ltrim(\str_replace('\\', '/', $containerPathRel), '/');

                        // Create a safe identifier for Caddy named matcher
                        $mappingId = \preg_replace('/[^a-zA-Z0-9_]/', '_', $alias . '_' . $routePath);

                        $siteBlock->newBlock('webassets')->assign([
                            'mapping' => $alias,
                            'mapping_id' => $mappingId,
                            'route_path' => $routePath,
                            'container_path' => $containerPathRel,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Compile data mapping handlers for a distributor.
     *
     * Maps the data/ URL prefix to the distributor's data directory,
     * serving uploaded/generated files.
     *
     * @param mixed $siteBlock Template site block
     * @param Distributor $distributor The distributor instance
     * @param string $domain Domain name
     * @param string $code Distributor code
     * @param string $routePath URL route path prefix
     * @param string $documentRoot Server document root
     */
    private function compileDataMappingHandlers(
        mixed       $siteBlock,
        Distributor $distributor,
        string      $domain,
        string      $code,
        string      $routePath,
        string      $documentRoot,
    ): void {
        $dataMapping = $distributor->getDataMapping();
        $counter = 0;

        if (!\count($dataMapping) || !isset($dataMapping['/'])) {
            $dataPath = \rtrim($documentRoot, '/') . '/data/' . $domain . '-' . $code;
            $dataId = \preg_replace('/[^a-zA-Z0-9_]/', '_', $code . '_' . $routePath . '_' . $counter++);

            $siteBlock->newBlock('data_mapping')->assign([
                'dist_code' => $code,
                'data_id' => $dataId,
                'route_path' => $routePath,
                'data_path' => $dataPath,
            ]);
        }

        foreach ($dataMapping as $path => $site) {
            $mappingRoutePath = ($path === '/')
                ? $routePath
                : \rtrim($routePath . \trim($path, '/'), '/') . '/';

            $dataPath = \rtrim($documentRoot, '/') . '/data/' . $site['domain'] . '-' . $site['dist'];
            $dataId = \preg_replace('/[^a-zA-Z0-9_]/', '_', $code . '_' . $mappingRoutePath . '_' . $counter++);

            $siteBlock->newBlock('data_mapping')->assign([
                'dist_code' => $code,
                'data_id' => $dataId,
                'route_path' => $mappingRoutePath,
                'data_path' => $dataPath,
            ]);
        }
    }
}
