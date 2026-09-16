<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy;

/**
 * Framework Prometheus metrics endpoint: GET /_razy/metrics.
 *
 * Interception happens in src/main.php BEFORE Application boot/routing, exactly
 * like Health, so scraping is cheap and dependency-free. This is the intended
 * source for the Horizontal Pod Autoscaler custom metric (deploy/k8s/hpa.yaml):
 * prometheus-adapter derives `http_requests_per_second` from the rate of
 * razy_http_requests_total.
 *
 * Output is the Prometheus text exposition format (text/plain; version=0.0.4).
 *
 * Metric tiers (mirrors Health's disclosure discipline):
 *  - always:   razy_up, razy_process_uptime_seconds, razy_http_requests_total,
 *              razy_memory_bytes
 *  - deep (?token=RAZY_HEALTH_TOKEN): adds OPcache hit rate, load average,
 *              peak memory — operational detail that should not be public.
 *
 * Counter durability: a monotonic request counter across FPM worker processes
 * requires shared state; this uses APCu when the extension is present (opcache
 * images ship it). Without APCu the counter is per-process — clearly labelled
 * via the `scope` label so scraped rates are never silently wrong.
 */
class Metrics
{
    /** Reserved scrape path (exact match after URL tidy). */
    public const PATH = '/_razy/metrics';

    private static ?int $bootTime = null;

    private static ?int $localRequestCount = null;

    /**
     * If $urlQuery targets the metrics path, emit the Prometheus response and
     * return true (caller must stop dispatch). Otherwise return false.
     */
    public static function respondIfRequested(string $urlQuery): bool
    {
        $path = \rtrim((string) \strtok($urlQuery, '?'), '/');

        if ($path !== self::PATH) {
            return false;
        }

        $query = [];
        \parse_str((string) (\parse_url($urlQuery, \PHP_URL_QUERY) ?: ''), $query);

        \header('HTTP/1.1 200', true, 200);
        \header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
        \header('Cache-Control: no-store');
        \header('X-Content-Type-Options: nosniff');

        echo self::render($query);

        return true;
    }

    /**
     * Count this request and increment the shared counter. Call once per handled
     * request from main.php (worker loop / FPM entry), independent of the scrape.
     */
    public static function recordRequest(): void
    {
        self::$bootTime ??= \time();
        self::$localRequestCount = (self::$localRequestCount ?? 0) + 1;

        if (\function_exists('apcu_inc') && \filter_var((string) \ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
            \apcu_inc('razy_http_requests_total');
        }
    }

    /**
     * Render the full exposition body.
     *
     * @param array<string, mixed> $query
     */
    public static function render(array $query = []): string
    {
        self::$bootTime ??= \time();

        [$scope, $requests] = self::requestCount();

        $lines = [];
        $lines[] = self::gauge('razy_up', 'Always 1 while the process answers.', 1);
        $lines[] = self::gauge('razy_process_uptime_seconds', 'Seconds since this process booted.', \max(0, \time() - self::$bootTime));
        $lines[] = "# TYPE razy_http_requests_total counter\nrazy_http_requests_total{scope=\"{$scope}\"} {$requests}";
        $lines[] = self::gauge('razy_memory_bytes', 'Current allocated memory in bytes.', \memory_get_usage(true));

        if (self::deepAuthorized($query)) {
            $peak = \memory_get_peak_usage(true);
            $lines[] = self::gauge('razy_memory_peak_bytes', 'Peak allocated memory in bytes.', $peak);

            if (\function_exists('sys_getloadavg')) {
                $load = \sys_getloadavg();
                $lines[] = self::gauge('razy_load_average_1m', 'System 1-minute load average.', $load[0] ?? 0);
            }

            $opcache = self::opcacheHitRate();
            if ($opcache !== null) {
                $lines[] = self::gauge('razy_opcache_hit_rate', 'OPcache hit ratio (0-1), reset on restart.', $opcache);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    /** Reset internal anchors (test seam). */
    public static function resetForTesting(): void
    {
        self::$bootTime = null;
        self::$localRequestCount = null;
    }

    /**
     * @return array{0: string, 1: int} [scope label, total]
     */
    private static function requestCount(): array
    {
        if (\function_exists('apcu_fetch') && \filter_var((string) \ini_get('apc.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
            $shared = \apcu_fetch('razy_http_requests_total', $ok);

            if ($ok) {
                return ['shared', (int) $shared];
            }

            // Seed once so the counter starts at the current process count.
            \apcu_store('razy_http_requests_total', self::$localRequestCount ?? 0);
        }

        return ['process', self::$localRequestCount ?? 0];
    }

    private static function opcacheHitRate(): ?float
    {
        if (!\function_exists('opcache_get_status')) {
            return null;
        }

        $status = @\opcache_get_status(false);

        if (!\is_array($status) || !isset($status['opcache_statistics']['hits'], $status['opcache_statistics']['misses'])) {
            return null;
        }

        $hits = (int) $status['opcache_statistics']['hits'];
        $misses = (int) $status['opcache_statistics']['misses'];
        $total = $hits + $misses;

        return $total > 0 ? \round($hits / $total, 4) : 0.0;
    }

    /**
     * Prometheus exposition gauge line.
     */
    private static function gauge(string $name, string $help, int|float $value): string
    {
        $num = \is_float($value) ? (string) $value : (string) $value;

        return "# TYPE {$name} gauge\n# HELP {$name} {$help}\n{$name} {$num}";
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
}
