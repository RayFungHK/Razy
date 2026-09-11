<?php

/**
 * Unit tests for Razy\Metrics — /_razy/metrics Prometheus endpoint (HPA metric source).
 *
 * This file is part of Razy v1.0.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Metrics;

#[CoversClass(Metrics::class)]
class MetricsTest extends TestCase
{
    protected function setUp(): void
    {
        Metrics::resetForTesting();
        unset($_ENV['RAZY_HEALTH_TOKEN']);
        \putenv('RAZY_HEALTH_TOKEN');
    }

    protected function tearDown(): void
    {
        Metrics::resetForTesting();
        unset($_ENV['RAZY_HEALTH_TOKEN']);
        \putenv('RAZY_HEALTH_TOKEN');
    }

    public function testPassesThroughOnOtherPaths(): void
    {
        $this->assertFalse(Metrics::respondIfRequested('/'));
        $this->assertFalse(Metrics::respondIfRequested('/_razy/health'), 'health path is Health\'s job');
        $this->assertFalse(Metrics::respondIfRequested('/_razy/metricsz'), 'prefix must not match');
        $this->assertFalse(Metrics::respondIfRequested('/_razy/metricsX'));
    }

    public function testRespondsAndRendersPrometheusFormat(): void
    {
        Metrics::recordRequest();
        Metrics::recordRequest();

        \ob_start();
        $handled = Metrics::respondIfRequested('/_razy/metrics');
        $body = (string) \ob_get_clean();

        $this->assertTrue($handled);
        // Prometheus text exposition essentials.
        $this->assertStringContainsString('# TYPE razy_up gauge', $body);
        $this->assertStringContainsString('razy_up 1', $body);
        $this->assertStringContainsString('# TYPE razy_http_requests_total counter', $body);
        $this->assertMatchesRegularExpression('/razy_http_requests_total\{scope="(process|shared)"\} \d+/', $body);
        $this->assertStringContainsString('razy_memory_bytes', $body);
    }

    public function testTrailingSlashAndQueryStillMatch(): void
    {
        foreach (['/_razy/metrics', '/_razy/metrics/', '/_razy/metrics?format=prom'] as $path) {
            \ob_start();
            $handled = Metrics::respondIfRequested($path);
            \ob_get_clean();
            $this->assertTrue($handled, "must handle {$path}");
        }
    }

    public function testDefaultTierOmitsDeepMetrics(): void
    {
        $body = Metrics::render();

        $this->assertStringNotContainsString('razy_opcache_hit_rate', $body);
        $this->assertStringNotContainsString('razy_load_average_1m', $body);
        $this->assertStringNotContainsString('razy_memory_peak_bytes', $body);
    }

    public function testDeepTierRequiresToken(): void
    {
        $_ENV['RAZY_HEALTH_TOKEN'] = 'metric-otter';

        $noToken = Metrics::render([]);
        $wrong = Metrics::render(['token' => 'nope']);
        $this->assertStringNotContainsString('razy_memory_peak_bytes', $noToken);
        $this->assertStringNotContainsString('razy_memory_peak_bytes', $wrong);

        $deep = Metrics::render(['token' => 'metric-otter']);
        $this->assertStringContainsString('razy_memory_peak_bytes', $deep);
        $this->assertStringContainsString('# TYPE razy_memory_peak_bytes gauge', $deep);
    }

    public function testRecordRequestIncrementsCounter(): void
    {
        Metrics::resetForTesting();
        Metrics::recordRequest();
        Metrics::recordRequest();
        Metrics::recordRequest();

        $body = Metrics::render();

        // Process-scope counter equals 3 unless APCu shared store is active; parse the value.
        $this->assertSame(1, \preg_match('/razy_http_requests_total\{scope="[^"]+"\} (\d+)/', $body, $m));
        $this->assertGreaterThanOrEqual(3, (int) $m[1]);
    }

    public function testUptimeNonNegativeAndUpIsOne(): void
    {
        Metrics::resetForTesting();
        $body = Metrics::render();

        $this->assertMatchesRegularExpression('/razy_process_uptime_seconds \d+/', $body);
        $this->assertMatchesRegularExpression('/^razy_up 1$/m', $body);
    }
}
