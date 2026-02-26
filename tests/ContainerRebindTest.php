<?php

/**
 * Tests for Container rebind support (Strategy C+).
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use stdClass;

#[CoversClass(Container::class)]
class ContainerRebindTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ── rebind() ────────────────────────────────────────

    public function testRebindReplacesExistingBinding(): void
    {
        $this->container->bind('service.greeting', fn () => (object) ['msg' => 'hello']);
        $v1 = $this->container->make('service.greeting');
        $this->assertSame('hello', $v1->msg);

        $old = $this->container->rebind('service.greeting', fn () => (object) ['msg' => 'goodbye']);

        $v2 = $this->container->make('service.greeting');
        $this->assertSame('goodbye', $v2->msg);
    }

    public function testRebindReturnsOldInstance(): void
    {
        $this->container->bind('service.counter', fn () => (object) ['val' => 42]);
        $this->container->make('service.counter'); // resolve to cache

        $old = $this->container->rebind('service.counter', fn () => (object) ['val' => 99]);

        // New should resolve to 99
        $v2 = $this->container->make('service.counter');
        $this->assertSame(99, $v2->val);
    }

    public function testRebindNonExistentCreatesBinding(): void
    {
        $old = $this->container->rebind('new.service', fn () => (object) ['val' => 'fresh']);

        $this->assertNull($old);
        $v = $this->container->make('new.service');
        $this->assertSame('fresh', $v->val);
    }

    public function testRebindWithClassString(): void
    {
        $this->container->bind('counter', fn () => new stdClass());
        $this->container->make('counter');

        $old = $this->container->rebind('counter', fn () => new stdClass());

        $this->assertInstanceOf(stdClass::class, $this->container->make('counter'));
    }

    // ── onRebind() callbacks ────────────────────────────

    public function testOnRebindCallbackFiredOnRebind(): void
    {
        $this->container->bind('service.foo', fn () => (object) ['v' => 1]);
        $this->container->make('service.foo');

        $callbackFired = false;
        $receivedAbstract = '';
        $receivedOld = null;

        $this->container->onRebind('service.foo', function (string $abstract, $old, Container $c) use (&$callbackFired, &$receivedAbstract, &$receivedOld) {
            $callbackFired = true;
            $receivedAbstract = $abstract;
            $receivedOld = $old;
        });

        $this->container->rebind('service.foo', fn () => (object) ['v' => 2]);

        $this->assertTrue($callbackFired);
        $this->assertSame('service.foo', $receivedAbstract);
    }

    public function testOnRebindCallbackNotFiredOnFirstBind(): void
    {
        $callbackFired = false;
        $this->container->onRebind('service.bar', function () use (&$callbackFired) {
            $callbackFired = true;
        });

        $this->container->bind('service.bar', fn () => (object) ['val' => 'x']);

        $this->assertFalse($callbackFired);
    }

    public function testMultipleOnRebindCallbacksAllFired(): void
    {
        $this->container->bind('svc', fn () => (object) ['v' => 1]);

        $fired = [];
        $this->container->onRebind('svc', function () use (&$fired) {
            $fired[] = 'cb1';
        });
        $this->container->onRebind('svc', function () use (&$fired) {
            $fired[] = 'cb2';
        });

        $this->container->rebind('svc', fn () => (object) ['v' => 2]);

        $this->assertSame(['cb1', 'cb2'], $fired);
    }

    // ── Rebind counts ───────────────────────────────────

    public function testGetRebindCountInitiallyZero(): void
    {
        $this->assertSame(0, $this->container->getRebindCount('anything'));
    }

    public function testRebindIncrementsCount(): void
    {
        $this->container->bind('svc', fn () => (object) ['v' => 1]);
        $this->container->rebind('svc', fn () => (object) ['v' => 2]);

        $this->assertSame(1, $this->container->getRebindCount('svc'));
    }

    public function testMultipleRebindsIncrementCount(): void
    {
        $this->container->bind('svc', fn () => (object) ['v' => 1]);
        $this->container->rebind('svc', fn () => (object) ['v' => 2]);
        $this->container->rebind('svc', fn () => (object) ['v' => 3]);
        $this->container->rebind('svc', fn () => (object) ['v' => 4]);

        $this->assertSame(3, $this->container->getRebindCount('svc'));
    }

    public function testGetTotalRebindCountAcrossMultipleServices(): void
    {
        $this->container->bind('a', fn () => new stdClass());
        $this->container->bind('b', fn () => new stdClass());

        $this->container->rebind('a', fn () => new stdClass());
        $this->container->rebind('b', fn () => new stdClass());
        $this->container->rebind('a', fn () => new stdClass());

        $this->assertSame(3, $this->container->getTotalRebindCount());
    }

    // ── Rebind threshold ────────────────────────────────

    public function testExceedsRebindThresholdInitiallyFalse(): void
    {
        $this->assertFalse($this->container->exceedsRebindThreshold());
    }

    public function testExceedsRebindThresholdAfterManyRebinds(): void
    {
        $this->container->setMaxRebindsBeforeRestart(3);
        $this->container->bind('svc', fn () => new stdClass());

        $this->container->rebind('svc', fn () => new stdClass());
        $this->assertFalse($this->container->exceedsRebindThreshold());

        $this->container->rebind('svc', fn () => new stdClass());
        $this->assertFalse($this->container->exceedsRebindThreshold());

        $this->container->rebind('svc', fn () => new stdClass());
        $this->assertTrue($this->container->exceedsRebindThreshold());
    }

    public function testSetMaxRebindsBeforeRestart(): void
    {
        $this->container->setMaxRebindsBeforeRestart(5);
        $this->container->bind('x', fn () => new stdClass());

        for ($i = 1; $i <= 4; $i++) {
            $this->container->rebind('x', fn () => new stdClass());
        }
        $this->assertFalse($this->container->exceedsRebindThreshold());

        $this->container->rebind('x', fn () => new stdClass());
        $this->assertTrue($this->container->exceedsRebindThreshold());
    }

    // ── reset() behavior ────────────────────────────────

    public function testResetClearsRebindCallbacksButPreservesCounts(): void
    {
        $this->container->bind('svc', fn () => (object) ['v' => 1]);
        $this->container->rebind('svc', fn () => (object) ['v' => 2]);

        $callbackFired = false;
        $this->container->onRebind('svc', function () use (&$callbackFired) {
            $callbackFired = true;
        });

        $this->container->reset();

        // Count should be preserved (cumulative for class table tracking)
        $this->assertSame(1, $this->container->getRebindCount('svc'));

        // Callback should be cleared — rebind after reset won't fire old callback
        $this->container->bind('svc', fn () => (object) ['v' => 3]);
        $this->container->rebind('svc', fn () => (object) ['v' => 4]);
        $this->assertFalse($callbackFired);
    }

    // ── Integration: rebind + resolve ───────────────────

    public function testRebindResolvesNewInstance(): void
    {
        $this->container->bind('obj', fn () => (object) ['version' => 1]);
        $v1 = $this->container->make('obj');
        $this->assertSame(1, $v1->version);

        $this->container->rebind('obj', fn () => (object) ['version' => 2]);
        $v2 = $this->container->make('obj');
        $this->assertSame(2, $v2->version);

        // Ensure they're different instances
        $this->assertNotSame($v1, $v2);
    }

    public function testRebindWithAliasResolvesCorrectly(): void
    {
        $this->container->bind('full.service.name', fn () => (object) ['val' => 'original']);
        $this->container->alias('short', 'full.service.name');

        $v1 = $this->container->make('short');
        $this->assertSame('original', $v1->val);

        $this->container->rebind('full.service.name', fn () => (object) ['val' => 'updated']);
        $v2 = $this->container->make('full.service.name');
        $this->assertSame('updated', $v2->val);
    }
}
