<?php

/**
 * Unit tests for Razy\Health — /_razy/health liveness endpoint (audit §checklist).
 *
 * This file is part of Razy v1.0.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Health;

#[CoversClass(Health::class)]
class HealthTest extends TestCase
{
    protected function setUp(): void
    {
        Health::resetForTesting();
        unset($_ENV['RAZY_HEALTH_VERBOSE'], $_ENV['RAZY_HEALTH_TOKEN']);
        \putenv('RAZY_HEALTH_VERBOSE');
        \putenv('RAZY_HEALTH_TOKEN');
    }

    protected function tearDown(): void
    {
        unset($_ENV['RAZY_HEALTH_VERBOSE'], $_ENV['RAZY_HEALTH_TOKEN']);
        \putenv('RAZY_HEALTH_VERBOSE');
        \putenv('RAZY_HEALTH_TOKEN');
    }

    public function testRespondsOnlyOnHealthPath(): void
    {
        $this->assertFalse(Health::respondIfRequested('/'), 'root must pass through');
        $this->assertFalse(Health::respondIfRequested('/blog/post/1'));
        $this->assertFalse(Health::respondIfRequested('/_razy/healthz'), 'prefix-must-not-match');
        $this->assertFalse(Health::respondIfRequested('/api/_razy/health'), 'suffix-must-not-match');
    }

    public function testDefaultPayloadIsProbeSafe(): void
    {
        $payload = Health::payload();

        $this->assertSame('ok', $payload['status']);
        $this->assertArrayHasKey('uptime_seconds', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        // Default tier must NOT leak version / php / paths.
        $this->assertArrayNotHasKey('version', $payload);
        $this->assertArrayNotHasKey('php', $payload);
        $this->assertArrayNotHasKey('mode', $payload);
    }

    public function testVerboseTierAddsVersionWhenEnabled(): void
    {
        $_ENV['RAZY_HEALTH_VERBOSE'] = '1';

        $payload = Health::payload();

        $this->assertArrayHasKey('version', $payload);
        $this->assertArrayHasKey('php', $payload);
        $this->assertArrayHasKey('mode', $payload);
    }

    public function testDeepTierRequiresMatchingToken(): void
    {
        $_ENV['RAZY_HEALTH_TOKEN'] = 'probe-kangaroo';

        $this->assertArrayNotHasKey('checks', Health::payload([]), 'no token -> no deep checks');
        $this->assertArrayNotHasKey('checks', Health::payload(['token' => 'wrong']), 'wrong token -> no deep checks');

        $granted = Health::payload(['token' => 'probe-kangaroo']);
        $this->assertArrayHasKey('checks', $granted);
        $this->assertArrayHasKey('memory_mb', $granted['checks']);
    }

    public function testRespondIfRequestedEmitsJsonForHealthPath(): void
    {
        // A trailing slash and a query string must both still hit the endpoint.
        foreach (['/_razy/health', '/_razy/health/', '/_razy/health?x=1'] as $path) {
            Health::resetForTesting();
            \ob_start();
            $handled = Health::respondIfRequested($path);
            $body = (string) \ob_get_clean();

            $this->assertTrue($handled, "must handle {$path}");
            $decoded = \json_decode($body, true);
            $this->assertIsArray($decoded, "must emit JSON for {$path}");
            $this->assertSame('ok', $decoded['status']);
        }
    }

    public function testUptimeIsNonNegative(): void
    {
        Health::resetForTesting();
        $payload = Health::payload();

        $this->assertGreaterThanOrEqual(0, $payload['uptime_seconds']);
    }
}
