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

use Exception;
use Razy\Distributor;
use Razy\Template;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Compiles Apache .htaccess rewrite rules from the multisite configuration.
 *
 * Extracted from Application::updateRewriteRules() to isolate the complex
 * rewrite rule compilation logic into a dedicated service class.
 *
 * Handles:
 * - Domain detection rules (RewriteCond/RewriteRule for RAZY_DOMAIN)
 * - Domain alias mapping
 * - Wildcard domain catch-all
 * - Per-distributor data mapping rules
 * - Module webasset path rules
 * - Fallback routing rules
 *
 *
 * @license MIT
 */
class RewriteRuleCompiler
{
    /**
     * Convert a domain name to an Apache-compatible regex pattern.
     *
     * @param string $domain The domain name or pattern
     *
     * @return string The escaped regex pattern
     */
    public static function domainToPattern(string $domain): string
    {
        $pattern = \str_replace('.', '\.', $domain);
        $pattern = \str_replace('*', '.+', $pattern);

        // If the domain does not already specify a port (e.g. "localhost:8888"),
        // append an optional port suffix so the pattern matches HTTP_HOST both
        // with and without a port.  HTTP_HOST includes the port when the client
        // uses a non-default port (e.g. "localhost:8888"), while SERVER_NAME
        // (used by the PHP layer) strips it.  Without this, .htaccess domain
        // detection silently fails for non-standard ports.
        if (!\str_contains($domain, ':')) {
            $pattern .= '(:\d+)?';
        }

        return $pattern;
    }

    /**
     * Compile and write the .htaccess rewrite rules.
     *
     * @param array<string, array<string, string>> $multisite Domain => path => distributor mapping
     * @param array<string, string> $aliases Alias domain => canonical domain mapping
     * @param string $outputPath Path to write the .htaccess file
     *
     * @return bool True on success
     *
     * @throws Throwable
     */
    public function compile(array $multisite, array $aliases, string $outputPath): bool
    {
        $source = Template::loadFile(PHAR_PATH . '/asset/setup/htaccess.tpl');
        $rootBlock = $source->getRoot();

        // ── Phase 1: Domain Detection ──
        $this->compileDomainRules($rootBlock, $multisite, $aliases);

        // ── Phase 2: Per-Distributor Rewrite Rules ──
        $this->compileDistributorRules($rootBlock, $multisite);

        \file_put_contents($outputPath, $source->output());
        return true;
    }

    /**
     * Generate RewriteCond/RewriteRule pairs for domain detection and alias mapping.
     *
     * @param mixed $rootBlock The template root block
     * @param array $multisite Domain => path => distributor mapping
     * @param array $aliases Alias => canonical domain mapping
     */
    private function compileDomainRules(mixed $rootBlock, array $multisite, array $aliases): void
    {
        $hasWildcard = false;
        $registeredDomains = [];

        foreach ($multisite as $domain => $paths) {
            if ($domain === '*') {
                $hasWildcard = true;
                continue;
            }

            if (!isset($registeredDomains[$domain])) {
                $registeredDomains[$domain] = true;
                $domainPattern = self::domainToPattern($domain);

                $rootBlock->newBlock('domain')->assign([
                    'domain' => $domain,
                    'domain_pattern' => $domainPattern,
                ]);
            }
        }

        // Alias detection rules: alias domains map to their canonical domain
        foreach ($aliases as $alias => $canonicalDomain) {
            if (isset($registeredDomains[$canonicalDomain]) || ($canonicalDomain === '*' && $hasWildcard)) {
                $aliasPattern = self::domainToPattern($alias);
                $rootBlock->newBlock('alias')->assign([
                    'alias' => $alias,
                    'alias_pattern' => $aliasPattern,
                    'domain' => $canonicalDomain,
                ]);
            }
        }

        // Wildcard domain: catch any requests that didn't match a specific domain
        if ($hasWildcard) {
            $rootBlock->newBlock('wildcard');
        }
    }

    /**
     * Generate webassets, data, and fallback rules for each distributor.
     *
     * @param mixed $rootBlock The template root block
     * @param array $multisite Domain => path => distributor mapping
     */
    private function compileDistributorRules(mixed $rootBlock, array $multisite): void
    {
        $addedWebAssets = [];

        foreach ($multisite as $domain => $paths) {
            foreach ($paths as $urlPath => $distIdentifier) {
                try {
                    [$code, $tag] = \explode('@', $distIdentifier . '@', 2);
                    $distributor = new Distributor($code, $tag ?: '*');
                    $distributor->initialize(true);
                    $modules = $distributor->getRegistry()->getModules();

                    $routePath = ($urlPath === '/') ? '' : \trim($urlPath, '/') . '/';

                    $rewriteBlock = $rootBlock->newBlock('rewrite')->assign([
                        'domain' => $domain,
                        'dist_code' => $code,
                        'route_path' => $routePath,
                    ]);

                    // ── Data mapping rules ──
                    $this->compileDataMappingRules($rewriteBlock, $distributor, $domain, $code, $routePath);

                    // ── Webassets rules ──
                    $this->compileWebAssetRules($rewriteBlock, $modules, $domain, $code, $routePath, $addedWebAssets);

                    // ── Fallback rule ──
                    if ($distributor->getFallback()) {
                        $rewriteBlock->newBlock('fallback')->assign([
                            'domain' => $domain,
                            'route_path' => $routePath,
                        ]);
                    }
                } catch (Exception $e) {
                    \error_log('Warning: Failed to process distribution ' . $distIdentifier . ' for domain ' . $domain . ': ' . $e->getMessage());
                    continue;
                }
            }
        }
    }

    /**
     * Compile data mapping rewrite rules for a single distributor.
     *
     * @param mixed $rewriteBlock The template rewrite block
     * @param Distributor $distributor The distributor instance
     * @param string $domain The domain name
     * @param string $code The distributor code
     * @param string $routePath The URL route path prefix
     */
    private function compileDataMappingRules(mixed $rewriteBlock, Distributor $distributor, string $domain, string $code, string $routePath): void
    {
        $dataMapping = $distributor->getDataMapping();
        if (!\count($dataMapping) || !isset($dataMapping['/'])) {
            $dataPath = '%{ENV:BASE}data/' . $domain . '-' . $code . '/$1';

            $rewriteBlock->newBlock('data_mapping')->assign([
                'domain' => $domain,
                'route_path' => $routePath,
                'data_path' => $dataPath,
            ]);
        }
        foreach ($dataMapping as $path => $site) {
            $mappingRoutePath = ($path === '/')
                ? $routePath
                : \rtrim($routePath . \trim($path, '/'), '/') . '/';

            $dataPath = '%{ENV:BASE}data/' . $site['domain'] . '-' . $site['dist'] . '/$1';

            $rewriteBlock->newBlock('data_mapping')->assign([
                'domain' => $domain,
                'route_path' => $mappingRoutePath,
                'data_path' => $dataPath,
            ]);
        }
    }

    /**
     * Compile webasset rewrite rules for modules in a distributor.
     *
     * @param mixed $rewriteBlock The template rewrite block
     * @param array $modules The modules loaded by the distributor
     * @param string $domain The domain name
     * @param string $code The distributor code
     * @param string $routePath The URL route path prefix
     * @param array &$addedWebAssets Tracking array to prevent duplicate rules
     */
    private function compileWebAssetRules(mixed $rewriteBlock, array $modules, string $domain, string $code, string $routePath, array &$addedWebAssets): void
    {
        foreach ($modules as $module) {
            $moduleInfo = $module->getModuleInfo();
            $modulePath = $moduleInfo->getPath();

            if (!empty($modulePath) && \is_dir($modulePath)) {
                $webassetPath = PathUtil::append($modulePath, 'webassets');

                if (\is_dir($webassetPath)) {
                    $webAssetKey = $domain . '::' . $code . '::' . $moduleInfo->getAlias();

                    if (!isset($addedWebAssets[$webAssetKey])) {
                        $addedWebAssets[$webAssetKey] = true;

                        $containerPathRel = $moduleInfo->getContainerPath(true);
                        $containerPathRel = \ltrim(\str_replace('\\', '/', $containerPathRel), '/');
                        $distPath = $containerPathRel . '/$1/webassets/$2';

                        $rewriteBlock->newBlock('webassets')->assign([
                            'domain' => $domain,
                            'dist_path' => $distPath,
                            'route_path' => $routePath,
                            'mapping' => $moduleInfo->getAlias(),
                        ]);
                    }
                }
            }
        }
    }
}
