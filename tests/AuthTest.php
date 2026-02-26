<?php

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Auth\AccessDeniedException;
use Razy\Auth\AuthManager;
use Razy\Auth\AuthMiddleware;
use Razy\Auth\AuthorizeMiddleware;
use Razy\Auth\CallbackGuard;
use Razy\Auth\Gate;
use Razy\Auth\GenericUser;
use Razy\Auth\Hash;
use Razy\Contract\AuthenticatableInterface;
use Razy\Contract\GuardInterface;
use RuntimeException;

// ─── Fixture: Simple Post class for policy tests ─────────────────

class AuthTestPost
{
    public function __construct(
        public readonly int $id,
        public readonly int $authorId,
        public readonly string $title = 'Test Post',
    ) {
    }
}

// ─── Fixture: Post Policy ────────────────────────────────────────

class AuthTestPostPolicy
{
    public function edit(AuthenticatableInterface $user, AuthTestPost $post): bool
    {
        return $user->getAuthIdentifier() === $post->authorId;
    }

    public function delete(AuthenticatableInterface $user, AuthTestPost $post): bool
    {
        // Only admin (user id 1) can delete
        return $user->getAuthIdentifier() === 1;
    }

    public function viewAny(AuthenticatableInterface $user): bool
    {
        return true;
    }
}

/**
 * Tests for P5: Auth/Authorization system.
 *
 * Covers Hash, GenericUser, CallbackGuard, AuthManager, Gate,
 * AccessDeniedException, AuthMiddleware, and AuthorizeMiddleware.
 */
#[CoversClass(Hash::class)]
#[CoversClass(GenericUser::class)]
#[CoversClass(CallbackGuard::class)]
#[CoversClass(AuthManager::class)]
#[CoversClass(Gate::class)]
#[CoversClass(AccessDeniedException::class)]
#[CoversClass(AuthMiddleware::class)]
#[CoversClass(AuthorizeMiddleware::class)]
class AuthTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: Hash
    // ═══════════════════════════════════════════════════════════════

    public function testHashMake(): void
    {
        $hash = Hash::make('password123');

        $this->assertNotEmpty($hash);
        $this->assertNotSame('password123', $hash);
    }

    public function testHashCheck(): void
    {
        $hash = Hash::make('secret');

        $this->assertTrue(Hash::check('secret', $hash));
        $this->assertFalse(Hash::check('wrong', $hash));
    }

    public function testHashCheckWithBcrypt(): void
    {
        $hash = Hash::make('mypassword', PASSWORD_BCRYPT);

        $this->assertTrue(Hash::check('mypassword', $hash));
        $this->assertFalse(Hash::check('other', $hash));
    }

    public function testHashNeedsRehash(): void
    {
        // Hash with low cost
        $hash = Hash::make('test', PASSWORD_BCRYPT, ['cost' => 4]);

        // Should need rehash with default (higher) cost
        $this->assertTrue(Hash::needsRehash($hash, PASSWORD_BCRYPT, ['cost' => 12]));

        // Should NOT need rehash with same cost
        $this->assertFalse(Hash::needsRehash($hash, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    public function testHashInfo(): void
    {
        $hash = Hash::make('test', PASSWORD_BCRYPT);
        $info = Hash::info($hash);

        $this->assertArrayHasKey('algo', $info);
        $this->assertArrayHasKey('algoName', $info);
        $this->assertArrayHasKey('options', $info);
        $this->assertSame('bcrypt', $info['algoName']);
    }

    public function testHashDifferentPasswordsProduceDifferentHashes(): void
    {
        $hash1 = Hash::make('password1');
        $hash2 = Hash::make('password2');

        $this->assertNotSame($hash1, $hash2);
    }

    public function testHashSamePasswordProducesDifferentHashes(): void
    {
        // Due to random salt
        $hash1 = Hash::make('same');
        $hash2 = Hash::make('same');

        $this->assertNotSame($hash1, $hash2);
        // But both verify correctly
        $this->assertTrue(Hash::check('same', $hash1));
        $this->assertTrue(Hash::check('same', $hash2));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: GenericUser
    // ═══════════════════════════════════════════════════════════════

    public function testGenericUserGetAuthIdentifier(): void
    {
        $user = new GenericUser(['id' => 42, 'name' => 'Alice', 'password' => 'hashed']);

        $this->assertSame(42, $user->getAuthIdentifier());
    }

    public function testGenericUserGetAuthIdentifierName(): void
    {
        $user = new GenericUser(['id' => 1]);
        $this->assertSame('id', $user->getAuthIdentifierName());
    }

    public function testGenericUserCustomIdentifierName(): void
    {
        $user = new GenericUser([
            '__identifier_name' => 'uuid',
            'uuid' => 'abc-123',
        ]);

        $this->assertSame('uuid', $user->getAuthIdentifierName());
        $this->assertSame('abc-123', $user->getAuthIdentifier());
    }

    public function testGenericUserGetAuthPassword(): void
    {
        $hash = Hash::make('secret');
        $user = new GenericUser(['id' => 1, 'password' => $hash]);

        $this->assertSame($hash, $user->getAuthPassword());
    }

    public function testGenericUserGetAuthPasswordDefault(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertSame('', $user->getAuthPassword());
    }

    public function testGenericUserGetAttribute(): void
    {
        $user = new GenericUser(['id' => 1, 'email' => 'alice@example.com']);

        $this->assertSame('alice@example.com', $user->getAttribute('email'));
        $this->assertNull($user->getAttribute('missing'));
        $this->assertSame('default_val', $user->getAttribute('missing', 'default_val'));
    }

    public function testGenericUserGetAttributes(): void
    {
        $attrs = ['id' => 1, 'name' => 'Bob'];
        $user = new GenericUser($attrs);

        $this->assertSame($attrs, $user->getAttributes());
    }

    public function testGenericUserHasAttribute(): void
    {
        $user = new GenericUser(['id' => 1, 'role' => 'admin']);

        $this->assertTrue($user->hasAttribute('role'));
        $this->assertFalse($user->hasAttribute('missing'));
    }

    public function testGenericUserImplementsInterface(): void
    {
        $user = new GenericUser(['id' => 1]);

        $this->assertInstanceOf(AuthenticatableInterface::class, $user);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: CallbackGuard
    // ═══════════════════════════════════════════════════════════════

    public function testCallbackGuardNoResolverIsGuest(): void
    {
        $guard = new CallbackGuard();

        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
        $this->assertNull($guard->user());
        $this->assertNull($guard->id());
    }

    public function testCallbackGuardWithUserResolver(): void
    {
        $user = new GenericUser(['id' => 5, 'name' => 'Charlie']);
        $guard = new CallbackGuard(userResolver: fn () => $user);

        $this->assertTrue($guard->check());
        $this->assertFalse($guard->guest());
        $this->assertSame($user, $guard->user());
        $this->assertSame(5, $guard->id());
    }

    public function testCallbackGuardResolverCalledOnce(): void
    {
        $callCount = 0;
        $user = new GenericUser(['id' => 1]);

        $guard = new CallbackGuard(userResolver: function () use (&$callCount, $user) {
            $callCount++;
            return $user;
        });

        // Call user() multiple times
        $guard->user();
        $guard->user();
        $guard->check();

        $this->assertSame(1, $callCount, 'User resolver should be called only once (lazy)');
    }

    public function testCallbackGuardResolverReturnsNull(): void
    {
        $guard = new CallbackGuard(userResolver: fn () => null);

        $this->assertFalse($guard->check());
        $this->assertTrue($guard->guest());
    }

    public function testCallbackGuardSetUser(): void
    {
        $guard = new CallbackGuard();
        $user = new GenericUser(['id' => 10]);

        $guard->setUser($user);

        $this->assertTrue($guard->check());
        $this->assertSame($user, $guard->user());
        $this->assertSame(10, $guard->id());
    }

    public function testCallbackGuardSetUserOverridesResolver(): void
    {
        $resolverUser = new GenericUser(['id' => 1]);
        $manualUser = new GenericUser(['id' => 99]);

        $guard = new CallbackGuard(userResolver: fn () => $resolverUser);
        $guard->setUser($manualUser);

        // setUser marks resolved=true, so resolver won't be called
        $this->assertSame($manualUser, $guard->user());
        $this->assertSame(99, $guard->id());
    }

    public function testCallbackGuardValidate(): void
    {
        $guard = new CallbackGuard(
            credentialValidator: fn (array $creds) => $creds['password'] === 'correct',
        );

        $this->assertTrue($guard->validate(['password' => 'correct']));
        $this->assertFalse($guard->validate(['password' => 'wrong']));
    }

    public function testCallbackGuardValidateNoValidator(): void
    {
        $guard = new CallbackGuard();

        $this->assertFalse($guard->validate(['anything' => 'value']));
    }

    public function testCallbackGuardResetClearsCachedUser(): void
    {
        $user = new GenericUser(['id' => 1]);
        $guard = new CallbackGuard();
        $guard->setUser($user);

        $this->assertTrue($guard->check());

        $guard->reset();

        // After reset with no resolver, user is null
        $this->assertFalse($guard->check());
        $this->assertNull($guard->user());
    }

    public function testCallbackGuardResetAllowsReResolve(): void
    {
        $callCount = 0;
        $guard = new CallbackGuard(userResolver: function () use (&$callCount) {
            $callCount++;
            return new GenericUser(['id' => $callCount]);
        });

        $user1 = $guard->user();
        $this->assertSame(1, $user1->getAuthIdentifier());

        $guard->reset();
        $user2 = $guard->user();
        $this->assertSame(2, $user2->getAuthIdentifier());
        $this->assertSame(2, $callCount);
    }

    public function testCallbackGuardImplementsInterface(): void
    {
        $guard = new CallbackGuard();

        $this->assertInstanceOf(GuardInterface::class, $guard);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: AuthManager
    // ═══════════════════════════════════════════════════════════════

    public function testAuthManagerAddAndGetGuard(): void
    {
        $auth = new AuthManager();
        $guard = new CallbackGuard();

        $auth->addGuard('web', $guard);

        $this->assertSame($guard, $auth->guard('web'));
    }

    public function testAuthManagerDefaultGuard(): void
    {
        $guard = new CallbackGuard();
        $auth = new AuthManager(['default' => $guard]);

        $this->assertSame($guard, $auth->guard());
        $this->assertSame('default', $auth->getDefaultGuard());
    }

    public function testAuthManagerSetDefaultGuard(): void
    {
        $web = new CallbackGuard();
        $api = new CallbackGuard();

        $auth = new AuthManager(['web' => $web, 'api' => $api]);
        $auth->setDefaultGuard('api');

        $this->assertSame('api', $auth->getDefaultGuard());
        $this->assertSame($api, $auth->guard());
    }

    public function testAuthManagerHasGuard(): void
    {
        $auth = new AuthManager();
        $auth->addGuard('web', new CallbackGuard());

        $this->assertTrue($auth->hasGuard('web'));
        $this->assertFalse($auth->hasGuard('api'));
    }

    public function testAuthManagerGetGuardNames(): void
    {
        $auth = new AuthManager([
            'web' => new CallbackGuard(),
            'api' => new CallbackGuard(),
        ]);

        $names = $auth->getGuardNames();
        $this->assertContains('web', $names);
        $this->assertContains('api', $names);
        $this->assertCount(2, $names);
    }

    public function testAuthManagerThrowsForUnregisteredGuard(): void
    {
        $auth = new AuthManager();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Guard [missing] is not registered');
        $auth->guard('missing');
    }

    public function testAuthManagerThrowsForUnregisteredDefaultGuard(): void
    {
        $auth = new AuthManager();

        $this->expectException(InvalidArgumentException::class);
        $auth->guard(); // default 'default' guard not registered
    }

    public function testAuthManagerDelegatesCheck(): void
    {
        $user = new GenericUser(['id' => 1]);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        $this->assertTrue($auth->check());
        $this->assertFalse($auth->guest());
    }

    public function testAuthManagerDelegatesUser(): void
    {
        $user = new GenericUser(['id' => 42]);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        $this->assertSame($user, $auth->user());
        $this->assertSame(42, $auth->id());
    }

    public function testAuthManagerDelegatesValidate(): void
    {
        $guard = new CallbackGuard(
            credentialValidator: fn (array $c) => $c['key'] === 'valid',
        );
        $auth = new AuthManager(['default' => $guard]);

        $this->assertTrue($auth->validate(['key' => 'valid']));
        $this->assertFalse($auth->validate(['key' => 'invalid']));
    }

    public function testAuthManagerDelegatesSetUser(): void
    {
        $guard = new CallbackGuard();
        $auth = new AuthManager(['default' => $guard]);

        $user = new GenericUser(['id' => 7]);
        $auth->setUser($user);

        $this->assertSame($user, $auth->user());
    }

    public function testAuthManagerMultipleGuards(): void
    {
        $webUser = new GenericUser(['id' => 1, 'name' => 'WebUser']);
        $apiUser = new GenericUser(['id' => 2, 'name' => 'APIUser']);

        $auth = new AuthManager([
            'web' => new CallbackGuard(userResolver: fn () => $webUser),
            'api' => new CallbackGuard(userResolver: fn () => $apiUser),
        ], 'web');

        // Default (web) guard
        $this->assertSame($webUser, $auth->user());
        $this->assertSame(1, $auth->id());

        // Specific (api) guard
        $this->assertSame($apiUser, $auth->guard('api')->user());
        $this->assertSame(2, $auth->guard('api')->id());
    }

    public function testAuthManagerFluentApi(): void
    {
        $auth = new AuthManager();

        $result = $auth->addGuard('web', new CallbackGuard());
        $this->assertSame($auth, $result);

        $result2 = $auth->setDefaultGuard('web');
        $this->assertSame($auth, $result2);
    }

    public function testAuthManagerConstructorGuards(): void
    {
        $g1 = new CallbackGuard();
        $g2 = new CallbackGuard();

        $auth = new AuthManager(['a' => $g1, 'b' => $g2], 'b');

        $this->assertSame($g1, $auth->guard('a'));
        $this->assertSame($g2, $auth->guard('b'));
        $this->assertSame('b', $auth->getDefaultGuard());
    }

    public function testGateDefineAndAllow(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('create-post', fn (AuthenticatableInterface $user) => true);

        $this->assertTrue($gate->allows('create-post'));
    }

    public function testGateDefineAndDeny(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('delete-all', fn (AuthenticatableInterface $user) => false);

        $this->assertTrue($gate->denies('delete-all'));
        $this->assertFalse($gate->allows('delete-all'));
    }

    public function testGateUndefinedAbilityDenied(): void
    {
        $gate = $this->createAuthenticatedGate();

        $this->assertFalse($gate->allows('nonexistent'));
        $this->assertTrue($gate->denies('nonexistent'));
    }

    public function testGateGuestAlwaysDenied(): void
    {
        $gate = $this->createGuestGate();
        $gate->define('anything', fn (AuthenticatableInterface $user) => true);

        $this->assertFalse($gate->allows('anything'));
    }

    public function testGateAbilityWithArguments(): void
    {
        $gate = $this->createAuthenticatedGate(new GenericUser(['id' => 5]));
        $gate->define('edit-post', function (AuthenticatableInterface $user, AuthTestPost $post) {
            return $user->getAuthIdentifier() === $post->authorId;
        });

        $ownPost = new AuthTestPost(1, 5);
        $otherPost = new AuthTestPost(2, 99);

        $this->assertTrue($gate->allows('edit-post', $ownPost));
        $this->assertFalse($gate->allows('edit-post', $otherPost));
    }

    public function testGateHasAbility(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('fly', fn () => true);

        $this->assertTrue($gate->has('fly'));
        $this->assertFalse($gate->has('swim'));
    }

    public function testGateAbilities(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('a', fn () => true);
        $gate->define('b', fn () => true);
        $gate->define('c', fn () => true);

        $abilities = $gate->abilities();
        $this->assertCount(3, $abilities);
        $this->assertContains('a', $abilities);
        $this->assertContains('b', $abilities);
        $this->assertContains('c', $abilities);
    }

    public function testGateDefineFluentChain(): void
    {
        $gate = $this->createAuthenticatedGate();

        $result = $gate->define('x', fn () => true);
        $this->assertSame($gate, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Gate — check / any / none
    // ═══════════════════════════════════════════════════════════════

    public function testGateCheckAllAbilities(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('read', fn () => true);
        $gate->define('write', fn () => true);
        $gate->define('delete', fn () => false);

        $this->assertTrue($gate->check(['read', 'write']));
        $this->assertFalse($gate->check(['read', 'delete']));
    }

    public function testGateAny(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('read', fn () => true);
        $gate->define('write', fn () => false);
        $gate->define('delete', fn () => false);

        $this->assertTrue($gate->any(['read', 'write']));
        $this->assertFalse($gate->any(['write', 'delete']));
    }

    public function testGateNone(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('read', fn () => false);
        $gate->define('write', fn () => false);

        $this->assertTrue($gate->none(['read', 'write']));
    }

    public function testGateNoneFalseWhenOneAllowed(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('read', fn () => true);
        $gate->define('write', fn () => false);

        $this->assertFalse($gate->none(['read', 'write']));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: Gate — authorize (throws)
    // ═══════════════════════════════════════════════════════════════

    public function testGateAuthorizeSuccess(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('allowed', fn () => true);

        // Should not throw
        $gate->authorize('allowed');
        $this->assertTrue(true); // confirm we reached here
    }

    public function testGateAuthorizeThrowsOnDeny(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('restricted', fn () => false);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('restricted');
        $gate->authorize('restricted');
    }

    public function testGateAuthorizeThrowsForGuest(): void
    {
        $gate = $this->createGuestGate();
        $gate->define('anything', fn () => true);

        $this->expectException(AccessDeniedException::class);
        $gate->authorize('anything');
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Gate — Policies
    // ═══════════════════════════════════════════════════════════════

    public function testGatePolicyEdit(): void
    {
        $user = new GenericUser(['id' => 5]);
        $gate = $this->createAuthenticatedGate($user);
        $gate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $ownPost = new AuthTestPost(1, 5);
        $otherPost = new AuthTestPost(2, 99);

        $this->assertTrue($gate->allows('edit', $ownPost));
        $this->assertFalse($gate->allows('edit', $otherPost));
    }

    public function testGatePolicyDelete(): void
    {
        $admin = new GenericUser(['id' => 1]);
        $regular = new GenericUser(['id' => 50]);

        $adminGate = $this->createAuthenticatedGate($admin);
        $adminGate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $regularGate = $this->createAuthenticatedGate($regular);
        $regularGate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $post = new AuthTestPost(1, 50);

        $this->assertTrue($adminGate->allows('delete', $post));
        $this->assertFalse($regularGate->allows('delete', $post));
    }

    public function testGatePolicyUndefinedMethodFallsThrough(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $post = new AuthTestPost(1, 1);

        // 'archive' doesn't exist on policy, and no ability defined → denied
        $this->assertFalse($gate->allows('archive', $post));
    }

    public function testGateGetPolicyFor(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $this->assertSame(AuthTestPostPolicy::class, $gate->getPolicyFor(AuthTestPost::class));
        $this->assertNull($gate->getPolicyFor('NonexistentClass'));
    }

    public function testGatePolicyKebabCaseAbility(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->policy(AuthTestPost::class, AuthTestPostPolicy::class);

        $post = new AuthTestPost(1, 1);

        // 'view-any' should map to 'viewAny' method
        $this->assertTrue($gate->allows('view-any', $post));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Gate — before / after interceptors
    // ═══════════════════════════════════════════════════════════════

    public function testGateBeforeAllowsAll(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('restricted', fn () => false);
        $gate->before(fn () => true); // override: always allow

        $this->assertTrue($gate->allows('restricted'));
    }

    public function testGateBeforeDeniesAll(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('allowed', fn () => true);
        $gate->before(fn () => false); // override: always deny

        $this->assertFalse($gate->allows('allowed'));
    }

    public function testGateBeforeNullProceedsNormally(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('check', fn () => true);
        $gate->before(fn () => null); // null = proceed to normal check

        $this->assertTrue($gate->allows('check'));
    }

    public function testGateAfterCanOverride(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('check', fn () => false);

        // After callback overrides the result
        $gate->after(function (AuthenticatableInterface $user, string $ability, bool $result) {
            return true; // override deny to allow
        });

        $this->assertTrue($gate->allows('check'));
    }

    public function testGateAfterNullKeepsResult(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('check', fn () => true);
        $gate->after(fn () => null); // null = no override

        $this->assertTrue($gate->allows('check'));
    }

    public function testGateBeforeReceivesAbilityInfo(): void
    {
        $receivedAbility = null;
        $receivedArgs = null;

        $gate = $this->createAuthenticatedGate();
        $gate->before(function (AuthenticatableInterface $user, string $ability, array $args) use (&$receivedAbility, &$receivedArgs) {
            $receivedAbility = $ability;
            $receivedArgs = $args;
        });
        $gate->define('test-ability', fn () => true);

        $post = new AuthTestPost(1, 1);
        $gate->allows('test-ability', $post);

        $this->assertSame('test-ability', $receivedAbility);
        $this->assertCount(1, $receivedArgs);
        $this->assertSame($post, $receivedArgs[0]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Gate — forUser
    // ═══════════════════════════════════════════════════════════════

    public function testGateForUserScopesCheck(): void
    {
        $currentUser = new GenericUser(['id' => 1]);
        $otherUser = new GenericUser(['id' => 99]);

        $gate = $this->createAuthenticatedGate($currentUser);
        $gate->define('is-admin', fn (AuthenticatableInterface $user) => $user->getAuthIdentifier() === 1);

        // Current user (id=1) is admin
        $this->assertTrue($gate->allows('is-admin'));

        // Check for other user (id=99)
        $this->assertFalse($gate->forUser($otherUser)->allows('is-admin'));
    }

    public function testGateForUserDoesNotAffectOriginal(): void
    {
        $user = new GenericUser(['id' => 1]);
        $gate = $this->createAuthenticatedGate($user);
        $gate->define('test', fn (AuthenticatableInterface $u) => $u->getAuthIdentifier() === 1);

        $other = new GenericUser(['id' => 2]);
        $scopedGate = $gate->forUser($other);

        // Scoped gate uses other user
        $this->assertFalse($scopedGate->allows('test'));

        // Original gate unchanged
        $this->assertTrue($gate->allows('test'));
    }

    public function testGateForUserSharesAbilities(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('shared', fn () => true);

        $other = new GenericUser(['id' => 2]);
        $scopedGate = $gate->forUser($other);

        // Scoped gate has same abilities
        $this->assertTrue($scopedGate->has('shared'));
        $this->assertTrue($scopedGate->allows('shared'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: AccessDeniedException
    // ═══════════════════════════════════════════════════════════════

    public function testAccessDeniedExceptionDefaults(): void
    {
        $e = new AccessDeniedException();

        $this->assertSame('This action is unauthorized.', $e->getMessage());
        $this->assertSame(403, $e->getStatusCode());
    }

    public function testAccessDeniedExceptionCustomMessage(): void
    {
        $e = new AccessDeniedException('Custom message', 401);

        $this->assertSame('Custom message', $e->getMessage());
        $this->assertSame(401, $e->getStatusCode());
    }

    public function testAccessDeniedExceptionIsRuntimeException(): void
    {
        $e = new AccessDeniedException();
        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: AuthMiddleware
    // ═══════════════════════════════════════════════════════════════

    public function testAuthMiddlewarePassesWhenAuthenticated(): void
    {
        $user = new GenericUser(['id' => 1]);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        $middleware = new AuthMiddleware($auth);
        $called = false;

        $result = $middleware->handle([], function (array $ctx) use (&$called) {
            $called = true;
            return 'handler_result';
        });

        $this->assertTrue($called);
        $this->assertSame('handler_result', $result);
    }

    public function testAuthMiddlewareBlocksGuest(): void
    {
        $guard = new CallbackGuard(); // no user
        $auth = new AuthManager(['default' => $guard]);

        $middleware = new AuthMiddleware($auth);
        $called = false;

        $result = $middleware->handle([], function () use (&$called) {
            $called = true;
            return 'should_not_reach';
        });

        $this->assertFalse($called);
        $this->assertNull($result);
    }

    public function testAuthMiddlewareWithSpecificGuard(): void
    {
        $webUser = new GenericUser(['id' => 1]);
        $auth = new AuthManager([
            'web' => new CallbackGuard(userResolver: fn () => $webUser),
            'api' => new CallbackGuard(), // no user
        ]);

        // Middleware for API guard should block
        $apiMiddleware = new AuthMiddleware($auth, 'api');
        $called = false;

        $apiMiddleware->handle([], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);

        // Middleware for web guard should pass
        $webMiddleware = new AuthMiddleware($auth, 'web');
        $webCalled = false;

        $webMiddleware->handle([], function () use (&$webCalled) {
            $webCalled = true;
        });

        $this->assertTrue($webCalled);
    }

    public function testAuthMiddlewareCustomRejectionHandler(): void
    {
        $guard = new CallbackGuard(); // no user
        $auth = new AuthManager(['default' => $guard]);

        $handlerCalled = false;
        $receivedContext = null;

        $middleware = new AuthMiddleware($auth, null, function (array $ctx) use (&$handlerCalled, &$receivedContext) {
            $handlerCalled = true;
            $receivedContext = $ctx;
            return 'custom_rejection';
        });

        $context = ['url_query' => '/protected'];
        $result = $middleware->handle($context, fn () => 'should_not_reach');

        $this->assertTrue($handlerCalled);
        $this->assertSame($context, $receivedContext);
        $this->assertSame('custom_rejection', $result);
    }

    public function testAuthMiddlewareImplementsInterface(): void
    {
        $auth = new AuthManager(['default' => new CallbackGuard()]);
        $middleware = new AuthMiddleware($auth);

        $this->assertInstanceOf(\Razy\Contract\MiddlewareInterface::class, $middleware);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 13: AuthorizeMiddleware
    // ═══════════════════════════════════════════════════════════════

    public function testAuthorizeMiddlewarePassesWhenAllowed(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('manage', fn () => true);

        $middleware = new AuthorizeMiddleware($gate, 'manage');
        $called = false;

        $result = $middleware->handle([], function () use (&$called) {
            $called = true;
            return 'ok';
        });

        $this->assertTrue($called);
        $this->assertSame('ok', $result);
    }

    public function testAuthorizeMiddlewareBlocksWhenDenied(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('admin', fn () => false);

        $middleware = new AuthorizeMiddleware($gate, 'admin');
        $called = false;

        $result = $middleware->handle([], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        $this->assertNull($result);
    }

    public function testAuthorizeMiddlewareWithArgumentResolver(): void
    {
        $user = new GenericUser(['id' => 5]);
        $gate = $this->createAuthenticatedGate($user);
        $gate->define('edit-post', function (AuthenticatableInterface $u, AuthTestPost $post) {
            return $u->getAuthIdentifier() === $post->authorId;
        });

        // Argument resolver extracts the post from context
        $post = new AuthTestPost(1, 5); // owned by user 5
        $middleware = new AuthorizeMiddleware($gate, 'edit-post', fn (array $ctx) => [$post]);

        $called = false;
        $middleware->handle([], function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testAuthorizeMiddlewareArgumentResolverDeny(): void
    {
        $user = new GenericUser(['id' => 5]);
        $gate = $this->createAuthenticatedGate($user);
        $gate->define('edit-post', function (AuthenticatableInterface $u, AuthTestPost $post) {
            return $u->getAuthIdentifier() === $post->authorId;
        });

        // Post NOT owned by user 5
        $post = new AuthTestPost(1, 99);
        $middleware = new AuthorizeMiddleware($gate, 'edit-post', fn (array $ctx) => [$post]);

        $called = false;
        $middleware->handle([], function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function testAuthorizeMiddlewareCustomForbiddenHandler(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('restricted', fn () => false);

        $handlerCalled = false;
        $middleware = new AuthorizeMiddleware(
            $gate,
            'restricted',
            onForbidden: function (array $ctx) use (&$handlerCalled) {
                $handlerCalled = true;
                return 'forbidden_response';
            },
        );

        $result = $middleware->handle([], fn () => 'should_not_reach');

        $this->assertTrue($handlerCalled);
        $this->assertSame('forbidden_response', $result);
    }

    public function testAuthorizeMiddlewareArgumentResolverSingleValue(): void
    {
        $gate = $this->createAuthenticatedGate();
        $gate->define('view', function (AuthenticatableInterface $u, $value) {
            return $value === 'allowed';
        });

        // Return non-array — should be wrapped
        $middleware = new AuthorizeMiddleware($gate, 'view', fn (array $ctx) => 'allowed');
        $called = false;

        $middleware->handle([], function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function testAuthorizeMiddlewareImplementsInterface(): void
    {
        $gate = $this->createAuthenticatedGate();
        $middleware = new AuthorizeMiddleware($gate, 'test');

        $this->assertInstanceOf(\Razy\Contract\MiddlewareInterface::class, $middleware);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 14: Integration — Full Auth Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testFullAuthLifecycle(): void
    {
        // 1. Create users
        $password = Hash::make('secret123');
        $admin = new GenericUser(['id' => 1, 'name' => 'Admin', 'password' => $password, 'role' => 'admin']);
        $user = new GenericUser(['id' => 2, 'name' => 'User', 'password' => $password, 'role' => 'user']);

        // 2. Set up guard with credential validation
        $users = [$admin, $user];
        $guard = new CallbackGuard(
            userResolver: fn () => null, // no one logged in initially
            credentialValidator: function (array $creds) use ($users) {
                foreach ($users as $u) {
                    if ($u->getAttribute('name') === $creds['name'] && Hash::check($creds['password'], $u->getAuthPassword())) {
                        return true;
                    }
                }
                return false;
            },
        );

        // 3. Set up auth manager
        $auth = new AuthManager(['default' => $guard]);

        // 4. Validate credentials
        $this->assertTrue($auth->validate(['name' => 'Admin', 'password' => 'secret123']));
        $this->assertFalse($auth->validate(['name' => 'Admin', 'password' => 'wrong']));

        // 5. Initially guest
        $this->assertTrue($auth->guest());

        // 6. Login (manually set user)
        $auth->setUser($admin);
        $this->assertTrue($auth->check());
        $this->assertSame($admin, $auth->user());
        $this->assertSame(1, $auth->id());

        // 7. Set up gate with abilities
        $gate = new Gate($auth);
        $gate->define('manage-users', fn (AuthenticatableInterface $u) => $u->getAttribute('role') === 'admin');
        $gate->define('create-post', fn () => true);

        // 8. Admin can manage users
        $this->assertTrue($gate->allows('manage-users'));
        $this->assertTrue($gate->allows('create-post'));

        // 9. Regular user can't manage users
        $this->assertFalse($gate->forUser($user)->allows('manage-users'));
        $this->assertTrue($gate->forUser($user)->allows('create-post'));

        // 10. Policy-based
        $gate->policy(AuthTestPost::class, AuthTestPostPolicy::class);
        $post = new AuthTestPost(1, 2); // authored by user 2

        $this->assertTrue($gate->forUser($user)->allows('edit', $post));    // own post
        $this->assertFalse($gate->forUser($user)->allows('delete', $post)); // not admin
        $this->assertTrue($gate->allows('delete', $post));                   // admin
    }

    public function testFullMiddlewarePipelineIntegration(): void
    {
        // Set up authenticated user
        $user = new GenericUser(['id' => 1, 'role' => 'admin']);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        // Set up gate
        $gate = new Gate($auth);
        $gate->define('admin-panel', fn (AuthenticatableInterface $u) => $u->getAttribute('role') === 'admin');

        // Create middleware chain (manual pipeline simulation)
        $authMiddleware = new AuthMiddleware($auth);
        $authorizeMiddleware = new AuthorizeMiddleware($gate, 'admin-panel');

        $handlerExecuted = false;

        // Simulate: AuthMiddleware → AuthorizeMiddleware → Handler
        $result = $authMiddleware->handle([], function (array $ctx) use ($authorizeMiddleware, &$handlerExecuted) {
            return $authorizeMiddleware->handle($ctx, function (array $ctx2) use (&$handlerExecuted) {
                $handlerExecuted = true;
                return 'admin_page';
            });
        });

        $this->assertTrue($handlerExecuted);
        $this->assertSame('admin_page', $result);
    }

    public function testMiddlewarePipelineBlockedByAuth(): void
    {
        // Guest (not authenticated)
        $auth = new AuthManager(['default' => new CallbackGuard()]);
        $gate = new Gate($auth);
        $gate->define('anything', fn () => true);

        $authMiddleware = new AuthMiddleware($auth);
        $authorizeMiddleware = new AuthorizeMiddleware($gate, 'anything');

        $handlerExecuted = false;

        $result = $authMiddleware->handle([], function (array $ctx) use ($authorizeMiddleware, &$handlerExecuted) {
            return $authorizeMiddleware->handle($ctx, function () use (&$handlerExecuted) {
                $handlerExecuted = true;
            });
        });

        $this->assertFalse($handlerExecuted);
        $this->assertNull($result);
    }

    public function testMiddlewarePipelineBlockedByAuthorization(): void
    {
        // Authenticated but unauthorized
        $user = new GenericUser(['id' => 1, 'role' => 'user']);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        $gate = new Gate($auth);
        $gate->define('admin-only', fn (AuthenticatableInterface $u) => $u->getAttribute('role') === 'admin');

        $authMiddleware = new AuthMiddleware($auth);
        $authorizeMiddleware = new AuthorizeMiddleware($gate, 'admin-only');

        $handlerExecuted = false;

        $result = $authMiddleware->handle([], function (array $ctx) use ($authorizeMiddleware, &$handlerExecuted) {
            return $authorizeMiddleware->handle($ctx, function () use (&$handlerExecuted) {
                $handlerExecuted = true;
            });
        });

        // Auth passes, but authorization blocks
        $this->assertFalse($handlerExecuted);
        $this->assertNull($result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Gate — Basic Abilities
    // ═══════════════════════════════════════════════════════════════

    private function createAuthenticatedGate(GenericUser $user = null): Gate
    {
        $user ??= new GenericUser(['id' => 1, 'name' => 'Admin']);
        $guard = new CallbackGuard(userResolver: fn () => $user);
        $auth = new AuthManager(['default' => $guard]);

        return new Gate($auth);
    }

    private function createGuestGate(): Gate
    {
        $guard = new CallbackGuard(); // no resolver = guest
        $auth = new AuthManager(['default' => $guard]);

        return new Gate($auth);
    }
}
