<?php

/**
 * Security tests for Razy\Container — blocklist, parent traversal, and
 * SecurityException propagation.
 *
 * These tests verify that the container's security hardening prevents
 * module code from resolving internal system classes or traversing the
 * container hierarchy to reach the Application-level container.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\Exception\SecurityException;

// ──────────────────────────────────────────────────────────
// Test Fixtures for Security Tests
// ──────────────────────────────────────────────────────────

/** Dummy "system" class that should be blocked. */
class FakeSystemClass
{
    public string $role = 'system';
}

/** Another dummy system class. */
class FakeInternalService
{
    public string $role = 'internal';
}

/** A safe module-level class that should be resolvable. */
class SafeModuleService
{
    public string $role = 'module';
}

/** Class with a constructor dependency on a blocked class. */
class ServiceWithBlockedDep
{
    public function __construct(public readonly FakeSystemClass $system)
    {
    }
}

/** Class with a nullable blocked dependency. */
class ServiceWithNullableBlockedDep
{
    public function __construct(public readonly ?FakeSystemClass $system = null)
    {
    }
}

/** Class with a defaulted blocked dependency. */
class ServiceWithDefaultBlockedDep
{
    public function __construct(public readonly ?FakeSystemClass $system = null)
    {
    }
}

/** Class with only safe dependencies. */
class SafeServiceWithDeps
{
    public function __construct(public readonly SafeModuleService $service)
    {
    }
}

// ──────────────────────────────────────────────────────────
// Test Class
// ──────────────────────────────────────────────────────────

#[CoversClass(Container::class)]
class ContainerSecurityTest extends TestCase
{
    // ── blockAbstracts() ──────────────────────────────────

    public function testMakeThrowsSecurityExceptionForBlockedAbstract(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage(FakeSystemClass::class);
        $child->make(FakeSystemClass::class);
    }

    public function testHasReturnsFalseForBlockedAbstract(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        $this->assertFalse($child->has(FakeSystemClass::class));
    }

    public function testGetThrowsForBlockedAbstract(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        // get() delegates to has() which returns false → ContainerNotFoundException
        $this->expectException(\Razy\Exception\ContainerNotFoundException::class);
        $child->get(FakeSystemClass::class);
    }

    public function testGetBindingsExcludesBlockedAbstracts(): void
    {
        $child = new Container();
        $child->bind(FakeSystemClass::class, FakeSystemClass::class);
        $child->bind(SafeModuleService::class, SafeModuleService::class);
        $child->blockAbstracts([FakeSystemClass::class]);

        $bindings = $child->getBindings();
        $this->assertContains(SafeModuleService::class, $bindings);
        $this->assertNotContains(FakeSystemClass::class, $bindings);
    }

    public function testBlockedAbstractDoesNotAffectParentContainer(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        // Parent should still be able to resolve the class
        $instance = $parent->make(FakeSystemClass::class);
        $this->assertInstanceOf(FakeSystemClass::class, $instance);
    }

    public function testMultipleBlockedAbstracts(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());
        $parent->instance(FakeInternalService::class, new FakeInternalService());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class, FakeInternalService::class]);

        $this->assertFalse($child->has(FakeSystemClass::class));
        $this->assertFalse($child->has(FakeInternalService::class));
    }

    public function testUnblockedClassCanStillBeResolved(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());
        $parent->bind(SafeModuleService::class, SafeModuleService::class);

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        // SafeModuleService should resolve fine via parent delegation
        $instance = $child->make(SafeModuleService::class);
        $this->assertInstanceOf(SafeModuleService::class, $instance);
    }

    public function testBlockedAbstractPreventsLocalBindingResolution(): void
    {
        $child = new Container();
        $child->bind(FakeSystemClass::class, FakeSystemClass::class);
        $child->blockAbstracts([FakeSystemClass::class]);

        // Even if the binding is local, it should still be blocked
        $this->expectException(SecurityException::class);
        $child->make(FakeSystemClass::class);
    }

    // ── blockParentTraversal() ────────────────────────────

    public function testGetParentThrowsWhenBlocked(): void
    {
        $parent = new Container();
        $child = new Container($parent);
        $child->blockParentTraversal();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('parent traversal');
        $child->getParent();
    }

    public function testGetParentReturnsParentWhenNotBlocked(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $this->assertSame($parent, $child->getParent());
    }

    public function testGetParentReturnsNullWhenNoParent(): void
    {
        $container = new Container();
        $this->assertNull($container->getParent());
    }

    // ── SecurityException propagation in auto-wiring ──────

    public function testAutoWiringPropagatesSecurityExceptionForBlockedDep(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        // ServiceWithBlockedDep has a required FakeSystemClass parameter.
        // The SecurityException from make(FakeSystemClass::class) should
        // propagate, NOT be silently caught.
        $this->expectException(SecurityException::class);
        $child->make(ServiceWithBlockedDep::class);
    }

    public function testCallPropagatesSecurityExceptionForBlockedTypeHint(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->blockAbstracts([FakeSystemClass::class]);

        $fn = function (FakeSystemClass $system) {
            return $system;
        };

        $this->expectException(SecurityException::class);
        $child->call($fn);
    }

    public function testAutoWiringResolvesUnblockedDependencies(): void
    {
        $child = new Container();
        $child->bind(SafeModuleService::class, SafeModuleService::class);

        // SafeServiceWithDeps depends on SafeModuleService which is not blocked
        $instance = $child->make(SafeServiceWithDeps::class);
        $this->assertInstanceOf(SafeServiceWithDeps::class, $instance);
        $this->assertInstanceOf(SafeModuleService::class, $instance->service);
    }

    // ── Combined defense ──────────────────────────────────

    public function testFullModuleContainerIsolation(): void
    {
        // Simulate the Application → Module container hierarchy
        $appContainer = new Container();
        $appContainer->instance(FakeSystemClass::class, new FakeSystemClass());
        $appContainer->instance(FakeInternalService::class, new FakeInternalService());
        $appContainer->bind(SafeModuleService::class, SafeModuleService::class);

        // Create module child container with security hardening
        $moduleContainer = new Container($appContainer);
        $moduleContainer->blockAbstracts([
            FakeSystemClass::class,
            FakeInternalService::class,
        ]);
        $moduleContainer->blockParentTraversal();

        // 1. Module can resolve safe services
        $safe = $moduleContainer->make(SafeModuleService::class);
        $this->assertInstanceOf(SafeModuleService::class, $safe);

        // 2. Module cannot resolve blocked system classes
        $this->assertFalse($moduleContainer->has(FakeSystemClass::class));
        $this->assertFalse($moduleContainer->has(FakeInternalService::class));

        // 3. Module cannot traverse to parent
        try {
            $moduleContainer->getParent();
            $this->fail('Expected SecurityException for parent traversal');
        } catch (SecurityException) {
            $this->assertTrue(true);
        }

        // 4. Blocked classes don't appear in getBindings()
        $moduleContainer->bind('some.service', SafeModuleService::class);
        $moduleContainer->bind(FakeSystemClass::class, FakeSystemClass::class);
        $bindings = $moduleContainer->getBindings();
        $this->assertContains('some.service', $bindings);
        $this->assertNotContains(FakeSystemClass::class, $bindings);

        // 5. Application container is unaffected
        $appInstance = $appContainer->make(FakeSystemClass::class);
        $this->assertInstanceOf(FakeSystemClass::class, $appInstance);
    }

    public function testBlockedAbstractCannotBypassViaAlias(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        $child->alias('system.alias', FakeSystemClass::class);
        $child->blockAbstracts([FakeSystemClass::class]);

        // Alias should resolve to the blocked class name and be caught
        $this->expectException(SecurityException::class);
        $child->make('system.alias');
    }

    public function testBlockedAbstractsAreAdditive(): void
    {
        $child = new Container();
        $child->blockAbstracts([FakeSystemClass::class]);
        $child->blockAbstracts([FakeInternalService::class]);

        $this->assertFalse($child->has(FakeSystemClass::class));
        $this->assertFalse($child->has(FakeInternalService::class));
    }

    public function testContainerWithNoBlocklistResolvesNormally(): void
    {
        $parent = new Container();
        $parent->instance(FakeSystemClass::class, new FakeSystemClass());

        $child = new Container($parent);
        // No blocklist set — resolution should work normally
        $instance = $child->make(FakeSystemClass::class);
        $this->assertInstanceOf(FakeSystemClass::class, $instance);
    }
}
