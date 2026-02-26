<?php

/**
 * Unit tests for Razy\Container — the lightweight DI container.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\Contract\Container\NotFoundExceptionInterface;
use Razy\Contract\Container\PsrContainerInterface;
use Razy\Contract\ContainerInterface;
use Razy\Exception\ContainerException;
use Razy\Exception\ContainerNotFoundException;

// ──────────────────────────────────────────────────────────
// Test Fixtures
// ──────────────────────────────────────────────────────────

/** Simple class with no dependencies */
class StubNoDeps
{
    public string $tag = 'no-deps';
}

/** Class with a scalar default value */
class StubWithDefault
{
    public function __construct(public string $name = 'default-name')
    {
    }
}

/** Class with a nullable dependency */
class StubWithNullable
{
    public function __construct(public ?StubNoDeps $dep = null)
    {
    }
}

/** Class with a typed dependency (auto-wirable) */
class StubWithDep
{
    public function __construct(public StubNoDeps $dep)
    {
    }
}

/** Class with multiple dependencies */
class StubMultiDep
{
    public function __construct(
        public StubNoDeps $a,
        public StubWithDefault $b,
        public string $label = 'multi',
    ) {
    }
}

/** Class with Container dependency (self-referencing) */
class StubNeedsContainer
{
    public function __construct(public Container $container)
    {
    }
}

/** Interface for testing interface bindings */
interface StubServiceInterface
{
    public function value(): string;
}

/** Concrete implementation of StubServiceInterface */
class StubServiceImpl implements StubServiceInterface
{
    public function __construct(private string $val = 'impl')
    {
    }

    public function value(): string
    {
        return $this->val;
    }
}

/** Another implementation of StubServiceInterface */
class StubServiceAltImpl implements StubServiceInterface
{
    public function value(): string
    {
        return 'alt';
    }
}

/** Class that depends on an interface */
class StubUsesInterface
{
    public function __construct(public StubServiceInterface $service)
    {
    }
}

/** Circular dependency A → B */
class StubCircularA
{
    public function __construct(public StubCircularB $b)
    {
    }
}

/** Circular dependency B → A */
class StubCircularB
{
    public function __construct(public StubCircularA $a)
    {
    }
}

/** Abstract class (not instantiable) */
abstract class StubAbstract
{
    abstract public function run(): void;
}

/** Class with an unresolvable primitive parameter */
class StubUnresolvable
{
    public function __construct(public int $count)
    {
    }
}

// ──────────────────────────────────────────────────────────
// Test Cases
// ──────────────────────────────────────────────────────────

#[CoversClass(Container::class)]
class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ── Interface compliance ───────────────────────────────

    public function testImplementsContainerInterface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    // ── bind() — transient bindings ────────────────────────

    public function testBindTransientClassProducesNewInstanceEachTime(): void
    {
        $this->container->bind(StubServiceInterface::class, StubServiceImpl::class);

        $a = $this->container->make(StubServiceInterface::class);
        $b = $this->container->make(StubServiceInterface::class);

        $this->assertInstanceOf(StubServiceImpl::class, $a);
        $this->assertInstanceOf(StubServiceImpl::class, $b);
        $this->assertNotSame($a, $b, 'Transient binding should produce new instances');
    }

    public function testBindWithClosureFactory(): void
    {
        $this->container->bind('greeting', function (Container $c, array $params = []) {
            return new StubWithDefault($params['name'] ?? 'from-closure');
        });

        $obj = $this->container->make('greeting');
        $this->assertInstanceOf(StubWithDefault::class, $obj);
        $this->assertSame('from-closure', $obj->name);
    }

    public function testBindWithClosureAndParams(): void
    {
        $this->container->bind('greeting', function (Container $c, array $params = []) {
            return new StubWithDefault($params['name'] ?? 'fallback');
        });

        $obj = $this->container->make('greeting', ['name' => 'Ray']);
        $this->assertSame('Ray', $obj->name);
    }

    public function testBindOverridesPreviousBinding(): void
    {
        $this->container->bind(StubServiceInterface::class, StubServiceImpl::class);
        $this->container->bind(StubServiceInterface::class, StubServiceAltImpl::class);

        $obj = $this->container->make(StubServiceInterface::class);
        $this->assertInstanceOf(StubServiceAltImpl::class, $obj);
    }

    public function testBindClearsCachedSingletonInstance(): void
    {
        $this->container->singleton(StubServiceInterface::class, StubServiceImpl::class);
        $first = $this->container->make(StubServiceInterface::class);

        // Re-bind as transient — the cached singleton should be cleared
        $this->container->bind(StubServiceInterface::class, StubServiceAltImpl::class);
        $second = $this->container->make(StubServiceInterface::class);

        $this->assertInstanceOf(StubServiceAltImpl::class, $second);
        $this->assertNotSame($first, $second);
    }

    // ── singleton() — shared bindings ──────────────────────

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton(StubServiceInterface::class, StubServiceImpl::class);

        $a = $this->container->make(StubServiceInterface::class);
        $b = $this->container->make(StubServiceInterface::class);

        $this->assertSame($a, $b, 'Singleton should return the same instance');
    }

    public function testSingletonSelfBind(): void
    {
        $this->container->singleton(StubNoDeps::class);

        $a = $this->container->make(StubNoDeps::class);
        $b = $this->container->make(StubNoDeps::class);

        $this->assertInstanceOf(StubNoDeps::class, $a);
        $this->assertSame($a, $b);
    }

    public function testSingletonWithClosure(): void
    {
        $callCount = 0;
        $this->container->singleton('counter', function () use (&$callCount) {
            ++$callCount;
            return new StubNoDeps();
        });

        $this->container->make('counter');
        $this->container->make('counter');
        $this->container->make('counter');

        $this->assertSame(1, $callCount, 'Singleton closure should only be called once');
    }

    // ── instance() — pre-built instances ───────────────────

    public function testInstanceStoresAndReturnsPreBuiltObject(): void
    {
        $obj = new StubWithDefault('pre-built');
        $this->container->instance('config', $obj);

        $resolved = $this->container->make('config');
        $this->assertSame($obj, $resolved);
    }

    public function testInstanceOverridesPreviousInstance(): void
    {
        $obj1 = new StubNoDeps();
        $obj2 = new StubNoDeps();

        $this->container->instance('service', $obj1);
        $this->container->instance('service', $obj2);

        $this->assertSame($obj2, $this->container->make('service'));
    }

    // ── has() ──────────────────────────────────────────────

    public function testHasReturnsTrueForBindings(): void
    {
        $this->container->bind('foo', StubNoDeps::class);
        $this->assertTrue($this->container->has('foo'));
    }

    public function testHasReturnsTrueForInstances(): void
    {
        $this->container->instance('bar', new StubNoDeps());
        $this->assertTrue($this->container->has('bar'));
    }

    public function testHasReturnsTrueForSingletons(): void
    {
        $this->container->singleton('baz', StubNoDeps::class);
        $this->assertTrue($this->container->has('baz'));
    }

    public function testHasReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->container->has('nonexistent'));
    }

    public function testHasResolvesAliases(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $this->container->alias('nodeps', StubNoDeps::class);
        $this->assertTrue($this->container->has('nodeps'));
    }

    // ── get() ──────────────────────────────────────────────

    public function testGetReturnsRegisteredBinding(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $obj = $this->container->get(StubNoDeps::class);
        $this->assertInstanceOf(StubNoDeps::class, $obj);
    }

    public function testGetThrowsForUnknownId(): void
    {
        $this->expectException(ContainerNotFoundException::class);
        $this->expectExceptionMessage("No binding found for 'nonexistent'.");
        $this->container->get('nonexistent');
    }

    public function testGetThrowsNotFoundExceptionInterface(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('nonexistent');
    }

    public function testContainerImplementsPsrInterface(): void
    {
        $this->assertInstanceOf(PsrContainerInterface::class, $this->container);
    }

    // ── alias() ────────────────────────────────────────────

    public function testAliasResolvesToCanonicalBinding(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $this->container->alias('simple', StubNoDeps::class);

        $a = $this->container->make(StubNoDeps::class);
        $b = $this->container->make('simple');

        $this->assertSame($a, $b);
    }

    public function testChainedAliasResolution(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $this->container->alias('level1', StubNoDeps::class);
        $this->container->alias('level2', 'level1');
        $this->container->alias('level3', 'level2');

        $obj = $this->container->make('level3');
        $this->assertInstanceOf(StubNoDeps::class, $obj);
        $this->assertSame($this->container->make(StubNoDeps::class), $obj);
    }

    // ── Auto-wiring ────────────────────────────────────────

    public function testAutoWireClassWithNoDependencies(): void
    {
        $obj = $this->container->make(StubNoDeps::class);
        $this->assertInstanceOf(StubNoDeps::class, $obj);
        $this->assertSame('no-deps', $obj->tag);
    }

    public function testAutoWireClassWithDefaultParameter(): void
    {
        $obj = $this->container->make(StubWithDefault::class);
        $this->assertSame('default-name', $obj->name);
    }

    public function testAutoWireClassWithNullableDependency(): void
    {
        // StubNoDeps is not registered — should resolve to null via nullable type
        $obj = $this->container->make(StubWithNullable::class);
        $this->assertInstanceOf(StubWithNullable::class, $obj);
        // Since StubNoDeps can be auto-resolved (it's a concrete class), it should be injected
        $this->assertInstanceOf(StubNoDeps::class, $obj->dep);
    }

    public function testAutoWireClassWithTypedDependency(): void
    {
        $obj = $this->container->make(StubWithDep::class);
        $this->assertInstanceOf(StubWithDep::class, $obj);
        $this->assertInstanceOf(StubNoDeps::class, $obj->dep);
    }

    public function testAutoWireClassWithMultipleDependencies(): void
    {
        $obj = $this->container->make(StubMultiDep::class);
        $this->assertInstanceOf(StubMultiDep::class, $obj);
        $this->assertInstanceOf(StubNoDeps::class, $obj->a);
        $this->assertInstanceOf(StubWithDefault::class, $obj->b);
        $this->assertSame('multi', $obj->label);
    }

    public function testAutoWireWithContainerSelfReference(): void
    {
        $this->container->instance(Container::class, $this->container);
        $obj = $this->container->make(StubNeedsContainer::class);
        $this->assertSame($this->container, $obj->container);
    }

    public function testAutoWireWithExplicitParamOverride(): void
    {
        $obj = $this->container->make(StubWithDefault::class, ['name' => 'override']);
        $this->assertSame('override', $obj->name);
    }

    public function testAutoWireWithInterfaceBinding(): void
    {
        $this->container->bind(StubServiceInterface::class, StubServiceImpl::class);
        $obj = $this->container->make(StubUsesInterface::class);

        $this->assertInstanceOf(StubUsesInterface::class, $obj);
        $this->assertInstanceOf(StubServiceImpl::class, $obj->service);
    }

    // ── Error cases ────────────────────────────────────────

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');
        $this->container->make(StubCircularA::class);
    }

    public function testNonExistentClassThrows(): void
    {
        $this->expectException(ContainerNotFoundException::class);
        $this->expectExceptionMessage('does not exist');
        $this->container->make('Razy\Tests\NonExistentClass');
    }

    public function testAbstractClassThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');
        $this->container->make(StubAbstract::class);
    }

    public function testUnresolvableParameterThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve parameter '\$count'");
        $this->container->make(StubUnresolvable::class);
    }

    public function testInterfaceWithoutBindingThrows(): void
    {
        $this->expectException(ContainerException::class);
        // StubServiceInterface is an interface — cannot be auto-wired without a binding
        $this->container->make(StubUsesInterface::class);
    }

    // ── reset() / forget() / getBindings() ─────────────────

    public function testResetClearsEverything(): void
    {
        $this->container->singleton('a', StubNoDeps::class);
        $this->container->instance('b', new StubNoDeps());
        $this->container->alias('c', 'a');

        $this->container->reset();

        $this->assertFalse($this->container->has('a'));
        $this->assertFalse($this->container->has('b'));
        $this->assertFalse($this->container->has('c'));
        $this->assertEmpty($this->container->getBindings());
    }

    public function testForgetRemovesSpecificBinding(): void
    {
        $this->container->singleton('a', StubNoDeps::class);
        $this->container->singleton('b', StubWithDefault::class);
        $this->container->make('a'); // cache it

        $this->container->forget('a');

        $this->assertFalse($this->container->has('a'));
        $this->assertTrue($this->container->has('b'));
    }

    public function testForgetResolvesAlias(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $this->container->alias('simple', StubNoDeps::class);

        $this->container->forget('simple');
        $this->assertFalse($this->container->has(StubNoDeps::class));
    }

    public function testGetBindingsReturnsRegisteredKeys(): void
    {
        $this->container->bind('x', StubNoDeps::class);
        $this->container->singleton('y', StubWithDefault::class);

        $bindings = $this->container->getBindings();
        $this->assertContains('x', $bindings);
        $this->assertContains('y', $bindings);
        $this->assertCount(2, $bindings);
    }

    // ── Edge cases ─────────────────────────────────────────

    public function testMakeWithUnregisteredConcreteClass(): void
    {
        // Auto-wiring should work for unregistered concrete classes
        $obj = $this->container->make(StubNoDeps::class);
        $this->assertInstanceOf(StubNoDeps::class, $obj);
    }

    public function testSingletonResolvedThroughAlias(): void
    {
        $this->container->singleton(StubNoDeps::class);
        $this->container->alias('alias', StubNoDeps::class);

        $fromDirect = $this->container->make(StubNoDeps::class);
        $fromAlias = $this->container->make('alias');

        $this->assertSame($fromDirect, $fromAlias);
    }

    public function testClosureFactoryReceivesContainerInstance(): void
    {
        $receivedContainer = null;
        $this->container->bind('test', function (Container $c) use (&$receivedContainer) {
            $receivedContainer = $c;
            return new StubNoDeps();
        });

        $this->container->make('test');
        $this->assertSame($this->container, $receivedContainer);
    }

    public function testMultipleSingletonsDontInterfere(): void
    {
        $this->container->singleton('a', function () {
            return new StubWithDefault('A');
        });
        $this->container->singleton('b', function () {
            return new StubWithDefault('B');
        });

        $a = $this->container->make('a');
        $b = $this->container->make('b');

        $this->assertSame('A', $a->name);
        $this->assertSame('B', $b->name);
        $this->assertNotSame($a, $b);
    }

    public function testTransientBindingsWithSharedDependency(): void
    {
        // Shared dependency
        $this->container->singleton(StubNoDeps::class);

        // Two transient classes that depend on the same singleton
        $obj1 = $this->container->make(StubWithDep::class);
        $obj2 = $this->container->make(StubWithDep::class);

        // Different StubWithDep instances but same StubNoDeps dependency
        $this->assertNotSame($obj1, $obj2);
        $this->assertSame($obj1->dep, $obj2->dep);
    }

    // ── Worker mode simulation ─────────────────────────────

    public function testWorkerModeResetBetweenRequests(): void
    {
        // Simulate request 1
        $this->container->singleton('db', function () {
            return new StubWithDefault('request-1-db');
        });
        $db1 = $this->container->make('db');
        $this->assertSame('request-1-db', $db1->name);

        // Reset between requests (worker mode)
        $this->container->reset();

        // Simulate request 2
        $this->container->singleton('db', function () {
            return new StubWithDefault('request-2-db');
        });
        $db2 = $this->container->make('db');
        $this->assertSame('request-2-db', $db2->name);

        $this->assertNotSame($db1, $db2);
    }
}
