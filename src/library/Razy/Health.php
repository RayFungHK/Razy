<?php

namespace Razy;

use Razy\Health\HealthRegistry;

/**
 * Framework liveness endpoint: GET /_razy/health (2026 audit security checklist).
 *
 * Interception happens in src/main.php BEFORE Application boot/routing, so probes
 * work even when no distributor resolves, cost almost nothing, and carry no
 * session/module side effects.
 *
 * Response tiers:
 *  - default:  {"status":"ok","uptime_seconds":N,"timestamp":ts}     (probe-safe)
 *  - verbose:  adds version/php/mode — only when RAZY_HEALTH_VERBOSE=1
 *  - deep:     adds composer/package count — requires RAZY_HEALTH_TOKEN secret
 *              (compare via ?token=..., constant-time)
 *
 * Liveness, not dependency-readiness: DB/cache round-trips need distributor/module
 * context this endpoint deliberately does not boot.
 *
 * RZ-13 readiness: GET /_razy/ready is answered by the dist (after its modules
 * loaded, before the session and the route table) from the checks modules
 * registered with `$agent->addHealthCheck()` ({@see HealthRegistry}). 503 when
 * a critical check fails, 200 otherwise. Check errors are shown only to a
 * caller holding RAZY_HEALTH_TOKEN; everyone else sees names and statuses.
 */
class Health
{
    /** Reserved probe path (exact match after URL tidy). */
    public const PATH = '/_razy/health';

    /** RZ-13: reserved readiness path, relative to the dist's URL path. */
    public const READY_PATH = '/_razy/ready';

    /** Worker-mode process boot anchor; FPM re-bootstraps per request (uptime ~0). */
    private static ?int $bootTime = null;

    /**
     * If $urlQuery targets the health path, emit the JSON response and return
     * true (caller must stop dispatch). Otherwise return false.
     */
    public static function respondIfRequested(string $urlQuery): bool
    {
        $path = \rtrim((string) \strtok($urlQuery, '?'), '/');

        if ($path !== self::PATH) {
            return false;
        }

        $query = [];
        \parse_str((string) (\parse_url($urlQuery, \PHP_URL_QUERY) ?: ''), $query);

        $payload = self::payload($query);

        \header('HTTP/1.1 200', true, 200);
        \header('Content-Type: application/json; charset=UTF-8');
        \header('Cache-Control: no-store');
        \header('X-Content-Type-Options: nosniff');

        echo \json_encode($payload, \JSON_UNESCAPED_SLASHES);

        return true;
    }

    /**
     * Build the health payload according to the tier the caller may access.
     *
     * @param array $query parsed query string (?token=, exposed via ?full=1)
     *
     * @return array<string, mixed>
     */
    public static function payload(array $query = []): array
    {
        self::$bootTime ??= \time();

        $payload = [
            'status' => 'ok',
            'uptime_seconds' => \max(0, \time() - self::$bootTime),
            'timestamp' => \time(),
        ];

        if (self::verboseEnabled()) {
            $payload['version'] = \defined('RAZY_VERSION') ? RAZY_VERSION : 'unknown';
            $payload['php'] = \PHP_VERSION;
            $payload['mode'] = self::runtimeMode();
        }

        if (self::deepAuthorized($query)) {
            $payload['checks'] = [
                'memory_mb' => \round(\memory_get_usage(true) / 1048576, 1),
                'load' => \function_exists('sys_getloadavg') ? \sys_getloadavg()[0] ?? null : null,
            ];
        }

        return $payload;
    }

    /**
     * RZ-13: whether a dist-relative path (no query string) is the readiness probe.
     */
    public static function isReadyPath(string $urlQuery): bool
    {
        return \rtrim($urlQuery, '/') === self::READY_PATH;
    }

    /**
     * RZ-13: run the readiness checks and build the response.
     *
     * @param HealthRegistry $registry The dist's checks
     * @param array<string, mixed> $query Parsed query string (?token= unlocks check errors)
     *
     * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON payload
     */
    public static function readiness(HealthRegistry $registry, array $query = []): array
    {
        $report = $registry->run();
        if (!self::deepAuthorized($query)) {
            foreach ($report['checks'] as &$check) {
                unset($check['error']);
            }
            unset($check);
        }

        $payload = [
            'status' => $report['status'],
            'checks' => (object) $report['checks'],
            'timestamp' => \time(),
        ];

        return [$report['status'] === HealthRegistry::STATUS_FAIL ? 503 : 200, $payload];
    }

    /**
     * RZ-13: answer GET /_razy/ready with the readiness JSON.
     *
     * @param HealthRegistry $registry The dist's checks
     * @param array<string, mixed>|null $query Parsed query string (default: $_GET)
     *
     * @return int The HTTP status sent
     */
    public static function respondReady(HealthRegistry $registry, ?array $query = null): int
    {
        [$status, $payload] = self::readiness($registry, $query ?? $_GET);

        if (!\headers_sent()) {
            \http_response_code($status);
            \header('Content-Type: application/json; charset=UTF-8');
            \header('Cache-Control: no-store');
            \header('X-Content-Type-Options: nosniff');
        }
        echo \json_encode($payload, \JSON_UNESCAPED_SLASHES);

        return $status;
    }

    /** Reset the boot anchor (test seam / worker hot-reload). */
    public static function resetForTesting(): void
    {
        self::$bootTime = null;
    }

    private static function verboseEnabled(): bool
    {
        return \filter_var((string) ($_ENV['RAZY_HEALTH_VERBOSE'] ?? \getenv('RAZY_HEALTH_VERBOSE') ?: ''), \FILTER_VALIDATE_BOOLEAN);
    }

    private static function deepAuthorized(array $query): bool
    {
        $secret = (string) ($_ENV['RAZY_HEALTH_TOKEN'] ?? \getenv('RAZY_HEALTH_TOKEN') ?: '');

        if ($secret === '') {
            return false;
        }

        $given = isset($query['token']) && \is_string($query['token']) ? $query['token'] : '';

        return $given !== '' && \hash_equals($secret, $given);
    }

    private static function runtimeMode(): string
    {
        if (\defined('WORKER_MODE') && WORKER_MODE && \function_exists('frankenphp_handle_request')) {
            return 'worker';
        }

        return \defined('CLI_MODE') && CLI_MODE ? 'cli' : 'fpm';
    }
}
