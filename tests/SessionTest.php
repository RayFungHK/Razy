<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\SessionDriverInterface;
use Razy\Contract\SessionInterface;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Driver\NullDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\SessionMiddleware;
use RuntimeException;

/**
 * Tests for P9: Session Abstraction Layer.
 *
 * Covers Session, SessionConfig, SessionMiddleware,
 * ArrayDriver, NullDriver, and both contracts.
 */
#[CoversClass(Session::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(SessionMiddleware::class)]
#[CoversClass(ArrayDriver::class)]
#[CoversClass(NullDriver::class)]
class SessionTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: SessionConfig
    // ═══════════════════════════════════════════════════════════════

    public function testConfigDefaults(): void
    {
        $config = new SessionConfig();
        $this->assertSame('RAZY_SESSION', $config->name);
        $this->assertSame(0, $config->lifetime);
        $this->assertSame('/', $config->path);
        $this->assertSame('', $config->domain);
        $this->assertFalse($config->secure);
        $this->assertTrue($config->httpOnly);
        $this->assertSame('Lax', $config->sameSite);
        $this->assertSame(1440, $config->gcMaxLifetime);
        $this->assertSame(1, $config->gcProbability);
        $this->assertSame(100, $config->gcDivisor);
    }

    public function testConfigCustomValues(): void
    {
        $config = new SessionConfig(
            name: 'MY_APP',
            lifetime: 3600,
            path: '/app',
            domain: '.example.com',
            secure: true,
            httpOnly: false,
            sameSite: 'Strict',
            gcMaxLifetime: 7200,
        );
        $this->assertSame('MY_APP', $config->name);
        $this->assertSame(3600, $config->lifetime);
        $this->assertSame('/app', $config->path);
        $this->assertSame('.example.com', $config->domain);
        $this->assertTrue($config->secure);
        $this->assertFalse($config->httpOnly);
        $this->assertSame('Strict', $config->sameSite);
        $this->assertSame(7200, $config->gcMaxLifetime);
    }

    public function testConfigWith(): void
    {
        $config = new SessionConfig();
        $new = $config->with(['name' => 'CUSTOM', 'secure' => true]);
        $this->assertSame('CUSTOM', $new->name);
        $this->assertTrue($new->secure);
        // Original unchanged
        $this->assertSame('RAZY_SESSION', $config->name);
        $this->assertFalse($config->secure);
    }

    public function testConfigWithPartialOverrides(): void
    {
        $config = new SessionConfig(name: 'APP', lifetime: 100);
        $new = $config->with(['lifetime' => 999]);
        $this->assertSame('APP', $new->name); // preserved
        $this->assertSame(999, $new->lifetime); // overridden
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Session Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function testStartReturnsTrue(): void
    {
        $session = $this->makeSession();
        $this->assertTrue($session->start());
    }

    public function testIsStartedFalseBeforeStart(): void
    {
        $session = $this->makeSession();
        $this->assertFalse($session->isStarted());
    }

    public function testIsStartedTrueAfterStart(): void
    {
        $session = $this->makeSession();
        $session->start();
        $this->assertTrue($session->isStarted());
    }

    public function testStartIdempotent(): void
    {
        $session = $this->makeSession();
        $session->start();
        $id = $session->getId();
        $session->start(); // second call should be no-op
        $this->assertTrue($session->isStarted());
        $this->assertSame($id, $session->getId());
    }

    public function testSaveWritesAndCloses(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('key', 'value');
        $session->save();
        $this->assertFalse($session->isStarted());

        // Data was persisted
        $driver = $this->getDriver($session);
        $this->assertSame(1, $driver->count());
    }

    public function testSaveWithoutStartIsNoOp(): void
    {
        $session = $this->makeSession();
        $session->save(); // should not throw
        $this->assertFalse($session->isStarted());
    }

    public function testDestroyRemovesData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('key', 'value');
        $id = $session->getId();
        $session->destroy();

        $this->assertFalse($session->isStarted());
        $driver = $this->getDriver($session);
        $this->assertSame([], $driver->read($id));
    }

    public function testDataPersistsAcrossStartSaveCycles(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // First request
        $session1 = new Session($driver, $config);
        $session1->start();
        $id = $session1->getId();
        $session1->set('user', 'Ray');
        $session1->save();

        // Second request (same driver, same ID)
        $session2 = new Session($driver, $config);
        $session2->setId($id);
        $session2->start();
        $this->assertSame('Ray', $session2->get('user'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Session ID Management
    // ═══════════════════════════════════════════════════════════════

    public function testGetIdReturnsEmptyBeforeStart(): void
    {
        $session = $this->makeSession();
        $this->assertSame('', $session->getId());
    }

    public function testGetIdReturnsNonEmptyAfterStart(): void
    {
        $session = $this->makeSession();
        $session->start();
        $this->assertNotEmpty($session->getId());
    }

    public function testSetIdBeforeStart(): void
    {
        $session = $this->makeSession();
        $session->setId('custom-id-123');
        $session->start();
        $this->assertSame('custom-id-123', $session->getId());
    }

    public function testGeneratedIdIsHexString(): void
    {
        $session = $this->makeSession();
        $session->start();
        $id = $session->getId();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $id);
    }

    public function testRegenerateChangesId(): void
    {
        $session = $this->makeSession();
        $session->start();
        $oldId = $session->getId();
        $session->regenerate();
        $newId = $session->getId();
        $this->assertNotSame($oldId, $newId);
    }

    public function testRegeneratePreservesData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('name', 'Ray');
        $session->regenerate();
        $this->assertSame('Ray', $session->get('name'));
    }

    public function testRegenerateDestroyOldRemovesOldData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('name', 'Ray');
        $oldId = $session->getId();
        $session->save();

        // Regenerate with destroy
        $session->start();
        $session->regenerate(destroyOld: true);

        $driver = $this->getDriver($session);
        $this->assertSame([], $driver->read($oldId));
    }

    public function testRegenerateWithoutDestroyKeepsOldData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('name', 'Ray');
        $oldId = $session->getId();
        $session->save();

        $session->setId($oldId);
        $session->start();
        $session->regenerate(destroyOld: false);

        $driver = $this->getDriver($session);
        // Old session data still exists
        $oldData = $driver->read($oldId);
        $this->assertArrayHasKey('name', $oldData);
    }

    public function testUniqueIdsGenerated(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; ++$i) {
            $session = $this->makeSession();
            $session->start();
            $ids[] = $session->getId();
        }
        // All IDs should be unique
        $this->assertCount(100, \array_unique($ids));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: Data Access (get / set / has / remove / all / clear)
    // ═══════════════════════════════════════════════════════════════

    public function testSetAndGet(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('name', 'Ray');
        $this->assertSame('Ray', $session->get('name'));
    }

    public function testGetDefaultWhenMissing(): void
    {
        $session = $this->makeSession();
        $session->start();
        $this->assertNull($session->get('nonexistent'));
        $this->assertSame('fallback', $session->get('nonexistent', 'fallback'));
    }

    public function testHas(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('key', 'value');
        $this->assertTrue($session->has('key'));
        $this->assertFalse($session->has('missing'));
    }

    public function testHasWithNullValue(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('nullable', null);
        $this->assertTrue($session->has('nullable'));
    }

    public function testRemove(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('key', 'value');
        $session->remove('key');
        $this->assertFalse($session->has('key'));
    }

    public function testAll(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('a', 1);
        $session->set('b', 2);
        $all = $session->all();
        $this->assertSame(1, $all['a']);
        $this->assertSame(2, $all['b']);
    }

    public function testClear(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('a', 1);
        $session->set('b', 2);
        $session->clear();
        $this->assertSame([], $session->all());
    }

    public function testOverwriteValue(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('key', 'old');
        $session->set('key', 'new');
        $this->assertSame('new', $session->get('key'));
    }

    public function testComplexDataTypes(): void
    {
        $session = $this->makeSession();
        $session->start();

        $session->set('array', ['a', 'b', 'c']);
        $this->assertSame(['a', 'b', 'c'], $session->get('array'));

        $session->set('int', 42);
        $this->assertSame(42, $session->get('int'));

        $session->set('bool', true);
        $this->assertTrue($session->get('bool'));

        $session->set('null', null);
        $this->assertNull($session->get('null'));

        $session->set('nested', ['user' => ['name' => 'Ray', 'age' => 30]]);
        $this->assertSame(['user' => ['name' => 'Ray', 'age' => 30]], $session->get('nested'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: Flash Data
    // ═══════════════════════════════════════════════════════════════

    public function testFlashSetAndGet(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->flash('message', 'Success!');
        $this->assertSame('Success!', $session->getFlash('message'));
    }

    public function testHasFlash(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->flash('message', 'Hello');
        $this->assertTrue($session->hasFlash('message'));
        $this->assertFalse($session->hasFlash('other'));
    }

    public function testGetFlashDefault(): void
    {
        $session = $this->makeSession();
        $session->start();
        $this->assertNull($session->getFlash('missing'));
        $this->assertSame('default', $session->getFlash('missing', 'default'));
    }

    public function testFlashSurvivesOneRequest(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Request 1: set flash
        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->flash('msg', 'Hello');
        $s1->save();

        // Request 2: flash is available
        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $this->assertTrue($s2->hasFlash('msg'));
        $this->assertSame('Hello', $s2->getFlash('msg'));
        $s2->save();

        // Request 3: flash is gone (aged out)
        $s3 = new Session($driver, $config);
        $s3->setId($id);
        $s3->start();
        $this->assertFalse($s3->hasFlash('msg'));
    }

    public function testReflashKeepsAllFlashData(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Request 1: set flash
        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->flash('a', '1');
        $s1->flash('b', '2');
        $s1->save();

        // Request 2: reflash everything
        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $s2->reflash();
        $s2->save();

        // Request 3: data still available
        $s3 = new Session($driver, $config);
        $s3->setId($id);
        $s3->start();
        $this->assertTrue($s3->hasFlash('a'));
        $this->assertTrue($s3->hasFlash('b'));
    }

    public function testKeepSelectiveFlashKeys(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Request 1: set multiple flashes
        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->flash('keep_me', 'yes');
        $s1->flash('drop_me', 'no');
        $s1->save();

        // Request 2: keep only one
        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $s2->keep(['keep_me']);
        $s2->save();

        // Request 3: only kept key remains
        $s3 = new Session($driver, $config);
        $s3->setId($id);
        $s3->start();
        $this->assertTrue($s3->hasFlash('keep_me'));
        $this->assertFalse($s3->hasFlash('drop_me'));
    }

    public function testFlashOverwrites(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->flash('msg', 'First');
        $session->flash('msg', 'Second');
        $this->assertSame('Second', $session->getFlash('msg'));
    }

    public function testFlashDoesNotPolluteRegularData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->set('persistent', 'yes');
        $session->flash('temporary', 'value');

        $this->assertSame('yes', $session->get('persistent'));
        // Flash data is stored internally but not as regular attribute
        $this->assertNull($session->get('temporary'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: ArrayDriver
    // ═══════════════════════════════════════════════════════════════

    public function testArrayDriverOpenClose(): void
    {
        $driver = new ArrayDriver();
        $this->assertFalse($driver->isOpened());
        $driver->open();
        $this->assertTrue($driver->isOpened());
        $driver->close();
        $this->assertFalse($driver->isOpened());
    }

    public function testArrayDriverReadEmpty(): void
    {
        $driver = new ArrayDriver();
        $this->assertSame([], $driver->read('nonexistent'));
    }

    public function testArrayDriverWriteAndRead(): void
    {
        $driver = new ArrayDriver();
        $driver->write('sess1', ['user' => 'Ray']);
        $this->assertSame(['user' => 'Ray'], $driver->read('sess1'));
    }

    public function testArrayDriverDestroy(): void
    {
        $driver = new ArrayDriver();
        $driver->write('sess1', ['data' => 'value']);
        $driver->destroy('sess1');
        $this->assertSame([], $driver->read('sess1'));
    }

    public function testArrayDriverCount(): void
    {
        $driver = new ArrayDriver();
        $driver->write('a', ['x' => 1]);
        $driver->write('b', ['y' => 2]);
        $this->assertSame(2, $driver->count());
    }

    public function testArrayDriverGc(): void
    {
        $driver = new ArrayDriver();
        $driver->write('fresh', ['a' => 1]);
        $driver->write('old', ['b' => 2]);

        // Backdate the 'old' session by 2 hours
        $driver->setSessionTime('old', \time() - 7200);

        // GC with 1-hour max lifetime
        $deleted = $driver->gc(3600);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $driver->count());
        $this->assertSame([], $driver->read('old'));
        $this->assertSame(['a' => 1], $driver->read('fresh'));
    }

    public function testArrayDriverGetLastGcCount(): void
    {
        $driver = new ArrayDriver();
        $driver->write('x', ['d' => 1]);
        $driver->setSessionTime('x', \time() - 9999);
        $driver->gc(100);
        $this->assertSame(1, $driver->getLastGcCount());
    }

    public function testArrayDriverGetSessions(): void
    {
        $driver = new ArrayDriver();
        $driver->write('id1', ['key' => 'val']);
        $sessions = $driver->getSessions();
        $this->assertArrayHasKey('id1', $sessions);
        $this->assertSame(['key' => 'val'], $sessions['id1']['data']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: NullDriver
    // ═══════════════════════════════════════════════════════════════

    public function testNullDriverReturnsEmptyArray(): void
    {
        $driver = new NullDriver();
        $this->assertSame([], $driver->read('any'));
    }

    public function testNullDriverWriteReturnsTrue(): void
    {
        $driver = new NullDriver();
        $this->assertTrue($driver->write('id', ['data' => 'value']));
    }

    public function testNullDriverDestroyReturnsTrue(): void
    {
        $driver = new NullDriver();
        $this->assertTrue($driver->destroy('id'));
    }

    public function testNullDriverOpenClose(): void
    {
        $driver = new NullDriver();
        $this->assertTrue($driver->open());
        $this->assertTrue($driver->close());
    }

    public function testNullDriverGcReturnsZero(): void
    {
        $driver = new NullDriver();
        $this->assertSame(0, $driver->gc(1440));
    }

    public function testNullDriverSessionDoesNotPersist(): void
    {
        $driver = new NullDriver();
        $config = new SessionConfig(gcProbability: 0);

        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->set('name', 'Ray');
        $s1->save();

        // Next "request" — data is gone
        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $this->assertNull($s2->get('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: SessionMiddleware
    // ═══════════════════════════════════════════════════════════════

    public function testMiddlewareStartsAndSavesSession(): void
    {
        $session = $this->makeSession();
        $middleware = new SessionMiddleware($session);

        $handlerCalled = false;
        $sessionStartedInHandler = false;

        $result = $middleware->handle([], function (array $ctx) use ($session, &$handlerCalled, &$sessionStartedInHandler) {
            $handlerCalled = true;
            $sessionStartedInHandler = $session->isStarted();
            $session->set('in_handler', true);

            return 'response';
        });

        $this->assertTrue($handlerCalled);
        $this->assertTrue($sessionStartedInHandler);
        $this->assertSame('response', $result);
        // After middleware completes, session is saved/closed
        $this->assertFalse($session->isStarted());
    }

    public function testMiddlewareSavesEvenOnException(): void
    {
        $session = $this->makeSession();
        $middleware = new SessionMiddleware($session);

        try {
            $middleware->handle([], function () {
                throw new RuntimeException('Handler error');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // Session should still be saved/closed
        $this->assertFalse($session->isStarted());
    }

    public function testMiddlewarePassesContextToNext(): void
    {
        $session = $this->makeSession();
        $middleware = new SessionMiddleware($session);
        $receivedContext = null;

        $middleware->handle(['route' => '/test'], function (array $ctx) use (&$receivedContext) {
            $receivedContext = $ctx;
        });

        $this->assertSame(['route' => '/test'], $receivedContext);
    }

    public function testMiddlewareGetSession(): void
    {
        $session = $this->makeSession();
        $middleware = new SessionMiddleware($session);
        $this->assertSame($session, $middleware->getSession());
    }

    public function testMiddlewareImplementsInterface(): void
    {
        $session = $this->makeSession();
        $middleware = new SessionMiddleware($session);
        $this->assertInstanceOf(\Razy\Contract\MiddlewareInterface::class, $middleware);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Interface Contracts
    // ═══════════════════════════════════════════════════════════════

    public function testSessionImplementsSessionInterface(): void
    {
        $session = $this->makeSession();
        $this->assertInstanceOf(SessionInterface::class, $session);
    }

    public function testArrayDriverImplementsDriverInterface(): void
    {
        $driver = new ArrayDriver();
        $this->assertInstanceOf(SessionDriverInterface::class, $driver);
    }

    public function testNullDriverImplementsDriverInterface(): void
    {
        $driver = new NullDriver();
        $this->assertInstanceOf(SessionDriverInterface::class, $driver);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Session with Config
    // ═══════════════════════════════════════════════════════════════

    public function testSessionGetConfig(): void
    {
        $config = new SessionConfig(name: 'MY_SESS');
        $session = new Session(new ArrayDriver(), $config);
        $this->assertSame('MY_SESS', $session->getConfig()->name);
    }

    public function testSessionGetDriver(): void
    {
        $driver = new ArrayDriver();
        $session = new Session($driver, new SessionConfig(gcProbability: 0));
        $this->assertSame($driver, $session->getDriver());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: Integration — Multi-Request Simulation
    // ═══════════════════════════════════════════════════════════════

    public function testFullRequestLifecycle(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Request 1: Login
        $s1 = new Session($driver, $config);
        $mw1 = new SessionMiddleware($s1);
        $sessionId = '';
        $mw1->handle([], function () use ($s1, &$sessionId) {
            $s1->set('user_id', 42);
            $s1->set('role', 'admin');
            $s1->flash('welcome', 'Welcome back, Admin!');
            $sessionId = $s1->getId();
        });

        // Request 2: Dashboard — session persists, flash available
        $s2 = new Session($driver, $config);
        $s2->setId($sessionId);
        $mw2 = new SessionMiddleware($s2);
        $mw2->handle([], function () use ($s2) {
            $this->assertSame(42, $s2->get('user_id'));
            $this->assertSame('admin', $s2->get('role'));
            $this->assertSame('Welcome back, Admin!', $s2->getFlash('welcome'));
        });

        // Request 3: Another page — flash is gone
        $s3 = new Session($driver, $config);
        $s3->setId($sessionId);
        $mw3 = new SessionMiddleware($s3);
        $mw3->handle([], function () use ($s3) {
            $this->assertSame(42, $s3->get('user_id'));
            $this->assertFalse($s3->hasFlash('welcome'));
        });
    }

    public function testSessionRegenerationAfterLogin(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Pre-auth session
        $s1 = new Session($driver, $config);
        $s1->start();
        $preAuthId = $s1->getId();
        $s1->set('cart', ['item1', 'item2']);
        $s1->save();

        // Login — regenerate to prevent session fixation
        $s2 = new Session($driver, $config);
        $s2->setId($preAuthId);
        $s2->start();
        $s2->regenerate(destroyOld: true);
        $postAuthId = $s2->getId();
        $s2->set('user_id', 99);
        $s2->save();

        // Old session destroyed
        $this->assertSame([], $driver->read($preAuthId));
        $this->assertNotSame($preAuthId, $postAuthId);

        // New session has data (cart was preserved in-memory)
        $s3 = new Session($driver, $config);
        $s3->setId($postAuthId);
        $s3->start();
        $this->assertSame(99, $s3->get('user_id'));
        $this->assertSame(['item1', 'item2'], $s3->get('cart'));
    }

    public function testDestroyAndCreateNewSession(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Setup session
        $s1 = new Session($driver, $config);
        $s1->start();
        $s1->set('user', 'Ray');
        $s1->save();
        $this->assertSame(1, $driver->count());

        // Destroy (logout)
        $s1->start();
        $s1->destroy();
        $this->assertSame(0, $driver->count());
    }

    public function testConcurrentSessions(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Two different users
        $sa = new Session($driver, $config);
        $sa->start();
        $sa->set('user', 'Alice');
        $sa->save();

        $sb = new Session($driver, $config);
        $sb->start();
        $sb->set('user', 'Bob');
        $sb->save();

        $this->assertSame(2, $driver->count());

        // Reload each
        $sa2 = new Session($driver, $config);
        $sa2->setId($sa->getId());
        $sa2->start();
        $this->assertSame('Alice', $sa2->get('user'));

        $sb2 = new Session($driver, $config);
        $sb2->setId($sb->getId());
        $sb2->start();
        $this->assertSame('Bob', $sb2->get('user'));
    }

    public function testMultipleFlashMessagesAcrossRequests(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        // Request 1
        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->flash('success', 'Item created.');
        $s1->flash('info', 'You have 5 new notifications.');
        $s1->save();

        // Request 2: both available
        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $this->assertSame('Item created.', $s2->getFlash('success'));
        $this->assertSame('You have 5 new notifications.', $s2->getFlash('info'));
        // Add new flash in same request
        $s2->flash('warning', 'Session expiring soon.');
        $s2->save();

        // Request 3: old flashes gone, new one available
        $s3 = new Session($driver, $config);
        $s3->setId($id);
        $s3->start();
        $this->assertFalse($s3->hasFlash('success'));
        $this->assertFalse($s3->hasFlash('info'));
        $this->assertTrue($s3->hasFlash('warning'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testRemoveNonexistentKey(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->remove('never_set'); // should not throw
        $this->assertFalse($session->has('never_set'));
    }

    public function testDestroyWithoutStart(): void
    {
        $session = $this->makeSession();
        $session->destroy(); // should not throw
        $this->assertFalse($session->isStarted());
    }

    public function testClearPreservesSessionId(): void
    {
        $session = $this->makeSession();
        $session->start();
        $id = $session->getId();
        $session->set('key', 'value');
        $session->clear();
        $this->assertSame($id, $session->getId());
        $this->assertTrue($session->isStarted());
    }

    public function testFlashWithNullValue(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->flash('nullable', null);
        $this->assertTrue($session->hasFlash('nullable'));
        $this->assertNull($session->getFlash('nullable'));
    }

    public function testRegenerateBeforeStart(): void
    {
        $session = $this->makeSession();
        $result = $session->regenerate();
        $this->assertTrue($result);
        $this->assertNotEmpty($session->getId());
    }

    public function testEmptySessionSavesCleanly(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->save();
        $driver = $this->getDriver($session);
        $this->assertSame(1, $driver->count());
    }

    public function testArrayDriverDestroyNonexistent(): void
    {
        $driver = new ArrayDriver();
        $this->assertTrue($driver->destroy('nonexistent'));
    }

    public function testKeepWithEmptyArray(): void
    {
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 0);

        $s1 = new Session($driver, $config);
        $s1->start();
        $id = $s1->getId();
        $s1->flash('msg', 'hi');
        $s1->save();

        $s2 = new Session($driver, $config);
        $s2->setId($id);
        $s2->start();
        $s2->keep([]); // keep nothing
        $s2->save();

        $s3 = new Session($driver, $config);
        $s3->setId($id);
        $s3->start();
        $this->assertFalse($s3->hasFlash('msg'));
    }

    public function testReflashWhenNoFlashData(): void
    {
        $session = $this->makeSession();
        $session->start();
        $session->reflash(); // no flash data — should not throw
        $session->save();
        $this->assertFalse($session->isStarted());
    }

    public function testGcTriggeredByConfig(): void
    {
        // Use probability=1, divisor=1 to guarantee GC runs
        $driver = new ArrayDriver();
        $config = new SessionConfig(gcProbability: 1, gcDivisor: 1, gcMaxLifetime: 100);

        // Insert an old session
        $driver->write('old', ['x' => 1]);
        $driver->setSessionTime('old', \time() - 200);

        // Starting a new session triggers GC
        $session = new Session($driver, $config);
        $session->start();

        // Old session should be cleaned up
        $this->assertSame([], $driver->read('old'));
    }

    public function testSessionMiddlewareChainedWithOtherMiddleware(): void
    {
        $session = $this->makeSession();
        $sessionMw = new SessionMiddleware($session);

        // Simulate an outer middleware wrapping session middleware
        $outerCalled = false;
        $result = $sessionMw->handle(['key' => 'val'], function (array $ctx) use (&$outerCalled, $session) {
            $outerCalled = true;
            $session->set('from_handler', $ctx['key']);

            return 'done';
        });

        $this->assertTrue($outerCalled);
        $this->assertSame('done', $result);
    }

    private function makeSession(?SessionConfig $config = null): Session
    {
        // Use gcProbability=0 by default to avoid random GC in tests
        return new Session(
            new ArrayDriver(),
            $config ?? new SessionConfig(gcProbability: 0),
        );
    }

    private function getDriver(Session $session): ArrayDriver
    {
        $driver = $session->getDriver();
        \assert($driver instanceof ArrayDriver);

        return $driver;
    }
}
