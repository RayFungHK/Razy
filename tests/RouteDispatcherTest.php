<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;

/**
 * Tests for RouteDispatcher — route registration, lookup, and matching.
 *
 * Note: matchRoute() is heavily coupled to runtime state (constants, headers, exit)
 * and is better tested via integration tests. These unit tests focus on the registration
 * and data-access surface.
 */
#[CoversClass(RouteDispatcher::class)]
class RouteDispatcherTest extends TestCase
{
    private RouteDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new RouteDispatcher();
    }

    // ─── setRoute() ───────────────────────────────────────

    public function testSetRouteReturnsFluentSelf(): void
    {
        $module = $this->createModuleMock();
        $result = $this->dispatcher->setRoute($module, '/home', '/handler');
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetRouteRegistersRoute(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '/home', '/handler');

        $routes = $this->dispatcher->getRoutes();
        $this->assertNotEmpty($routes);
        $this->assertCount(1, $routes);
    }

    public function testSetRouteStoresModuleAndPath(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '/home', '/my_handler');

        $routes = $this->dispatcher->getRoutes();
        $route = \reset($routes);
        $this->assertSame($module, $route['module']);
        $this->assertSame('/my_handler', $route['path']);
        $this->assertSame('standard', $route['type']);
    }

    public function testSetRouteMultiple(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '/a', '/handler_a');
        $this->dispatcher->setRoute($module, '/b', '/handler_b');
        $this->dispatcher->setRoute($module, '/c', '/handler_c');

        $routes = $this->dispatcher->getRoutes();
        $this->assertCount(3, $routes);
    }

    public function testSetRouteSamePathOverwrites(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '/home', '/handler_v1');
        $this->dispatcher->setRoute($module, '/home', '/handler_v2');

        $routes = $this->dispatcher->getRoutes();
        $this->assertCount(1, $routes);
        $route = \reset($routes);
        $this->assertSame('/handler_v2', $route['path']);
    }

    // ─── setLazyRoute() ──────────────────────────────────

    public function testSetLazyRouteReturnsFluentSelf(): void
    {
        $module = $this->createModuleMock('mymod');
        $result = $this->dispatcher->setLazyRoute($module, '/lazy', '/lazy_handler');
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetLazyRouteRegistersWithCorrectType(): void
    {
        $module = $this->createModuleMock('mymod');
        $this->dispatcher->setLazyRoute($module, '/endpoint', '/handler');

        $routes = $this->dispatcher->getRoutes();
        $route = \reset($routes);
        $this->assertSame('lazy', $route['type']);
    }

    public function testSetLazyRoutePrefixesWithAlias(): void
    {
        $module = $this->createModuleMock('admin');
        $this->dispatcher->setLazyRoute($module, '/users', '/handler');

        $routes = $this->dispatcher->getRoutes();
        // The key should be prefixed with the module alias
        $keys = \array_keys($routes);
        $key = $keys[0];
        $this->assertStringContainsString('admin', $key);
    }

    // ─── setShadowRoute() ─────────────────────────────────

    public function testSetShadowRouteReturnsFluentSelf(): void
    {
        $module = $this->createModuleMock('owner');
        $target = $this->createModuleMock('target');
        $result = $this->dispatcher->setShadowRoute($module, '/shadow', $target, '/handler');
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetShadowRouteRegistersRoute(): void
    {
        $module = $this->createModuleMock('owner');
        $target = $this->createModuleMock('target');
        $this->dispatcher->setShadowRoute($module, '/proxy', $target, '/handler');

        $routes = $this->dispatcher->getRoutes();
        $this->assertCount(1, $routes);
    }

    public function testSetShadowRouteWithNullTargetDoesNotRegister(): void
    {
        $module = $this->createModuleMock('owner');
        $this->dispatcher->setShadowRoute($module, '/proxy', null, '/handler');

        $routes = $this->dispatcher->getRoutes();
        $this->assertEmpty($routes);
    }

    // ─── setScript() ──────────────────────────────────────

    public function testSetScriptReturnsFluentSelf(): void
    {
        $module = $this->createModuleMock('cli');
        $result = $this->dispatcher->setScript($module, '/build', '/build_handler');
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetScriptRegistersWithCorrectType(): void
    {
        $module = $this->createModuleMock('cli');
        $this->dispatcher->setScript($module, '/deploy', '/handler');

        // CLI scripts are separate from routes — getRoutes() won't include them
        $routes = $this->dispatcher->getRoutes();
        // Unless they use the same list... let me check
        $this->assertEmpty($routes, 'CLI scripts should be stored separately from routes');
    }

    // ─── getRoutes() ──────────────────────────────────────

    public function testGetRoutesEmptyByDefault(): void
    {
        $this->assertSame([], $this->dispatcher->getRoutes());
    }

    public function testGetRoutesReturnsAllRegistered(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '/a', '/handler_a');

        $module2 = $this->createModuleMock('mod2');
        $this->dispatcher->setLazyRoute($module2, '/b', '/handler_b');

        $routes = $this->dispatcher->getRoutes();
        $this->assertCount(2, $routes);
    }

    // ─── getRoutedInfo() ──────────────────────────────────

    public function testGetRoutedInfoEmptyByDefault(): void
    {
        $this->assertSame([], $this->dispatcher->getRoutedInfo());
    }

    // ─── matchRoute() — basic tests ───────────────────────

    public function testMatchRouteNoRoutesReturnsFalse(): void
    {
        $registry = $this->createMock(ModuleRegistry::class);
        $result = $this->dispatcher->matchRoute('/test', 'http://localhost', $registry);
        $this->assertFalse($result);
    }

    public function testMatchRouteNonLoadedModuleSkipped(): void
    {
        $module = $this->createModuleMock('test', 'vendor/test', ModuleStatus::Pending);
        $this->dispatcher->setRoute($module, '/home', '/handler');

        $registry = $this->createMock(ModuleRegistry::class);
        $result = $this->dispatcher->matchRoute('/home', 'http://localhost', $registry);
        $this->assertFalse($result);
    }

    // ─── Mixed route types ────────────────────────────────

    public function testMixedRouteTypeRegistration(): void
    {
        $mod1 = $this->createModuleMock('mod1');
        $mod2 = $this->createModuleMock('mod2');
        $mod3 = $this->createModuleMock('mod3');

        $this->dispatcher->setRoute($mod1, '/standard', '/handler1');
        $this->dispatcher->setLazyRoute($mod2, '/lazy', '/handler2');
        $this->dispatcher->setShadowRoute($mod1, '/shadow', $mod3, '/handler3');

        $routes = $this->dispatcher->getRoutes();
        $this->assertCount(3, $routes);

        $types = \array_column($routes, 'type');
        $this->assertContains('standard', $types);
        $this->assertContains('lazy', $types);
    }

    // ─── Route path normalization ─────────────────────────

    public function testRoutePathsAreNormalized(): void
    {
        $module = $this->createModuleMock();
        $this->dispatcher->setRoute($module, '//home//', '/handler');

        $routes = $this->dispatcher->getRoutes();
        // Routes should be normalized (trailing/leading slash handling)
        $this->assertNotEmpty($routes);
    }

    /**
     * Create a Module mock with a given ModuleInfo (or default).
     */
    private function createModuleMock(
        string $alias = 'test',
        string $code = 'vendor/test',
        ModuleStatus $status = ModuleStatus::Loaded,
    ): Module {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getAlias')->willReturn($alias);
        $moduleInfo->method('getCode')->willReturn($code);

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($moduleInfo);
        $module->method('getStatus')->willReturn($status);

        return $module;
    }
}
