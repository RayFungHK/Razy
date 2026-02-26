<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for P15: Named Routes.
 *
 * Tests Route::name()/getName()/hasName(), RouteDispatcher named route
 * registration, lookup (hasNamedRoute/getNamedRoute/getNamedRoutes),
 * URL generation (route/substituteParams), duplicate detection, and
 * integration with the existing routing pipeline.
 *
 * @package Razy
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Route;
use RuntimeException;

#[CoversClass(Route::class)]
#[CoversClass(RouteDispatcher::class)]
class NamedRoutesTest extends TestCase
{
    // ══════════════════════════════════════════════════════════════
    //  Route — Name validation
    // ══════════════════════════════════════════════════════════════

    public static function validNameProvider(): array
    {
        return [
            'simple' => ['users'],
            'dotted' => ['users.index'],
            'deeply_dotted' => ['api.v1.users.show'],
            'with_hyphens' => ['user-profile'],
            'underscored' => ['user_profile'],
            'starts_under' => ['_private'],
            'mixed' => ['api.users-list_v2'],
            'with_numbers' => ['route123'],
            'single_char' => ['a'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  DataProvider — URL generation patterns
    // ══════════════════════════════════════════════════════════════

    public static function urlGenerationProvider(): array
    {
        return [
            'no params' => ['/about/', [], '/about/'],
            'single digit' => ['/users/:d+/', [1], '/users/1/'],
            'large id' => ['/users/:d+/', [99999], '/users/99999/'],
            'string param' => ['/posts/:a+/', ['my-post'], '/posts/my-post/'],
            'two params' => ['/u/:d+/p/:a+/', [10, 'slug'], '/u/10/p/slug/'],
            'three params' => ['/:a+/:d+/:w+/', ['api', 1, 'test'], '/api/1/test/'],
            'quantified' => ['/code/:d{3}/', [123], '/code/123/'],
            'char class' => ['/tag/:[a-z-]+/', ['my-tag'], '/tag/my-tag/'],
            'zero as param' => ['/item/:d+/', [0], '/item/0/'],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  Route — Name property
    // ══════════════════════════════════════════════════════════════

    public function testRouteNameDefaultsToNull(): void
    {
        $route = new Route('handler');
        $this->assertNull($route->getName());
        $this->assertFalse($route->hasName());
    }

    public function testNameSetterReturnsFluent(): void
    {
        $route = new Route('handler');
        $result = $route->name('users.index');
        $this->assertSame($route, $result);
    }

    public function testNameGetterReturnsSetName(): void
    {
        $route = new Route('handler');
        $route->name('users.show');
        $this->assertSame('users.show', $route->getName());
        $this->assertTrue($route->hasName());
    }

    public function testNameCanBeChainedWithOtherMethods(): void
    {
        $route = new Route('handler');
        $route->name('api.users')
              ->method('GET')
              ->contain(['key' => 'val']);

        $this->assertSame('api.users', $route->getName());
        $this->assertSame('GET', $route->getMethod());
        $this->assertSame(['key' => 'val'], $route->getData());
    }

    public function testNameCanBeOverwritten(): void
    {
        $route = new Route('handler');
        $route->name('first');
        $route->name('second');

        $this->assertSame('second', $route->getName());
    }

    #[DataProvider('validNameProvider')]
    public function testValidRouteNames(string $name): void
    {
        $route = new Route('handler');
        $route->name($name);
        $this->assertSame($name, $route->getName());
    }

    public function testEmptyNameThrows(): void
    {
        $route = new Route('handler');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');
        $route->name('');
    }

    public function testWhitespaceOnlyNameThrows(): void
    {
        $route = new Route('handler');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');
        $route->name('   ');
    }

    public function testNameStartingWithNumberThrows(): void
    {
        $route = new Route('handler');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid route name');
        $route->name('123route');
    }

    public function testNameWithSpacesThrows(): void
    {
        $route = new Route('handler');
        $this->expectException(InvalidArgumentException::class);
        $route->name('my route');
    }

    public function testNameWithSpecialCharsThrows(): void
    {
        $route = new Route('handler');
        $this->expectException(InvalidArgumentException::class);
        $route->name('my@route');
    }

    // ══════════════════════════════════════════════════════════════
    //  RouteDispatcher — Named route registration
    // ══════════════════════════════════════════════════════════════

    public function testSetRouteRegistersNamedRoute(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();
        $route = (new Route('UserController/show'))->name('users.show');

        $dispatcher->setRoute($module, '/users/:d+/', $route);

        $this->assertTrue($dispatcher->hasNamedRoute('users.show'));
    }

    public function testSetRouteDoesNotRegisterUnnamedRoute(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();
        $route = new Route('UserController/index');

        $dispatcher->setRoute($module, '/users/', $route);

        $this->assertSame([], $dispatcher->getNamedRoutes());
    }

    public function testSetRouteWithStringPathDoesNotRegisterName(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/api/', 'ApiController/index');

        $this->assertSame([], $dispatcher->getNamedRoutes());
    }

    public function testDuplicateNameThrowsException(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $route1 = (new Route('Controller/a'))->name('home');
        $route2 = (new Route('Controller/b'))->name('home');

        $dispatcher->setRoute($module, '/a/', $route1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate named route 'home'");

        $dispatcher->setRoute($module, '/b/', $route2);
    }

    public function testSameNameSameRouteKeyDoesNotThrow(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $route = (new Route('Controller/index'))->name('home');

        // Re-registering the same route key with the same name is OK
        $dispatcher->setRoute($module, '/home/', $route);
        $dispatcher->setRoute($module, '/home/', $route);

        $this->assertTrue($dispatcher->hasNamedRoute('home'));
    }

    public function testMultipleNamedRoutes(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/users/', (new Route('UserController/index'))->name('users.index'));
        $dispatcher->setRoute($module, '/users/:d+/', (new Route('UserController/show'))->name('users.show'));
        $dispatcher->setRoute($module, '/posts/', (new Route('PostController/index'))->name('posts.index'));

        $names = $dispatcher->getNamedRoutes();
        $this->assertCount(3, $names);
        $this->assertTrue($dispatcher->hasNamedRoute('users.index'));
        $this->assertTrue($dispatcher->hasNamedRoute('users.show'));
        $this->assertTrue($dispatcher->hasNamedRoute('posts.index'));
    }

    // ══════════════════════════════════════════════════════════════
    //  RouteDispatcher — Named route lookup
    // ══════════════════════════════════════════════════════════════

    public function testHasNamedRouteReturnsFalseForUnknown(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertFalse($dispatcher->hasNamedRoute('nonexistent'));
    }

    public function testGetNamedRouteReturnsRouteData(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();
        $route = (new Route('UserController/show'))->name('users.show')->method('GET');

        $dispatcher->setRoute($module, '/users/:d+/', $route);

        $data = $dispatcher->getNamedRoute('users.show');
        $this->assertNotNull($data);
        $this->assertSame('standard', $data['type']);
        $this->assertSame('GET', $data['method']);
        $this->assertSame($route, $data['path']);
    }

    public function testGetNamedRouteReturnsNullForUnknown(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertNull($dispatcher->getNamedRoute('nonexistent'));
    }

    public function testGetNamedRoutesReturnsEmptyByDefault(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertSame([], $dispatcher->getNamedRoutes());
    }

    // ══════════════════════════════════════════════════════════════
    //  RouteDispatcher — URL generation (route method)
    // ══════════════════════════════════════════════════════════════

    public function testRouteGeneratesSimpleUrl(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/users/', (new Route('UserController/index'))->name('users.index'));

        $url = $dispatcher->route('users.index');
        $this->assertSame('/users/', $url);
    }

    public function testRouteGeneratesUrlWithParam(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/users/:d+/', (new Route('UserController/show'))->name('users.show'));

        $url = $dispatcher->route('users.show', [42]);
        $this->assertSame('/users/42/', $url);
    }

    public function testRouteGeneratesUrlWithMultipleParams(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute(
            $module,
            '/users/:d+/posts/:a+/',
            (new Route('PostController/show'))->name('users.posts.show'),
        );

        $url = $dispatcher->route('users.posts.show', [5, 'hello-world']);
        $this->assertSame('/users/5/posts/hello-world/', $url);
    }

    public function testRouteGeneratesUrlWithQueryString(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/search/', (new Route('SearchController/index'))->name('search'));

        $url = $dispatcher->route('search', [], ['q' => 'razy', 'page' => '2']);
        $this->assertSame('/search/?q=razy&page=2', $url);
    }

    public function testRouteWithParamsAndQuery(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute(
            $module,
            '/users/:d+/',
            (new Route('UserController/show'))->name('users.show'),
        );

        $url = $dispatcher->route('users.show', [99], ['tab' => 'settings']);
        $this->assertSame('/users/99/?tab=settings', $url);
    }

    public function testRouteThrowsForUndefinedName(): void
    {
        $dispatcher = new RouteDispatcher();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Named route 'nonexistent' is not defined");

        $dispatcher->route('nonexistent');
    }

    // ══════════════════════════════════════════════════════════════
    //  RouteDispatcher — substituteParams static method
    // ══════════════════════════════════════════════════════════════

    public function testSubstituteParamsDigit(): void
    {
        $result = RouteDispatcher::substituteParams('/users/:d+/', [42]);
        $this->assertSame('/users/42/', $result);
    }

    public function testSubstituteParamsAlphanumeric(): void
    {
        $result = RouteDispatcher::substituteParams('/posts/:a+/', ['hello-world']);
        $this->assertSame('/posts/hello-world/', $result);
    }

    public function testSubstituteParamsWord(): void
    {
        $result = RouteDispatcher::substituteParams('/tags/:w+/', ['php_dev']);
        $this->assertSame('/tags/php_dev/', $result);
    }

    public function testSubstituteParamsWithQuantifier(): void
    {
        $result = RouteDispatcher::substituteParams('/code/:a{3,5}/', ['abc']);
        $this->assertSame('/code/abc/', $result);
    }

    public function testSubstituteParamsCustomCharClass(): void
    {
        $result = RouteDispatcher::substituteParams('/slug/:[a-z0-9-]+/', ['my-slug']);
        $this->assertSame('/slug/my-slug/', $result);
    }

    public function testSubstituteParamsMultiple(): void
    {
        $result = RouteDispatcher::substituteParams('/api/:a+/:d+/:w+/', ['users', 42, 'posts']);
        $this->assertSame('/api/users/42/posts/', $result);
    }

    public function testSubstituteParamsNoPlaceholders(): void
    {
        $result = RouteDispatcher::substituteParams('/static/path/', []);
        $this->assertSame('/static/path/', $result);
    }

    public function testSubstituteParamsTooFewParamsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough parameters');

        RouteDispatcher::substituteParams('/users/:d+/posts/:a+/', [42]);
    }

    public function testSubstituteParamsExtraParamsIgnored(): void
    {
        // Extra parameters beyond what the pattern needs are simply unused
        $result = RouteDispatcher::substituteParams('/users/:d+/', [42, 'extra', 'more']);
        $this->assertSame('/users/42/', $result);
    }

    public function testSubstituteParamsUppercaseShorthand(): void
    {
        // :D and :W are uppercase shorthand (negated classes)
        $result = RouteDispatcher::substituteParams('/x/:D+/:W+/', ['abc', '---']);
        $this->assertSame('/x/abc/---/', $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Named route with method constraint
    // ══════════════════════════════════════════════════════════════

    public function testNamedRoutePreservesMethodConstraint(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $route = (new Route('UserController/store'))->name('users.store')->method('POST');
        $dispatcher->setRoute($module, '/users/', $route);

        $data = $dispatcher->getNamedRoute('users.store');
        $this->assertSame('POST', $data['method']);
    }

    public function testNamedRoutePreservesData(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $route = (new Route('UserController/show'))
            ->name('users.show')
            ->contain(['permission' => 'view_users']);

        $dispatcher->setRoute($module, '/users/:d+/', $route);

        $data = $dispatcher->getNamedRoute('users.show');
        $this->assertSame(['permission' => 'view_users'], $data['path']->getData());
    }

    public function testNamedRoutePreservesMiddleware(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $mw = function ($ctx, $next) {
            return $next($ctx);
        };
        $route = (new Route('AdminController/index'))
            ->name('admin.dashboard')
            ->middleware($mw);

        $dispatcher->setRoute($module, '/admin/', $route);

        $data = $dispatcher->getNamedRoute('admin.dashboard');
        $this->assertTrue($data['path']->hasMiddleware());
        $this->assertCount(1, $data['path']->getMiddleware());
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Full fluent chain
    // ══════════════════════════════════════════════════════════════

    public function testFullFluentChain(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $mw = function ($ctx, $next) {
            return $next($ctx);
        };
        $route = (new Route('Controller/action'))
            ->name('my.route')
            ->method('PUT')
            ->contain(['key' => 'value'])
            ->middleware($mw);

        $dispatcher->setRoute($module, '/resource/:d+/', $route);

        // Verify everything round-trips
        $this->assertTrue($dispatcher->hasNamedRoute('my.route'));
        $this->assertSame('/resource/7/', $dispatcher->route('my.route', [7]));

        $data = $dispatcher->getNamedRoute('my.route');
        $this->assertSame('PUT', $data['method']);
        $this->assertSame('my.route', $data['path']->getName());
        $this->assertSame(['key' => 'value'], $data['path']->getData());
        $this->assertCount(1, $data['path']->getMiddleware());
    }

    #[DataProvider('urlGenerationProvider')]
    public function testUrlGenerationPatterns(string $pattern, array $params, string $expected): void
    {
        $result = RouteDispatcher::substituteParams($pattern, $params);
        $this->assertSame($expected, $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════

    public function testNameWithMaxLengthWorks(): void
    {
        $name = \str_repeat('a', 100) . '.route';
        $route = new Route('handler');
        $route->name($name);
        $this->assertSame($name, $route->getName());
    }

    public function testNameTrimsWhitespace(): void
    {
        $route = new Route('handler');
        $route->name('  users.index  ');
        $this->assertSame('users.index', $route->getName());
    }

    public function testRoutePatternWithNoTrailingSlash(): void
    {
        $result = RouteDispatcher::substituteParams('/users/:d+', [42]);
        $this->assertSame('/users/42', $result);
    }

    public function testRouteUrlEmptyQuery(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        $dispatcher->setRoute($module, '/home/', (new Route('HomeController/index'))->name('home'));

        $url = $dispatcher->route('home', [], []);
        $this->assertSame('/home/', $url);
    }

    public function testMultipleDispatchersHaveIndependentRegistries(): void
    {
        $module = $this->createModuleMock();

        $d1 = new RouteDispatcher();
        $d1->setRoute($module, '/a/', (new Route('A/index'))->name('route.a'));

        $d2 = new RouteDispatcher();
        $d2->setRoute($module, '/b/', (new Route('B/index'))->name('route.b'));

        $this->assertTrue($d1->hasNamedRoute('route.a'));
        $this->assertFalse($d1->hasNamedRoute('route.b'));

        $this->assertTrue($d2->hasNamedRoute('route.b'));
        $this->assertFalse($d2->hasNamedRoute('route.a'));
    }

    public function testGenerateUrlWithStringParams(): void
    {
        // Params are cast to string in the URL
        $result = RouteDispatcher::substituteParams('/api/:d+/', ['42']);
        $this->assertSame('/api/42/', $result);
    }
    // ══════════════════════════════════════════════════════════════
    //  Helper — Module mock
    // ══════════════════════════════════════════════════════════════

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
