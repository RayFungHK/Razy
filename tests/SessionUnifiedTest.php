<?php

/**
 * This file is part of Razy.
 *
 * RZ-05: one session subsystem. Opaque 64-hex cookie, stored under
 * sha256(cookie) hex (the format a non-PHP service can look up), Secure
 * resolved from the request, dist.php 'session' config, JSON rows in the
 * database driver, and the CSRF door on the dist's one session.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Container;
use Razy\Contract\SessionInterface;
use Razy\Csrf\CsrfDoor;
use Razy\Csrf\CsrfTokenManager;
use Razy\Distributor;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\RouteDispatcher;
use Razy\Exception\ConfigurationException;
use Razy\Session\Driver\ArrayDriver;
use Razy\Session\Driver\DatabaseDriver;
use Razy\Session\Driver\FileDriver;
use Razy\Session\Driver\LazyDriver;
use Razy\Session\Session;
use Razy\Session\SessionConfig;
use Razy\Session\SessionDoor;
use Razy\Session\SessionFactory;
use Razy\Session\SessionMiddleware;

#[CoversClass(Session::class)]
#[CoversClass(SessionConfig::class)]
#[CoversClass(SessionFactory::class)]
#[CoversClass(SessionDoor::class)]
#[CoversClass(DatabaseDriver::class)]
class SessionUnifiedTest extends TestCase
{
    private ?float $requestTime = null;

    protected function setUp(): void
    {
        unset($_COOKIE['RAZY_SESSION'], $_SERVER['HTTPS'], $_SERVER['SERVER_PORT'], $_SERVER['REQUEST_METHOD']);
        $_SERVER['REQUEST_TIME_FLOAT'] = $this->requestTime ??= (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true));
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    // ── Token format ──────────────────────────────────────────

    public function testNewIdsAre64Hex(): void
    {
        $session = $this->session();
        $session->start();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $session->getId());
    }

    public function testStorageKeyIsLowercaseSha256HexOfTheCookie(): void
    {
        // Python: hashlib.sha256(b"abc").hexdigest()
        $this->assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            Session::storageKey('abc'),
        );
    }

    public function testTheRawCookieIsNeverAStoreKey(): void
    {
        $driver = new ArrayDriver();
        $session = $this->session($driver);
        $session->set('user', 7);
        $id = $session->getId();
        $session->save();

        $this->assertSame([], $driver->read($id));
        $this->assertSame(7, $driver->read(Session::storageKey($id))['user'] ?? null);
    }

    public function testCarried64HexCookieIsAdopted(): void
    {
        $driver = new ArrayDriver();
        $id = \str_repeat('ab', 32);
        $driver->write(Session::storageKey($id), ['user' => 'kept']);
        $_COOKIE['RAZY_SESSION'] = $id;

        $session = $this->session($driver);
        $this->assertSame('kept', $session->get('user'));
        $this->assertSame($id, $session->getId());
    }

    public function testLegacy40HexSessionMigratesToTheHashedKey(): void
    {
        $driver = new ArrayDriver();
        $legacy = \str_repeat('c', 40);
        $driver->write($legacy, ['user' => 'legacy']);
        $_COOKIE['RAZY_SESSION'] = $legacy;

        $session = $this->session($driver);
        $this->assertSame('legacy', $session->get('user'));
        $session->save();

        $this->assertSame([], $driver->read($legacy), 'the plaintext-keyed row is removed');
        $this->assertSame('legacy', $driver->read(Session::storageKey($legacy))['user'] ?? null);
    }

    public function testRegenerateDestroysTheOldHashedRow(): void
    {
        $driver = new ArrayDriver();
        $session = $this->session($driver);
        $session->set('k', 'v');
        $old = $session->getId();
        $session->save();
        $session->start();

        $session->regenerate(true);
        $session->save();

        $this->assertSame([], $driver->read(Session::storageKey($old)));
        $this->assertSame('v', $driver->read(Session::storageKey($session->getId()))['k'] ?? null);
    }

    public function testDestroyRemovesTheHashedRow(): void
    {
        $driver = new ArrayDriver();
        $session = $this->session($driver);
        $session->set('k', 'v');
        $id = $session->getId();
        $session->save();
        $session->start();
        $session->destroy();

        $this->assertSame([], $driver->read(Session::storageKey($id)));
    }

    public function testReusedSessionObjectNeverCarriesStateIntoTheNextRequest(): void
    {
        // Worker mode keeps the Distributor, and so its session, across
        // requests. Request 2 must not inherit request 1's id or data.
        $driver = new ArrayDriver();
        $session = $this->session($driver);
        $requestTime = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? \microtime(true));

        $_COOKIE['RAZY_SESSION'] = $alice = \str_repeat('a1', 32);
        $session->start();
        $session->set('user', 'alice');
        $session->save();

        unset($_COOKIE['RAZY_SESSION']);
        $_SERVER['REQUEST_TIME_FLOAT'] = $requestTime + 1.0;
        $session->start();
        $this->assertNotSame($alice, $session->getId(), 'a cookieless request gets a fresh id');
        $this->assertNull($session->get('user'));
        $session->save();

        $_COOKIE['RAZY_SESSION'] = $bob = \str_repeat('b2', 32);
        $_SERVER['REQUEST_TIME_FLOAT'] = $requestTime + 2.0;
        $driver->write(Session::storageKey($bob), ['user' => 'bob']);
        $this->assertSame('bob', $session->get('user'), 'lazy start adopts the new cookie');
        $this->assertSame($bob, $session->getId());
    }

    public function testADifferentCookieInTheSameRequestMarkerIsStillNotInherited(): void
    {
        $driver = new ArrayDriver();
        $session = $this->session($driver);
        $_COOKIE['RAZY_SESSION'] = \str_repeat('a1', 32);
        $session->set('user', 'alice');
        $session->save();

        $_COOKIE['RAZY_SESSION'] = $bob = \str_repeat('b2', 32);
        $this->assertNull($session->get('user'));
        $this->assertSame($bob, $session->getId());
    }

    public function testReopeningWithinTheSameRequestResumes(): void
    {
        $session = $this->session();
        $session->set('user', 'kept');
        $id = $session->getId();
        $session->save();
        $session->start();
        $this->assertSame($id, $session->getId());
        $this->assertSame('kept', $session->get('user'));
    }

    public function testRotationSurvivesReopeningInTheSameRequest(): void
    {
        // setcookie() never updates $_COOKIE: the browser's old id is still
        // there after regenerate(); reopening must keep the rotated session.
        $driver = new ArrayDriver();
        $_COOKIE['RAZY_SESSION'] = $old = \str_repeat('a1', 32);
        $driver->write(Session::storageKey($old), ['user' => 'alice']);
        $session = $this->session($driver);
        $session->start();
        $session->regenerate(true);
        $new = $session->getId();
        $session->save();

        $this->assertSame('alice', $session->get('user'));
        $this->assertSame($new, $session->getId());
    }

    // ── Secure cookie ─────────────────────────────────────────

    public function testSecureDefaultsToAutoAndFollowsTheRequest(): void
    {
        $config = new SessionConfig();
        $this->assertNull($config->secure);
        $this->assertFalse($config->resolveSecure());

        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue($config->resolveSecure());
    }

    public function testExplicitSecureWins(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertFalse((new SessionConfig(secure: false))->resolveSecure());
        unset($_SERVER['HTTPS']);
        $this->assertTrue((new SessionConfig(secure: true))->resolveSecure());
    }

    public function testCookieOptionsUseTheResolvedSecureFlag(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $session = new class(new ArrayDriver(), new SessionConfig(gcProbability: 0)) extends Session {
            /** @return array<string, mixed> */
            public function options(): array
            {
                return $this->cookieOptions();
            }
        };
        $this->assertTrue($session->options()['secure']);
    }

    // ── dist.php 'session' config ─────────────────────────────

    public function testConfigFromArray(): void
    {
        $config = SessionConfig::fromArray([
            'name' => 'app_session',
            'lifetime' => 3600,
            'path' => '/app',
            'domain' => 'example.com',
            'secure' => 'auto',
            'http_only' => true,
            'same_site' => 'Strict',
        ]);
        $this->assertSame('app_session', $config->name);
        $this->assertSame(3600, $config->lifetime);
        $this->assertSame('/app', $config->path);
        $this->assertSame('example.com', $config->domain);
        $this->assertNull($config->secure);
        $this->assertSame('Strict', $config->sameSite);
    }

    public function testConfigFromArrayRejectsTyposAndBadValues(): void
    {
        foreach ([['nmae' => 'x'], ['same_site' => 'Sometimes'], ['secure' => 'yes'], ['lifetime' => -1], ['name' => 'bad name']] as $bad) {
            try {
                SessionConfig::fromArray($bad);
                $this->fail('accepted ' . \json_encode($bad));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFactoryBuildsEachDriver(): void
    {
        $this->assertInstanceOf(ArrayDriver::class, SessionFactory::create(['driver' => 'array'], 'd')->getDriver());

        $file = SessionFactory::create(['driver' => 'file', 'file' => ['path' => \sys_get_temp_dir()]], 'd');
        $this->assertInstanceOf(FileDriver::class, $file->getDriver());

        $pdo = new PDO('sqlite::memory:');
        $db = SessionFactory::create(['driver' => 'database', 'database' => ['pdo' => $pdo, 'table' => 'app_sessions', 'create_table' => true]], 'd');
        $this->assertInstanceOf(DatabaseDriver::class, $db->getDriver()->resolve());
        $this->assertSame('app_sessions', $db->getDriver()->resolve()->getTable());
    }

    public function testFactoryDefaultIsTheFileDriverAsBefore(): void
    {
        $this->assertInstanceOf(FileDriver::class, SessionFactory::create([], 'd')->getDriver());
    }

    public function testFactoryRejectsUnknownDriverAndMissingDatabase(): void
    {
        foreach ([['driver' => 'memcache'], ['driver' => 'database'], ['driver' => 'array', 'bogus' => 1]] as $bad) {
            try {
                SessionFactory::create($bad, 'd');
                $this->fail('accepted ' . \json_encode(\array_keys($bad)));
            } catch (ConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDatabaseDefaultTableIsPerDist(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $a = SessionFactory::create(['driver' => 'database', 'database' => ['pdo' => $pdo, 'create_table' => true]], 'shop-a');
        $b = SessionFactory::create(['driver' => 'database', 'database' => ['pdo' => $pdo, 'create_table' => true]], 'shop.b');
        $this->assertSame('razy_sessions_shop_a', $a->getDriver()->resolve()->getTable());
        $this->assertSame('razy_sessions_shop_b', $b->getDriver()->resolve()->getTable());
    }

    public function testDriversThatDoIoAreBuiltOnFirstUseNotAtBoot(): void
    {
        $session = SessionFactory::create(['driver' => 'database', 'database' => ['connection' => 'not-connected-yet']], 'd');
        $this->assertInstanceOf(LazyDriver::class, $session->getDriver(), 'boot (and CLI introspection) does no I/O');
        $this->expectException(ConfigurationException::class);
        $session->getDriver()->resolve();
    }

    // ── Database rows a non-PHP service can read ──────────────

    public function testDatabaseDriverWritesJsonAndStillReadsSerializedRows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $driver = new DatabaseDriver($pdo);
        $driver->createTable();

        $driver->write('k1', ['user_id' => 42, 'roles' => ['admin']]);
        $raw = $pdo->query("SELECT data FROM sessions WHERE id = 'k1'")->fetchColumn();
        $this->assertSame(['user_id' => 42, 'roles' => ['admin']], \json_decode((string) $raw, true));

        $pdo->exec("INSERT INTO sessions (id, data, last_activity) VALUES ('old', '" . \serialize(['legacy' => true]) . "', 0)");
        $this->assertSame(['legacy' => true], $driver->read('old'));
    }

    public function testUnencodableDataStillPersists(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $driver = new DatabaseDriver($pdo);
        $driver->createTable();
        $this->assertTrue($driver->write('k', ['bad' => "\xB1\x31", 'n' => 1]));
        $this->assertSame(['bad' => "\xB1\x31", 'n' => 1], $driver->read('k'));
    }

    // ── One session per dist; CSRF rides on it ────────────────

    public function testSessionDoorMountsMiddlewareAndBindsTheContainer(): void
    {
        $dispatcher = new RouteDispatcher();
        $session = $this->session();
        $distributor = $this->distributor($dispatcher, $container = new Container(), $session);

        SessionDoor::arm($distributor);

        $middleware = $dispatcher->getGlobalMiddleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(SessionMiddleware::class, $middleware[0]);
        $this->assertSame($session, $middleware[0]->getSession());
        $this->assertSame($session, $container->make(SessionInterface::class));
    }

    public function testCsrfDoorUsesTheDistSession(): void
    {
        $dispatcher = new RouteDispatcher();
        $session = $this->session();
        $distributor = $this->distributor($dispatcher, $container = new Container(), $session);

        CsrfDoor::arm($distributor);

        $middleware = $dispatcher->getGlobalMiddleware();
        $this->assertSame($session, $middleware[0]->getSession(), 'CSRF tokens live in the dist session');
        $this->assertSame($session, $container->make(CsrfTokenManager::class)->getSession());
    }

    public function testDistributorWiresSessionConfig(): void
    {
        $source = \str_replace("\r\n", "\n", (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Distributor.php'));
        $this->assertStringContainsString("SessionFactory::validate(\$config['session']", $source);
        $this->assertStringContainsString('SessionDoor::arm($this);', $source);
        $this->assertStringContainsString('public function getSession(): SessionInterface', $source);
        $csrfDoor = (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Csrf/CsrfDoor.php');
        $this->assertStringNotContainsString('new FileDriver(', $csrfDoor, 'no second, private session store');
    }

    // ── helpers ───────────────────────────────────────────────

    private function session(?ArrayDriver $driver = null): Session
    {
        return new Session($driver ?? new ArrayDriver(), new SessionConfig(gcProbability: 0));
    }

    private function distributor(RouteDispatcher $dispatcher, Container $container, SessionInterface $session): Distributor
    {
        $distributor = $this->createMock(Distributor::class);
        $distributor->method('getCode')->willReturn('sessiondist');
        $distributor->method('getRouter')->willReturn($dispatcher);
        $distributor->method('getRegistry')->willReturn($this->createMock(ModuleRegistry::class));
        $distributor->method('getContainer')->willReturn($container);
        $distributor->method('getSession')->willReturn($session);

        return $distributor;
    }
}
