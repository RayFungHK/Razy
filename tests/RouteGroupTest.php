<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\MiddlewareInterface;
use Razy\Route;
use Razy\Routing\RouteGroup;

/**
 * Tests for #9: Route Groups — Shared prefix/middleware.
 *
 * Covers RouteGroup class and its resolve() flattening logic.
 *
 * Sections:
 *  1. Basic Configuration (prefix, middleware, namePrefix, method)
 *  2. Route Registration
 *  3. Resolve — Simple Prefix
 *  4. Resolve — Middleware Propagation
 *  5. Resolve — Name Prefix
 *  6. Resolve — Method Constraint
 *  7. Nested Groups
 *  8. Fluent API / Factory
 *  9. Edge Cases
 * 10. Integration — Route Decoration
 */
#[CoversClass(RouteGroup::class)]
#[CoversClass(Route::class)]
class RouteGroupTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: Basic Configuration
    // ═══════════════════════════════════════════════════════════════

    public function testConstructorSetsPrefix(): void
    {
        $group = new RouteGroup('/api/v1');
        $this->assertSame('api/v1', $group->getPrefix());
    }

    public function testConstructorTrimsSlashes(): void
    {
        $group = new RouteGroup('/admin/');
        $this->assertSame('admin', $group->getPrefix());
    }

    public function testEmptyPrefix(): void
    {
        $group = new RouteGroup('');
        $this->assertSame('', $group->getPrefix());
    }

    public function testMiddlewareFluent(): void
    {
        $mw = $this->createMockMiddleware();
        $group = new RouteGroup('/api');
        $result = $group->middleware($mw);

        $this->assertSame($group, $result);
        $this->assertCount(1, $group->getMiddleware());
    }

    public function testMultipleMiddleware(): void
    {
        $mw1 = $this->createMockMiddleware();
        $mw2 = fn(array $ctx, Closure $next) => $next($ctx);
        $group = (new RouteGroup('/api'))->middleware($mw1, $mw2);
        $this->assertCount(2, $group->getMiddleware());
    }

    public function testNamePrefixFluent(): void
    {
        $group = new RouteGroup('/api');
        $result = $group->namePrefix('api.');
        $this->assertSame($group, $result);
        $this->assertSame('api.', $group->getNamePrefix());
    }

    public function testMethodFluent(): void
    {
        $group = new RouteGroup('/api');
        $result = $group->method('POST');
        $this->assertSame($group, $result);
        $this->assertSame('POST', $group->getMethod());
    }

    public function testMethodDefaultIsWildcard(): void
    {
        $group = new RouteGroup('/api');
        $this->assertSame('*', $group->getMethod());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Route Registration
    // ═══════════════════════════════════════════════════════════════

    public function testAddRouteStoresEntry(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/users', 'api/users');

        $entries = $group->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('route', $entries[0]['type']);
        $this->assertSame('/users', $entries[0]['path']);
        $this->assertSame('api/users', $entries[0]['handler']);
        $this->assertSame('Route', $entries[0]['routeType']);
    }

    public function testAddLazyRouteStoresEntry(): void
    {
        $group = new RouteGroup('/api');
        $group->addLazyRoute('/posts', 'api/posts');

        $entries = $group->getEntries();
        $this->assertSame('LazyRoute', $entries[0]['routeType']);
    }

    public function testAddRouteWithRouteObject(): void
    {
        $route = (new Route('handler'))->name('test');
        $group = new RouteGroup('/api');
        $group->addRoute('/path', $route);

        $entries = $group->getEntries();
        $this->assertInstanceOf(Route::class, $entries[0]['handler']);
    }

    public function testAddRouteReturnsSelf(): void
    {
        $group = new RouteGroup('/api');
        $result = $group->addRoute('/x', 'handler');
        $this->assertSame($group, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Resolve — Simple Prefix
    // ═══════════════════════════════════════════════════════════════

    public function testResolveAppliesPrefix(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/users', 'users/index');
        $group->addRoute('/posts', 'posts/index');

        $resolved = $group->resolve();

        $this->assertCount(2, $resolved);
        $this->assertSame('api/users', $resolved[0]['path']);
        $this->assertSame('api/posts', $resolved[1]['path']);
    }

    public function testResolveEmptyPrefixNoChange(): void
    {
        $group = new RouteGroup('');
        $group->addRoute('/users', 'users/index');

        $resolved = $group->resolve();
        $this->assertSame('users', $resolved[0]['path']);
    }

    public function testResolveRootRouteInGroup(): void
    {
        $group = new RouteGroup('/admin');
        $group->addRoute('/', 'admin/dashboard');

        $resolved = $group->resolve();
        $this->assertSame('admin', $resolved[0]['path']);
    }

    public function testResolveWithParentPrefix(): void
    {
        $group = new RouteGroup('/v1');
        $group->addRoute('/users', 'users/index');

        $resolved = $group->resolve('api');
        $this->assertSame('api/v1/users', $resolved[0]['path']);
    }

    public function testResolvePreservesRouteType(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/users', 'users');
        $group->addLazyRoute('/lazy', 'lazy');

        $resolved = $group->resolve();
        $this->assertSame('Route', $resolved[0]['routeType']);
        $this->assertSame('LazyRoute', $resolved[1]['routeType']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Resolve — Middleware Propagation
    // ═══════════════════════════════════════════════════════════════

    public function testResolveAttachesMiddlewareToStringHandler(): void
    {
        $mw = $this->createMockMiddleware();
        $group = (new RouteGroup('/api'))->middleware($mw);
        $group->addRoute('/users', 'users/index');

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];

        $this->assertInstanceOf(Route::class, $handler);
        $this->assertSame('users/index', $handler->getClosurePath());
        $this->assertCount(1, $handler->getMiddleware());
        $this->assertSame($mw, $handler->getMiddleware()[0]);
    }

    public function testResolveNoMiddlewareKeepsStringHandler(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/users', 'users/index');

        $resolved = $group->resolve();
        $this->assertIsString($resolved[0]['handler']);
    }

    public function testResolveGroupMiddlewarePrependedToRouteMiddleware(): void
    {
        $groupMw = $this->createMockMiddleware();
        $routeMw = $this->createMockMiddleware();
        $route = (new Route('handler'))->middleware($routeMw);

        $group = (new RouteGroup('/api'))->middleware($groupMw);
        $group->addRoute('/users', $route);

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];

        $this->assertCount(2, $handler->getMiddleware());
        $this->assertSame($groupMw, $handler->getMiddleware()[0]); // Group first
        $this->assertSame($routeMw, $handler->getMiddleware()[1]); // Route second
    }

    public function testResolveParentMiddlewareCarriesDown(): void
    {
        $parentMw = $this->createMockMiddleware();
        $groupMw = $this->createMockMiddleware();

        $group = (new RouteGroup('/api'))->middleware($groupMw);
        $group->addRoute('/users', 'users');

        $resolved = $group->resolve('', [$parentMw]);
        $handler = $resolved[0]['handler'];

        $this->assertInstanceOf(Route::class, $handler);
        $this->assertCount(2, $handler->getMiddleware());
        $this->assertSame($parentMw, $handler->getMiddleware()[0]);
        $this->assertSame($groupMw, $handler->getMiddleware()[1]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Resolve — Name Prefix
    // ═══════════════════════════════════════════════════════════════

    public function testResolveAppliesNamePrefixToNamedRoute(): void
    {
        $route = (new Route('handler'))->name('users.index');
        $group = (new RouteGroup('/api'))->namePrefix('api.');
        $group->addRoute('/users', $route);

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertSame('api.users.index', $handler->getName());
    }

    public function testResolveNamePrefixAccumulatesInNestedGroups(): void
    {
        $group = (new RouteGroup('/api'))->namePrefix('api.');
        $group->group('/v1', function (RouteGroup $sub) {
            $sub->namePrefix('v1.');
            $sub->addRoute('/users', (new Route('handler'))->name('users'));
        });

        $resolved = $group->resolve();
        $this->assertSame('api.v1.users', $resolved[0]['handler']->getName());
    }

    public function testResolveNamePrefixOnUnnamedStringHandler(): void
    {
        $group = (new RouteGroup('/api'))->namePrefix('api.');
        $group->addRoute('/users', 'users/index');

        $resolved = $group->resolve();
        // String handler is wrapped in Route (hasGroupAttrs = true) but name stays null
        $handler = $resolved[0]['handler'];
        $this->assertInstanceOf(Route::class, $handler);
        $this->assertNull($handler->getName());
        $this->assertSame('users/index', $handler->getClosurePath());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Resolve — Method Constraint
    // ═══════════════════════════════════════════════════════════════

    public function testResolveAppliesGroupMethodToStringHandler(): void
    {
        $group = (new RouteGroup('/api'))->method('GET');
        $group->addRoute('/users', 'users');

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertInstanceOf(Route::class, $handler);
        $this->assertSame('GET', $handler->getMethod());
    }

    public function testResolveRouteMethodOverridesGroupMethod(): void
    {
        $route = (new Route('handler'))->method('POST');
        $group = (new RouteGroup('/api'))->method('GET');
        $group->addRoute('/users', $route);

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertSame('POST', $handler->getMethod()); // Route takes precedence
    }

    public function testResolveMethodInheritsFromParent(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/users', 'users');

        $resolved = $group->resolve('', [], '', 'DELETE');
        $handler = $resolved[0]['handler'];
        $this->assertInstanceOf(Route::class, $handler);
        $this->assertSame('DELETE', $handler->getMethod());
    }

    public function testResolveGroupMethodOverridesParentMethod(): void
    {
        $group = (new RouteGroup('/api'))->method('PUT');
        $group->addRoute('/users', 'users');

        $resolved = $group->resolve('', [], '', 'DELETE');
        $handler = $resolved[0]['handler'];
        $this->assertSame('PUT', $handler->getMethod());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Nested Groups
    // ═══════════════════════════════════════════════════════════════

    public function testNestedGroupPrefixAccumulates(): void
    {
        $group = new RouteGroup('/api');
        $group->group('/v1', function (RouteGroup $sub) {
            $sub->addRoute('/users', 'users');
        });

        $resolved = $group->resolve();
        $this->assertSame('api/v1/users', $resolved[0]['path']);
    }

    public function testNestedGroupMiddlewareAccumulates(): void
    {
        $outerMw = $this->createMockMiddleware();
        $innerMw = $this->createMockMiddleware();

        $group = (new RouteGroup('/api'))->middleware($outerMw);
        $group->group('/v1', function (RouteGroup $sub) use ($innerMw) {
            $sub->middleware($innerMw);
            $sub->addRoute('/users', 'users');
        });

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertCount(2, $handler->getMiddleware());
        $this->assertSame($outerMw, $handler->getMiddleware()[0]);
        $this->assertSame($innerMw, $handler->getMiddleware()[1]);
    }

    public function testDeeplyNestedGroups(): void
    {
        $group = new RouteGroup('/api');
        $group->group('/v1', function (RouteGroup $v1) {
            $v1->group('/admin', function (RouteGroup $admin) {
                $admin->group('/settings', function (RouteGroup $settings) {
                    $settings->addRoute('/general', 'settings/general');
                });
            });
        });

        $resolved = $group->resolve();
        $this->assertSame('api/v1/admin/settings/general', $resolved[0]['path']);
    }

    public function testNestedGroupsWithMixedRouteTypes(): void
    {
        $group = new RouteGroup('/api');
        $group->addRoute('/root', 'root');
        $group->group('/sub', function (RouteGroup $sub) {
            $sub->addRoute('/a', 'a');
            $sub->addLazyRoute('/b', 'b');
        });
        $group->addRoute('/end', 'end');

        $resolved = $group->resolve();
        $this->assertCount(4, $resolved);
        $this->assertSame('api/root', $resolved[0]['path']);
        $this->assertSame('api/sub/a', $resolved[1]['path']);
        $this->assertSame('api/sub/b', $resolved[2]['path']);
        $this->assertSame('api/end', $resolved[3]['path']);
        $this->assertSame('Route', $resolved[0]['routeType']);
        $this->assertSame('Route', $resolved[1]['routeType']);
        $this->assertSame('LazyRoute', $resolved[2]['routeType']);
    }

    public function testNestedGroupReturnsSelf(): void
    {
        $group = new RouteGroup('/api');
        $result = $group->group('/sub', function (RouteGroup $sub) {});
        $this->assertSame($group, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Fluent API / Factory
    // ═══════════════════════════════════════════════════════════════

    public function testStaticCreate(): void
    {
        $group = RouteGroup::create('/api');
        $this->assertInstanceOf(RouteGroup::class, $group);
        $this->assertSame('api', $group->getPrefix());
    }

    public function testRoutesCallback(): void
    {
        $group = RouteGroup::create('/api')->routes(function (RouteGroup $g) {
            $g->addRoute('/users', 'users');
            $g->addRoute('/posts', 'posts');
        });

        $this->assertCount(2, $group->getEntries());
    }

    public function testFullFluentChain(): void
    {
        $mw = $this->createMockMiddleware();

        $group = RouteGroup::create('/api')
            ->middleware($mw)
            ->namePrefix('api.')
            ->method('GET')
            ->routes(function (RouteGroup $g) {
                $g->addRoute('/users', (new Route('users'))->name('users'));
            });

        $resolved = $group->resolve();
        $this->assertCount(1, $resolved);
        $this->assertSame('api/users', $resolved[0]['path']);

        $handler = $resolved[0]['handler'];
        $this->assertSame('api.users', $handler->getName());
        $this->assertSame('GET', $handler->getMethod());
        $this->assertCount(1, $handler->getMiddleware());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyGroupResolvesEmpty(): void
    {
        $group = new RouteGroup('/api');
        $resolved = $group->resolve();
        $this->assertSame([], $resolved);
    }

    public function testGroupWithOnlyNestedGroupsNoRoutes(): void
    {
        $group = new RouteGroup('/api');
        $group->group('/v1', function (RouteGroup $sub) {});

        $resolved = $group->resolve();
        $this->assertSame([], $resolved);
    }

    public function testRouteDataPreservedThroughDecoration(): void
    {
        $route = (new Route('handler'))->contain(['role' => 'admin'])->name('test');
        $mw = $this->createMockMiddleware();

        $group = (new RouteGroup('/api'))->middleware($mw);
        $group->addRoute('/admin', $route);

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertSame(['role' => 'admin'], $handler->getData());
    }

    public function testMultipleRoutesInGroupAllDecorated(): void
    {
        $mw = $this->createMockMiddleware();
        $group = (new RouteGroup('/api'))->middleware($mw);
        $group->addRoute('/a', 'handler_a');
        $group->addRoute('/b', 'handler_b');
        $group->addRoute('/c', 'handler_c');

        $resolved = $group->resolve();
        $this->assertCount(3, $resolved);
        foreach ($resolved as $entry) {
            $this->assertInstanceOf(Route::class, $entry['handler']);
            $this->assertCount(1, $entry['handler']->getMiddleware());
        }
    }

    public function testClosureMiddlewareInGroup(): void
    {
        $called = false;
        $closureMw = function (array $ctx, Closure $next) use (&$called) {
            $called = true;
            return $next($ctx);
        };

        $group = (new RouteGroup('/api'))->middleware($closureMw);
        $group->addRoute('/test', 'test');

        $resolved = $group->resolve();
        $handler = $resolved[0]['handler'];
        $this->assertCount(1, $handler->getMiddleware());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Integration — Complex Scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testComplexNestedGroupWithAllFeatures(): void
    {
        $authMw = $this->createMockMiddleware();
        $adminMw = $this->createMockMiddleware();

        $group = RouteGroup::create('/api')
            ->middleware($authMw)
            ->namePrefix('api.')
            ->routes(function (RouteGroup $api) use ($adminMw) {
                $api->addRoute('/public', 'public/index');

                $api->group('/admin', function (RouteGroup $admin) use ($adminMw) {
                    $admin->middleware($adminMw);
                    $admin->namePrefix('admin.');
                    $admin->method('POST');

                    $admin->addRoute('/users', (new Route('admin/users'))->name('users'));
                    $admin->addRoute('/settings', 'admin/settings');
                });
            });

        $resolved = $group->resolve();
        $this->assertCount(3, $resolved);

        // Public route — has auth middleware only
        $this->assertSame('api/public', $resolved[0]['path']);
        $this->assertInstanceOf(Route::class, $resolved[0]['handler']);
        $this->assertCount(1, $resolved[0]['handler']->getMiddleware());

        // Admin users — has auth + admin middleware, name prefix, POST method
        $this->assertSame('api/admin/users', $resolved[1]['path']);
        $handler = $resolved[1]['handler'];
        $this->assertCount(2, $handler->getMiddleware());
        $this->assertSame('api.admin.users', $handler->getName());
        $this->assertSame('POST', $handler->getMethod());

        // Admin settings — string handler wrapped with middleware + method
        $this->assertSame('api/admin/settings', $resolved[2]['path']);
        $handler2 = $resolved[2]['handler'];
        $this->assertInstanceOf(Route::class, $handler2);
        $this->assertSame('POST', $handler2->getMethod());
    }

    public function testResolveMultipleTimesProducesSameResult(): void
    {
        $group = (new RouteGroup('/api'))->middleware($this->createMockMiddleware());
        $group->addRoute('/users', 'users');

        $first = $group->resolve();
        $second = $group->resolve();

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame($first[0]['path'], $second[0]['path']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private function createMockMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function handle(array $context, Closure $next): mixed
            {
                return $next($context);
            }
        };
    }
}
