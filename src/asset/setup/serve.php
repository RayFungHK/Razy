<?php
/**
 * Razy Development Server — Router Script
 *
 * Copied from phar://Razy.phar/asset/setup/serve.php by `php Razy.phar serve`.
 * Reads configuration from .razy-serve-config.json in the same directory.
 *
 * Handles:
 *   - Static file passthrough (CSS, JS, images, fonts) at C level
 *   - Webasset URL rewriting (standalone + multisite modules)
 *   - Data directory mapping
 *   - FQDN simulation (overrides SERVER_NAME for --dist mode)
 *   - Dashboard placeholder (/.razy/dashboard)
 *   - Fall-through to index.php for application routing
 *
 * This file is safe to delete — it will be re-copied on the next serve.
 */

// ── Load config ─────────────────────────────────────────────────────────
$__serveConfig = @json_decode(
    file_get_contents(__DIR__ . '/.razy-serve-config.json'),
    true,
);

if (!is_array($__serveConfig)) {
    // Fallback defaults if config is missing
    $__serveConfig = [
        'mode'         => 'standalone',
        'worker'       => false,
        'max_requests' => 500,
        'memory_limit' => 128,
        'gc_interval'  => 100,
    ];
}

// ── Worker Mode: request counting + memory monitoring ───────────────────
if (!empty($__serveConfig['worker'])) {
    $__workerStateFile = __DIR__ . '/.razy-serve-state';
    $__workerGcInterval = (int) ($__serveConfig['gc_interval'] ?? 100);

    // Increment request counter
    $__reqCount = 1;
    if (is_file($__workerStateFile)) {
        $__stateData = @json_decode(file_get_contents($__workerStateFile), true);
        $__reqCount  = (int) ($__stateData['requests'] ?? 0) + 1;
    }
    file_put_contents($__workerStateFile, json_encode([
        'requests' => $__reqCount,
        'memory'   => memory_get_usage(true),
        'pid'      => getmypid(),
    ]));

    // Periodic garbage collection
    if ($__workerGcInterval > 0 && $__reqCount % $__workerGcInterval === 0) {
        gc_collect_cycles();
    }

    // Update state file with post-request memory snapshot
    register_shutdown_function(function () use ($__workerStateFile, $__reqCount) {
        file_put_contents($__workerStateFile, json_encode([
            'requests' => $__reqCount,
            'memory'   => memory_get_usage(true),
            'pid'      => getmypid(),
        ]));
    });
}

// ── Routing ─────────────────────────────────────────────────────────────
$uri      = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docRoot  = $_SERVER['DOCUMENT_ROOT'];
$filePath = $docRoot . $uri;

// ── 1. Physical file exists? Let the built-in server handle it ──────────
if ($uri !== '/' && is_file($filePath)) {
    return false;
}

// ── 2. Webasset URL rewriting ───────────────────────────────────────────
// URL pattern: /<route_prefix>webassets/<vendor>/<module>/<version>/<file>
// or for standalone: /webassets/standalone/app/<version>/<file>
if (preg_match('#^(?:/[^/]+)?/webassets/(.+?)/(.+?)/([^/]+)/(.+)$#', $uri, $m)) {
    $vendor  = $m[1];
    $module  = $m[2];
    $version = $m[3];
    $file    = $m[4];

    $resolved = resolveWebassetPath($docRoot, $vendor, $module, $version, $file);
    if ($resolved && is_file($resolved)) {
        serveStaticFile($resolved);
        return true;
    }
}

// ── 3. Data directory mapping ───────────────────────────────────────────
// URL pattern: /<route_prefix>data/<path>
if (preg_match('#^(?:/[^/]+)?/data/(.+)$#', $uri, $m)) {
    $dataFile = $docRoot . '/data/' . $m[1];
    if (is_file($dataFile)) {
        serveStaticFile($dataFile);
        return true;
    }
}

// ── 4. Dashboard placeholder ────────────────────────────────────────────
// Future: Razy.phar dashboard UI. For now, returns a JSON status endpoint.
if (str_starts_with($uri, '/.razy/')) {
    if ($uri === '/.razy/dashboard' || $uri === '/.razy/dashboard/') {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        echo json_encode([
            'status'  => 'ok',
            'mode'    => $__serveConfig['mode'] ?? 'unknown',
            'worker'  => !empty($__serveConfig['worker']),
            'php'     => PHP_VERSION,
            'message' => 'Razy Dashboard — coming soon',
        ]);
        return true;
    }

    if ($uri === '/.razy/status') {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        $statusData = [
            'mode'    => $__serveConfig['mode'] ?? 'unknown',
            'worker'  => !empty($__serveConfig['worker']),
            'php'     => PHP_VERSION,
            'memory'  => memory_get_usage(true),
            'uptime'  => time() - $_SERVER['REQUEST_TIME'],
        ];
        if (!empty($__serveConfig['dist_code'])) {
            $statusData['dist'] = $__serveConfig['dist_code'];
        }
        if (!empty($__serveConfig['fqdn_domain'])) {
            $statusData['fqdn'] = $__serveConfig['fqdn_domain'] . ($__serveConfig['fqdn_path'] ?? '/');
        }
        echo json_encode($statusData);
        return true;
    }
}

// ── 5. FQDN simulation (--dist mode) ───────────────────────────────────
// Override SERVER_NAME and HTTP_HOST so the Razy bootstrap resolves the
// correct domain from sites.inc.php. Also prepend the FQDN path to
// REQUEST_URI so the Domain URL-path matcher can route to the right dist.
if (!empty($__serveConfig['fqdn_domain'])) {
    $_SERVER['SERVER_NAME'] = $__serveConfig['fqdn_domain'];
    $_SERVER['HTTP_HOST']   = $__serveConfig['fqdn_domain'];

    $fqdnPath = $__serveConfig['fqdn_path'] ?? '/';
    if ($fqdnPath !== '/' && $fqdnPath !== '') {
        $prefix = rtrim($fqdnPath, '/');
        // Only prepend if not already prefixed (prevents double-prepend on rewrite)
        if (!str_starts_with($_SERVER['REQUEST_URI'], $prefix)) {
            $_SERVER['REQUEST_URI'] = $prefix . $_SERVER['REQUEST_URI'];
        }
    }

    // Ensure RAZY_MULTIPLE_SITE is set for the framework
    putenv('RAZY_MULTIPLE_SITE=true');
    $_ENV['RAZY_MULTIPLE_SITE'] = 'true';
}

// ── 6. Fall through to Razy application ─────────────────────────────────
require $docRoot . '/index.php';
return true;


// ═══════════════════════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Resolve a webasset URL to the physical file path.
 *
 * Searches in order:
 *   1. standalone/webassets/FILE                    (standalone mode)
 *   2. sites/DIST/modules/VENDOR/MODULE/VERSION/webassets/FILE
 *   3. shared/modules/VENDOR/MODULE/VERSION/webassets/FILE
 *   4. modules/VENDOR/MODULE/VERSION/webassets/FILE   (playground layout)
 */
function resolveWebassetPath(string $docRoot, string $vendor, string $module, string $version, string $file): ?string
{
    // Prevent path traversal attacks
    if (str_contains($vendor, '..') || str_contains($module, '..') ||
        str_contains($version, '..') || str_contains($file, '..')) {
        return null;
    }

    // ── Standalone mode: flat layout, webassets at standalone/webassets/ ──
    $standaloneAsset = $docRoot . '/standalone/webassets/' . $file;
    if (is_file($standaloneAsset)) {
        return $standaloneAsset;
    }

    // ── Multisite: sites/<dist>/modules/<vendor>/<module>/<version>/webassets/<file> ──
    $sitesDir = $docRoot . '/sites';
    if (is_dir($sitesDir)) {
        foreach (scandir($sitesDir) as $dist) {
            if ($dist[0] === '.') continue;

            $candidates = [
                $sitesDir . '/' . $dist . '/modules/' . $vendor . '/' . $module . '/' . $version . '/webassets/' . $file,
                $sitesDir . '/' . $dist . '/' . $vendor . '/' . $module . '/' . $version . '/webassets/' . $file,
            ];

            foreach ($candidates as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
        }
    }

    // ── Shared modules ──
    $sharedAsset = $docRoot . '/shared/modules/' . $vendor . '/' . $module . '/' . $version . '/webassets/' . $file;
    if (is_file($sharedAsset)) {
        return $sharedAsset;
    }

    // ── Direct/symlinked modules ──
    $directAsset = $docRoot . '/modules/' . $vendor . '/' . $module . '/' . $version . '/webassets/' . $file;
    if (is_file($directAsset)) {
        return $directAsset;
    }

    return null;
}

/**
 * Serve a static file using C-level stream I/O.
 *
 * MIME detection: mime_content_type() delegates to libmagic (C library),
 * but it often misidentifies web assets (.css/.js as text/plain).
 * A small override map corrects the critical types; everything else
 * falls through to the OS-level detection.
 *
 * File streaming: fopen/fread/fwrite operate directly on C-level
 * php_stream, and the 64 KB buffer size aligns with the OS page cache
 * so the kernel satisfies reads from RAM when the file is hot.
 */
function serveStaticFile(string $path): void
{
    // Override map for types that mime_content_type() / libmagic gets wrong
    static $webMimeOverrides = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'json'  => 'application/json',
        'map'   => 'application/json',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'eot'   => 'application/vnd.ms-fontobject',
        'wasm'  => 'application/wasm',
        'avif'  => 'image/avif',
        'webp'  => 'image/webp',
    ];

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $contentType = $webMimeOverrides[$ext]
        ?? mime_content_type($path)
        ?: 'application/octet-stream';

    $fileSize = filesize($path);

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: public, max-age=10');

    // 64 KB chunks — OS page-cache aligned
    $fp  = fopen($path, 'rb');
    $out = fopen('php://output', 'wb');

    if ($fp && $out) {
        while (!feof($fp)) {
            $chunk = fread($fp, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
        }
        fclose($fp);
        fclose($out);
    }
}
