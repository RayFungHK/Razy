<?php

/**
 * RZ-13: readiness checks and GET /_razy/ready.
 *
 * `/_razy/health` always said ok, so a dist whose database was down still
 * looked healthy. Modules now register checks; the dist answers the readiness
 * probe from them (503 on a critical failure) before the session and routes.
 *
 * @license MIT
 */

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor;
use Razy\Domain;
use Razy\Error\ErrorReporter;
use Razy\Health;
use Razy\Health\HealthRegistry;
use Razy\Logger;
use Razy\Util\PathUtil;
use RuntimeException;

#[CoversClass(HealthRegistry::class)]
#[CoversClass(Health::class)]
final class HealthReadinessTest extends TestCase
{
    private const DIST = 'rz13_ready_fixture';

    private string $distPath;

    /** @var array<string, mixed> */
    private array $server;

    private Logger $log;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        unset($_ENV['RAZY_HEALTH_TOKEN']);
        \putenv('RAZY_HEALTH_TOKEN');
        // Check failures are reported as warnings; keep them in memory, not on stderr.
        $this->log = new Logger(null, 'debug', 'Y-m-d', true);
        ErrorReporter::setLogger($this->log);
        $GLOBALS['rz13_db_up'] = true;
        $GLOBALS['rz13_routed'] = false;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        unset($_ENV['RAZY_HEALTH_TOKEN'], $GLOBALS['rz13_db_up'], $GLOBALS['rz13_routed']);
        \putenv('RAZY_HEALTH_TOKEN');
        ErrorReporter::setLogger(null);
        if (isset($this->distPath)) {
            $this->removeDir($this->distPath);
        }
    }

    public function testAllChecksPassingIsOk(): void
    {
        $registry = (new HealthRegistry())
            ->register('db', fn () => true)
            ->register('cache', function (): void {
            });

        $report = $registry->run();

        $this->assertSame('ok', $report['status']);
        $this->assertSame(['db', 'cache'], \array_keys($report['checks']));
        $this->assertSame('ok', $report['checks']['cache']['status'], 'a check that returns nothing passes');
        $this->assertTrue($report['checks']['db']['critical']);
        $this->assertIsFloat($report['checks']['db']['duration_ms']);
    }

    public function testCriticalFailureFailsAndNonCriticalDegrades(): void
    {
        $degraded = (new HealthRegistry())
            ->register('db', fn () => true)
            ->register('search', fn () => false, false);
        $this->assertSame('degraded', $degraded->run()['status']);

        $failed = (new HealthRegistry())
            ->register('search', fn () => false, false)
            ->register('db', fn () => throw new RuntimeException('connection refused'))
            ->register('cache', fn () => false, false);
        $report = $failed->run();

        $this->assertSame('fail', $report['status'], 'a later non-critical failure must not downgrade fail');
        $this->assertSame('returned false', $report['checks']['search']['error']);
        $this->assertSame('RuntimeException: connection refused', $report['checks']['db']['error']);
    }

    public function testFailuresAreLogged(): void
    {
        (new HealthRegistry())->register('db', fn () => throw new RuntimeException('down'))->run();

        $messages = \array_column($this->log->getBuffer(), 'message');
        $this->assertSame(["Health check 'db' failed: RuntimeException: down"], $messages);
    }

    public function testRegisterReplacesAndRemoves(): void
    {
        $registry = (new HealthRegistry())->register('db', fn () => false)->register('db', fn () => true);
        $this->assertSame(['db'], $registry->names());
        $this->assertSame('ok', $registry->run()['status']);

        $registry->remove('db');
        $this->assertFalse($registry->has('db'));
        $this->assertSame(['status' => 'ok', 'checks' => []], $registry->run());
    }

    public function testInvalidNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new HealthRegistry())->register("db\nInjected: 1", fn () => true);
    }

    public function testReadinessHidesErrorsWithoutTheToken(): void
    {
        $registry = (new HealthRegistry())->register('db', fn () => throw new RuntimeException('host db-7 refused'));

        [$status, $payload] = Health::readiness($registry);
        $this->assertSame(503, $status);
        $this->assertSame('fail', $payload['status']);
        $this->assertArrayNotHasKey('error', ((array) $payload['checks'])['db']);

        $_ENV['RAZY_HEALTH_TOKEN'] = 'probe-secret';
        [, $wrong] = Health::readiness($registry, ['token' => 'nope']);
        $this->assertArrayNotHasKey('error', ((array) $wrong['checks'])['db']);
        [, $granted] = Health::readiness($registry, ['token' => 'probe-secret']);
        $this->assertSame('RuntimeException: host db-7 refused', ((array) $granted['checks'])['db']['error']);
    }

    public function testDegradedIsStill200AndEmptyChecksEncodeAsObject(): void
    {
        [$status] = Health::readiness((new HealthRegistry())->register('search', fn () => false, false));
        $this->assertSame(200, $status);

        [$status, $payload] = Health::readiness(new HealthRegistry());
        $this->assertSame(200, $status);
        $this->assertStringContainsString('"checks":{}', (string) \json_encode($payload));
    }

    public function testReadyPathMatching(): void
    {
        $this->assertTrue(Health::isReadyPath('/_razy/ready'));
        $this->assertTrue(Health::isReadyPath('/_razy/ready/'));
        $this->assertFalse(Health::isReadyPath('/_razy/readyz'));
        $this->assertFalse(Health::isReadyPath('/api/_razy/ready'));

        $this->assertTrue(Health::isReadyProbe('/_razy/ready?token=x'));
        $this->assertTrue(Health::isReadyProbe('/shop/_razy/ready/'), 'a dist mounted below / has its own probe');
        $this->assertFalse(Health::isReadyProbe('/_razy/readyz'));
        $this->assertFalse(Health::isReadyProbe('/products/'));
    }

    public function testDistAnswersReadyFromModuleChecksBeforeRoutes(): void
    {
        $this->makeDist();

        $up = \json_decode($this->request('/_razy/ready'), true);
        $this->assertSame('ok', $up['status']);
        $this->assertSame(['acme/probe:db', 'acme/probe:search'], \array_keys($up['checks']));
        $this->assertFalse($GLOBALS['rz13_routed'], 'the probe never reaches the route table');
        $this->assertSame(\PHP_SESSION_NONE, \session_status(), 'the probe never opens a session');

        $GLOBALS['rz13_db_up'] = false;
        $down = \json_decode($this->request('/_razy/ready'), true);
        $this->assertSame('fail', $down['status']);
        $this->assertSame('fail', $down['checks']['acme/probe:db']['status']);
        $this->assertFalse($down['checks']['acme/probe:search']['critical']);
    }

    public function testOtherPathsStillRoute(): void
    {
        $this->makeDist();

        $this->assertSame('routed', $this->request('/probe/hello/'));
        $this->assertTrue($GLOBALS['rz13_routed']);
    }

    private function makeDist(): void
    {
        $this->distPath = PathUtil::append(SITES_FOLDER, self::DIST);
        $controller = PathUtil::append($this->distPath, 'acme', 'probe', 'default', 'controller');
        \mkdir($controller, 0o777, true);
        \file_put_contents($this->distPath . '/dist.php', "<?php\nreturn ['dist' => '" . self::DIST . "'];\n");
        \file_put_contents($this->distPath . '/acme/probe/module.php', "<?php\nreturn ['module_code' => 'acme/probe', 'author' => 't', 'description' => 't'];\n");
        \file_put_contents($this->distPath . '/acme/probe/default/package.php', "<?php\nreturn ['alias' => 'probe'];\n");
        \file_put_contents($controller . '/probe.php', <<<'PHP'
            <?php
            use Razy\Controller;
            return new class(null) extends Controller {
                public function __onInit($agent): bool
                {
                    $agent->addScript(['hello' => 'hello']);
                    return true;
                }
                public function __onLoad($agent): bool
                {
                    $agent->addHealthCheck('db', fn () => $GLOBALS['rz13_db_up']);
                    $agent->addHealthCheck('search', fn () => true, false);
                    return true;
                }
            };
            PHP);
        \file_put_contents($controller . '/probe.hello.php', <<<'PHP'
            <?php
            return function () {
                $GLOBALS['rz13_routed'] = true;
                echo 'routed';
            };
            PHP);
    }

    private function request(string $path): string
    {
        $_SERVER['HTTP_HOST'] = 'ready.example.com';
        $_SERVER['SERVER_NAME'] = 'ready.example.com';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $domain = $this->createStub(Domain::class);
        $domain->method('getDomainName')->willReturn('ready.example.com');
        $dist = new Distributor(self::DIST, '*', $domain, '/', $path);
        $dist->initialize();

        \ob_start();
        try {
            $dist->matchRoute();
        } finally {
            $out = (string) \ob_get_clean();
        }

        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            \is_dir($p) ? $this->removeDir($p) : \unlink($p);
        }
        \rmdir($dir);
    }
}
