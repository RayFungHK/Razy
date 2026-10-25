<?php
/**
 * Razy Development Server — Router Script
 *
 * Copied from phar://Razy.phar/asset/setup/serve.php by `php Razy.phar serve`.
 * Reads configuration from .razy-serve-config.json in the same directory.
 *
 * Handles:
 *   - Data directory mapping (realpath-guarded; bytes only, never executed).
 *     html, htm, svg, xml, xhtml, js, mjs, and anything libmagic calls
 *     text/html or image/svg+xml, download with a sandbox CSP (RZ-25).
 *     /data/note.php/extra is that file's bytes, or 404. It is not PATH_INFO.
 *     /data/ and /data/sub/ are 404, including when index.html is there.
 *   - Webassets: /webassets/{alias}/{version}/{file} falls through to the
 *     app, which serves it (dist.php 'assets' => ['serve'], on by default
 *     under this server; RZ-09) with hashed-URL cache headers.
 *     Under php -S that request runs through PHP, not a C-level static send.
 *   - No passthrough of other real files. Returning false here makes php -S
 *     download the document root and execute every other .php (RZ-24).
 *   - FQDN simulation (overrides SERVER_NAME for --dist mode)
 *   - Dashboard placeholder (/.razy/dashboard)
 *   - Fall-through to index.php for application routing
 *
 * This file is safe to delete — it will be re-copied on the next serve.
 */

// Tests load the helpers at the bottom without starting the router.
// Function declarations in this file are registered before this return runs.
if (defined('RAZY_SERVE_FUNCTIONS_ONLY')) {
    return;
}

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

// ── 1. Data directory mapping ───────────────────────────────────────────
// URL pattern: /<route_prefix>data/<path>
// (Webassets are not resolved here: the app knows each module's alias and
// version folder, so /webassets/... falls through to index.php — RZ-09.)
// /data and /data/ are the directory itself. A real subdirectory is too.
// php -S would not append index.html, but falling through would be the
// app, and an index document must not be the response (RZ-25).
if (preg_match('#^(?:/[^/]+)?/data(?:/(.*))?$#', $uri, $m)) {
    $relative = $m[1] ?? '';
    if (serveDataRequestIsDirectory($docRoot . '/data', $relative)) {
        http_response_code(404);
        header('X-Content-Type-Options: nosniff');
        return true;
    }
    // /data/note.php/extra must not fall through. A false return would let
    // php -S execute note.php with PATH_INFO=/extra.
    $script = serveScriptPath($relative);
    if ($script !== null) {
        $relative = $script;
    }
    $dataFile = resolveStaticPath($docRoot . '/data', $relative);
    if ($dataFile !== null) {
        serveStaticFile($dataFile);
        return true;
    }
    if ($script !== null) {
        http_response_code(404);
        header('X-Content-Type-Options: nosniff');
        return true;
    }
}

// ── 2. Dashboard placeholder ────────────────────────────────────────────
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

// ── 3. FQDN simulation (--dist mode) ───────────────────────────────────
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

// ── 4. Fall through to Razy application ─────────────────────────────────
// Anything else, including a real file (Razy.phar, a template, another
// .php), is the front controller. Falling through (a false return) would
// make php -S serve the file or execute that .php.
// The built-in server sets SCRIPT_NAME to the request path when it has an
// extension (/webassets/app/default/js/a.js), which the bootstrap would read
// as a subdirectory install. The app is the front controller: say so.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docRoot . '/index.php';
require $docRoot . '/index.php';
return true;


// ═══════════════════════════════════════════════════════════════════════════
// Helper functions
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Whether this /data URL is the data directory or a directory under it.
 *
 * /data and /data/ are the root (true even when the folder is missing).
 * A real subdirectory is true, so the router 404s instead of publishing
 * index.html. The same segment rules as resolveStaticPath(): a dot
 * segment, a null byte, or a path that leaves the root is not a directory
 * we will answer.
 */
function serveDataRequestIsDirectory(string $root, string $relative): bool
{
    // One return. The router script itself must not hand php -S a false
    // result, or the built-in server publishes the file (RZ-24).
    $directory = false;
    $decoded = rawurldecode($relative);
    $safe = !str_contains($decoded, "\0") && !str_contains($decoded, '\\') && !str_contains($decoded, '%');
    $segments = $safe
        ? array_values(array_filter(explode('/', $decoded), static fn (string $s): bool => $s !== ''))
        : null;
    if ($segments === []) {
        $directory = true;
    } elseif (is_array($segments)) {
        $dot = false;
        foreach ($segments as $segment) {
            if ($segment[0] === '.') {
                $dot = true;
            }
        }
        $realRoot = $dot ? false : realpath($root);
        if (is_string($realRoot)) {
            $real = realpath($realRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $directory = is_string($real)
                && is_dir($real)
                && ($real === $realRoot || str_starts_with($real, $prefix));
        }
    }

    return $directory;
}

/**
 * Map a URL path below $root to a real file inside it, or null.
 *
 * Same rules as Razy\Http\StaticFile::resolve() (this script runs before
 * the framework loads): one URL decode, then no null byte, backslash, '%',
 * '.'/'..' or dotfile segment, and the realpath must stay under the root.
 */
function resolveStaticPath(string $root, string $relative): ?string
{
    $decoded = rawurldecode($relative);
    if (str_contains($decoded, "\0") || str_contains($decoded, '\\') || str_contains($decoded, '%')) {
        return null;
    }
    $segments = array_filter(explode('/', $decoded), static fn (string $s): bool => $s !== '');
    if (!$segments) {
        return null;
    }
    foreach ($segments as $segment) {
        if ($segment[0] === '.') {
            return null;
        }
    }

    $realRoot = realpath($root);
    if ($realRoot === false) {
        return null;
    }
    $real = realpath($realRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
    if ($real === false || !is_file($real) || !str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $real;
}

/**
 * Content-Type for a file served as bytes.
 *
 * Known web types win, because libmagic often calls .css/.js text/plain.
 * mime_content_type() runs only when the caller allows it and the function
 * exists (Windows CI has no fileinfo; calling it is a fatal). Otherwise an
 * extension map applies, then application/octet-stream. The response also
 * sends nosniff, so a generic type is not sniffed into something executable.
 *
 * Pass $fileinfo false to force the map. The router leaves it at the default.
 */
function serveContentType(string $path, bool $fileinfo = true): string
{
    static $overrides = [
        'css'   => 'text/css',
        'js'    => 'text/javascript',
        'mjs'   => 'text/javascript',
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
    static $byExtension = [
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'csv'  => 'text/csv',
        'xml'  => 'application/xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'pdf'  => 'application/pdf',
    ];

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($overrides[$ext])) {
        return $overrides[$ext];
    }
    if ($fileinfo && function_exists('mime_content_type')) {
        $detected = mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }

    return $byExtension[$ext] ?? 'application/octet-stream';
}

/**
 * Path of a .php / .phtml / .phar under /data, or null when the URL is not one.
 *
 * Stops at the first script extension, so note.php/extra and an encoded
 * slash map to note.php. The caller serves that file's bytes, or 404.
 * It must not fall through: php -S would execute the script (RZ-25).
 */
function serveScriptPath(string $relative): ?string
{
    $decoded = rawurldecode($relative);
    if (preg_match('#^(.+?\.(?i:php|phtml|phar))(?:/.*)?$#', $decoded, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * Whether a /data response must not render on the app origin (RZ-25).
 *
 * Browsers will execute these as a document or a script when they are
 * served inline. The extension list covers the usual names. libmagic
 * covers a renamed upload: text/html or image/svg+xml, parameters stripped.
 */
function serveIsActiveContent(string $path, string $contentType): bool
{
    static $extensions = [
        'html' => true,
        'htm' => true,
        'svg' => true,
        'xml' => true,
        'xhtml' => true,
        'js' => true,
        'mjs' => true,
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($extensions[$ext])) {
        return true;
    }

    $media = strtolower(trim($contentType));
    $semi = strpos($media, ';');
    if ($semi !== false) {
        $media = trim(substr($media, 0, $semi));
    }

    return $media === 'text/html' || $media === 'image/svg+xml';
}

/**
 * Serve a static file using C-level stream I/O.
 *
 * fopen/fread/fwrite operate directly on C-level php_stream, and the
 * 64 KB buffer size aligns with the OS page cache so the kernel satisfies
 * reads from RAM when the file is hot.
 *
 * Active types (RZ-25) also send Content-Disposition: attachment and
 * Content-Security-Policy: sandbox. nosniff stays on every data response.
 */
function serveStaticFile(string $path): void
{
    $fileSize = filesize($path);
    $type = serveContentType($path);

    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    if (serveIsActiveContent($path, $type)) {
        header('Content-Disposition: attachment');
        header('Content-Security-Policy: sandbox');
    }
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
