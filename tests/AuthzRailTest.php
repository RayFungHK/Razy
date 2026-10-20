<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Authz\Denial;
use Razy\Authz\Gate;
use Razy\Authz\Middleware;
use Razy\Compiler\BootCompiler;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Route;
use ReflectionMethod;
use RuntimeException;

/**
 * AUTHZ-RAIL execution pins (RZ-014): the ability registry, the always-
 * mounted gate middleware, the fail-closed posture, the 403 answer
 * shapes, and the compiler round-trip of a can()-declaring route.
 *
 * The Distributor's mount wiring itself boots a dist on disk and is
 * pinned separately by the wiring assertions at the bottom (source-shape
 * pins, the CompileOnDeployTest precedent for wiring-not-hinted).
 */
#[CoversClass(Gate::class)]
#[CoversClass(Middleware::class)]
#[CoversClass(Denial::class)]
#[CoversClass(Route::class)]
class AuthzRailTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            if (!\is_dir($dir)) {
                continue;
            }

            foreach ((array) \glob($dir . '/*') as $item) {
                if (\is_dir($item)) {
                    @\rmdir($item);
                } else {
                    @\unlink($item);
                }
            }
            @\rmdir($dir);
        }
        $this->dirs = [];
        parent::tearDown();
    }

    // ── Gate ───────────────────────────────────────────────────────────────

    public function testGateDefineHasAndCheck(): void
    {
        $gate = new Gate();
        $gate->define('user.manage', fn (array $c): bool => ($c['role'] ?? '') === 'admin');

        self::assertTrue($gate->has('user.manage'));
        self::assertSame(['user.manage'], $gate->abilities());
        self::assertTrue($gate->check('user.manage', ['role' => 'admin']));
        self::assertFalse($gate->check('user.manage', ['role' => 'guest']), 'a defined ability answers false, never null');
    }

    public function testGateUndefinedAbilityIsNullNotFalse(): void
    {
        $gate = new Gate();

        self::assertNull($gate->check('ghost.ability', []), 'undefined is a distinct signal — the middleware decides its HTTP consequence');
    }

    public function testGateThrowingCheckerDenies(): void
    {
        $gate = new Gate();
        $gate->define('boom', function (): bool {
            throw new RuntimeException('guard fell over');
        });

        self::assertFalse($gate->check('boom', []), 'a throwing predicate is a broken guard — fail closed, never a green light');
    }

    public function testGateNonBooleanTruthIsDenied(): void
    {
        $gate = new Gate();
        $gate->define('sneaky', fn (): mixed => 'yes');

        self::assertFalse($gate->check('sneaky', []), 'green means exactly true');
    }

    public function testGateEmptyAbilityNameRejectedAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Gate())->define('   ', fn (): bool => true);
    }

    // ── Route::can ─────────────────────────────────────────────────────────

    public function testRouteCanDeclaresTrimsAndReadsBack(): void
    {
        $route = new Route('closures/admin');

        self::assertFalse($route->hasAuthzAbility());
        self::assertNull($route->getAuthzAbility());

        self::assertSame($route, $route->can('  user.manage  '));
        self::assertTrue($route->hasAuthzAbility());
        self::assertSame('user.manage', $route->getAuthzAbility());
    }

    public function testRouteCanWithoutNameRejectedAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Route('closures/admin'))->can(' ');
    }

    // ── Middleware ─────────────────────────────────────────────────────────

    public function testMiddlewarePassesUndeclaredRoutesThrough(): void
    {
        $mw = new Middleware(new Gate(), $this->recordingDenial($seen));

        $result = $mw->handle(['route' => '/x/'], fn (array $c) => 'handler ran');

        self::assertSame('handler ran', $result);
        self::assertNull($seen, 'a route without can() must never meet the denial');
    }

    public function testMiddlewarePassesWhenAbilitySaysYes(): void
    {
        $gate = new Gate();
        $gate->define('user.manage', fn (array $c): bool => ($c['user'] ?? null) === 'boss');

        $mw = new Middleware($gate, $this->recordingDenial($seen));

        $result = $mw->handle(['authz' => 'user.manage', 'user' => 'boss'], fn () => 'handler ran');

        self::assertSame('handler ran', $result);
        self::assertNull($seen);
    }

    public function testMiddlewareDeniesAndNamesDefinednessToTheDenial(): void
    {
        $gate = new Gate();
        $gate->define('user.manage', fn (): bool => false);

        $mw = new Middleware($gate, $this->recordingDenial($seen));

        $result = $mw->handle(['authz' => 'user.manage'], fn () => 'handler ran');

        self::assertNull($result, 'the denial owns the answer; it returns null per middleware contract');
        self::assertSame('user.manage', $seen['ability']);
        self::assertFalse($seen['undefined'], 'a defined-but-false predicate denies as defined');

        $mw2 = new Middleware($gate, $this->recordingDenial($seen2));
        $mw2->handle(['authz' => 'never.defined'], fn () => 'handler ran');

        self::assertSame('never.defined', $seen2['ability']);
        self::assertTrue($seen2['undefined'], 'an undefined ability must fail CLOSED and say so honestly');
    }

    // ── Denial (403 shapes) ────────────────────────────────────────────────

    public function testDenialAnswersXhrWithJsonEnvelope(): void
    {
        $deny = new Denial();
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        try {
            \ob_start();
            $ret = $deny(['module' => 'test/mod', 'route' => '/admin'], 'user.manage', false);
            $body = (string) \ob_get_clean();
        } finally {
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        }

        self::assertNull($ret);
        $payload = \json_decode($body, true);
        self::assertIsArray($payload, 'XHR gets the JSON dialect (CsrfRejection posture)');
        self::assertSame('authz-denied', $payload['error']);
        self::assertSame('user.manage', $payload['ability']);
        self::assertStringContainsString('refused this request', $payload['message']);
    }

    public function testDenialLogsMisconfigurationNotToTheBrowser(): void
    {
        // RZ-06: the page names no internals; the operator gets the detail.
        $deny = new Denial();
        $log = \tempnam(\sys_get_temp_dir(), 'razy_authz_log');
        $previous = \ini_set('error_log', (string) $log);

        try {
            \ob_start();
            $deny(['module' => 'test/mod', 'route' => '/admin'], 'ghost.ability', true);
            $body = (string) \ob_get_clean();
            $logged = (string) \file_get_contents((string) $log);
        } finally {
            \ini_set('error_log', (string) $previous);
            @\unlink((string) $log);
        }

        self::assertStringContainsString('403', $body);
        self::assertStringNotContainsString('ghost.ability', $body);
        self::assertStringContainsString('ghost.ability', $logged);
        self::assertStringContainsString('fail-closed', $logged, 'the log says the guard is missing, not that the user is wrong');
    }

    public function testDenialAnnouncesAuthzFailedThroughTheModuleEmitter(): void
    {
        $announced = null;

        $fakeModule = new class($announced) {
            /** @var mixed */
            private $announced;

            public function __construct(&$announced)
            {
                $this->announced = &$announced;
            }

            public function createEmitter(string $name): object
            {
                return new class($name, $this->announced) {
                    private string $name;

                    /** @var mixed */
                    private $announced;

                    public function __construct(string $name, &$announced)
                    {
                        $this->name = $name;
                        $this->announced = &$announced;
                    }

                    public function resolve(array $payload = []): void
                    {
                        $this->announced = [$this->name, $payload];
                    }
                };
            }
        };

        $deny = new Denial(fn (string $code) => $code === 'test/mod' ? $fakeModule : null);

        \ob_start();
        $deny(['module' => 'test/mod', 'route' => '/admin'], 'user.manage', false);
        \ob_end_clean();

        self::assertNotNull($announced, 'refusals must be auditable via event without anyone subscribing to output');
        self::assertSame('authz.failed', $announced[0]);
        self::assertSame('user.manage', $announced[1]['ability']);
    }

    // ── Compiler round-trip ────────────────────────────────────────────────

    public function testCanSurvivesDumpAndReplay(): void
    {
        $route = (new Route('closures/admin'))->can('user.manage');

        $dump = new ReflectionMethod(BootCompiler::class, 'dumpRoute');
        $dump->setAccessible(true);
        $refusals = [];
        /** @var array<string, mixed> $spec */
        $spec = $dump->invoke(null, $route, 'GET:/admin/', $refusals);

        self::assertIsArray($spec);
        self::assertSame('user.manage', $spec['authz'], 'the declaration is serialized, not re-derived');

        $dispatcher = new RouteDispatcher();
        $dispatcher->loadCompiled([
            'GET:/admin/' => [
                'module' => 'test/TestModule',
                'module_code' => 'test/TestModule',
                'path' => ['__route' => $spec + ['data' => []]],
                'route' => '/admin/',
                'route_path' => '/admin/',
                'type' => 'standard',
                'method' => 'GET',
                'compiled_regex' => '#^admin$#',
                'is_redirect' => false,
                'redirect_absolute' => false,
                'redirect_target' => '',
                'tidied_path' => 'admin',
                'tidied_route' => '/admin/',
                'ready_gate' => null,
            ],
        ], ['test/TestModule' => $this->createModule()]);

        $rebuilt = $dispatcher->getRoutes()['GET:/admin/']['path'];
        self::assertInstanceOf(Route::class, $rebuilt);
        self::assertSame('user.manage', $rebuilt->getAuthzAbility(), 'compiled replay rebuilds the gate declaration (zero compiled/legacy drift)');
    }

    // ── Wiring pins (source-shape, CompileOnDeployTest precedent) ─────────

    public function testRailIsWiredNotHinted(): void
    {
        $dist = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        self::assertStringContainsString('new AuthzMiddleware(', $dist, 'the gate is permanently mounted at boot');
        self::assertStringContainsString('$container->instance(AuthzGate::class, $this->authzGate)', $dist, 'the gate is resolvable where containers are');

        $dispatcher = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor/RouteDispatcher.php');
        self::assertStringContainsString("\$this->routedInfo['authz'] = (string) \$data['path']->getAuthzAbility();", $dispatcher, 'the ability travels as routed context, object-free');
        self::assertStringContainsString("\$route->can(\$spec['authz'])", $dispatcher, 'compiled replay re-declares the ability');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array{ability: string, undefined: bool}|null $seen captured by reference
     */
    private function recordingDenial(?array &$seen): Closure
    {
        $seen = null;

        return (new class($seen) extends Denial {
            private ?array $seen;

            public function __construct(&$seen)
            {
                $this->seen = &$seen;
            }

            public function __invoke(array $context, string $ability, bool $undefined = false): null
            {
                $this->seen = ['ability' => $ability, 'undefined' => $undefined];

                return null;
            }
        })(...);
    }

    private function createModule(): \Razy\Module
    {
        $mock = $this->createMock(Distributor::class);
        $mock->method('isStrict')->willReturn(false);
        $mock->method('getCode')->willReturn('test-dist');
        $mock->method('getSiteURL')->willReturn('/site/');
        $mock->method('getContainer')->willReturn(null);
        $mock->method('getRouter')->willReturn($this->createMock(RouteDispatcher::class));
        $mock->method('getRegistry')->willReturn($this->createMock(ModuleRegistry::class));
        $mock->method('getPrerequisites')->willReturn($this->createMock(PrerequisiteResolver::class));

        $dir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_authz_' . \uniqid();
        \mkdir($dir . '/default', 0o777, true);
        \file_put_contents($dir . '/default/package.php', '<?php return ["alias" => "authztest"];');
        $this->dirs[] = $dir;

        return new \Razy\Module($mock, $dir, ['module_code' => 'test/TestModule', 'author' => 'Test Author']);
    }
}
