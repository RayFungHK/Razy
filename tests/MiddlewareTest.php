<?php

/**
 * Tests for P2: HTTP Middleware Pipeline.
 *
 * Covers MiddlewareInterface, MiddlewarePipeline, Route::middleware(),
 * RouteDispatcher middleware registration, and pipeline integration.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\MiddlewareInterface;
use Razy\Distributor\MiddlewarePipeline;
use Razy\Distributor\RouteDispatcher;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\ModuleInfo;
use Razy\Route;
use RuntimeException;
use stdClass;

#[CoversClass(MiddlewarePipeline::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteDispatcher::class)]
class MiddlewareTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    //  MiddlewarePipeline — Core Pipeline Behavior
    // ═══════════════════════════════════════════════════════

    public function testPipelineIsEmptyByDefault(): void
    {
        $pipeline = new MiddlewarePipeline();
        $this->assertTrue($pipeline->isEmpty());
        $this->assertSame(0, $pipeline->count());
    }

    public function testPipeReturnsFluent(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->pipe(function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $this->assertSame($pipeline, $result);
    }

    public function testPipeAddsClosure(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $this->assertFalse($pipeline->isEmpty());
        $this->assertSame(1, $pipeline->count());
    }

    public function testPipeAddsMiddlewareInterface(): void
    {
        $mw = new class() implements MiddlewareInterface {
            public function handle(array $context, Closure $next): mixed
            {
                return $next($context);
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($mw);
        $this->assertSame(1, $pipeline->count());
    }

    public function testPipeManyAddsMultiple(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipeMany([
            function (array $ctx, Closure $next) {
                return $next($ctx);
            },
            function (array $ctx, Closure $next) {
                return $next($ctx);
            },
            function (array $ctx, Closure $next) {
                return $next($ctx);
            },
        ]);
        $this->assertSame(3, $pipeline->count());
    }

    public function testGetMiddlewareReturnsAll(): void
    {
        $mw1 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $mw2 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($mw1)->pipe($mw2);

        $all = $pipeline->getMiddleware();
        $this->assertCount(2, $all);
        $this->assertSame($mw1, $all[0]);
        $this->assertSame($mw2, $all[1]);
    }

    // ═══════════════════════════════════════════════════════
    //  MiddlewarePipeline — Execution
    // ═══════════════════════════════════════════════════════

    public function testProcessCallsCoreHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $called = false;

        $pipeline->process(['test' => true], function (array $ctx) use (&$called) {
            $called = true;
            return 'result';
        });

        $this->assertTrue($called, 'Core handler should be called');
    }

    public function testProcessPassesContextToCoreHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $receivedCtx = null;

        $pipeline->process(['key' => 'value'], function (array $ctx) use (&$receivedCtx) {
            $receivedCtx = $ctx;
        });

        $this->assertSame(['key' => 'value'], $receivedCtx);
    }

    public function testProcessReturnsCoreResult(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->process([], function (array $ctx) {
            return 42;
        });
        $this->assertSame(42, $result);
    }

    public function testProcessClosureMiddlewareWrapsHandler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $order = [];

        $pipeline->pipe(function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'mw1-before';
            $result = $next($ctx);
            $order[] = 'mw1-after';
            return $result;
        });

        $pipeline->process([], function (array $ctx) use (&$order) {
            $order[] = 'handler';
        });

        $this->assertSame(['mw1-before', 'handler', 'mw1-after'], $order);
    }

    public function testProcessInterfaceMiddlewareWrapsHandler(): void
    {
        $tracker = new stdClass();
        $tracker->order = [];
        $mw = new class($tracker) implements MiddlewareInterface {
            private stdClass $tracker;

            public function __construct(stdClass $tracker)
            {
                $this->tracker = $tracker;
            }

            public function handle(array $context, Closure $next): mixed
            {
                $this->tracker->order[] = 'interface-before';
                $result = $next($context);
                $this->tracker->order[] = 'interface-after';
                return $result;
            }
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($mw);

        $pipeline->process([], function (array $ctx) use ($tracker) {
            $tracker->order[] = 'handler';
        });

        $this->assertSame(['interface-before', 'handler', 'interface-after'], $tracker->order);
    }

    public function testProcessMultipleMiddlewareOnionOrder(): void
    {
        $pipeline = new MiddlewarePipeline();
        $order = [];

        $pipeline->pipe(function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'A-before';
            $result = $next($ctx);
            $order[] = 'A-after';
            return $result;
        });

        $pipeline->pipe(function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'B-before';
            $result = $next($ctx);
            $order[] = 'B-after';
            return $result;
        });

        $pipeline->pipe(function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'C-before';
            $result = $next($ctx);
            $order[] = 'C-after';
            return $result;
        });

        $pipeline->process([], function (array $ctx) use (&$order) {
            $order[] = 'handler';
        });

        $this->assertSame([
            'A-before', 'B-before', 'C-before',
            'handler',
            'C-after', 'B-after', 'A-after',
        ], $order);
    }

    public function testProcessMiddlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $handlerCalled = false;

        $pipeline->pipe(function (array $ctx, Closure $next) {
            // Short-circuit: don't call $next
            return 'blocked';
        });

        $result = $pipeline->process([], function (array $ctx) use (&$handlerCalled) {
            $handlerCalled = true;
            return 'should-not-reach';
        });

        $this->assertFalse($handlerCalled, 'Handler should NOT be called when middleware short-circuits');
        $this->assertSame('blocked', $result);
    }

    public function testProcessMiddlewareCanModifyContext(): void
    {
        $pipeline = new MiddlewarePipeline();
        $receivedCtx = null;

        $pipeline->pipe(function (array $ctx, Closure $next) {
            $ctx['injected'] = 'by-middleware';
            return $next($ctx);
        });

        $pipeline->process(['original' => true], function (array $ctx) use (&$receivedCtx) {
            $receivedCtx = $ctx;
        });

        $this->assertArrayHasKey('injected', $receivedCtx);
        $this->assertSame('by-middleware', $receivedCtx['injected']);
        $this->assertTrue($receivedCtx['original']);
    }

    public function testProcessMiddlewareCanTransformResult(): void
    {
        $pipeline = new MiddlewarePipeline();

        $pipeline->pipe(function (array $ctx, Closure $next) {
            $result = $next($ctx);
            return $result * 2;
        });

        $result = $pipeline->process([], function (array $ctx) {
            return 21;
        });

        $this->assertSame(42, $result);
    }

    public function testProcessMixedMiddlewareTypes(): void
    {
        $tracker = new stdClass();
        $tracker->order = [];
        $interfaceMw = new class($tracker) implements MiddlewareInterface {
            private stdClass $tracker;

            public function __construct(stdClass $tracker)
            {
                $this->tracker = $tracker;
            }

            public function handle(array $context, Closure $next): mixed
            {
                $this->tracker->order[] = 'interface';
                return $next($context);
            }
        };

        $closureMw = function (array $ctx, Closure $next) use ($tracker) {
            $tracker->order[] = 'closure';
            return $next($ctx);
        };

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($interfaceMw)->pipe($closureMw);

        $pipeline->process([], function (array $ctx) use ($tracker) {
            $tracker->order[] = 'handler';
        });

        $this->assertSame(['interface', 'closure', 'handler'], $tracker->order);
    }

    public function testProcessEmptyPipelineCallsHandlerDirectly(): void
    {
        $pipeline = new MiddlewarePipeline();
        $result = $pipeline->process(['data' => 1], function (array $ctx) {
            return $ctx['data'] + 1;
        });
        $this->assertSame(2, $result);
    }

    // ═══════════════════════════════════════════════════════
    //  Route — Middleware Attachment
    // ═══════════════════════════════════════════════════════

    public function testRouteHasNoMiddlewareByDefault(): void
    {
        $route = new Route('handler');
        $this->assertFalse($route->hasMiddleware());
        $this->assertSame([], $route->getMiddleware());
    }

    public function testRouteMiddlewareReturnsFluent(): void
    {
        $route = new Route('handler');
        $result = $route->middleware(function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $this->assertSame($route, $result);
    }

    public function testRouteMiddlewareAddsSingle(): void
    {
        $mw = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $route = new Route('handler');
        $route->middleware($mw);

        $this->assertTrue($route->hasMiddleware());
        $this->assertCount(1, $route->getMiddleware());
    }

    public function testRouteMiddlewareAddsMultipleVariadic(): void
    {
        $mw1 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $mw2 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };

        $route = new Route('handler');
        $route->middleware($mw1, $mw2);

        $this->assertCount(2, $route->getMiddleware());
    }

    public function testRouteMiddlewareAcceptsInterface(): void
    {
        $mw = new class() implements MiddlewareInterface {
            public function handle(array $context, Closure $next): mixed
            {
                return $next($context);
            }
        };

        $route = new Route('handler');
        $route->middleware($mw);
        $this->assertCount(1, $route->getMiddleware());
        $this->assertSame($mw, $route->getMiddleware()[0]);
    }

    public function testRouteMiddlewareChainsWithMethodAndContain(): void
    {
        $mw = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $route = new Route('handler');
        $route->method('POST')
              ->middleware($mw)
              ->contain(['key' => 'value']);

        $this->assertSame('POST', $route->getMethod());
        $this->assertTrue($route->hasMiddleware());
        $this->assertSame(['key' => 'value'], $route->getData());
    }

    public function testRouteMiddlewareAccumulates(): void
    {
        $route = new Route('handler');
        $mw1 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $mw2 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };

        $route->middleware($mw1);
        $route->middleware($mw2);

        $this->assertCount(2, $route->getMiddleware());
    }

    public function testGlobalMiddlewareEmptyByDefault(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertSame([], $dispatcher->getGlobalMiddleware());
    }

    public function testAddGlobalMiddlewareReturnsFluent(): void
    {
        $dispatcher = new RouteDispatcher();
        $result = $dispatcher->addGlobalMiddleware(function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $this->assertSame($dispatcher, $result);
    }

    public function testAddGlobalMiddlewareSingle(): void
    {
        $mw = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $dispatcher = new RouteDispatcher();
        $dispatcher->addGlobalMiddleware($mw);

        $global = $dispatcher->getGlobalMiddleware();
        $this->assertCount(1, $global);
        $this->assertSame($mw, $global[0]);
    }

    public function testAddGlobalMiddlewareMultipleVariadic(): void
    {
        $mw1 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $mw2 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };

        $dispatcher = new RouteDispatcher();
        $dispatcher->addGlobalMiddleware($mw1, $mw2);

        $this->assertCount(2, $dispatcher->getGlobalMiddleware());
    }

    public function testAddGlobalMiddlewareAccumulates(): void
    {
        $dispatcher = new RouteDispatcher();
        $dispatcher->addGlobalMiddleware(function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $dispatcher->addGlobalMiddleware(function (array $ctx, Closure $next) {
            return $next($ctx);
        });

        $this->assertCount(2, $dispatcher->getGlobalMiddleware());
    }

    // ═══════════════════════════════════════════════════════
    //  RouteDispatcher — Module Middleware Registration
    // ═══════════════════════════════════════════════════════

    public function testModuleMiddlewareEmptyByDefault(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertSame([], $dispatcher->getModuleMiddleware('vendor/test'));
    }

    public function testAddModuleMiddlewareReturnsFluent(): void
    {
        $dispatcher = new RouteDispatcher();
        $result = $dispatcher->addModuleMiddleware('vendor/test', function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $this->assertSame($dispatcher, $result);
    }

    public function testAddModuleMiddlewareStoresPerModule(): void
    {
        $mw1 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $mw2 = function (array $ctx, Closure $next) {
            return $next($ctx);
        };

        $dispatcher = new RouteDispatcher();
        $dispatcher->addModuleMiddleware('vendor/auth', $mw1);
        $dispatcher->addModuleMiddleware('vendor/api', $mw2);

        $this->assertCount(1, $dispatcher->getModuleMiddleware('vendor/auth'));
        $this->assertCount(1, $dispatcher->getModuleMiddleware('vendor/api'));
        $this->assertSame([], $dispatcher->getModuleMiddleware('vendor/other'));
    }

    public function testAddModuleMiddlewareAccumulates(): void
    {
        $dispatcher = new RouteDispatcher();
        $dispatcher->addModuleMiddleware('vendor/test', function (array $ctx, Closure $next) {
            return $next($ctx);
        });
        $dispatcher->addModuleMiddleware('vendor/test', function (array $ctx, Closure $next) {
            return $next($ctx);
        });

        $this->assertCount(2, $dispatcher->getModuleMiddleware('vendor/test'));
    }

    // ═══════════════════════════════════════════════════════
    //  MiddlewarePipeline — Edge Cases
    // ═══════════════════════════════════════════════════════

    public function testMiddlewareExceptionPropagates(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(function (array $ctx, Closure $next) {
            throw new RuntimeException('Auth failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Auth failed');

        $pipeline->process([], function (array $ctx) {
        });
    }

    public function testDeepPipelineNesting(): void
    {
        $pipeline = new MiddlewarePipeline();
        $depth = 0;
        $maxDepth = 0;

        for ($i = 0; $i < 20; $i++) {
            $pipeline->pipe(function (array $ctx, Closure $next) use (&$depth, &$maxDepth) {
                $depth++;
                $maxDepth = \max($maxDepth, $depth);
                $result = $next($ctx);
                $depth--;
                return $result;
            });
        }

        $pipeline->process([], function (array $ctx) use (&$depth, &$maxDepth) {
            $depth++;
            $maxDepth = \max($maxDepth, $depth);
            $depth--;
        });

        $this->assertSame(21, $maxDepth, 'Should nest to depth 20 middleware + 1 handler');
    }

    public function testPipelineContextImmutability(): void
    {
        // Ensure original context is not mutated by middleware modifications
        $pipeline = new MiddlewarePipeline();
        $originalCtx = ['key' => 'original'];

        $pipeline->pipe(function (array $ctx, Closure $next) {
            $ctx['key'] = 'modified';
            $ctx['new'] = 'added';
            return $next($ctx);
        });

        $receivedCtx = null;
        $pipeline->process($originalCtx, function (array $ctx) use (&$receivedCtx) {
            $receivedCtx = $ctx;
        });

        // Array is passed by value — original should be untouched
        $this->assertSame('original', $originalCtx['key']);
        $this->assertSame('modified', $receivedCtx['key']);
    }

    public function testPipelineSecondMiddlewareNotCalledAfterShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $secondCalled = false;

        $pipeline->pipe(function (array $ctx, Closure $next) {
            return 'stopped'; // Short-circuit
        });

        $pipeline->pipe(function (array $ctx, Closure $next) use (&$secondCalled) {
            $secondCalled = true;
            return $next($ctx);
        });

        $pipeline->process([], function (array $ctx) {
        });

        $this->assertFalse($secondCalled, 'Second middleware should not be called after short-circuit');
    }

    // ═══════════════════════════════════════════════════════
    //  Integration: Route + RouteDispatcher middleware storage
    // ═══════════════════════════════════════════════════════

    public function testSetRouteWithRouteObjectMiddleware(): void
    {
        $mw = function (array $ctx, Closure $next) {
            return $next($ctx);
        };
        $routeObj = (new Route('handler'))->middleware($mw);

        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();
        $dispatcher->setRoute($module, '/api', $routeObj);

        $routes = $dispatcher->getRoutes();
        $entry = \reset($routes);

        // The path should be the Route object (middleware is on it)
        $this->assertInstanceOf(Route::class, $entry['path']);
        $this->assertTrue($entry['path']->hasMiddleware());
    }

    public function testMiddlewareLayerOrder(): void
    {
        // Verify the expected order: global → module → route
        $order = [];

        $globalMw = function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'global';
            return $next($ctx);
        };

        $moduleMw = function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'module';
            return $next($ctx);
        };

        $routeMw = function (array $ctx, Closure $next) use (&$order) {
            $order[] = 'route';
            return $next($ctx);
        };

        // Simulate what RouteDispatcher::matchRoute does
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipeMany([$globalMw]);   // Global first
        $pipeline->pipeMany([$moduleMw]);   // Module second
        $pipeline->pipeMany([$routeMw]);    // Route third

        $pipeline->process([], function (array $ctx) use (&$order) {
            $order[] = 'handler';
        });

        $this->assertSame(['global', 'module', 'route', 'handler'], $order);
    }

    // ═══════════════════════════════════════════════════════
    //  Backward Compatibility
    // ═══════════════════════════════════════════════════════

    public function testRoutesWithoutMiddlewareUnaffected(): void
    {
        $dispatcher = new RouteDispatcher();
        $module = $this->createModuleMock();

        // Register route without any middleware (old-style)
        $dispatcher->setRoute($module, '/legacy', '/handler');

        $routes = $dispatcher->getRoutes();
        $entry = \reset($routes);

        // Should work fine — path is a string, no middleware
        $this->assertIsString($entry['path']);
        $this->assertSame('/handler', $entry['path']);
    }

    public function testEmptyGlobalMiddlewareDoesNotAffectRouting(): void
    {
        $dispatcher = new RouteDispatcher();
        $this->assertSame([], $dispatcher->getGlobalMiddleware());
        // No exceptions thrown when no middleware is registered
    }

    // ═══════════════════════════════════════════════════════
    //  RouteDispatcher — Global Middleware Registration
    // ═══════════════════════════════════════════════════════

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
