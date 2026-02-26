<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Session\SessionConfig;

#[CoversClass(SessionConfig::class)]
class SessionConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $cfg = new SessionConfig();

        $this->assertSame('RAZY_SESSION', $cfg->name);
        $this->assertSame(0, $cfg->lifetime);
        $this->assertSame('/', $cfg->path);
        $this->assertSame('', $cfg->domain);
        $this->assertFalse($cfg->secure);
        $this->assertTrue($cfg->httpOnly);
        $this->assertSame('Lax', $cfg->sameSite);
        $this->assertSame(1440, $cfg->gcMaxLifetime);
        $this->assertSame(1, $cfg->gcProbability);
        $this->assertSame(100, $cfg->gcDivisor);
    }

    public function testCustomValues(): void
    {
        $cfg = new SessionConfig(
            name: 'MY_SESSION',
            lifetime: 3600,
            path: '/app',
            domain: 'example.com',
            secure: true,
            httpOnly: false,
            sameSite: 'Strict',
            gcMaxLifetime: 7200,
            gcProbability: 5,
            gcDivisor: 1000,
        );

        $this->assertSame('MY_SESSION', $cfg->name);
        $this->assertSame(3600, $cfg->lifetime);
        $this->assertSame('/app', $cfg->path);
        $this->assertSame('example.com', $cfg->domain);
        $this->assertTrue($cfg->secure);
        $this->assertFalse($cfg->httpOnly);
        $this->assertSame('Strict', $cfg->sameSite);
        $this->assertSame(7200, $cfg->gcMaxLifetime);
        $this->assertSame(5, $cfg->gcProbability);
        $this->assertSame(1000, $cfg->gcDivisor);
    }

    public function testWithOverridesSingleProperty(): void
    {
        $original = new SessionConfig();
        $modified = $original->with(['name' => 'CUSTOM']);

        $this->assertSame('CUSTOM', $modified->name);
        // All other values should remain defaults
        $this->assertSame(0, $modified->lifetime);
        $this->assertSame('/', $modified->path);
        $this->assertTrue($modified->httpOnly);
    }

    public function testWithOverridesMultipleProperties(): void
    {
        $original = new SessionConfig();
        $modified = $original->with([
            'lifetime' => 7200,
            'secure' => true,
            'sameSite' => 'None',
        ]);

        $this->assertSame(7200, $modified->lifetime);
        $this->assertTrue($modified->secure);
        $this->assertSame('None', $modified->sameSite);
        // Unchanged
        $this->assertSame('RAZY_SESSION', $modified->name);
    }

    public function testWithReturnsNewInstance(): void
    {
        $original = new SessionConfig();
        $modified = $original->with(['name' => 'NEW']);

        $this->assertNotSame($original, $modified);
        $this->assertSame('RAZY_SESSION', $original->name);
        $this->assertSame('NEW', $modified->name);
    }

    public function testWithEmptyArrayReturnsCopy(): void
    {
        $original = new SessionConfig(name: 'A', lifetime: 100);
        $copy = $original->with([]);

        $this->assertNotSame($original, $copy);
        $this->assertSame('A', $copy->name);
        $this->assertSame(100, $copy->lifetime);
    }
}
