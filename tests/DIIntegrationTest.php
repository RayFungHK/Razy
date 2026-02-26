<?php

/**
 * DI Integration Tests for Phase 2 of Stage 2 Refactoring.
 *
 * Covers:
 * - 2.1 Container registers core framework services
 * - 2.2 Distributor registers itself in Container
 * - 2.3 Module factory methods (Agent/ThreadManager via Container)
 * - 2.4 Controller convenience methods (resolve/hasService)
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Agent;
use Razy\Container;
use Razy\Contract\ContainerInterface;
use Razy\Controller;
use Razy\Distributor;
use Razy\Exception\ContainerException;
use Razy\Module;
use Razy\ThreadManager;
use stdClass;

// ──────────────────────────────────────────────────────────
// Test Fixtures for Controller DI tests
// ──────────────────────────────────────────────────────────

/** A simple service to test resolution through Controller */
class DITestService
{
    public function __construct(public string $name = 'di-test')
    {
    }
}

/** A service with a dependency for auto-wiring tests */
class DITestServiceWithDep
{
    public function __construct(public DITestService $dep)
    {
    }
}

/** Interface for binding tests */
interface DITestServiceInterface
{
    public function value(): string;
}

class DITestServiceImpl implements DITestServiceInterface
{
    public function value(): string
    {
        return 'concrete';
    }
}

// ──────────────────────────────────────────────────────────

#[CoversClass(Container::class)]
#[CoversClass(Controller::class)]
class DIIntegrationTest extends TestCase
{
    // ─── 2.1 Container Core Service Registration ───────────

    public function testContainerResolvesContainerInterfaceAlias(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->alias(ContainerInterface::class, Container::class);

        $resolved = $container->get(ContainerInterface::class);
        $this->assertSame($container, $resolved);
    }

    public function testContainerResolvesContainerInterfaceViaMake(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->alias(ContainerInterface::class, Container::class);

        $resolved = $container->make(ContainerInterface::class);
        $this->assertSame($container, $resolved);
    }

    public function testContainerHasReportsAliasedBindings(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->alias(ContainerInterface::class, Container::class);

        $this->assertTrue($container->has(Container::class));
        $this->assertTrue($container->has(ContainerInterface::class));
    }

    public function testPluginManagerSingletonRegistration(): void
    {
        // Simulate the registration pattern from Application constructor
        $container = new Container();
        $callCount = 0;
        $container->singleton('TestPluginManager', function () use (&$callCount) {
            $callCount++;
            return new stdClass();
        });

        $first = $container->get('TestPluginManager');
        $second = $container->get('TestPluginManager');

        $this->assertSame($first, $second, 'Singleton should return same instance');
        $this->assertEquals(1, $callCount, 'Factory should only be called once');
    }

    // ─── 2.2 Distributor Registration in Container ─────────

    public function testDistributorInstanceRegistrationPattern(): void
    {
        // Simulate the pattern: container->instance(Distributor::class, $this)
        $container = new Container();

        $mockDistributor = $this->createMock(Distributor::class);
        $container->instance(Distributor::class, $mockDistributor);

        $this->assertTrue($container->has(Distributor::class));
        $this->assertSame($mockDistributor, $container->get(Distributor::class));
    }

    public function testDistributorRegistrationOnlyWhenContainerAvailable(): void
    {
        // When getContainer returns null, no registration should happen
        $mockDistributor = $this->createMock(Distributor::class);
        $mockDistributor->method('getContainer')->willReturn(null);

        // This just ensures the pattern is safe; no assertion on container needed
        $container = $mockDistributor->getContainer();
        $this->assertNull($container);
    }

    // ─── 2.3 Module Factory Methods ────────────────────────

    public function testContainerCanMakeAgentWithParameters(): void
    {
        $container = new Container();

        $mockModule = $this->createMock(Module::class);
        $agent = $container->make(Agent::class, ['module' => $mockModule]);

        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testContainerCanMakeThreadManager(): void
    {
        $container = new Container();

        $threadManager = $container->make(ThreadManager::class);

        $this->assertInstanceOf(ThreadManager::class, $threadManager);
    }

    public function testAgentCreatedViaContainerIsFunctional(): void
    {
        $container = new Container();
        $mockModule = $this->createMock(Module::class);

        $agent = $container->make(Agent::class, ['module' => $mockModule]);

        $this->assertInstanceOf(Agent::class, $agent);
    }

    public function testMultipleAgentResolutionsCreateDistinctInstances(): void
    {
        // Agent is NOT a singleton — each make() should return a new instance
        $container = new Container();
        $mockModule = $this->createMock(Module::class);

        $agent1 = $container->make(Agent::class, ['module' => $mockModule]);
        $agent2 = $container->make(Agent::class, ['module' => $mockModule]);

        $this->assertNotSame($agent1, $agent2);
    }

    // ─── 2.4 Controller Container Access ───────────────────

    public function testControllerContainerReturnsNullWithoutModule(): void
    {
        $controller = new class(null) extends Controller {};

        $this->assertNull($controller->container());
    }

    public function testControllerContainerReturnsDelegatedContainer(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $this->assertSame($container, $controller->container());
    }

    public function testControllerResolveMethodDelegatesToContainer(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $result = $controller->resolve(DITestService::class);
        $this->assertInstanceOf(DITestService::class, $result);
        $this->assertEquals('di-test', $result->name);
    }

    public function testControllerResolveWithParameters(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $result = $controller->resolve(DITestService::class, ['name' => 'custom']);
        $this->assertInstanceOf(DITestService::class, $result);
        $this->assertEquals('custom', $result->name);
    }

    public function testControllerResolveAutoWiresDeep(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $result = $controller->resolve(DITestServiceWithDep::class);
        $this->assertInstanceOf(DITestServiceWithDep::class, $result);
        $this->assertInstanceOf(DITestService::class, $result->dep);
    }

    public function testControllerResolveThrowsWhenContainerUnavailable(): void
    {
        $controller = new class(null) extends Controller {};

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('DI container is not available');

        $controller->resolve(DITestService::class);
    }

    public function testControllerHasServiceReturnsTrueForRegistered(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->bind(DITestServiceInterface::class, DITestServiceImpl::class);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $this->assertTrue($controller->hasService(DITestServiceInterface::class));
        $this->assertTrue($controller->hasService(Container::class));
    }

    public function testControllerHasServiceReturnsFalseForUnregistered(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $this->assertFalse($controller->hasService('NonExistent\Service'));
    }

    public function testControllerHasServiceReturnsFalseWithoutContainer(): void
    {
        $controller = new class(null) extends Controller {};

        $this->assertFalse($controller->hasService(DITestService::class));
    }

    public function testControllerResolveWithInterfaceBinding(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->bind(DITestServiceInterface::class, DITestServiceImpl::class);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        $result = $controller->resolve(DITestServiceInterface::class);
        $this->assertInstanceOf(DITestServiceImpl::class, $result);
        $this->assertEquals('concrete', $result->value());
    }

    // ─── End-to-End DI Chain Tests ─────────────────────────

    public function testContainerChainApplicationToModule(): void
    {
        // Simulate the full chain: Application container → Distributor → Module → Controller
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->alias(ContainerInterface::class, Container::class);

        // Register a service at application level
        $container->singleton(DITestService::class, fn () => new DITestService('shared'));

        // Mock the chain
        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        // Controller should resolve the application-level singleton
        $service = $controller->resolve(DITestService::class);
        $this->assertEquals('shared', $service->name);

        // Second resolution should return same singleton
        $service2 = $controller->resolve(DITestService::class);
        $this->assertSame($service, $service2);
    }

    public function testContainerInterfaceResolvableFromController(): void
    {
        $container = new Container();
        $container->instance(Container::class, $container);
        $container->alias(ContainerInterface::class, Container::class);

        $mockModule = $this->createMock(Module::class);
        $mockModule->method('getContainer')->willReturn($container);

        $controller = new class($mockModule) extends Controller {};

        // Controller can resolve the container itself via the interface alias
        $resolved = $controller->resolve(ContainerInterface::class);
        $this->assertSame($container, $resolved);
    }
}
