<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Auth\GenericUser;
use Razy\Auth\SessionGuard;
use Razy\Contract\AuthenticatableInterface;

/**
 * SessionGuard (PERMISSION-MODULE.md S1): the P1 docblock lie becomes truth.
 * Contract pinned: framework stores ONLY the identifier; hydration is the
 * app resolver's job (Q1: framework never owns the user row); no session
 * started => request-lifetime fallback storage, never an exception.
 */
#[CoversClass(SessionGuard::class)]
class SessionGuardTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION);
    }

    protected function tearDown(): void
    {
        unset($_SESSION);
    }

    public function testGuestWhenSessionHasNoIdentifier(): void
    {
        $_SESSION = [];
        $calls = 0;
        $guard = $this->guard($this->hydrator($calls));

        $this->assertTrue($guard->guest());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
        $this->assertSame(0, $calls, 'no id in session => the app resolver is never invoked');
    }

    public function testHydratesFromPersistedIdentifierOnce(): void
    {
        $_SESSION = ['__auth_user_id' => 7];
        $calls = 0;
        $guard = $this->guard($this->hydrator($calls));

        $this->assertTrue($guard->check());
        $this->assertSame(7, $guard->id());
        $this->assertSame('actor-7', $guard->user()->getAttribute('name'));
        $guard->user();
        $guard->check();
        $this->assertSame(1, $calls, 'lazy-resolve happens at most once per request');
    }

    public function testResolverNullKeepsCallerGuestAndSessionUntouched(): void
    {
        $_SESSION = ['__auth_user_id' => 99];
        $guard = $this->guard(fn ($id): ?AuthenticatableInterface => null);

        $this->assertTrue($guard->guest());
        $this->assertSame(99, $_SESSION['__auth_user_id'], 'a deleted actor does NOT make the guard silently mutate app session data');
    }

    public function testSetUserPersistsIdentifierForNextRequest(): void
    {
        $_SESSION = [];
        $guard = $this->guard($this->hydrator());
        $actor = new GenericUser(['id' => 'u-42']);

        $guard->setUser($actor);
        $this->assertSame('u-42', $_SESSION['__auth_user_id'], 'manual authentication must persist, or "logged in" dies with the request');

        // next request: a fresh guard hydrates the same actor
        $next = $this->guard($this->hydrator());
        $this->assertSame('u-42', $next->id());
    }

    public function testLoginAliasAndLogout(): void
    {
        $_SESSION = [];
        $guard = $this->guard($this->hydrator());

        $guard->login(new GenericUser(['id' => 5]));
        $this->assertTrue($guard->check());

        $guard->logout();
        $this->assertTrue($guard->guest());
        $this->assertArrayNotHasKey('__auth_user_id', $_SESSION);

        // Same request must NOT re-hydrate even if the key reappears (resolved latch)
        $_SESSION['__auth_user_id'] = 6;
        $this->assertTrue($guard->guest());
    }

    public function testFallbackStorageWithoutStartedSession(): void
    {
        // $_SESSION unset in setUp: no session started (CLI reality).
        $guard = $this->guard($this->hydrator());
        $guard->login(new GenericUser(['id' => 1]));
        $this->assertTrue($guard->check(), 'login works in-memory with no session');

        $fresh = $this->guard($this->hydrator());
        $this->assertTrue($fresh->guest(), 'fallback storage NEVER survives the request — stated loudly in the docblock, pinned here');
    }

    public function testCustomSessionKey(): void
    {
        $_SESSION = ['app_actor' => 'a-1'];
        $guard = $this->guard($this->hydrator(), 'app_actor');

        $this->assertSame('a-1', $guard->id());
    }

    public function testNonScalarOrEmptySessionValuesFailClosed(): void
    {
        foreach ([['x'], '', true, null, 1.5] as $junk) {
            $_SESSION = ['__auth_user_id' => $junk];
            $guard = $this->guard($this->hydrator());

            $this->assertTrue($guard->guest(), 'session garbage resolves to guest, not a resolver call');
        }
    }

    public function testResetRehydratesFromPersistedIdentifierForWorkerMode(): void
    {
        $_SESSION = ['__auth_user_id' => 'w-1'];
        $guard = $this->guard($this->hydrator());
        $this->assertSame('w-1', $guard->id());

        // worker boundary: reset() clears the request cache, session persists
        $guard->reset();
        $this->assertSame('w-1', $guard->id(), 'after reset the SAME guard re-hydrates from session storage');
    }

    public function testValidateWithoutValidatorIsFalse(): void
    {
        $_SESSION = [];

        $this->assertFalse($this->guard($this->hydrator())->validate(['email' => 'a@b.c', 'password' => 'x']));
    }

    public function testValidateDelegatesToInjectedValidator(): void
    {
        $_SESSION = [];
        $guard = new SessionGuard(
            $this->hydrator(),
            '__auth_user_id',
            fn (array $c): bool => ($c['password'] ?? '') === 'swordfish',
        );

        $this->assertTrue($guard->validate(['password' => 'swordfish']));
        $this->assertFalse($guard->validate(['password' => 'nope']));
    }

    /**
     * @param Closure(string|int): ?AuthenticatableInterface $resolver
     */
    private function guard(Closure $resolver, string $key = '__auth_user_id'): SessionGuard
    {
        return new SessionGuard($resolver, $key);
    }

    private function hydrator(int &$calls = 0): Closure
    {
        return function (string|int $id) use (&$calls): ?AuthenticatableInterface {
            $calls++;

            return new GenericUser(['id' => $id, 'name' => 'actor-' . $id]);
        };
    }
}
