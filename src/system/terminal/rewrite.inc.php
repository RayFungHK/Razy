<?php
/**
 * CLI Command: rewrite
 *
 * Rebuilds server rewrite/routing configuration based on the current site
 * configuration. Scans all registered distributors and their domain bindings,
 * then regenerates the appropriate server configuration file.
 *
 * Supports two output modes:
 * - Apache .htaccess (default)
 * - Caddy/FrankenPHP Caddyfile (--caddy flag)
 *
 * Usage:
 *   php Razy.phar rewrite [--caddy] [--no-worker] [--document-root=PATH]
 *
 * Options:
 *   --caddy              Generate Caddyfile instead of .htaccess
 *   --no-worker           Disable FrankenPHP worker mode (Caddy only)
 *   --document-root=PATH  Set the document root path (Caddy only, default: /app/public)
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;
return function (string $distCode = '') use (&$parameters) {
    // Parse flags from parameters
    $isCaddy = in_array('--caddy', $parameters, true);
    $noWorker = in_array('--no-worker', $parameters, true);
    $documentRoot = '/app/public';

    foreach ($parameters as $param) {
        if (str_starts_with($param, '--document-root=')) {
            $documentRoot = substr($param, strlen('--document-root='));
        }
    }

    $serverType = $isCaddy ? 'Caddy' : 'Apache';
    $this->writeLineLogging('{@s:bu}Rebuild ' . $serverType . ' rewrite rules', true);
    $this->writeLineLogging('', true);

    try {
        // Initialize the application and load existing site configuration
        $app = new Application();
        $app->loadSiteConfig();

        $this->writeLineLogging('{@c:blue}Scanning distributors...{@reset}', true);
        
        // Enumerate all distributor entries from site config domain mappings
        $config = $app->loadSiteConfig();
        $distributors = [];
        
        if (is_array($config['domains'] ?? null)) {
            foreach ($config['domains'] as $domain => $distPaths) {
                foreach ($distPaths as $path => $distIdentifier) {
                    if (is_string($distIdentifier)) {
                        [$code, $tag] = explode('@', $distIdentifier . '@', 2);
                        $key = $code . '@' . ($tag ?: '*');
                        if (!isset($distributors[$key])) {
                            $distributors[$key] = [
                                'code' => $code,
                                'tag' => $tag ?: '*',
                                'domains' => 0,
                            ];
                        }
                        $distributors[$key]['domains']++;
                    }
                }
            }
        }

        if (empty($distributors)) {
            $this->writeLineLogging('{@c:yellow}[WARNING] No domain bindings found.{@reset}', true);
            exit(1);
        }

        $this->writeLineLogging(sprintf('  Found {@c:cyan}%d{@reset} distributor(s)', count($distributors)), true);
        foreach ($distributors as $key => $dist) {
            $tagDisplay = $dist['tag'] === '*' ? 'default' : $dist['tag'];
            $this->writeLineLogging(sprintf('    - {@c:white}%s{@reset} [%s] (%d domain binding%s)', 
                $dist['code'], 
                $tagDisplay,
                $dist['domains'],
                $dist['domains'] === 1 ? '' : 's'
            ), true);
        }
        
        $this->writeLineLogging('', true);

        if ($isCaddy) {
            // ── Caddy / FrankenPHP mode ──
            $workerMode = !$noWorker;
            $modeLabel = $workerMode ? 'worker' : 'standard';
            $this->writeLineLogging('{@c:blue}Building Caddyfile ({@c:cyan}' . $modeLabel . '{@c:blue} mode)...{@reset}', true);

            if ($app->updateCaddyfile($workerMode, $documentRoot)) {
                $caddyfilePath = PathUtil::append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'Caddyfile');
                $this->writeLineLogging('{@c:green}[SUCCESS] Caddyfile generated.{@reset}', true);
                $this->writeLineLogging('', true);
                $this->writeLineLogging(sprintf('  File: {@c:white}%s{@reset}', PathUtil::getRelativePath($caddyfilePath, SYSTEM_ROOT)), true);
                $fileSizeKb = round(filesize($caddyfilePath) / 1024, 2);
                $this->writeLineLogging(sprintf('  Size: {@c:white}%.2f KB{@reset}', $fileSizeKb), true);
                $this->writeLineLogging(sprintf('  Mode: {@c:white}%s{@reset}', $modeLabel), true);
                $this->writeLineLogging(sprintf('  Root: {@c:white}%s{@reset}', $documentRoot), true);
                $this->writeLineLogging('', true);
            } else {
                $this->writeLineLogging('{@c:red}[ERROR] Failed to generate Caddyfile.{@reset}', true);
                $this->writeLineLogging('', true);
                exit(1);
            }

        } else {
            // ── Apache .htaccess mode (default) ──
            $this->writeLineLogging('{@c:blue}Building rewrite rules...{@reset}', true);

            if ($app->updateRewriteRules()) {
                $htaccessPath = PathUtil::append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, '.htaccess');
                $this->writeLineLogging('{@c:green}[SUCCESS] Rewrite rules updated.{@reset}', true);
                $this->writeLineLogging('', true);
                $this->writeLineLogging(sprintf('  File: {@c:white}%s{@reset}', PathUtil::getRelativePath($htaccessPath, SYSTEM_ROOT)), true);
                $fileSizeKb = round(filesize($htaccessPath) / 1024, 2);
                $this->writeLineLogging(sprintf('  Size: {@c:white}%.2f KB{@reset}', $fileSizeKb), true);
                $this->writeLineLogging('', true);
            } else {
                $this->writeLineLogging('{@c:red}[ERROR] Failed to update rewrite rules.{@reset}', true);
                $this->writeLineLogging('', true);
                exit(1);
            }
        }

    } catch (\Exception $e) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}[ERROR] ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }
};
