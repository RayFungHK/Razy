<?php

/**
 * Comprehensive tests for #11: Middleware Groups.
 *
 * Covers MiddlewareGroupRegistry — define, resolve, resolveMany,
 * appendTo, prependTo, remove, has, count, getGroupNames, and all
 * edge cases for practical integration testing.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Contract\MiddlewareInterface;
use Razy\Distributor\MiddlewareGroupRegistry;

#[CoversClass(MiddlewareGroupRegistry::class)]
class MiddlewareGroupRegistryTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    //  11. Group Name Variants
    // ═══════════════════════════════════════════════════════

    public static function groupNameProvider(): array
    {
        return [
            'simple' => ['web'],
            'hyphenated' => ['auth-guard'],
            'dotted' => ['api.v2'],
            'numeric suffix' => ['group123'],
            'with underscore' => ['my_group'],
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  1. Initial State
    // ═══════════════════════════════════════════════════════

    public function testRegistryIsEmptyByDefault(): void
    {
        $reg = new MiddlewareGroupRegistry();

        $this->assertSame(0, $reg->count());
        $this->assertSame([], $reg->getGroupNames());
        $this->assertFalse($reg->has('web'));
    }

    // ═══════════════════════════════════════════════════════
    //  2. define()
    // ═══════════════════════════════════════════════════════

    public function testDefineCreatesGroupAndReturnsThis(): void
    {
        $mw = fn (array $ctx, Closure $next) => $next($ctx);
        $reg = new MiddlewareGroupRegistry();
        $ret = $reg->define('web', [$mw]);

        $this->assertSame($reg, $ret);
        $this->assertTrue($reg->has('web'));
        $this->assertSame(1, $reg->count());
        $this->assertSame(['web'], $reg->getGroupNames());
    }

    public function testDefineOverwritesExistingGroup(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw3 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1, $mw2]);
        $this->assertCount(2, $reg->resolve('web'));

        $reg->define('web', [$mw3]);
        $this->assertCount(1, $reg->resolve('web'));
        $this->assertSame($mw3, $reg->resolve('web')[0]);
    }

    public function testDefineMultipleDistinctGroups(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [fn (array $ctx, Closure $next) => $next($ctx)]);
        $reg->define('api', [fn (array $ctx, Closure $next) => $next($ctx)]);
        $reg->define('admin', [fn (array $ctx, Closure $next) => $next($ctx)]);

        $this->assertSame(3, $reg->count());
        $this->assertEqualsCanonicalizing(['web', 'api', 'admin'], $reg->getGroupNames());
    }

    public function testDefineWithMiddlewareInterfaceInstances(): void
    {
        $log = [];
        $mw = $this->makeInterfaceMw('auth', $log);
        $reg = new MiddlewareGroupRegistry();
        $reg->define('secure', [$mw]);

        $this->assertSame([$mw], $reg->resolve('secure'));
    }

    public function testDefineWithMixedClosuresAndInterfaces(): void
    {
        $log = [];
        $closure = $this->makeMw('logger', $log);
        $iface = $this->makeInterfaceMw('auth', $log);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('mixed', [$closure, $iface]);

        $resolved = $reg->resolve('mixed');
        $this->assertCount(2, $resolved);
        $this->assertSame($closure, $resolved[0]);
        $this->assertSame($iface, $resolved[1]);
    }

    public function testDefineEmptyGroup(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $reg->define('empty', []);

        $this->assertTrue($reg->has('empty'));
        $this->assertSame([], $reg->resolve('empty'));
    }

    // ═══════════════════════════════════════════════════════
    //  3. resolve()
    // ═══════════════════════════════════════════════════════

    public function testResolveReturnsMiddlewareInOrder(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw3 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1, $mw2, $mw3]);

        $resolved = $reg->resolve('web');
        $this->assertSame([$mw1, $mw2, $mw3], $resolved);
    }

    public function testResolveThrowsForUndefinedGroup(): void
    {
        $reg = new MiddlewareGroupRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Middleware group 'unknown' is not defined.");
        $reg->resolve('unknown');
    }

    // ═══════════════════════════════════════════════════════
    //  4. resolveMany()
    // ═══════════════════════════════════════════════════════

    public function testResolveManyWithGroupNamesOnly(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1]);
        $reg->define('api', [$mw2]);

        $resolved = $reg->resolveMany(['web', 'api']);
        $this->assertSame([$mw1, $mw2], $resolved);
    }

    public function testResolveManyWithConcreteInstancesOnly(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $this->assertSame([$mw1, $mw2], $reg->resolveMany([$mw1, $mw2]));
    }

    public function testResolveManyMixGroupNamesAndConcreteInstances(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);
        $inline = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1, $mw2]);

        $resolved = $reg->resolveMany(['web', $inline]);
        $this->assertSame([$mw1, $mw2, $inline], $resolved);
    }

    public function testResolveManyThrowsForUndefinedGroupName(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $this->expectException(InvalidArgumentException::class);
        $reg->resolveMany(['nonexistent']);
    }

    public function testResolveManyEmptyArray(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $this->assertSame([], $reg->resolveMany([]));
    }

    public function testResolveManyDuplicateGroupYieldsDuplicates(): void
    {
        $mw = fn (array $ctx, Closure $next) => $next($ctx);
        $reg = new MiddlewareGroupRegistry();
        $reg->define('cors', [$mw]);

        $resolved = $reg->resolveMany(['cors', 'cors']);
        $this->assertCount(2, $resolved);
    }

    public function testResolveManyPreservesOrderAcrossGroups(): void
    {
        $log = [];
        $mwA = $this->makeMw('A', $log);
        $mwB = $this->makeMw('B', $log);
        $mwC = $this->makeMw('C', $log);
        $mwD = $this->makeMw('D', $log);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('first', [$mwA, $mwB]);
        $reg->define('second', [$mwC, $mwD]);

        $this->assertSame([$mwA, $mwB, $mwC, $mwD], $reg->resolveMany(['first', 'second']));
    }

    // ═══════════════════════════════════════════════════════
    //  5. appendTo()
    // ═══════════════════════════════════════════════════════

    public function testAppendToExistingGroupAddsAtEnd(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1]);
        $ret = $reg->appendTo('web', [$mw2]);

        $this->assertSame($reg, $ret);
        $this->assertSame([$mw1, $mw2], $reg->resolve('web'));
    }

    public function testAppendToCreatesGroupIfNotExists(): void
    {
        $mw = fn (array $ctx, Closure $next) => $next($ctx);
        $reg = new MiddlewareGroupRegistry();

        $reg->appendTo('fresh', [$mw]);
        $this->assertTrue($reg->has('fresh'));
        $this->assertSame([$mw], $reg->resolve('fresh'));
    }

    public function testAppendToMultipleTimes(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw3 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('pipe', [$mw1]);
        $reg->appendTo('pipe', [$mw2]);
        $reg->appendTo('pipe', [$mw3]);

        $this->assertSame([$mw1, $mw2, $mw3], $reg->resolve('pipe'));
    }

    // ═══════════════════════════════════════════════════════
    //  6. prependTo()
    // ═══════════════════════════════════════════════════════

    public function testPrependToExistingGroupAddsAtFront(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1]);
        $ret = $reg->prependTo('web', [$mw2]);

        $this->assertSame($reg, $ret);
        $this->assertSame([$mw2, $mw1], $reg->resolve('web'));
    }

    public function testPrependToCreatesGroupIfNotExists(): void
    {
        $mw = fn (array $ctx, Closure $next) => $next($ctx);
        $reg = new MiddlewareGroupRegistry();

        $reg->prependTo('new-group', [$mw]);
        $this->assertTrue($reg->has('new-group'));
    }

    public function testPrependToPreservesInternalOrder(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw3 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('pipe', [$mw1]);
        $reg->prependTo('pipe', [$mw2, $mw3]);

        $this->assertSame([$mw2, $mw3, $mw1], $reg->resolve('pipe'));
    }

    // ═══════════════════════════════════════════════════════
    //  7. remove()
    // ═══════════════════════════════════════════════════════

    public function testRemoveDeletesGroup(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [fn (array $ctx, Closure $next) => $next($ctx)]);

        $ret = $reg->remove('web');
        $this->assertSame($reg, $ret);
        $this->assertFalse($reg->has('web'));
        $this->assertSame(0, $reg->count());
    }

    public function testRemoveNonexistentGroupIsSafe(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $reg->remove('nope');
        $this->assertSame(0, $reg->count());
    }

    public function testRemoveThenRedefine(): void
    {
        $mw1 = fn (array $ctx, Closure $next) => $next($ctx);
        $mw2 = fn (array $ctx, Closure $next) => $next($ctx);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$mw1]);
        $reg->remove('web');
        $reg->define('web', [$mw2]);

        $this->assertSame([$mw2], $reg->resolve('web'));
    }

    public function testRemoveOnlyAffectsTargetGroup(): void
    {
        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [fn (array $ctx, Closure $next) => $next($ctx)]);
        $reg->define('api', [fn (array $ctx, Closure $next) => $next($ctx)]);
        $reg->remove('web');

        $this->assertFalse($reg->has('web'));
        $this->assertTrue($reg->has('api'));
        $this->assertSame(1, $reg->count());
    }

    // ═══════════════════════════════════════════════════════
    //  8. Pipeline Integration
    // ═══════════════════════════════════════════════════════

    public function testClosureMiddlewareExecutionOrder(): void
    {
        $log = [];
        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [
            $this->makeMw('auth', $log),
            $this->makeMw('cors', $log),
        ]);
        $reg->appendTo('web', [$this->makeMw('logging', $log)]);

        $result = $this->runPipeline($reg->resolve('web'));
        $this->assertSame('final', $result);
        $this->assertSame(['auth', 'cors', 'logging'], $log);
    }

    public function testInterfaceMiddlewareExecutionInPipeline(): void
    {
        $log = [];
        $reg = new MiddlewareGroupRegistry();
        $reg->define('api', [
            $this->makeInterfaceMw('guard', $log),
            $this->makeMw('transform', $log),
        ]);

        $result = $this->runPipeline($reg->resolve('api'));
        $this->assertSame('final', $result);
        $this->assertSame(['guard', 'transform'], $log);
    }

    public function testShortCircuitMiddleware(): void
    {
        $log = [];
        $auth = function (array $ctx, Closure $next) use (&$log) {
            $log[] = 'auth';

            return ($ctx['authorized'] ?? false) ? $next($ctx) : 'denied';
        };

        $reg = new MiddlewareGroupRegistry();
        $reg->define('secure', [$auth, $this->makeMw('handler', $log)]);

        $resolved = $reg->resolve('secure');

        // Build manual pipeline (needed for short-circuit test)
        $final = fn (array $ctx) => 'ok';
        $pipe = \array_reduce(
            \array_reverse($resolved),
            fn (Closure $next, $mw) => fn (array $ctx) => $mw($ctx, $next),
            $final,
        );

        $this->assertSame('denied', $pipe(['authorized' => false]));
        $this->assertSame(['auth'], $log);

        $log = [];
        $this->assertSame('ok', $pipe(['authorized' => true]));
        $this->assertSame(['auth', 'handler'], $log);
    }

    // ═══════════════════════════════════════════════════════
    //  9. Complex append/prepend chain
    // ═══════════════════════════════════════════════════════

    public function testComplexAppendPrependChain(): void
    {
        $log = [];
        $auth = $this->makeMw('auth', $log);
        $cors = $this->makeMw('cors', $log);
        $session = $this->makeMw('session', $log);
        $logger = $this->makeMw('logger', $log);
        $csrf = $this->makeMw('csrf', $log);

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$auth, $cors]);
        $reg->appendTo('web', [$session]);
        $reg->prependTo('web', [$logger]);
        $reg->appendTo('web', [$csrf]);

        $resolved = $reg->resolve('web');
        $this->assertSame([$logger, $auth, $cors, $session, $csrf], $resolved);
    }

    // ═══════════════════════════════════════════════════════
    //  10. Large Group
    // ═══════════════════════════════════════════════════════

    public function testLargeGroupPreservesAllMiddleware(): void
    {
        $mws = [];
        for ($i = 0; $i < 50; $i++) {
            $mws[] = fn (array $ctx, Closure $next) => $next($ctx);
        }

        $reg = new MiddlewareGroupRegistry();
        $reg->define('large', $mws);

        $this->assertCount(50, $reg->resolve('large'));
    }

    #[DataProvider('groupNameProvider')]
    public function testGroupNamesOfVariousFormats(string $name): void
    {
        $mw = fn (array $ctx, Closure $next) => $next($ctx);
        $reg = new MiddlewareGroupRegistry();
        $reg->define($name, [$mw]);

        $this->assertTrue($reg->has($name));
        $this->assertCount(1, $reg->resolve($name));
    }

    // ═══════════════════════════════════════════════════════
    //  12. Context Modification in Middleware
    // ═══════════════════════════════════════════════════════

    public function testMiddlewareCanModifyContext(): void
    {
        $addUserId = function (array $ctx, Closure $next) {
            $ctx['user_id'] = 42;

            return $next($ctx);
        };

        $capturedCtx = null;
        $capture = function (array $ctx, Closure $next) use (&$capturedCtx) {
            $capturedCtx = $ctx;

            return $next($ctx);
        };

        $reg = new MiddlewareGroupRegistry();
        $reg->define('web', [$addUserId, $capture]);

        $final = fn (array $ctx) => $ctx['user_id'] ?? null;
        $pipe = \array_reduce(
            \array_reverse($reg->resolve('web')),
            fn (Closure $next, $mw) => fn (array $ctx) => $mw($ctx, $next),
            $final,
        );

        $result = $pipe([]);
        $this->assertSame(42, $result);
        $this->assertSame(42, $capturedCtx['user_id']);
    }
    // ─────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────

    /** Create a tracking closure-middleware that records its label. */
    private function makeMw(string $label, array &$log): Closure
    {
        return function (array $ctx, Closure $next) use ($label, &$log) {
            $log[] = $label;

            return $next($ctx);
        };
    }

    /** Create a tracking MiddlewareInterface instance. */
    private function makeInterfaceMw(string $label, array &$log): MiddlewareInterface
    {
        return new class($label, $log) implements MiddlewareInterface {
            public function __construct(
                private readonly string $label,
                private array &$log,
            ) {
            }

            public function handle(array $context, Closure $next): mixed
            {
                $this->log[] = $this->label;

                return $next($context);
            }
        };
    }

    /** Build & run a simple chain pipeline from resolved middleware list. */
    private function runPipeline(array $middleware, array $ctx = []): mixed
    {
        $handler = fn (array $c) => 'final';
        $pipe = \array_reduce(
            \array_reverse($middleware),
            function (Closure $next, $mw) {
                return function (array $ctx) use ($next, $mw) {
                    if ($mw instanceof MiddlewareInterface) {
                        return $mw->handle($ctx, $next);
                    }

                    return $mw($ctx, $next);
                };
            },
            $handler,
        );

        return $pipe($ctx);
    }
}
