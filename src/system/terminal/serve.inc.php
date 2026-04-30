<?php

/**
 * CLI Command: serve.
 *
 * Starts a lightweight development web server using PHP's built-in server.
 * Supports standalone, multisite (auto-detected), and explicit distributor
 * mode — no external web server (Apache, Caddy, Nginx) required.
 *
 * The command copies a router script (.razy-serve-router.php) from the phar
 * asset into the project root. The router handles:
 *   - Static file serving (CSS, JS, images, fonts) at C level
 *   - Webasset URL rewriting (maps /webassets/{module}/{version}/... to
 *     the physical module directory — equivalent to .htaccess rules)
 *   - Data directory mapping
 *   - FQDN simulation (overrides SERVER_NAME for distributor mode)
 *   - Falls through to index.php for all application routes
 *
 * Usage:
 *   php Razy.phar serve [options]
 *
 * Options:
 *   -l <host:port>          Listen address (default: localhost:8080)
 *   -r <path>               Runtime directory (used by Razy Hive)
 *   --dist <code[@tag]>     Serve a specific distributor (forces multisite)
 *   --fqdn <domain[/path]>  Simulated FQDN for dist mode (default: serve.razy.local)
 *   --standalone             Force standalone mode
 *   --open                   Open browser automatically
 *   --worker, -w             Enable worker mode (auto-restart)
 *   --max-requests <N>       Max requests before restart (default: 500)
 *   --memory-limit <N>       Memory limit MB before restart (default: 128)
 *   --gc-interval <N>        GC cycle interval in requests (default: 100)
 *   --daemon                 Run server in background (writes PID file)
 *   --stop                   Stop a running daemon
 *
 * Mode Selection:
 *   --standalone             Forces standalone mode (standalone/ folder)
 *   --dist <code>            Forces multisite mode for a specific distributor
 *   (neither)                Auto-detect from project structure
 *
 * FQDN Simulation:
 *   When --dist is used, the dev server creates a temporary sites.inc.php
 *   mapping the simulated FQDN to the distributor code. The router overrides
 *   $_SERVER['SERVER_NAME'] and $_SERVER['HTTP_HOST'] so the framework
 *   resolves the correct distributor — even though the browser connects to
 *   localhost.
 *
 *   Example: php Razy.phar serve --dist mysite --fqdn example.com/app
 *   This maps example.com → /app/ → mysite in sites.inc.php and overrides
 *   SERVER_NAME to example.com, prepending /app/ to REQUEST_URI.
 *
 * Worker Mode:
 *   PHP's built-in server runs in a single process. Worker mode wraps it in
 *   a supervisor loop that auto-restarts after N requests or when memory
 *   exceeds a threshold — preventing memory leaks in long dev sessions.
 *
 * Daemon Mode:
 *   --daemon spawns the server as a background process and writes its PID
 *   to .razy-serve.pid. Use --stop to terminate a running daemon.
 *
 * Limitations (development use only):
 *   - Single-threaded (one request at a time)
 *   - HTTP/1.1 only (no HTTP/2 or HTTPS)
 *   - Not recommended for production — use Caddy/FrankenPHP or Apache
 *
 * @license MIT
 */

namespace Razy;

use Razy\Util\PathUtil;

return function (string ...$args) use (&$parameters) {
    // ── Display help ──────────────────────────────────────────────
    // main.php strips only one leading dash, so --help → key "-help"
    if (isset($parameters['help']) || isset($parameters['-help']) || isset($parameters['h'])) {
        $this->writeLineLogging('{@s:bu}Razy Development Server', true);
        $this->writeLineLogging('Start a lightweight dev server using PHP\'s built-in server.', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Usage:{@reset}');
        $this->writeLineLogging('  php Razy.phar serve [options]', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Options:{@reset}');
        $this->writeLineLogging('  {@c:green}-l <host:port>{@reset}        Listen address (default: localhost:8080)', true);
        $this->writeLineLogging('  {@c:green}-r <path>{@reset}             Runtime directory (used by Razy Hive)', true);
        $this->writeLineLogging('  {@c:green}--dist <code[@tag]>{@reset}   Serve a specific distributor (forces multisite)', true);
        $this->writeLineLogging('  {@c:green}--fqdn <domain[/path]>{@reset} Simulated FQDN (use with --dist)', true);
        $this->writeLineLogging('  {@c:green}--standalone{@reset}           Force standalone mode', true);
        $this->writeLineLogging('  {@c:green}--open{@reset}                Open browser automatically', true);
        $this->writeLineLogging('  {@c:green}--worker, -w{@reset}          Enable worker mode (auto-restart)', true);
        $this->writeLineLogging('  {@c:green}--max-requests <N>{@reset}    Max requests before restart (default: 500)', true);
        $this->writeLineLogging('  {@c:green}--memory-limit <N>{@reset}    Memory limit in MB before restart (default: 128)', true);
        $this->writeLineLogging('  {@c:green}--gc-interval <N>{@reset}     GC cycle interval in requests (default: 100)', true);
        $this->writeLineLogging('  {@c:green}--daemon{@reset}              Run server in background', true);
        $this->writeLineLogging('  {@c:green}--stop{@reset}                Stop a running daemon', true);
        $this->writeLineLogging('  {@c:green}--help, -h{@reset}            Show this help message', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Examples:{@reset}');
        $this->writeLineLogging('  php Razy.phar serve', true);
        $this->writeLineLogging('  php Razy.phar serve -l 0.0.0.0:3000 --open', true);
        $this->writeLineLogging('  php Razy.phar serve --standalone', true);
        $this->writeLineLogging('  php Razy.phar serve --dist mysite', true);
        $this->writeLineLogging('  php Razy.phar serve --dist mysite --fqdn example.com/app', true);
        $this->writeLineLogging('  php Razy.phar serve --worker --max-requests 200', true);
        $this->writeLineLogging('  php Razy.phar serve --daemon', true);
        $this->writeLineLogging('  php Razy.phar serve --stop', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Modes:{@reset}');
        $this->writeLineLogging('  {@c:cyan}Standalone:{@reset}  Use --standalone or auto-detected when standalone/ exists.', true);
        $this->writeLineLogging('  {@c:cyan}Distributor:{@reset} Use --dist <code> to target a specific dist (+ optional --fqdn).', true);
        $this->writeLineLogging('  {@c:cyan}Multisite:{@reset}   Auto-detected when sites/ + sites.inc.php + multiple_site config.', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Worker Mode:{@reset}');
        $this->writeLineLogging('  Prevents memory leaks by auto-restarting the server process after', true);
        $this->writeLineLogging('  a configurable number of requests or when memory exceeds a threshold.', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}WARNING:{@reset} Development use only — not suitable for production.', true);

        return;
    }

    // ── Determine project root ────────────────────────────────────
    $projectRoot = \defined('RAZY_PATH') ? RAZY_PATH : (\defined('SYSTEM_ROOT') ? SYSTEM_ROOT : \getcwd());
    $projectRoot = \realpath($projectRoot);

    if (!$projectRoot || !\is_dir($projectRoot)) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Project directory not found.', true);

        return false;
    }

    // ── Handle --stop (daemon termination) ────────────────────────
    if (isset($parameters['stop']) || isset($parameters['-stop'])) {
        $pidFile = PathUtil::append($projectRoot, '.razy-serve.pid');
        if (!\is_file($pidFile)) {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No daemon PID file found (.razy-serve.pid).', true);

            return false;
        }

        $pid = (int) \trim(\file_get_contents($pidFile));
        if ($pid < 1) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid PID in .razy-serve.pid.', true);
            @\unlink($pidFile);

            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            \exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $output, $exitCode);
        } else {
            \exec('kill ' . $pid . ' 2>&1', $output, $exitCode);
        }

        @\unlink($pidFile);

        if ($exitCode === 0) {
            $this->writeLineLogging('{@c:green}[OK]{@reset} Daemon (PID ' . $pid . ') stopped.', true);
        } else {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Could not stop PID ' . $pid . ' (may have already exited).', true);
        }

        // Also clean up temp files
        foreach (['.razy-serve-router.php', '.razy-serve-config.json', '.razy-serve-state', '.razy-serve-sites-backup.inc.php'] as $f) {
            $fp = PathUtil::append($projectRoot, $f);
            if (\is_file($fp)) {
                @\unlink($fp);
            }
        }
        // Restore original sites.inc.php if backup exists
        $sitesBackup = PathUtil::append($projectRoot, '.razy-serve-sites-backup.inc.php');
        $sitesFile = PathUtil::append($projectRoot, 'sites.inc.php');
        if (\is_file($sitesBackup)) {
            \rename($sitesBackup, $sitesFile);
        }

        return true;
    }

    // ── Parse -l <host:port> from raw args ────────────────────────
    $listen = null;
    foreach ($args as $i => $arg) {
        if ('-l' === $arg && isset($args[$i + 1])) {
            $listen = $args[$i + 1];
            break;
        }
    }
    if (!$listen) {
        $listen = 'localhost:8080';
    }

    // ── Parse -r <runtime-dir> from raw args ──────────────────────
    $runtimeDir = null;
    foreach ($args as $i => $arg) {
        if ('-r' === $arg && isset($args[$i + 1])) {
            $runtimeDir = $args[$i + 1];
            break;
        }
    }

    // ── Parse --dist <code[@tag]> ─────────────────────────────────
    $distCode = null;
    foreach ($args as $i => $arg) {
        if (('--dist' === $arg || '-dist' === $arg) && isset($args[$i + 1])) {
            $distCode = $args[$i + 1];
            break;
        }
    }

    // ── Parse --fqdn <domain[/path]> ──────────────────────────────
    $fqdnArg = null;
    foreach ($args as $i => $arg) {
        if (('--fqdn' === $arg || '-fqdn' === $arg) && isset($args[$i + 1])) {
            $fqdnArg = $args[$i + 1];
            break;
        }
    }

    // ── Parse mode flags ──────────────────────────────────────────
    $forceStandalone = isset($parameters['standalone']) || isset($parameters['-standalone']);
    $daemonMode = isset($parameters['daemon']) || isset($parameters['-daemon']);

    // ── Parse worker mode flags ───────────────────────────────────
    $workerMode = isset($parameters['worker']) || isset($parameters['-worker']) || isset($parameters['w']);

    // Parse --max-requests <N>
    $maxRequests = 500;
    foreach ($args as $i => $arg) {
        if (('--max-requests' === $arg || '-max-requests' === $arg) && isset($args[$i + 1])) {
            $maxRequests = (int) $args[$i + 1];
            break;
        }
    }
    $maxRequests = \max(1, $maxRequests);

    // Parse --memory-limit <N> (in MB)
    $memoryLimitMB = 128;
    foreach ($args as $i => $arg) {
        if (('--memory-limit' === $arg || '-memory-limit' === $arg) && isset($args[$i + 1])) {
            $memoryLimitMB = (int) $args[$i + 1];
            break;
        }
    }
    $memoryLimitMB = \max(16, $memoryLimitMB);

    // Parse --gc-interval <N>
    $gcInterval = 100;
    foreach ($args as $i => $arg) {
        if (('--gc-interval' === $arg || '-gc-interval' === $arg) && isset($args[$i + 1])) {
            $gcInterval = (int) $args[$i + 1];
            break;
        }
    }
    $gcInterval = \max(0, $gcInterval);

    // ── Separate host and port ────────────────────────────────────
    if (\str_contains($listen, ':')) {
        [$host, $port] = \explode(':', $listen, 2);
    } else {
        $host = $listen;
        $port = '8080';
    }
    $port = (int) $port;

    if ($port < 1 || $port > 65535) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid port number: ' . $port, true);

        return false;
    }

    // ── Validate conflicting flags ────────────────────────────────
    if ($forceStandalone && $distCode) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Cannot use --standalone and --dist together.', true);

        return false;
    }

    if ($fqdnArg && !$distCode) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} --fqdn requires --dist.', true);

        return false;
    }

    // ── Determine mode ────────────────────────────────────────────
    $standalonePath = PathUtil::append($projectRoot, 'standalone');
    $sitesPath = PathUtil::append($projectRoot, 'sites');
    $sitesConfigFile = PathUtil::append($projectRoot, 'sites.inc.php');
    $sitesBackupFile = PathUtil::append($projectRoot, '.razy-serve-sites-backup.inc.php');
    $appConfigFile = PathUtil::append($projectRoot, 'config.inc.php');

    $mode = null;            // 'standalone' | 'dist' | 'multisite'
    $fqdnDomain = null;      // Simulated domain for --dist mode
    $fqdnPath = '/';          // Simulated URL path prefix for --dist mode
    $sitesBackedUp = false;   // Whether we backed up the original sites.inc.php

    if ($forceStandalone) {
        // ── Forced standalone mode ────────────────────────────────
        if (!\is_dir($standalonePath)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} standalone/ folder not found.', true);
            $this->writeLineLogging('Create one with: php Razy.phar standalone', true);

            return false;
        }
        $mode = 'standalone';
    } elseif ($distCode) {
        // ── Explicit distributor mode ─────────────────────────────
        // Validate dist code format
        $distParts = \explode('@', $distCode . '@', 2);
        $distName = $distParts[0];
        $distTag = \trim($distParts[1]) ?: '*';

        if (!\preg_match('/^[a-z][\w\-]*$/i', $distName)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid distributor code: ' . $distName, true);

            return false;
        }

        // Verify distributor folder and dist.php exist
        $distFolder = PathUtil::append($projectRoot, 'sites', $distName);
        if (!\is_dir($distFolder)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Distributor folder not found: sites/' . $distName, true);

            return false;
        }
        if (!\is_file(PathUtil::append($distFolder, 'dist.php'))) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Missing dist.php in sites/' . $distName, true);

            return false;
        }

        // Parse FQDN argument: domain[/path]
        if ($fqdnArg) {
            // Split into domain and path: "example.com/app" → domain="example.com", path="/app/"
            $slashPos = \strpos($fqdnArg, '/');
            if ($slashPos !== false) {
                $fqdnDomain = \substr($fqdnArg, 0, $slashPos);
                $fqdnPath = '/' . \trim(\substr($fqdnArg, $slashPos), '/') . '/';
            } else {
                $fqdnDomain = $fqdnArg;
                $fqdnPath = '/';
            }
        } else {
            // Default simulated domain
            $fqdnDomain = 'serve.razy.local';
            $fqdnPath = '/';
        }

        // Tidy the path: ensure it starts and ends with /
        $fqdnPath = '/' . \trim($fqdnPath, '/');
        if ($fqdnPath !== '/') {
            $fqdnPath .= '/';
        }

        // Build the dist identifier for sites.inc.php
        $distIdentifier = ($distTag && $distTag !== '*') ? ($distName . '@' . $distTag) : $distName;

        // ── Write temporary sites.inc.php ─────────────────────────
        // Backup existing sites.inc.php if present
        if (\is_file($sitesConfigFile)) {
            if (\copy($sitesConfigFile, $sitesBackupFile)) {
                $sitesBackedUp = true;
            } else {
                $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Could not backup sites.inc.php — proceeding anyway.', true);
            }
        }

        // Write a temporary sites.inc.php mapping the simulated FQDN to the dist
        $tempSitesContent = "<?php\n"
            . "// Auto-generated by Razy serve --dist (temporary — will be cleaned up)\n"
            . "return [\n"
            . "    'domains' => [\n"
            . '        ' . \var_export($fqdnDomain, true) . " => [\n"
            . '            ' . \var_export($fqdnPath, true) . ' => ' . \var_export($distIdentifier, true) . ",\n"
            . "        ],\n"
            . "    ],\n"
            . "    'alias' => [],\n"
            . "];\n";

        if (false === \file_put_contents($sitesConfigFile, $tempSitesContent)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Could not write temporary sites.inc.php.', true);
            // Restore backup if we made one
            if ($sitesBackedUp) {
                \rename($sitesBackupFile, $sitesConfigFile);
            }

            return false;
        }

        $mode = 'dist';
    } else {
        // ── Auto-detect mode ──────────────────────────────────────
        $configData = [];
        if (\is_file($appConfigFile)) {
            $configData = require $appConfigFile;
        }

        $isMultisiteEnabled = !empty($configData['multiple_site']) || \getenv('RAZY_MULTIPLE_SITE') === 'true';
        $isStandalone = \is_dir($standalonePath) && !$isMultisiteEnabled;
        $isMultisite = $isMultisiteEnabled && \is_dir($sitesPath);

        if ($isStandalone) {
            $mode = 'standalone';
        } elseif ($isMultisite) {
            $mode = 'multisite';
        } else {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No standalone/ folder or multisite configuration found.', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Options:', true);
            $this->writeLineLogging('  php Razy.phar serve --standalone       (standalone mode)', true);
            $this->writeLineLogging('  php Razy.phar serve --dist <code>      (distributor mode)', true);
            $this->writeLineLogging('  php Razy.phar standalone               (create standalone app)', true);
            $this->writeLineLogging('  php Razy.phar build                    (set up multisite)', true);

            return false;
        }
    }

    // ── Verify index.php exists ───────────────────────────────────
    $indexPath = PathUtil::append($projectRoot, 'index.php');
    if (!\is_file($indexPath)) {
        $this->writeLineLogging('{@c:yellow}[INFO]{@reset} No index.php found — generating entry point...', true);

        $indexContent = <<<'PHP'
<?php
namespace Razy;

use Exception;
use Phar;

$pharPath = './Razy.phar';
if (is_file('./config.inc.php')) {
    try {
        $razyConfig = require './config.inc.php';
        $pharPath   = realpath(($razyConfig['phar_location'] ?? '.') . '/Razy.phar');
        if (!is_file($pharPath)) {
            echo 'Razy.phar not found. Check phar_location in config.inc.php.';
            exit;
        }
    } catch (Exception) {
        echo 'Failed to load config.inc.php';
        exit;
    }
}

Phar::loadPhar($pharPath, 'Razy.phar');
include 'phar://Razy.phar/main.php';

PHP;

        if (false !== \file_put_contents($indexPath, $indexContent)) {
            $this->writeLineLogging('  {@c:green}[OK]{@reset} index.php created', true);
        } else {
            $this->writeLineLogging('  {@c:red}[FAIL]{@reset} Could not write index.php', true);

            return false;
        }
    }

    // ── Validate port availability ────────────────────────────────
    $testSocket = @\stream_socket_server(
        'tcp://' . $host . ':' . $port,
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    );
    if (!$testSocket) {
        $this->writeLineLogging(
            '{@c:red}[Error]{@reset} Cannot bind to ' . $host . ':' . $port . ' — ' . $errstr . ' (errno ' . $errno . ')',
            true,
        );
        $this->writeLineLogging('Is another process already using this port?', true);

        // Restore sites.inc.php backup if we modified it
        if ($sitesBackedUp) {
            \rename($sitesBackupFile, $sitesConfigFile);
        }

        return false;
    }
    \fclose($testSocket);
    // Brief pause so the OS fully releases the socket before php -S binds
    \usleep(100_000);

    // ── Copy router script from phar asset ──────────────────────
    $routerPath = PathUtil::append($projectRoot, '.razy-serve-router.php');
    $configPath = PathUtil::append($projectRoot, '.razy-serve-config.json');
    $statePath = PathUtil::append($projectRoot, '.razy-serve-state');
    $pidFilePath = PathUtil::append($projectRoot, '.razy-serve.pid');
    $assetRouter = PHAR_PATH . '/asset/setup/serve.php';

    if (!\copy($assetRouter, $routerPath)) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Could not copy router script from phar asset.', true);

        // Restore sites.inc.php backup if we modified it
        if ($sitesBackedUp) {
            \rename($sitesBackupFile, $sitesConfigFile);
        }

        return false;
    }

    // ── Write serve config JSON ───────────────────────────────────
    $serveConfig = [
        'mode' => $mode,
        'worker' => $workerMode,
        'max_requests' => $maxRequests,
        'memory_limit' => $memoryLimitMB,
        'gc_interval' => $gcInterval,
    ];

    // Add FQDN simulation config for dist mode
    if ($mode === 'dist') {
        $serveConfig['fqdn_domain'] = $fqdnDomain;
        $serveConfig['fqdn_path'] = $fqdnPath;
        $serveConfig['dist_code'] = $distCode;
    }

    if (false === \file_put_contents($configPath, \json_encode($serveConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n")) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Could not write serve config.', true);

        // Restore sites.inc.php backup if we modified it
        if ($sitesBackedUp) {
            \rename($sitesBackupFile, $sitesConfigFile);
        }

        return false;
    }

    // ── Build display labels ──────────────────────────────────────
    $modeLabel = match ($mode) {
        'standalone' => 'Standalone',
        'dist' => 'Distributor (' . $distCode . ')',
        'multisite' => 'Multisite',
    };

    // ── Display banner ────────────────────────────────────────────
    $this->writeLineLogging('', true);
    $this->writeLineLogging('{@s:bu}Razy Development Server', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('  {@c:cyan}Mode:{@reset}     ' . $modeLabel, true);
    $this->writeLineLogging('  {@c:cyan}Root:{@reset}     ' . $projectRoot, true);
    $this->writeLineLogging('  {@c:cyan}Listen:{@reset}   http://' . $host . ':' . $port, true);
    $this->writeLineLogging('  {@c:cyan}PHP:{@reset}      ' . PHP_VERSION . ' (' . PHP_SAPI . ')', true);
    if ($runtimeDir) {
        $this->writeLineLogging('  {@c:cyan}Runtime:{@reset}  ' . $runtimeDir, true);
    }
    if ($mode === 'dist' && $fqdnDomain) {
        $this->writeLineLogging('  {@c:cyan}FQDN:{@reset}    ' . $fqdnDomain . ($fqdnPath !== '/' ? $fqdnPath : ''), true);
    }
    if ($workerMode) {
        $this->writeLineLogging('  {@c:cyan}Worker:{@reset}   ON (max ' . $maxRequests . ' req, ' . $memoryLimitMB . ' MB limit, GC every ' . ($gcInterval ?: 'disabled') . ' req)', true);
    }
    if ($daemonMode) {
        $this->writeLineLogging('  {@c:cyan}Daemon:{@reset}   ON', true);
    }
    $this->writeLineLogging('', true);

    // ── Open browser if requested ─────────────────────────────────
    if (isset($parameters['open']) || isset($parameters['-open'])) {
        $url = 'http://' . $host . ':' . $port;
        if (PHP_OS_FAMILY === 'Windows') {
            \pclose(\popen('start "" ' . \escapeshellarg($url), 'r'));
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            \exec('open ' . \escapeshellarg($url));
        } else {
            \exec('xdg-open ' . \escapeshellarg($url) . ' &');
        }
    }

    // ── Build PHP command ─────────────────────────────────────────
    $phpBinary = PHP_BINARY;
    $memoryLimitPHP = $memoryLimitMB . 'M';

    // Build environment variables for the child process.
    // For dist mode, set RAZY_MULTIPLE_SITE so main.php activates multisite.
    $envVars = [];
    if ($mode === 'dist') {
        $envVars['RAZY_MULTIPLE_SITE'] = 'true';
    }

    // In worker mode, enforce memory_limit at the PHP level
    $phpFlags = $workerMode
        ? \sprintf('-d memory_limit=%s', \escapeshellarg($memoryLimitPHP))
        : '';

    $command = \trim(\sprintf(
        '%s %s -S %s:%d -t %s %s',
        \escapeshellarg($phpBinary),
        $phpFlags,
        \escapeshellarg($host),
        $port,
        \escapeshellarg($projectRoot),
        \escapeshellarg($routerPath),
    ));

    // Prepend environment variables (cross-platform)
    $envPrefix = '';
    foreach ($envVars as $k => $v) {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use set command chained with &&
            $envPrefix .= 'set ' . \escapeshellarg($k . '=' . $v) . '& ';
        } else {
            $envPrefix .= $k . '=' . \escapeshellarg($v) . ' ';
        }
    }

    // ── Daemon mode ───────────────────────────────────────────────
    if ($daemonMode) {
        $this->writeLineLogging('{@c:cyan}[Serve]{@reset} Starting daemon...', true);

        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use start /B to spawn in background
            $daemonCmd = $envPrefix . 'start /B ' . $command . ' > NUL 2>&1';
            \pclose(\popen($daemonCmd, 'r'));

            // Wait a moment, then find the PID via tasklist
            \usleep(500_000);
            \exec('wmic process where "CommandLine like \'%-S ' . \addcslashes($host, "'\\") . ':' . $port . '%\'" get ProcessId 2>NUL', $wmicOut);
            $daemonPid = 0;
            foreach ($wmicOut as $line) {
                $line = \trim($line);
                if (\is_numeric($line) && (int) $line > 0) {
                    $daemonPid = (int) $line;
                    break;
                }
            }
        } else {
            // Unix: nohup + background
            $daemonCmd = $envPrefix . 'nohup ' . $command . ' > /dev/null 2>&1 & echo $!';
            $daemonPid = (int) \trim(\shell_exec($daemonCmd) ?? '0');
        }

        if ($daemonPid > 0) {
            \file_put_contents($pidFilePath, (string) $daemonPid);
            $this->writeLineLogging('{@c:green}[OK]{@reset} Daemon started (PID: ' . $daemonPid . ')', true);
            $this->writeLineLogging('  Stop with: php Razy.phar serve --stop', true);
        } else {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Daemon started but PID could not be captured.', true);
        }

        return true;
    }

    // ── Foreground mode ───────────────────────────────────────────
    $this->writeLineLogging('{@c:yellow}Press Ctrl+C to stop the server.{@reset}', true);
    $this->writeLineLogging('', true);

    $restartCount = 0;
    $exitCode = 0;

    // Build proc_open environment array
    $procEnv = null;
    if (!empty($envVars)) {
        // Inherit current environment and merge our vars
        $procEnv = \array_merge(\getenv(), $envVars);
    }

    if ($workerMode) {
        // ── Worker mode: proc_open supervisor ─────────────────────
        $memoryLimitBytes = $memoryLimitMB * 1024 * 1024;

        // Clean state file before first run
        if (\is_file($statePath)) {
            @\unlink($statePath);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['pipe', 'w'],
        ];

        $keepRunning = true;

        while ($keepRunning) {
            $process = @\proc_open($command, $descriptorSpec, $pipes, $projectRoot, $procEnv);

            if (!\is_resource($process)) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} Failed to start server process.', true);

                break;
            }

            \fclose($pipes[0]);
            \stream_set_blocking($pipes[2], false);

            $shouldRestart = false;
            $reason = '';

            // ── Polling loop ──────────────────────────────────────
            while (true) {
                $procStatus = \proc_get_status($process);
                if (!$procStatus['running']) {
                    $exitCode = $procStatus['exitcode'];

                    break;
                }

                // Forward stderr in real-time
                $stderrChunk = @\fread($pipes[2], 8192);
                if ($stderrChunk !== false && $stderrChunk !== '') {
                    \fwrite(STDERR, $stderrChunk);
                }

                // Poll state file
                if (\is_file($statePath)) {
                    $stateJson = @\file_get_contents($statePath);
                    $stateData = $stateJson ? @\json_decode($stateJson, true) : null;

                    if (\is_array($stateData)) {
                        $reqCount = (int) ($stateData['requests'] ?? 0);
                        $memUsage = (int) ($stateData['memory'] ?? 0);

                        if ($maxRequests > 0 && $reqCount >= $maxRequests) {
                            $shouldRestart = true;
                            $reason = 'max requests reached (' . $reqCount . ' >= ' . $maxRequests . ')';
                        } elseif ($memUsage >= $memoryLimitBytes) {
                            $memMB = \round($memUsage / 1024 / 1024, 1);
                            $shouldRestart = true;
                            $reason = 'memory limit (' . $memMB . ' MB >= ' . $memoryLimitMB . ' MB)';
                        }

                        if ($shouldRestart) {
                            \proc_terminate($process);
                            $waited = 0;
                            while ($waited < 3000) {
                                $st = \proc_get_status($process);
                                if (!$st['running']) {
                                    break;
                                }
                                \usleep(100_000);
                                $waited += 100;
                            }

                            break;
                        }
                    }
                }

                \usleep(100_000);
            }

            // Drain remaining stderr
            $tail = @\stream_get_contents($pipes[2]);
            if ($tail) {
                \fwrite(STDERR, $tail);
            }
            \fclose($pipes[2]);
            \proc_close($process);

            if ($shouldRestart) {
                ++$restartCount;
                @\unlink($statePath);

                $this->writeLineLogging('', true);
                $this->writeLineLogging(
                    '{@c:yellow}[Worker]{@reset} Restarting server (#' . $restartCount . ') — ' . $reason,
                    true,
                );
                $this->writeLineLogging('', true);

                \file_put_contents($configPath, \json_encode($serveConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
                \usleep(250_000);

                continue;
            }

            $keepRunning = false;
        }
    } else {
        // ── Standard mode: passthru ───────────────────────────────
        // For env vars, use proc_open so we can pass the environment
        if (!empty($envVars)) {
            $descriptorSpec = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];
            $process = \proc_open($command, $descriptorSpec, $pipes, $projectRoot, $procEnv);
            if (\is_resource($process)) {
                $exitCode = \proc_close($process);
            }
        } else {
            \passthru($command, $exitCode);
        }
    }

    // ── Clean up on exit ──────────────────────────────────────────
    // Restore sites.inc.php if we backed it up (dist mode)
    if ($sitesBackedUp && \is_file($sitesBackupFile)) {
        \rename($sitesBackupFile, $sitesConfigFile);
        $this->writeLineLogging('{@c:cyan}[Serve]{@reset} Restored original sites.inc.php', true);
    } elseif ($mode === 'dist' && !$sitesBackedUp && \is_file($sitesConfigFile)) {
        // We wrote a new sites.inc.php but there was no original — remove it
        @\unlink($sitesConfigFile);
    }

    // Remove temporary files
    if (\is_file($routerPath)) {
        @\unlink($routerPath);
    }
    if (\is_file($configPath)) {
        @\unlink($configPath);
    }
    if (\is_file($statePath)) {
        @\unlink($statePath);
    }
    if (\is_file($pidFilePath)) {
        @\unlink($pidFilePath);
    }

    if ($workerMode && $restartCount > 0) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:cyan}[Worker]{@reset} Server stopped after ' . $restartCount . ' restart(s).', true);
    }

    if ($exitCode !== 0) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:red}Server exited with code ' . $exitCode . '.{@reset}', true);

        return false;
    }

    return true;
};
