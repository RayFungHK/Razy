<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Auth\AuthManager;
use Razy\Auth\CallbackGuard;
use Razy\Auth\Gate;
use Razy\Auth\GateFactory;
use Razy\Auth\GenericUser;
use Razy\Contract\AuthenticatableInterface;

/**
 * S1 additive seam (PERMISSION-MODULE.md): multi-subscriber Gate hooks
 * (addBefore/addAfter lists) + GateFactory memoization. The pre-existing
 * single-slot before()/after() semantics stay pinned by the 89 tests in
 * AuthTest.php — this file pins ONLY the appended-list generalizations.
 */
#[CoversClass(Gate::class)]
#[CoversClass(GateFactory::class)]
class GateHookListTest extends TestCase
{
    /** @var list<string> */
    private array $trace = [];

    protected function setUp(): void
    {
        $this->trace = [];
        GateFactory::flush();
    }

    protected function tearDown(): void
    {
        GateFactory::flush();
    }

    // ── addBefore list ────────────────────────────────────────────

    public function testBeforeHooksRunInRegistrationOrderAndAbstain(): void
    {
        $gate = $this->gateWithUser();
        $abilityRan = false;

        $gate->addBefore(function ($u, $ability) {
            $this->trace[] = 'b1';
        });
        $gate->addBefore(function ($u, $ability) {
            $this->trace[] = 'b2';
        });
        $gate->define('act', function () use (&$abilityRan) {
            $abilityRan = true;
            $this->trace[] = 'ability';

            return true;
        });

        $this->assertTrue($gate->allows('act'));
        $this->assertTrue($abilityRan);
        $this->assertSame(['b1', 'b2', 'ability'], $this->trace);
    }

    public function testFirstNonNullBeforeShortCircuitsAbilityAndAfterHooks(): void
    {
        $gate = $this->gateWithUser();

        $gate->addBefore(fn () => null);
        $gate->addBefore(fn () => true);
        $gate->define('act', function () {
            $this->fail('short-circuited ability must not run');
        });
        $gate->addAfter(function () {
            $this->fail('short-circuited decision must skip after hooks');
        });

        $this->assertTrue($gate->allows('act'));
    }

    public function testSingleSlotBeforeStillPrecedesTheList(): void
    {
        $gate = $this->gateWithUser();

        $gate->before(fn () => false);            // pinned last-writer-wins slot
        $gate->addBefore(fn () => true);          // list must NOT override it

        $this->assertFalse($gate->allows('anything'));
    }

    public function testGuestPreDeniesBeforeAnyListedHook(): void
    {
        $gate = $this->gateWithUser(null);

        $gate->addBefore(function () {
            $this->fail('guest short-circuit must precede hooks (existing pinned semantics)');
        });
        $gate->define('act', fn () => true);

        $this->assertFalse($gate->allows('act'));
    }

    // ── addAfter list ─────────────────────────────────────────────

    public function testAfterHooksSeePredecessorsResultAndLastNonNullWins(): void
    {
        $gate = $this->gateWithUser();
        $gate->define('act', fn () => false);

        $gate->addAfter(function ($u, $ability, $result) {
            $this->trace[] = 'a1 sees ' . \var_export($result, true);

            return true;                      // flips to allowed
        });
        $gate->addAfter(function ($u, $ability, $result) {
            $this->trace[] = 'a2 sees ' . \var_export($result, true);

            // abstains: a1's flip survives
        });

        $this->assertTrue($gate->allows('act'));
        $this->assertSame(['a1 sees false', 'a2 sees true'], $this->trace);
    }

    public function testLastAfterCanFlipBackToDeny(): void
    {
        $gate = $this->gateWithUser();
        $gate->define('act', fn () => false);
        $gate->addAfter(fn () => true);
        $gate->addAfter(fn () => false);

        $this->assertFalse($gate->allows('act'));
    }

    public function testSingleSlotAfterRunsBeforeTheList(): void
    {
        $gate = $this->gateWithUser();
        $gate->define('act', fn () => false);

        $gate->after(function () {
            $this->trace[] = 'slot';
        });
        $gate->addAfter(function () {
            $this->trace[] = 'listed';
        });

        $gate->allows('act');
        $this->assertSame(['slot', 'listed'], $this->trace);
    }

    public function testForUserCloneCarriesHookLists(): void
    {
        $gate = $this->gateWithUser(null);
        $gate->addBefore(fn () => true);
        $gate->define('act', fn () => false);

        $this->assertTrue($gate->forUser(new GenericUser(['id' => 9]))->allows('act'));
    }

    // ── GateFactory ───────────────────────────────────────────────

    public function testFactoryMemoizesOneGatePerName(): void
    {
        $auth = new AuthManager(['web' => new CallbackGuard(fn () => null)], 'web');
        $builds = 0;
        $builder = function (Gate $g) use (&$builds): void {
            $builds++;
            $g->define('shared.ability', fn () => true);
        };

        $first = GateFactory::make('siteA', $auth, $builder);
        $second = GateFactory::make('siteA', $auth, $builder);

        $this->assertSame($first, $second, 'same name => SAME Gate: module registrations must compose, not fork');
        $this->assertSame(1, $builds, 'builder runs exactly once (first registration wins)');
        $this->assertTrue(GateFactory::has('siteA'));

        $other = GateFactory::make('siteB', $auth);
        $this->assertNotSame($first, $other);
        $this->assertFalse($other->has('shared.ability'), 'gates are isolated per name');
    }

    public function testForgetAndFlushClearMemoization(): void
    {
        $auth = new AuthManager(['web' => new CallbackGuard(fn () => null)], 'web');
        $gate = GateFactory::make('siteA', $auth);

        GateFactory::forget('siteA');
        $this->assertFalse(GateFactory::has('siteA'));
        $this->assertNotSame($gate, GateFactory::make('siteA', $auth));

        GateFactory::flush();
        $this->assertFalse(GateFactory::has('siteA'));
    }

    private function gateWithUser(?AuthenticatableInterface $user = new GenericUser(['id' => 1])): Gate
    {
        $auth = new AuthManager(['web' => new CallbackGuard(fn () => $user)], 'web');

        return new Gate($auth);
    }
}
