<?php

namespace Razy;

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
 * context this endpoint deliberately does not boot. Readiness for external
 * dependencies is the deployment's own probe chain (documented in manual/06).
 */
class Health
{
    /** Reserved probe path (exact match after URL tidy). */
    public const PATH = '/_razy/health';

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
