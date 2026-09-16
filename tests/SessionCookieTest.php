<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;

/**
 * CSRF-RAIL L0: the session owns its cookie.
 *
 * Before this milestone SessionConfig's cookie fields were decoration —
 * nothing read $_COOKIE, nothing ever emitted a cookie, and first-party
 * queue-admin hand-rolled its own transport citing exactly that. These
 * tests pin the adoption (carried id), minting (fresh emit), rotation
 * (regenerate re-sends), and removal (destroy expires) through the
 * emitCookie/expireCookie seams (the real ones are inert under CLI).
 */
#[CoversClass(Session::class)]
#[CoversClass(SessionConfig::class)]
class SessionCookieTest extends TestCase
{
    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        unset($_COOKIE['RAZY_SESSION'], $_COOKIE['MY_SESS']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['RAZY_SESSION'], $_COOKIE['MY_SESS']);
    }
    // ────────────────────────────────────────────────────────────
    // Section 1: Adoption and minting
    // ────────────────────────────────────────────────────────────

    public function testFreshSessionMintsAndEmitsCookie(): void
    {
        $session = $this->recording();

        $this->assertTrue($session->start());
        $this->assertSame(40, \strlen($session->getId()));
        $this->assertTrue(\ctype_xdigit($session->getId()));
        // Brand-new identity must reach the browser or nothing survives.
        $this->assertSame([$session->getId()], $session->emitted);
    }

    public function testValidCarriedCookieIsAdoptedNotReissued(): void
    {
        $id = \bin2hex(\random_bytes(20));

        $driver = new ArrayDriver();
        $driver->open();
        $driver->write($id, ['k' => 'v']);
        $driver->close();

        $_COOKIE['RAZY_SESSION'] = $id;
        $session = $this->recording($driver);

        $session->start();

        // The browser's session is ITS session, not a fresh one …
        $this->assertSame($id, $session->getId());
        $this->assertSame('v', $session->get('k'));
        // … and an adopted id needs no Set-Cookie echo per request.
        $this->assertSame([], $session->emitted);
    }

    public function testMalformedCookieIsDiscardedNotTrusted(): void
    {
        // Shape mirrors generateId(): 40 hex. Both failure axes drop it —
        // the driver is never queried with attacker-shaped bytes.
        foreach ([\str_repeat('a', 39), \str_repeat('z', 40), 'not even close'] as $junk) {
            $_COOKIE['RAZY_SESSION'] = $junk;
            $session = $this->recording();

            $session->start();

            $this->assertNotSame($junk, $session->getId());
            $this->assertSame([$session->getId()], $session->emitted, 'junk cookie must mint+emit fresh');
        }
    }

    public function testCookieNameFollowsConfigNotHardcoding(): void
    {
        $id = \bin2hex(\random_bytes(20));
        $_COOKIE['RAZY_SESSION'] = \str_repeat('f', 40); // wrong-name value ignored
        $_COOKIE['MY_SESS'] = $id;

        $session = $this->recording(new ArrayDriver(), new SessionConfig(name: 'MY_SESS'));
        $session->start();

        $this->assertSame($id, $session->getId());
    }

    public function testExplicitIdSetByCallerWinsOverCookie(): void
    {
        // BC: anyone already driving setId() manually keeps working —
        // the cookie is only consulted when no id was preset.
        $_COOKIE['RAZY_SESSION'] = \str_repeat('a', 40);
        $session = $this->recording();
        $session->setId(\str_repeat('b', 40));

        $session->start();

        $this->assertSame(\str_repeat('b', 40), $session->getId());
        $this->assertSame([], $session->emitted);
    }

    // ────────────────────────────────────────────────────────────
    // Section 2: Rotation and removal
    // ────────────────────────────────────────────────────────────

    public function testRegenerateWhileStartedReEmitsTheNewId(): void
    {
        $session = $this->recording();
        $session->start();
        $session->emitted = [];

        $old = $session->getId();
        $session->regenerate();

        $this->assertNotSame($old, $session->getId());
        // An invisible rotation resurrects the OLD id next request —
        // the browser must be told.
        $this->assertSame([$session->getId()], $session->emitted);
    }

    public function testDestroyExpiresTheCookie(): void
    {
        $session = $this->recording();
        $session->start();

        $session->destroy();

        $this->assertTrue($session->expired);
    }

    // ────────────────────────────────────────────────────────────
    // Section 3: Options mapping and real-seam inertness
    // ────────────────────────────────────────────────────────────

    public function testCookieOptionsMapEveryConfigField(): void
    {
        $config = new SessionConfig(
            name: 'S',
            lifetime: 3600,
            path: '/app',
            domain: 'example.test',
            secure: true,
            httpOnly: false,
            sameSite: 'Strict',
        );
        $session = $this->recording(new ArrayDriver(), $config);

        $options = $session->optionsArray();

        $this->assertGreaterThanOrEqual(\time() + 3590, $options['expires']);
        $this->assertSame('/app', $options['path']);
        $this->assertSame('example.test', $options['domain']);
        $this->assertTrue($options['secure']);
        $this->assertFalse($options['httponly']);
        $this->assertSame('Strict', $options['samesite']);

        $this->assertLessThan(\time(), $session->optionsArray(true)['expires']);
    }

    public function testBrowserSessionLifetimeMapsToZeroExpiry(): void
    {
        $options = $this->recording()->optionsArray();

        $this->assertSame(0, $options['expires']);
        $this->assertSame('/', $options['path']);
        $this->assertTrue($options['httponly']);
        $this->assertSame('Lax', $options['samesite']);
    }

    public function testRealSeamsAreInertUnderCliSapi(): void
    {
        // The PRODUCTION emit paths run here (PHPUnit = CLI SAPI): the
        // guard must swallow them without notice, warning, or crash —
        // that is what keeps 30 years of CLI scripts session-safe.
        $session = new Session(new ArrayDriver());

        $this->assertTrue($session->start());
        $session->regenerate();
        $session->set('k', 'v');
        $session->save();
        $session->destroy();

        $this->assertFalse($session->isStarted());
    }

    private function recording(?ArrayDriver $driver = null, ?SessionConfig $config = null): RecordingSession
    {
        return new RecordingSession($driver ?? new ArrayDriver(), $config ?? new SessionConfig());
    }
}

/**
 * Records seam calls the real (guarded) methods cannot make under CLI.
 */
class RecordingSession extends Session
{
    /** @var list<string> ids whose cookie was (pretend-)emitted */
    public array $emitted = [];

    public bool $expired = false;

    /**
     * Expose the pure options mapping for direct assertion.
     *
     * @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    public function optionsArray(bool $expire = false): array
    {
        return $this->cookieOptions($expire);
    }

    protected function emitCookie(): void
    {
        $this->emitted[] = $this->getId();
    }

    protected function expireCookie(): void
    {
        $this->expired = true;
    }
}
