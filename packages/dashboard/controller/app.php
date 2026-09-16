<?php

/**
 * Dashboard Package — Main Controller.
 *
 * Serve-mode package that starts a PHP built-in web server to
 * provide a GUI dashboard for managing Razy packages, modules,
 * and system status.
 *
 * Lifecycle:
 *   __onPackageStart  → validate environment, resolve paths
 *   __onPackageServe  → start PHP built-in server (blocking)
 *   __onPackageStop   → cleanup
 *   __onPackageHealthcheck → return true if server is responsive
 */

namespace Razy\Module\standalone_app;

use Razy\Agent;
use Razy\Controller;
use Razy\PackageTrait;

return new class extends Controller {
    use PackageTrait;

    private string $host = '127.0.0.1';

    private int $port = 3900;

    private string $pkgDir = '';

    private string $projectRoot = '';

    /** @var resource|null */
    private $serverProcess = null;

    public function __onInit(Agent $agent): bool
    {
        return true;
    }

    /**
     * Validate environment and resolve paths.
     */
    public function __onPackageStart(array $packageInfo): bool
    {
        $this->projectRoot = \defined('RAZY_PATH')
            ? RAZY_PATH
            : (\defined('SYSTEM_ROOT') ? SYSTEM_ROOT : \getcwd());

        $this->pkgDir = $this->projectRoot . \DIRECTORY_SEPARATOR . 'packages';

        // Parse --port from args
        $args = $packageInfo['args'] ?? [];
        foreach ($args as $i => $arg) {
            if (('--port' === $arg || '-p' === $arg) && isset($args[$i + 1])) {
                $this->port = (int) $args[$i + 1];
            }
            if ('--host' === $arg && isset($args[$i + 1])) {
                $this->host = $args[$i + 1];
            }
        }

        return true;
    }

    /**
     * Start the PHP built-in web server and block until it exits.
     */
    public function __onPackageServe(array $packageInfo): void
    {
        $routerPath = __DIR__ . \DIRECTORY_SEPARATOR . 'router.php';
        $docRoot = __DIR__ . \DIRECTORY_SEPARATOR . 'assets';

        if (!\is_dir($docRoot)) {
            \mkdir($docRoot, 0o777, true);
        }

        $bind = $this->host . ':' . $this->port;

        echo "[dashboard] Starting on http://{$bind}\n";
        echo "[dashboard] Project root: {$this->projectRoot}\n";
        echo "[dashboard] Press Ctrl+C to stop.\n";

        // Set env vars so the router.php can access them
        \putenv('RAZY_DASHBOARD_PROJECT_ROOT=' . $this->projectRoot);
        \putenv('RAZY_DASHBOARD_PKG_DIR=' . $this->pkgDir);
        \putenv('RAZY_DASHBOARD_ASSETS=' . $docRoot);

        // Start PHP built-in server as a child process
        $phpBin = \defined('PHP_BINARY') ? PHP_BINARY : 'php';
        $cmd = \sprintf(
            '%s -S %s -t %s %s',
            \escapeshellarg($phpBin),
            \escapeshellarg($bind),
            \escapeshellarg($docRoot),
            \escapeshellarg($routerPath),
        );

        // Use proc_open so we can wait and stream output
        $descriptors = [
            0 => ['pipe', 'r'],        // stdin
            1 => ['pipe', 'w'],        // stdout
            2 => ['pipe', 'w'],        // stderr
        ];

        $this->serverProcess = \proc_open($cmd, $descriptors, $pipes);

        if (!\is_resource($this->serverProcess)) {
            echo "[dashboard] Failed to start web server.\n";

            return;
        }

        // Close stdin — server doesn't need it
        \fclose($pipes[0]);

        // Set stderr to non-blocking so we can poll it
        \stream_set_blocking($pipes[2], false);

        // Block and stream server output until process ends or signal
        $running = true;
        // Handle SIGINT/SIGTERM for graceful shutdown
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
            \pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            // Check if process is still alive
            $status = \proc_get_status($this->serverProcess);
            if (!$status['running']) {
                break;
            }

            // Read and echo server stderr (PHP built-in server logs to stderr)
            $line = \fgets($pipes[2]);
            if ($line !== false) {
                echo $line;
            }

            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }

            \usleep(50000); // 50ms poll
        }

        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_terminate($this->serverProcess);
        \proc_close($this->serverProcess);
        $this->serverProcess = null;

        echo "[dashboard] Server stopped.\n";
    }

    /**
     * Cleanup on shutdown.
     */
    public function __onPackageStop(): void
    {
        if (\is_resource($this->serverProcess)) {
            \proc_terminate($this->serverProcess);
            \proc_close($this->serverProcess);
            $this->serverProcess = null;
        }
    }

    /**
     * Check if the server is responsive.
     */
    public function __onPackageHealthcheck(): bool
    {
        $url = "http://{$this->host}:{$this->port}/api/health";
        $ctx = \stream_context_create(['http' => ['timeout' => 2]]);
        $response = @\file_get_contents($url, false, $ctx);

        return $response !== false && \str_contains($response, '"ok"');
    }
};
