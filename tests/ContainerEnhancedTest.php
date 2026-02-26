<?php
/**
 * Tests for Container enhancements:
 * - Tagged Bindings
 * - Scoped Singleton
 * - Resolving / AfterResolving hooks
 * - Method Injection (call())
 * - Parent Container delegation (hierarchy)
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\Contract\ContainerInterface;
use Razy\Exception\ContainerException;
use Razy\Exception\ContainerNotFoundException;

// ──────────────────────────────────────────────────────────
// Test Fixtures for Enhanced Container Tests
// ──────────────────────────────────────────────────────────

/** Interface for tagged binding tests */
interface ReportInterface
{
    public function name(): string;
}

class PdfReport implements ReportInterface
{
    public function name(): string { return 'pdf'; }
}

class CsvReport implements ReportInterface
{
    public function name(): string { return 'csv'; }
}

class HtmlReport implements ReportInterface
{
    public function name(): string { return 'html'; }
}

/** Simple service for scoped singleton tests */
class ScopedService
{
    private static int $counter = 0;

    public int $id;

    public function __construct()
    {
        $this->id = ++self::$counter;
    }

    public static function resetCounter(): void
    {
        self::$counter = 0;
    }
}

/** Service for resolving hook tests */
class HookableService
{
    public string $injectedValue = '';

    public function __construct(public string $label = 'hookable') {}
}

/** Service for method injection tests */
class CallTestService
{
    public function __construct(public string $tag = 'call-service') {}
}

/** Another service for method injection */
class CallTestDep
{
    public function __construct(public string $name = 'dep-name') {}
}

/** Invokable class for call() tests */
class InvokableStub
{
    public function __invoke(CallTestService $svc, string $extra = 'default'): string
    {
        return $svc->tag . ':' . $extra;
    }
}

// ──────────────────────────────────────────────────────────

#[CoversClass(Container::class)]
class ContainerEnhancedTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        ScopedService::resetCounter();
    }

    // ══════════════════════════════════════════════════════
    //  1. Tagged Bindings
    // ══════════════════════════════════════════════════════

    public function testTagAssignsAbstractsToTag(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->bind(CsvReport::class, CsvReport::class);

        $this->container->tag([PdfReport::class, CsvReport::class], 'reports');

        $reports = $this->container->tagged('reports');

        $this->assertCount(2, $reports);
        $this->assertInstanceOf(PdfReport::class, $reports[0]);
        $this->assertInstanceOf(CsvReport::class, $reports[1]);
    }

    public function testTagMergesWithExistingTag(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->bind(CsvReport::class, CsvReport::class);
        $this->container->bind(HtmlReport::class, HtmlReport::class);

        $this->container->tag([PdfReport::class], 'reports');
        $this->container->tag([CsvReport::class, HtmlReport::class], 'reports');

        $reports = $this->container->tagged('reports');
        $this->assertCount(3, $reports);
    }

    public function testTagDeduplicatesAbstracts(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);

        $this->container->tag([PdfReport::class], 'reports');
        $this->container->tag([PdfReport::class], 'reports');

        $reports = $this->container->tagged('reports');
        $this->assertCount(1, $reports);
    }

    public function testTaggedReturnsEmptyForUnknownTag(): void
    {
        $this->assertSame([], $this->container->tagged('nonexistent'));
    }

    public function testTaggedResolvesEachService(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->bind(CsvReport::class, CsvReport::class);
        $this->container->tag([PdfReport::class, CsvReport::class], 'reports');

        $reports = $this->container->tagged('reports');
        $names = array_map(fn(ReportInterface $r) => $r->name(), $reports);

        $this->assertSame(['pdf', 'csv'], $names);
    }

    public function testMultipleTagsOnSameAbstract(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);

        $this->container->tag([PdfReport::class], 'reports');
        $this->container->tag([PdfReport::class], 'exporters');

        $reports = $this->container->tagged('reports');
        $exporters = $this->container->tagged('exporters');

        $this->assertCount(1, $reports);
        $this->assertCount(1, $exporters);
        $this->assertInstanceOf(PdfReport::class, $reports[0]);
        $this->assertInstanceOf(PdfReport::class, $exporters[0]);
    }

    public function testTaggedWithSingletonsSharesInstances(): void
    {
        $this->container->singleton(PdfReport::class);
        $this->container->tag([PdfReport::class], 'reports');

        $first = $this->container->tagged('reports');
        $second = $this->container->tagged('reports');

        $this->assertSame($first[0], $second[0]);
    }

    public function testResetClearsTagBindings(): void
    {
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->tag([PdfReport::class], 'reports');

        $this->container->reset();

        $this->assertSame([], $this->container->tagged('reports'));
    }

    // ══════════════════════════════════════════════════════
    //  2. Scoped Singleton
    // ══════════════════════════════════════════════════════

    public function testScopedBindingSharesInstanceWithinScope(): void
    {
        $this->container->scoped(ScopedService::class);

        $a = $this->container->make(ScopedService::class);
        $b = $this->container->make(ScopedService::class);

        $this->assertSame($a, $b, 'Scoped binding should return same instance within scope');
    }

    public function testForgetScopedInstancesClearsOnlyScopedBindings(): void
    {
        $this->container->scoped(ScopedService::class);
        $this->container->singleton(CallTestService::class);

        $scoped1 = $this->container->make(ScopedService::class);
        $singleton1 = $this->container->make(CallTestService::class);

        // Clear scoped instances only
        $this->container->forgetScopedInstances();

        $scoped2 = $this->container->make(ScopedService::class);
        $singleton2 = $this->container->make(CallTestService::class);

        // Scoped should produce new instance
        $this->assertNotSame($scoped1, $scoped2, 'Scoped instance should be cleared');
        // Singleton should remain
        $this->assertSame($singleton1, $singleton2, 'Regular singleton should persist');
    }

    public function testScopedWorkerModeSimulation(): void
    {
        $this->container->scoped('request-db', function () {
            return new ScopedService();
        });

        // Request 1
        $db1 = $this->container->make('request-db');
        $db1Again = $this->container->make('request-db');
        $this->assertSame($db1, $db1Again);

        // Between requests
        $this->container->forgetScopedInstances();

        // Request 2
        $db2 = $this->container->make('request-db');
        $this->assertNotSame($db1, $db2);
    }

    public function testScopedWithClosureFactory(): void
    {
        $callCount = 0;
        $this->container->scoped('counter', function () use (&$callCount) {
            ++$callCount;
            return new ScopedService();
        });

        $this->container->make('counter');
        $this->container->make('counter');
        $this->assertSame(1, $callCount, 'Scoped closure called once within scope');

        $this->container->forgetScopedInstances();

        $this->container->make('counter');
        $this->assertSame(2, $callCount, 'Scoped closure called again after scope reset');
    }

    public function testScopedSelfBind(): void
    {
        $this->container->scoped(ScopedService::class);

        $a = $this->container->make(ScopedService::class);
        $this->assertInstanceOf(ScopedService::class, $a);
    }

    public function testForgetRemovesScopedTracking(): void
    {
        $this->container->scoped(ScopedService::class);
        $this->container->make(ScopedService::class);

        $this->container->forget(ScopedService::class);

        $this->assertFalse($this->container->has(ScopedService::class));
    }

    public function testResetClearsScopedBindings(): void
    {
        $this->container->scoped(ScopedService::class);
        $this->container->make(ScopedService::class);

        $this->container->reset();

        $this->assertFalse($this->container->has(ScopedService::class));
    }

    // ══════════════════════════════════════════════════════
    //  3. Resolving / AfterResolving Hooks
    // ══════════════════════════════════════════════════════

    public function testResolvingHookFiresOnMake(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $fired = false;
        $this->container->resolving(HookableService::class, function (object $instance, Container $c) use (&$fired) {
            $fired = true;
            $this->assertInstanceOf(HookableService::class, $instance);
        });

        $this->container->make(HookableService::class);
        $this->assertTrue($fired);
    }

    public function testAfterResolvingHookFiresOnMake(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $fired = false;
        $this->container->afterResolving(HookableService::class, function (object $instance, Container $c) use (&$fired) {
            $fired = true;
        });

        $this->container->make(HookableService::class);
        $this->assertTrue($fired);
    }

    public function testGlobalResolvingHookFiresForAllResolutions(): void
    {
        $resolved = [];
        $this->container->resolving(function (object $instance, Container $c) use (&$resolved) {
            $resolved[] = $instance::class;
        });

        $this->container->make(CallTestService::class);
        $this->container->make(CallTestDep::class);

        $this->assertSame([CallTestService::class, CallTestDep::class], $resolved);
    }

    public function testGlobalAfterResolvingHookFiresForAll(): void
    {
        $resolved = [];
        $this->container->afterResolving(function (object $instance, Container $c) use (&$resolved) {
            $resolved[] = $instance::class;
        });

        $this->container->make(CallTestService::class);
        $this->container->make(CallTestDep::class);

        $this->assertSame([CallTestService::class, CallTestDep::class], $resolved);
    }

    public function testResolvingHookCanMutateInstance(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $this->container->resolving(HookableService::class, function (HookableService $svc) {
            $svc->injectedValue = 'injected-via-hook';
        });

        $svc = $this->container->make(HookableService::class);
        $this->assertSame('injected-via-hook', $svc->injectedValue);
    }

    public function testMultipleResolvingHooksFireInOrder(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $order = [];
        $this->container->resolving(HookableService::class, function () use (&$order) {
            $order[] = 'first';
        });
        $this->container->resolving(HookableService::class, function () use (&$order) {
            $order[] = 'second';
        });

        $this->container->make(HookableService::class);
        $this->assertSame(['first', 'second'], $order);
    }

    public function testResolvingHooksFireBeforeAfterResolving(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $order = [];
        $this->container->afterResolving(HookableService::class, function () use (&$order) {
            $order[] = 'after';
        });
        $this->container->resolving(HookableService::class, function () use (&$order) {
            $order[] = 'during';
        });

        $this->container->make(HookableService::class);
        $this->assertSame(['during', 'after'], $order);
    }

    public function testTypedAndGlobalHooksBothFire(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);

        $order = [];
        $this->container->resolving(HookableService::class, function () use (&$order) {
            $order[] = 'typed';
        });
        $this->container->resolving(function () use (&$order) {
            $order[] = 'global';
        });

        $this->container->make(HookableService::class);
        $this->assertSame(['typed', 'global'], $order);
    }

    public function testSingletonResolvingHookFiresOnlyOnFirstMake(): void
    {
        $this->container->singleton(HookableService::class);

        $fireCount = 0;
        $this->container->resolving(HookableService::class, function () use (&$fireCount) {
            ++$fireCount;
        });

        $this->container->make(HookableService::class);
        $this->container->make(HookableService::class);
        $this->container->make(HookableService::class);

        $this->assertSame(1, $fireCount, 'Hook should fire only on first resolution of singleton');
    }

    public function testResetClearsResolvingHooks(): void
    {
        $fired = false;
        $this->container->resolving(function () use (&$fired) {
            $fired = true;
        });

        $this->container->reset();
        $this->container->make(CallTestService::class);

        $this->assertFalse($fired, 'Hooks should be cleared by reset()');
    }

    // ══════════════════════════════════════════════════════
    //  4. Method Injection (call())
    // ══════════════════════════════════════════════════════

    public function testCallWithClosure(): void
    {
        $result = $this->container->call(function (CallTestService $svc) {
            return $svc->tag;
        });

        $this->assertSame('call-service', $result);
    }

    public function testCallWithClosureAndExplicitParams(): void
    {
        $result = $this->container->call(
            function (CallTestService $svc, string $extra = 'default') {
                return $svc->tag . ':' . $extra;
            },
            ['extra' => 'custom']
        );

        $this->assertSame('call-service:custom', $result);
    }

    public function testCallWithMultipleDependencies(): void
    {
        $result = $this->container->call(function (CallTestService $svc, CallTestDep $dep) {
            return $svc->tag . '+' . $dep->name;
        });

        $this->assertSame('call-service+dep-name', $result);
    }

    public function testCallWithInstanceMethod(): void
    {
        $obj = new class {
            public function greet(CallTestService $svc): string
            {
                return 'hello:' . $svc->tag;
            }
        };

        $result = $this->container->call([$obj, 'greet']);
        $this->assertSame('hello:call-service', $result);
    }

    public function testCallWithStaticMethod(): void
    {
        $result = $this->container->call([CallTestHelper::class, 'staticMethod']);
        $this->assertSame('static:call-service', $result);
    }

    public function testCallWithInvokableObject(): void
    {
        $invokable = new InvokableStub();

        $result = $this->container->call($invokable);
        $this->assertSame('call-service:default', $result);
    }

    public function testCallWithInvokableAndParams(): void
    {
        $invokable = new InvokableStub();

        $result = $this->container->call($invokable, ['extra' => 'override']);
        $this->assertSame('call-service:override', $result);
    }

    public function testCallWithDefaultParameter(): void
    {
        $result = $this->container->call(function (string $name = 'world') {
            return 'hello ' . $name;
        });

        $this->assertSame('hello world', $result);
    }

    public function testCallWithNullableParameter(): void
    {
        $result = $this->container->call(function (?CallTestDep $dep = null) {
            return $dep?->name ?? 'null';
        });

        // CallTestDep is a concrete class, should be auto-resolved
        $this->assertSame('dep-name', $result);
    }

    public function testCallThrowsForUnresolvableParameter(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve parameter '\$count'");

        $this->container->call(function (int $count) {
            return $count;
        });
    }

    public function testCallWithRegisteredSingleton(): void
    {
        $this->container->singleton(CallTestService::class, function () {
            return new CallTestService('singleton-tag');
        });

        $result = $this->container->call(function (CallTestService $svc) {
            return $svc->tag;
        });

        $this->assertSame('singleton-tag', $result);
    }

    public function testCallWithNoParameters(): void
    {
        $result = $this->container->call(function () {
            return 42;
        });

        $this->assertSame(42, $result);
    }

    public function testCallWithStringFunction(): void
    {
        // Test with a built-in function that takes no type-hinted params
        $result = $this->container->call('strlen', ['string' => 'hello']);
        $this->assertSame(5, $result);
    }

    // ══════════════════════════════════════════════════════
    //  5. Parent Container Delegation (Hierarchy)
    // ══════════════════════════════════════════════════════

    public function testChildContainerDelegatesToParent(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class, fn() => new CallTestService('from-parent'));

        $child = new Container($parent);

        $svc = $child->make(CallTestService::class);
        $this->assertSame('from-parent', $svc->tag);
    }

    public function testChildContainerOverridesParentBinding(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class, fn() => new CallTestService('from-parent'));

        $child = new Container($parent);
        $child->singleton(CallTestService::class, fn() => new CallTestService('from-child'));

        $svc = $child->make(CallTestService::class);
        $this->assertSame('from-child', $svc->tag);
    }

    public function testChildHasChecksBothContainers(): void
    {
        $parent = new Container();
        $parent->bind('parent-only', CallTestService::class);

        $child = new Container($parent);
        $child->bind('child-only', CallTestDep::class);

        $this->assertTrue($child->has('parent-only'));
        $this->assertTrue($child->has('child-only'));
        $this->assertFalse($child->has('nonexistent'));
    }

    public function testParentSingletonIsSharedAcrossChildren(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class, fn() => new CallTestService('shared'));

        $child1 = new Container($parent);
        $child2 = new Container($parent);

        $svc1 = $child1->make(CallTestService::class);
        $svc2 = $child2->make(CallTestService::class);

        $this->assertSame($svc1, $svc2, 'Parent singleton should be shared across children');
    }

    public function testChildContainerDoesNotAffectParent(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $child->singleton(CallTestService::class, fn() => new CallTestService('child-only'));

        $this->assertTrue($child->has(CallTestService::class));
        $this->assertFalse($parent->has(CallTestService::class));
    }

    public function testGetParentReturnsParentContainer(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $this->assertSame($parent, $child->getParent());
    }

    public function testGetParentReturnsNullForRootContainer(): void
    {
        $container = new Container();
        $this->assertNull($container->getParent());
    }

    public function testChildContainerAutoWiresFromParentBindings(): void
    {
        $parent = new Container();
        $parent->bind(ReportInterface::class, PdfReport::class);

        $child = new Container($parent);

        // Child should resolve interface binding from parent
        $report = $child->make(ReportInterface::class);
        $this->assertInstanceOf(PdfReport::class, $report);
    }

    public function testThreeLevelContainerHierarchy(): void
    {
        $root = new Container();
        $root->singleton(CallTestService::class, fn() => new CallTestService('root'));

        $mid = new Container($root);
        $mid->singleton(CallTestDep::class, fn() => new CallTestDep('mid'));

        $leaf = new Container($mid);

        // Leaf should resolve from mid and root
        $svc = $leaf->make(CallTestService::class);
        $dep = $leaf->make(CallTestDep::class);

        $this->assertSame('root', $svc->tag);
        $this->assertSame('mid', $dep->name);
    }

    public function testChildResetDoesNotAffectParent(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class);

        $child = new Container($parent);
        $child->singleton(CallTestDep::class);

        $child->reset();

        $this->assertFalse($child->has(CallTestDep::class));
        // Parent's binding is still accessible
        $this->assertTrue($child->has(CallTestService::class));
    }

    public function testChildGetDelegatesToParent(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class, fn() => new CallTestService('via-get'));

        $child = new Container($parent);

        $svc = $child->get(CallTestService::class);
        $this->assertSame('via-get', $svc->tag);
    }

    public function testChildGetThrowsForUnknownInBoth(): void
    {
        $parent = new Container();
        $child = new Container($parent);

        $this->expectException(ContainerNotFoundException::class);
        $child->get('nonexistent');
    }

    // ══════════════════════════════════════════════════════
    //  Combined Feature Tests
    // ══════════════════════════════════════════════════════

    public function testTaggedWithScopedBindings(): void
    {
        $this->container->scoped(PdfReport::class);
        $this->container->scoped(CsvReport::class);
        $this->container->tag([PdfReport::class, CsvReport::class], 'reports');

        $reports1 = $this->container->tagged('reports');
        $this->assertCount(2, $reports1);

        // Same within scope
        $reports2 = $this->container->tagged('reports');
        $this->assertSame($reports1[0], $reports2[0]);

        // After scope reset
        $this->container->forgetScopedInstances();
        $reports3 = $this->container->tagged('reports');
        $this->assertNotSame($reports1[0], $reports3[0]);
    }

    public function testHooksFireOnCallResolution(): void
    {
        $resolved = [];
        $this->container->resolving(CallTestService::class, function (CallTestService $svc) use (&$resolved) {
            $resolved[] = $svc->tag;
        });

        // make() should fire the hook
        $this->container->make(CallTestService::class);
        $this->assertSame(['call-service'], $resolved);
    }

    public function testParentContainerWithTaggedBindings(): void
    {
        $parent = new Container();
        $parent->bind(PdfReport::class, PdfReport::class);
        $parent->bind(CsvReport::class, CsvReport::class);

        $child = new Container($parent);
        $child->tag([PdfReport::class, CsvReport::class], 'reports');

        // Tagged resolution from child should resolve from parent bindings
        $reports = $child->tagged('reports');
        $this->assertCount(2, $reports);
    }

    public function testCallUsesParentContainerBindings(): void
    {
        $parent = new Container();
        $parent->singleton(CallTestService::class, fn() => new CallTestService('parent-svc'));

        $child = new Container($parent);

        $result = $child->call(function (CallTestService $svc) {
            return $svc->tag;
        });

        $this->assertSame('parent-svc', $result);
    }

    public function testContainerImplementsInterface(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function testConstructorAcceptsNullParent(): void
    {
        $container = new Container(null);
        $this->assertNull($container->getParent());
    }

    public function testConstructorWithNoArgsDefaultsToNullParent(): void
    {
        $container = new Container();
        $this->assertNull($container->getParent());
    }

    // ══════════════════════════════════════════════════════════
    // bindIf / singletonIf / scopedIf
    // ══════════════════════════════════════════════════════════

    public function testBindIfRegistersWhenNotBound(): void
    {
        $this->container->bindIf(ReportInterface::class, PdfReport::class);
        $report = $this->container->make(ReportInterface::class);
        $this->assertInstanceOf(PdfReport::class, $report);
    }

    public function testBindIfSkipsWhenAlreadyBound(): void
    {
        $this->container->bind(ReportInterface::class, CsvReport::class);
        $this->container->bindIf(ReportInterface::class, PdfReport::class);
        $report = $this->container->make(ReportInterface::class);
        $this->assertInstanceOf(CsvReport::class, $report);
    }

    public function testBindIfSkipsWhenInstanceExists(): void
    {
        $instance = new HtmlReport();
        $this->container->instance(ReportInterface::class, $instance);
        $this->container->bindIf(ReportInterface::class, PdfReport::class);
        $resolved = $this->container->make(ReportInterface::class);
        $this->assertSame($instance, $resolved);
    }

    public function testSingletonIfRegistersWhenNotBound(): void
    {
        $this->container->singletonIf(CallTestService::class, fn() => new CallTestService('singleton-if'));
        $a = $this->container->make(CallTestService::class);
        $b = $this->container->make(CallTestService::class);
        $this->assertSame($a, $b);
        $this->assertSame('singleton-if', $a->tag);
    }

    public function testSingletonIfSkipsWhenAlreadyBound(): void
    {
        $this->container->singleton(CallTestService::class, fn() => new CallTestService('first'));
        $this->container->singletonIf(CallTestService::class, fn() => new CallTestService('second'));
        $result = $this->container->make(CallTestService::class);
        $this->assertSame('first', $result->tag);
    }

    public function testScopedIfRegistersWhenNotBound(): void
    {
        ScopedService::resetCounter();
        $this->container->scopedIf(ScopedService::class, ScopedService::class);
        $a = $this->container->make(ScopedService::class);
        $b = $this->container->make(ScopedService::class);
        $this->assertSame($a, $b);

        $this->container->forgetScopedInstances();
        $c = $this->container->make(ScopedService::class);
        $this->assertNotSame($a, $c);
    }

    public function testScopedIfSkipsWhenAlreadyBound(): void
    {
        ScopedService::resetCounter();
        $this->container->scoped(ScopedService::class, fn() => new ScopedService());
        $first = $this->container->make(ScopedService::class);

        $this->container->scopedIf(ScopedService::class, fn() => new ScopedService());
        $second = $this->container->make(ScopedService::class);
        $this->assertSame($first, $second);
    }

    // ══════════════════════════════════════════════════════════
    // bound()
    // ══════════════════════════════════════════════════════════

    public function testBoundReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->container->bound(ReportInterface::class));
    }

    public function testBoundReturnsTrueForBinding(): void
    {
        $this->container->bind(ReportInterface::class, PdfReport::class);
        $this->assertTrue($this->container->bound(ReportInterface::class));
    }

    public function testBoundReturnsTrueForInstance(): void
    {
        $this->container->instance(ReportInterface::class, new PdfReport());
        $this->assertTrue($this->container->bound(ReportInterface::class));
    }

    public function testBoundReturnsTrueForSingleton(): void
    {
        $this->container->singleton(ReportInterface::class, PdfReport::class);
        $this->assertTrue($this->container->bound(ReportInterface::class));
    }

    public function testBoundDoesNotCheckParentContainer(): void
    {
        $parent = new Container();
        $parent->bind(ReportInterface::class, PdfReport::class);
        $child = new Container($parent);

        $this->assertFalse($child->bound(ReportInterface::class));
        // but has() should still find it via parent
        $this->assertTrue($child->has(ReportInterface::class));
    }

    public function testBoundResolvesAliases(): void
    {
        $this->container->bind(ReportInterface::class, PdfReport::class);
        $this->container->alias('report', ReportInterface::class);
        $this->assertTrue($this->container->bound('report'));
    }

    // ══════════════════════════════════════════════════════════
    // factory()
    // ══════════════════════════════════════════════════════════

    public function testFactoryReturnsClosure(): void
    {
        $factory = $this->container->factory(PdfReport::class);
        $this->assertInstanceOf(Closure::class, $factory);
    }

    public function testFactoryResolvesNewInstanceEachCall(): void
    {
        $this->container->bind(ReportInterface::class, PdfReport::class);
        $factory = $this->container->factory(ReportInterface::class);

        $a = $factory();
        $b = $factory();
        $this->assertInstanceOf(PdfReport::class, $a);
        $this->assertInstanceOf(PdfReport::class, $b);
        $this->assertNotSame($a, $b);
    }

    public function testFactoryWithSingletonStillReturnsSameInstance(): void
    {
        $this->container->singleton(CallTestService::class, fn() => new CallTestService('single'));
        $factory = $this->container->factory(CallTestService::class);

        $a = $factory();
        $b = $factory();
        $this->assertSame($a, $b);
        $this->assertSame('single', $a->tag);
    }

    // ══════════════════════════════════════════════════════════
    // extend()
    // ══════════════════════════════════════════════════════════

    public function testExtendDecoratesResolvedInstance(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);
        $this->container->extend(HookableService::class, function (object $svc, Container $c) {
            $svc->injectedValue = 'extended';
            return $svc;
        });

        $result = $this->container->make(HookableService::class);
        $this->assertSame('extended', $result->injectedValue);
    }

    public function testExtendMultipleExtendersApplyInOrder(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);
        $this->container->extend(HookableService::class, function (object $svc, Container $c) {
            $svc->injectedValue .= 'A';
            return $svc;
        });
        $this->container->extend(HookableService::class, function (object $svc, Container $c) {
            $svc->injectedValue .= 'B';
            return $svc;
        });

        $result = $this->container->make(HookableService::class);
        $this->assertSame('AB', $result->injectedValue);
    }

    public function testExtendAppliesImmediatelyToCachedSingleton(): void
    {
        $this->container->singleton(HookableService::class, fn() => new HookableService('cached'));
        $first = $this->container->make(HookableService::class);
        $this->assertSame('', $first->injectedValue);

        // Extend after singleton is cached — should apply immediately
        $this->container->extend(HookableService::class, function (object $svc, Container $c) {
            $svc->injectedValue = 'post-cache';
            return $svc;
        });

        $second = $this->container->make(HookableService::class);
        $this->assertSame($first, $second);
        $this->assertSame('post-cache', $second->injectedValue);
    }

    public function testExtendResolvesAliases(): void
    {
        $this->container->bind(ReportInterface::class, PdfReport::class);
        $this->container->alias('report', ReportInterface::class);

        $decorated = false;
        $this->container->extend('report', function (object $svc, Container $c) use (&$decorated) {
            $decorated = true;
            return $svc;
        });

        $this->container->make(ReportInterface::class);
        $this->assertTrue($decorated);
    }

    public function testExtendClearedByReset(): void
    {
        $this->container->bind(HookableService::class, HookableService::class);
        $this->container->extend(HookableService::class, function (object $svc, Container $c) {
            $svc->injectedValue = 'ext';
            return $svc;
        });

        $this->container->reset();
        $this->container->bind(HookableService::class, HookableService::class);

        $result = $this->container->make(HookableService::class);
        $this->assertSame('', $result->injectedValue);
    }

    // ══════════════════════════════════════════════════════════
    // beforeResolving()
    // ══════════════════════════════════════════════════════════

    public function testBeforeResolvingHookFires(): void
    {
        $fired = false;
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->beforeResolving(PdfReport::class, function (string $abstract, Container $c) use (&$fired) {
            $fired = true;
            $this->assertSame(PdfReport::class, $abstract);
        });

        $this->container->make(PdfReport::class);
        $this->assertTrue($fired);
    }

    public function testGlobalBeforeResolvingHookFiresForAllTypes(): void
    {
        $abstracts = [];
        $this->container->beforeResolving(function (string $abstract, Container $c) use (&$abstracts) {
            $abstracts[] = $abstract;
        });

        $this->container->bind(ReportInterface::class, PdfReport::class);
        $this->container->make(ReportInterface::class);
        $this->container->make(CsvReport::class);

        $this->assertContains(ReportInterface::class, $abstracts);
        $this->assertContains(CsvReport::class, $abstracts);
    }

    public function testBeforeResolvingFiresBeforeResolvingHook(): void
    {
        $order = [];
        $this->container->bind(PdfReport::class, PdfReport::class);

        $this->container->beforeResolving(PdfReport::class, function () use (&$order) {
            $order[] = 'before';
        });
        $this->container->resolving(PdfReport::class, function () use (&$order) {
            $order[] = 'resolving';
        });
        $this->container->afterResolving(PdfReport::class, function () use (&$order) {
            $order[] = 'after';
        });

        $this->container->make(PdfReport::class);
        $this->assertSame(['before', 'resolving', 'after'], $order);
    }

    public function testBeforeResolvingClearedByReset(): void
    {
        $fired = false;
        $this->container->beforeResolving(PdfReport::class, function () use (&$fired) {
            $fired = true;
        });

        $this->container->reset();
        $this->container->bind(PdfReport::class, PdfReport::class);
        $this->container->make(PdfReport::class);
        $this->assertFalse($fired);
    }

    // ══════════════════════════════════════════════════════════
    // Hook Lifecycle: full order verification
    // ══════════════════════════════════════════════════════════

    public function testFullHookLifecycleOrder(): void
    {
        $order = [];
        $this->container->bind(HookableService::class, HookableService::class);

        $this->container->beforeResolving(HookableService::class, function () use (&$order) {
            $order[] = 'beforeResolving';
        });
        $this->container->extend(HookableService::class, function (object $svc) use (&$order) {
            $order[] = 'extend';
            return $svc;
        });
        $this->container->resolving(HookableService::class, function () use (&$order) {
            $order[] = 'resolving';
        });
        $this->container->afterResolving(HookableService::class, function () use (&$order) {
            $order[] = 'afterResolving';
        });

        $this->container->make(HookableService::class);
        $this->assertSame(['beforeResolving', 'extend', 'resolving', 'afterResolving'], $order);
    }

    public function testGlobalAndSpecificHooksFireInCorrectOrder(): void
    {
        $order = [];
        $this->container->bind(PdfReport::class, PdfReport::class);

        // Global hooks
        $this->container->beforeResolving(function () use (&$order) {
            $order[] = 'global-before';
        });
        $this->container->resolving(function () use (&$order) {
            $order[] = 'global-resolving';
        });
        $this->container->afterResolving(function () use (&$order) {
            $order[] = 'global-after';
        });

        // Type-specific hooks
        $this->container->beforeResolving(PdfReport::class, function () use (&$order) {
            $order[] = 'specific-before';
        });
        $this->container->resolving(PdfReport::class, function () use (&$order) {
            $order[] = 'specific-resolving';
        });
        $this->container->afterResolving(PdfReport::class, function () use (&$order) {
            $order[] = 'specific-after';
        });

        $this->container->make(PdfReport::class);

        // Type-specific fires first, then global for each hook phase
        $this->assertSame([
            'specific-before', 'global-before',
            'specific-resolving', 'global-resolving',
            'specific-after', 'global-after',
        ], $order);
    }
}

// ──────────────────────────────────────────────────────────
// Helper class for static method call() test
// ──────────────────────────────────────────────────────────

class CallTestHelper
{
    public static function staticMethod(CallTestService $svc): string
    {
        return 'static:' . $svc->tag;
    }
}
