<?php

/**
 * Dashboard Router — PHP built-in server router.
 *
 * Handles API endpoints and serves the single-page dashboard UI.
 * Runs inside PHP's built-in web server (php -S).
 *
 * API endpoints (Tier 1 — read-only, safe):
 *   GET /api/health              → Healthcheck
 *   GET /api/version             → Razy version
 *   GET /api/system              → System information
 *   GET /api/packages            → List installed packages
 *   GET /api/package/:name       → Package details
 *   GET /api/sites               → Site configuration (distributors)
 *   GET /api/cache/status        → Cache system status
 *   GET /api/cache/stats         → Cache statistics
 *   GET /api/inspect/:dist       → Inspect a distributor
 *   GET /api/routes/:dist        → List distributor routes
 *   GET /api/validate/:dist      → Validate distributor modules
 *
 * API endpoints (Tier 2 — mutating, require confirmation):
 *   POST /api/cache/clear        → Clear all cache
 *   POST /api/cache/gc           → Garbage-collect expired cache
 *   POST /api/pkg/stop/:name     → Stop a running daemon
 *
 * CLI proxy:
 *   POST /api/cli                → Execute arbitrary Razy CLI command (body: {"command":"...","args":[...]})
 *
 *   GET /                        → Dashboard HTML (single-page app)
 */

// Resolve paths from environment
$projectRoot = \getenv('RAZY_DASHBOARD_PROJECT_ROOT') ?: \getcwd();
$pkgDir = \getenv('RAZY_DASHBOARD_PKG_DIR') ?: ($projectRoot . DIRECTORY_SEPARATOR . 'packages');
$assetsDir = \getenv('RAZY_DASHBOARD_ASSETS') ?: __DIR__ . DIRECTORY_SEPARATOR . 'assets';

$uri = \parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = \rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── API Routes ────────────────────────────────────────────────────

if (\str_starts_with($uri, '/api/')) {
    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: no-cache');

    // Token-based authentication: if RAZY_DASHBOARD_TOKEN is set, require it
    $dashboardToken = \getenv('RAZY_DASHBOARD_TOKEN');
    if ($dashboardToken !== false && $dashboardToken !== '') {
        $requestToken = $_SERVER['HTTP_X_DASHBOARD_TOKEN'] ?? '';
        if (!\hash_equals($dashboardToken, $requestToken)) {
            \http_response_code(401);
            echo \json_encode(['error' => 'Unauthorized: invalid or missing X-Dashboard-Token header'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return true;
        }
    }

    try {
        $response = routeApi($uri, $method, $projectRoot, $pkgDir);
    } catch (\Throwable $e) {
        \http_response_code(500);
        $response = ['error' => $e->getMessage()];
    }

    $json = \json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        // Fallback: return a safe error response if encoding still fails
        \http_response_code(500);
        $json = \json_encode(['error' => 'Response encoding failed: ' . \json_last_error_msg()], JSON_PRETTY_PRINT);
    }
    echo $json;

    return true;
}

// ── Static Assets ─────────────────────────────────────────────────

if ($uri !== '/' && !\str_contains($uri, '..') && \is_file($assetsDir . $uri)) {
    // Verify the resolved path stays within the assets directory
    $resolvedAsset = \realpath($assetsDir . $uri);
    $resolvedAssetsDir = \realpath($assetsDir);
    if ($resolvedAsset !== false && $resolvedAssetsDir !== false
        && \str_starts_with($resolvedAsset, $resolvedAssetsDir . DIRECTORY_SEPARATOR)) {
        return false; // Let PHP's built-in server handle static files
    }
}

// ── Dashboard SPA ─────────────────────────────────────────────────

$indexPath = $assetsDir . DIRECTORY_SEPARATOR . 'index.html';
if (\is_file($indexPath)) {
    \header('Content-Type: text/html; charset=utf-8');
    \readfile($indexPath);

    return true;
}

\http_response_code(404);
echo '404 — Dashboard not found. Missing assets/index.html';

return true;

// ══════════════════════════════════════════════════════════════════
// API handler
// ══════════════════════════════════════════════════════════════════

function routeApi(string $uri, string $method, string $projectRoot, string $pkgDir): array
{
    // ── GET endpoints ─────────────────────────────────────────────
    if ($method === 'GET') {
        return match (true) {
            $uri === '/api/health'         => apiHealth(),
            $uri === '/api/version'        => apiVersion($projectRoot),
            $uri === '/api/system'         => apiSystem($projectRoot, $pkgDir),
            $uri === '/api/packages'       => apiPackages($pkgDir),
            $uri === '/api/sites'          => apiSites($projectRoot),
            $uri === '/api/cache/status'   => apiCli($projectRoot, 'cache', ['status']),
            $uri === '/api/cache/stats'    => apiCli($projectRoot, 'cache', ['stats']),
            \str_starts_with($uri, '/api/package/')  => apiPackageDetail(\substr($uri, 13), $pkgDir),
            \str_starts_with($uri, '/api/inspect/')  => apiCli($projectRoot, 'inspect', [\urldecode(\substr($uri, 13)), '--details']),
            \str_starts_with($uri, '/api/routes/')   => apiCli($projectRoot, 'routes', [\urldecode(\substr($uri, 12)), '--api']),
            \str_starts_with($uri, '/api/validate/') => apiCli($projectRoot, 'validate', [\urldecode(\substr($uri, 14)), '--verbose']),
            default => throw new \RuntimeException('Unknown API endpoint: ' . $uri),
        };
    }

    // ── POST endpoints ────────────────────────────────────────────
    if ($method === 'POST') {
        return match (true) {
            $uri === '/api/cache/clear' => apiCli($projectRoot, 'cache', ['clear']),
            $uri === '/api/cache/gc'    => apiCli($projectRoot, 'cache', ['gc']),
            $uri === '/api/cli'         => apiCliProxy($projectRoot),
            \str_starts_with($uri, '/api/pkg/stop/') => apiCli($projectRoot, 'pkg', ['stop', \urldecode(\substr($uri, 14))]),
            default => throw new \RuntimeException('Unknown API endpoint: ' . $uri),
        };
    }

    throw new \RuntimeException('Method not allowed: ' . $method);
}

// ══════════════════════════════════════════════════════════════════
// Tier 1 — Read-only endpoints
// ══════════════════════════════════════════════════════════════════

function apiHealth(): array
{
    return [
        'status' => 'ok',
        'time'   => \date('c'),
    ];
}

function apiVersion(string $projectRoot): array
{
    // Read VERSION file directly (fast, no subprocess)
    $versionFile = $projectRoot . DIRECTORY_SEPARATOR . 'VERSION';
    $pharVersionFile = $projectRoot . DIRECTORY_SEPARATOR . 'Razy.phar';

    $version = 'unknown';
    if (\is_file($versionFile)) {
        $version = \trim(\file_get_contents($versionFile));
    } elseif (\is_file($pharVersionFile)) {
        // Try reading from phar
        $pharVersion = 'phar://' . $pharVersionFile . '/VERSION';
        if (\is_file($pharVersion)) {
            $version = \trim(\file_get_contents($pharVersion));
        }
    }

    return [
        'version' => $version,
        'phar'    => \is_file($pharVersionFile) ? 'found' : 'not found',
    ];
}

function apiSystem(string $projectRoot, string $pkgDir): array
{
    $razyPhar = $projectRoot . DIRECTORY_SEPARATOR . 'Razy.phar';

    // Read version
    $version = 'unknown';
    $versionFile = $projectRoot . DIRECTORY_SEPARATOR . 'VERSION';
    if (\is_file($versionFile)) {
        $version = \trim(\file_get_contents($versionFile));
    }

    // Count packages
    $pkgCount = 0;
    if (\is_dir($pkgDir)) {
        $pkgCount += \count(\glob($pkgDir . DIRECTORY_SEPARATOR . '*.phar') ?: []);
        foreach (\glob($pkgDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (\is_file($dir . DIRECTORY_SEPARATOR . 'razy.pkg.json')) {
                ++$pkgCount;
            }
        }
    }

    // Count sites
    $siteCount = 0;
    $sitesFile = $projectRoot . DIRECTORY_SEPARATOR . 'sites.inc.php';
    if (\is_file($sitesFile)) {
        $config = @include $sitesFile;
        if (\is_array($config['domains'] ?? null)) {
            foreach ($config['domains'] as $domain => $paths) {
                if (\is_array($paths)) {
                    foreach ($paths as $path => $dist) {
                        ++$siteCount;
                    }
                }
            }
        }
    }

    return [
        'razy_version'   => $version,
        'php_version'    => PHP_VERSION,
        'php_binary'     => PHP_BINARY,
        'os'             => PHP_OS_FAMILY,
        'project_root'   => $projectRoot,
        'pkg_dir'        => $pkgDir,
        'razy_phar'      => \is_file($razyPhar) ? 'found' : 'not found',
        'package_count'  => $pkgCount,
        'site_count'     => $siteCount,
        'extensions'     => [
            'phar'   => \extension_loaded('phar'),
            'json'   => \extension_loaded('json'),
            'curl'   => \extension_loaded('curl'),
            'zip'    => \extension_loaded('zip'),
            'pcntl'  => \extension_loaded('pcntl'),
            'openssl' => \extension_loaded('openssl'),
            'mbstring' => \extension_loaded('mbstring'),
            'pdo'    => \extension_loaded('pdo'),
        ],
        'memory_limit'   => \ini_get('memory_limit'),
        'time'           => \date('c'),
    ];
}

function apiPackages(string $pkgDir): array
{
    $packages = [];

    if (!\is_dir($pkgDir)) {
        return ['packages' => [], 'error' => 'Package directory not found'];
    }

    // Scan .phar files
    foreach (\glob($pkgDir . DIRECTORY_SEPARATOR . '*.phar') ?: [] as $pharFile) {
        $manifest = readManifestFromPhar($pharFile);
        if ($manifest) {
            $manifest['source_type'] = 'phar';
            $manifest['source_path'] = \basename($pharFile);
            $packages[] = $manifest;
        }
    }

    // Scan directories with razy.pkg.json
    foreach (\glob($pkgDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
        $jsonPath = $dir . DIRECTORY_SEPARATOR . 'razy.pkg.json';
        if (\is_file($jsonPath)) {
            $data = \json_decode(\file_get_contents($jsonPath), true);
            if (\is_array($data) && !empty($data['package_name'])) {
                $data['source_type'] = 'directory';
                $data['source_path'] = \basename($dir);
                $packages[] = $data;
            }
        }
    }

    \usort($packages, fn($a, $b) => ($a['package_name'] ?? '') <=> ($b['package_name'] ?? ''));

    return ['packages' => $packages, 'count' => \count($packages)];
}

function apiSites(string $projectRoot): array
{
    $sitesFile = $projectRoot . DIRECTORY_SEPARATOR . 'sites.inc.php';

    if (!\is_file($sitesFile)) {
        return ['sites' => [], 'distributors' => [], 'error' => 'No sites.inc.php found'];
    }

    $config = @include $sitesFile;
    if (!\is_array($config)) {
        return ['sites' => [], 'distributors' => [], 'error' => 'Invalid sites.inc.php'];
    }

    $sites = [];
    $distributors = [];
    $domains = $config['domains'] ?? [];
    $aliases = $config['alias'] ?? [];

    foreach ($domains as $domain => $paths) {
        if (!\is_array($paths)) {
            continue;
        }
        foreach ($paths as $path => $distIdentifier) {
            if (!\is_string($distIdentifier)) {
                continue;
            }
            $parts = \explode('@', $distIdentifier . '@', 2);
            $code = $parts[0];
            $tag = $parts[1] ?: '*';

            $sites[] = [
                'domain'     => $domain,
                'path'       => $path,
                'dist_code'  => $code,
                'tag'        => $tag,
                'identifier' => $distIdentifier,
            ];

            if (!isset($distributors[$code])) {
                $distributors[$code] = [
                    'code'    => $code,
                    'domains' => [],
                ];
            }
            $distributors[$code]['domains'][] = [
                'domain' => $domain,
                'path'   => $path,
                'tag'    => $tag,
            ];
        }
    }

    return [
        'sites'        => $sites,
        'distributors' => \array_values($distributors),
        'aliases'      => $aliases,
        'config_file'  => $sitesFile,
    ];
}

function apiPackageDetail(string $name, string $pkgDir): array
{
    $name = \urldecode($name);

    // Path traversal protection: reject names with directory traversal sequences
    if (\str_contains($name, '..') || \str_contains($name, '\\') || \preg_match('#[\x00-\x1f]#', $name)) {
        return ['error' => 'Invalid package name'];
    }

    // Try directory first
    $dirPath = $pkgDir . DIRECTORY_SEPARATOR . $name;

    // Verify resolved path stays within pkgDir
    $resolvedPkgDir = \realpath($pkgDir);
    if ($resolvedPkgDir === false) {
        return ['error' => 'Package directory not found'];
    }

    if (\is_dir($dirPath)) {
        $resolvedDir = \realpath($dirPath);
        if ($resolvedDir === false || !\str_starts_with($resolvedDir, $resolvedPkgDir . DIRECTORY_SEPARATOR)) {
            return ['error' => 'Invalid package path'];
        }
        $jsonPath = $dirPath . DIRECTORY_SEPARATOR . 'razy.pkg.json';
        if (\is_file($jsonPath)) {
            $data = \json_decode(\file_get_contents($jsonPath), true);
            if (\is_array($data)) {
                $data['source_type'] = 'directory';
                $data['source_path'] = $dirPath;
                $data['has_controller'] = \is_dir($dirPath . DIRECTORY_SEPARATOR . 'controller');

                return ['package' => $data];
            }
        }
    }

    // Try .phar
    $pharPath = $pkgDir . DIRECTORY_SEPARATOR . $name . '.phar';
    if (\is_file($pharPath)) {
        $resolvedPhar = \realpath($pharPath);
        if ($resolvedPhar !== false && \str_starts_with($resolvedPhar, $resolvedPkgDir . DIRECTORY_SEPARATOR)) {
            $data = readManifestFromPhar($pharPath);
            if ($data) {
                $data['source_type'] = 'phar';
                $data['source_path'] = $pharPath;

                return ['package' => $data];
            }
        }
    }

    // Try flat phar naming (vendor__name)
    $flatName = \str_replace('/', '__', $name);
    $flatPath = $pkgDir . DIRECTORY_SEPARATOR . $flatName . '.phar';
    if (\is_file($flatPath)) {
        $resolvedFlat = \realpath($flatPath);
        if ($resolvedFlat !== false && \str_starts_with($resolvedFlat, $resolvedPkgDir . DIRECTORY_SEPARATOR)) {
            $data = readManifestFromPhar($flatPath);
            if ($data) {
                $data['source_type'] = 'phar';
                $data['source_path'] = $flatPath;

                return ['package' => $data];
            }
        }
    }

    return ['error' => 'Package not found: ' . $name];
}

// ══════════════════════════════════════════════════════════════════
// CLI execution — runs `php Razy.phar <command>` as a subprocess
// ══════════════════════════════════════════════════════════════════

/**
 * Execute a Razy CLI command and return its output.
 */
function apiCli(string $projectRoot, string $command, array $args = []): array
{
    $razyPhar = $projectRoot . DIRECTORY_SEPARATOR . 'Razy.phar';
    if (!\is_file($razyPhar)) {
        return ['error' => 'Razy.phar not found at ' . $razyPhar];
    }

    $phpBin = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $cmdParts = [
        \escapeshellarg($phpBin),
        \escapeshellarg($razyPhar),
        \escapeshellarg($command),
    ];
    foreach ($args as $arg) {
        $cmdParts[] = \escapeshellarg($arg);
    }
    // Pass project root via -f flag
    $cmdParts[] = '-f';
    $cmdParts[] = \escapeshellarg($projectRoot);

    $cmd = \implode(' ', $cmdParts);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = \proc_open($cmd, $descriptors, $pipes, $projectRoot);
    if (!\is_resource($process)) {
        return ['error' => 'Failed to execute command: ' . $command];
    }

    \fclose($pipes[0]);
    $stdout = \stream_get_contents($pipes[1]);
    $stderr = \stream_get_contents($pipes[2]);
    \fclose($pipes[1]);
    \fclose($pipes[2]);
    $exitCode = \proc_close($process);

    // Strip ANSI colour codes for clean text
    $clean = \preg_replace('/\x1b\[[0-9;]*m/', '', $stdout);
    // Also strip Razy's own {@c:..} / {@s:..} / {@reset} formatting tags
    $clean = \preg_replace('/\{@[^}]+\}/', '', $clean);

    // Ensure output is valid UTF-8 (Windows console may use non-UTF-8 codepage)
    if (!\mb_check_encoding($clean, 'UTF-8')) {
        $clean = \mb_convert_encoding($clean, 'UTF-8', 'Windows-1252');
    }
    if (!\mb_check_encoding($stderr, 'UTF-8')) {
        $stderr = \mb_convert_encoding($stderr, 'UTF-8', 'Windows-1252');
    }

    $lines = \array_values(\array_filter(
        \array_map('trim', \explode("\n", $clean)),
        fn($l) => $l !== '',
    ));

    return [
        'command'   => $command . ' ' . \implode(' ', $args),
        'exit_code' => $exitCode,
        'output'    => $lines,
        'raw'       => \trim($clean),
        'stderr'    => \trim($stderr) ?: null,
    ];
}

/**
 * CLI proxy endpoint — execute arbitrary allowed Razy CLI commands.
 * Reads JSON body: {"command": "cache", "args": ["status"]}
 */
function apiCliProxy(string $projectRoot): array
{
    $body = \json_decode(\file_get_contents('php://input'), true);
    if (!\is_array($body) || empty($body['command'])) {
        \http_response_code(400);

        return ['error' => 'Request body must contain "command" field'];
    }

    $command = (string) $body['command'];
    $rawArgs = $body['args'] ?? [];

    // Validate all args are strings (prevent type confusion & injection)
    if (!\is_array($rawArgs)) {
        \http_response_code(400);

        return ['error' => '"args" must be an array of strings'];
    }
    $args = [];
    foreach ($rawArgs as $arg) {
        if (!\is_string($arg)) {
            \http_response_code(400);

            return ['error' => 'All args must be strings'];
        }
        $args[] = $arg;
    }

    // Allowlist: only safe commands can be proxied
    $allowed = [
        'version', 'help', 'cache', 'inspect', 'routes',
        'validate', 'pkg', 'search',
    ];

    if (!\in_array($command, $allowed, true)) {
        \http_response_code(403);

        return ['error' => 'Command not allowed: ' . $command, 'allowed' => $allowed];
    }

    // Block dangerous sub-operations
    if ($command === 'pkg') {
        $sub = $args[0] ?? '';
        $allowedPkgSubs = ['list', 'info', 'stop'];
        if ($sub && !\in_array($sub, $allowedPkgSubs, true)) {
            \http_response_code(403);

            return ['error' => 'pkg sub-command not allowed: ' . $sub, 'allowed' => $allowedPkgSubs];
        }
    }

    // Strip dangerous flags that could alter system behaviour
    $dangerousFlags = ['--all', '-all', '--daemon', '-daemon', '--silent', '-silent', '--force', '-force', '--generate', '-generate', '-g'];
    $args = \array_values(\array_filter($args, fn($a) => !\in_array($a, $dangerousFlags, true)));

    return apiCli($projectRoot, $command, $args);
}

// ══════════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════════

function readManifestFromPhar(string $pharPath): ?array
{
    try {
        $phar = new \Phar($pharPath);
        if (isset($phar['razy.pkg.json'])) {
            $data = \json_decode($phar['razy.pkg.json']->getContent(), true);
            if (\is_array($data) && !empty($data['package_name'])) {
                return $data;
            }
        }
    } catch (\Throwable $e) {
        // Skip invalid phars
    }

    return null;
}
