<?php

/**
 * This file is part of Razy.
 *
 * RZ-06: request-scoped principal, 401 (sign in) vs 403 (refused), and a
 * pluggable denial renderer. The default pages no longer name module or
 * route internals.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Authz\Denial;
use Razy\Authz\Gate;
use Razy\Authz\Identity;
use Razy\Authz\Middleware;
use Razy\Authz\Principal;
use Razy\Authz\Refusal;
use Razy\Exception\ConfigurationException;
use RuntimeException;

#[CoversClass(Identity::class)]
#[CoversClass(Principal::class)]
#[CoversClass(Refusal::class)]
#[CoversClass(Middleware::class)]
#[CoversClass(Denial::class)]
class AuthzIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    // ── Principal / Identity ──────────────────────────────────

    public function testPrincipalCarriesIdAndAttributes(): void
    {
        $p = new Principal('42', ['tenant' => 't1', 'roles' => ['admin']]);
        $this->assertSame('42', $p->id);
        $this->assertSame('t1', $p->get('tenant'));
        $this->assertSame('fallback', $p->get('missing', 'fallback'));
    }

    public function testIdentityResolvesOncePerRequest(): void
    {
        $calls = 0;
        $identity = new Identity();
        $identity->setResolver(function (array $context) use (&$calls): ?Principal {
            $calls++;

            return new Principal('u1');
        }, 'vendor/auth');

        $this->assertSame('u1', $identity->principal()?->id);
        $this->assertSame('u1', $identity->principal()?->id);
        $this->assertSame(1, $calls, 'one lookup per request');

        $identity->reset();
        $identity->principal();
        $this->assertSame(2, $calls, 'a new request resolves again');
    }

    public function testANewRequestNeverSeesTheLastPrincipalEvenBeforeTheMiddleware(): void
    {
        // Worker mode: __onEntry, unmatched routes and 404 handlers run before
        // (or without) the Authz middleware's reset().
        $who = 'alice';
        $identity = new Identity();
        $identity->setResolver(static function () use (&$who): Principal {
            return new Principal($who);
        }, 'vendor/auth');
        $previous = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        try {
            $_SERVER['REQUEST_TIME_FLOAT'] = 1000.0;
            $this->assertSame('alice', $identity->principal()?->id);
            $who = 'bob';
            $_SERVER['REQUEST_TIME_FLOAT'] = 1001.0;
            $this->assertSame('bob', $identity->principal()?->id);
        } finally {
            $_SERVER['REQUEST_TIME_FLOAT'] = $previous;
        }
    }

    public function testNullPrincipalIsAlsoCached(): void
    {
        $calls = 0;
        $identity = new Identity();
        $identity->setResolver(function () use (&$calls): ?Principal {
            $calls++;

            return null;
        }, 'vendor/auth');
        $identity->principal();
        $identity->principal();
        $this->assertSame(1, $calls);
    }

    public function testThrowingResolverMeansSignedOut(): void
    {
        $identity = new Identity();
        $identity->setResolver(static fn (): ?Principal => throw new RuntimeException('db down'), 'vendor/auth');
        $this->assertNull($identity->principal(), 'fail closed: no principal, never a guessed one');
    }

    public function testOnlyOneModuleOwnsTheResolver(): void
    {
        $identity = new Identity();
        $identity->setResolver(static fn (): ?Principal => null, 'vendor/auth');
        $identity->setResolver(static fn (): ?Principal => null, 'vendor/auth');
        $this->expectException(LogicException::class);
        $identity->setResolver(static fn (): ?Principal => null, 'vendor/other');
    }

    public function testNoResolverMeansNoPrincipal(): void
    {
        $identity = new Identity();
        $this->assertFalse($identity->hasResolver());
        $this->assertNull($identity->principal());
    }

    // ── Middleware: 401 vs 403 ────────────────────────────────

    public function testSignedOutOnAnAbilityRouteIs401NotTheAbility(): void
    {
        $gate = new Gate();
        $asked = false;
        $gate->define('chat.use', function () use (&$asked): bool {
            $asked = true;

            return true;
        });
        $identity = $this->identity(null);
        $mw = new Middleware($gate, $this->recording($seen), $identity);

        $this->assertNull($mw->handle(['authz' => 'chat.use'], fn () => 'ran'));
        $this->assertTrue($seen['unauthenticated']);
        $this->assertFalse($asked, 'the ability is never asked about a signed-out request');
    }

    public function testSignedInButRefusedIs403(): void
    {
        $gate = new Gate();
        $gate->define('admin.panel', static fn (array $c): bool => ($c['principal']?->get('role')) === 'admin');
        $mw = new Middleware($gate, $this->recording($seen), $this->identity(new Principal('7', ['role' => 'user'])));

        $mw->handle(['authz' => 'admin.panel'], fn () => 'ran');
        $this->assertFalse($seen['unauthenticated']);
        $this->assertFalse($seen['undefined']);
    }

    public function testAbilityPredicatesReceiveThePrincipal(): void
    {
        $gate = new Gate();
        $gate->define('admin.panel', static fn (array $c): bool => ($c['principal']?->get('role')) === 'admin');
        $mw = new Middleware($gate, $this->recording($seen), $this->identity(new Principal('1', ['role' => 'admin'])));

        $this->assertSame('ran', $mw->handle(['authz' => 'admin.panel'], fn (array $c) => $c['principal']->id === '1' ? 'ran' : 'no'));
        $this->assertNull($seen);
    }

    public function testWithoutAResolverTheOldBehaviourStands(): void
    {
        $gate = new Gate();
        $gate->define('x', static fn (): bool => false);
        $mw = new Middleware($gate, $this->recording($seen), new Identity());

        $mw->handle(['authz' => 'x'], fn () => 'ran');
        $this->assertFalse($seen['unauthenticated'], 'no resolver = no notion of signed out: 403');
    }

    public function testMiddlewareStartsEachRequestWithAFreshPrincipal(): void
    {
        $who = 'alice';
        $identity = new Identity();
        $identity->setResolver(static function () use (&$who): Principal {
            return new Principal($who);
        }, 'vendor/auth');
        $mw = new Middleware(new Gate(), $this->recording($seen), $identity);

        $mw->handle([], fn () => $identity->principal());
        $who = 'bob';
        $this->assertSame('bob', $mw->handle([], fn () => $identity->principal()?->id), 'worker mode: request 2 never sees request 1');
    }

    // ── Denial rendering ──────────────────────────────────────

    public function testHtmlSignedOutRedirectsToTheConfiguredLogin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/chat/42?tab=files';
        $deny = new class(loginUrl: '/login') extends Denial {
            /** @var list<string> */
            public array $sent = [];

            protected function sendHeader(string $header, int $status = 0): void
            {
                $this->sent[] = $header . ($status ? " ({$status})" : '');
            }
        };

        \ob_start();
        $deny(['module' => 'vendor/chat', 'route' => '/chat/', 'unauthenticated' => true], 'chat.use', false);
        $body = (string) \ob_get_clean();

        $this->assertContains('Location: /login?next=%2Fchat%2F42%3Ftab%3Dfiles (302)', $deny->sent);
        $this->assertSame('', $body);
    }

    public function testJsonSignedOutIs401(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $deny = new class(loginUrl: '/login') extends Denial {
            /** @var list<string> */
            public array $sent = [];

            protected function sendHeader(string $header, int $status = 0): void
            {
                $this->sent[] = $header . ($status ? " ({$status})" : '');
            }
        };

        \ob_start();
        $deny(['module' => 'vendor/chat', 'unauthenticated' => true], 'chat.use', false);
        $payload = \json_decode((string) \ob_get_clean(), true);

        $this->assertContains('HTTP/1.1 401 (401)', $deny->sent);
        $this->assertSame('unauthenticated', $payload['error']);
        $this->assertSame('/login', $payload['login']);
    }

    public function testDefaultPagesDoNotNameModulesOrRoutes(): void
    {
        $deny = new Denial();
        foreach ([[false, false], [true, false], [false, true]] as [$undefined, $signedOut]) {
            \ob_start();
            $deny(['module' => 'secret/internal', 'route' => '/hidden/admin/', 'unauthenticated' => $signedOut], 'ops.secret', $undefined);
            $body = (string) \ob_get_clean();
            $this->assertStringNotContainsString('secret/internal', $body);
            $this->assertStringNotContainsString('/hidden/admin/', $body);
            $this->assertStringNotContainsString('ops.secret', $body, 'the HTML page names no ability either');
        }
    }

    public function testJsonForbiddenKeepsTheAbilityButNotTheModule(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        \ob_start();
        (new Denial())(['module' => 'secret/internal', 'route' => '/r/'], 'user.manage', false);
        $payload = \json_decode((string) \ob_get_clean(), true);
        $this->assertSame('authz-denied', $payload['error']);
        $this->assertSame('user.manage', $payload['ability']);
        $this->assertArrayNotHasKey('module', $payload);
    }

    public function testCustomRendererOwnsTheAnswer(): void
    {
        $got = null;
        $deny = new Denial(renderer: static function (Refusal $r) use (&$got): void {
            $got = $r;
            echo 'custom';
        });

        \ob_start();
        $deny(['module' => 'vendor/chat', 'route' => '/chat/', 'unauthenticated' => true], 'chat.use', false);
        $body = (string) \ob_get_clean();

        $this->assertSame('custom', $body);
        $this->assertInstanceOf(Refusal::class, $got);
        $this->assertSame(401, $got->status);
        $this->assertTrue($got->unauthenticated);
        $this->assertSame('chat.use', $got->ability);
        $this->assertSame('vendor/chat', $got->context['module']);
    }

    public function testLoginUrlMustBeALocalPath(): void
    {
        foreach (['https://evil.example/login', '//evil.example', 'login', "/ok\r\nX: y"] as $bad) {
            try {
                Denial::fromConfig(['login_url' => $bad]);
                $this->fail('accepted ' . $bad);
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(Denial::class, Denial::fromConfig(['login_url' => '/login']));
        $this->assertInstanceOf(Denial::class, Denial::fromConfig([]));
    }

    // ── Wiring ────────────────────────────────────────────────

    public function testControllerPrincipalReadsTheDistIdentity(): void
    {
        $module = $this->createMock(\Razy\Module::class);
        $module->method('getAuthzIdentity')->willReturn($this->identity(new Principal('99')));
        $controller = new class($module) extends \Razy\Controller {};

        $this->assertSame('99', $controller->principal()?->id);
    }

    public function testControllerPrincipalIgnoresAnotherDistsContainerBinding(): void
    {
        // Two dists sharing an Application: the later one rebinds Identity in the
        // shared container. A controller must still read its own dist's identity.
        $container = new \Razy\Container();
        $container->instance(Identity::class, $this->identity(new Principal('other-dist')));
        $module = $this->createMock(\Razy\Module::class);
        $module->method('getContainer')->willReturn($container);
        $module->method('getAuthzIdentity')->willReturn($this->identity(new Principal('own-dist')));
        $controller = new class($module) extends \Razy\Controller {};

        $this->assertSame('own-dist', $controller->principal()?->id);
    }

    public function testControllerPrincipalIsNullWithoutAnIdentity(): void
    {
        $module = $this->createMock(\Razy\Module::class);
        $module->method('getAuthzIdentity')->willReturn(new Identity());
        $controller = new class($module) extends \Razy\Controller {};

        $this->assertNull($controller->principal());
        $this->assertNull((new class() extends \Razy\Controller {})->principal());
    }

    public function testDistributorWiresIdentityDenialAndConfig(): void
    {
        $dist = \str_replace("\r\n", "\n", (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php'));
        $this->assertStringContainsString('AuthzDenial::fromConfig($authz,', $dist);
        $this->assertStringContainsString('new AuthzMiddleware($this->authzGate, $this->authzDenial->__invoke(...), $this->authzIdentity)', $dist);
        $this->assertStringContainsString('$container->instance(AuthzIdentity::class, $this->authzIdentity);', $dist);

        $agent = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Agent.php');
        $this->assertStringContainsString('public function identity(callable $resolver): static', $agent);
        $this->assertStringContainsString('public function onAuthzDenied(callable $renderer): static', $agent);
    }

    public function testRendererHasOneOwner(): void
    {
        $deny = new Denial();
        $deny->setRenderer(static function (Refusal $r): void {
        }, 'vendor/a');
        $this->expectException(LogicException::class);
        $deny->setRenderer(static function (Refusal $r): void {
        }, 'vendor/b');
    }

    // ── helpers ───────────────────────────────────────────────

    private function identity(?Principal $principal): Identity
    {
        $identity = new Identity();
        $identity->setResolver(static fn (): ?Principal => $principal, 'vendor/auth');

        return $identity;
    }

    /**
     * @param array<string, mixed>|null $seen
     */
    private function recording(?array &$seen): Closure
    {
        $seen = null;

        return static function (array $context, string $ability, bool $undefined = false) use (&$seen): null {
            $seen = ['ability' => $ability, 'undefined' => $undefined, 'unauthenticated' => ($context['unauthenticated'] ?? false) === true];

            return null;
        };
    }
}
