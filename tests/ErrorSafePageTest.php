<?php

/**
 * RZ-01 — production-safe error handling (regression pins).
 *
 * Before RZ-01 the exception page printed message, file and line on every
 * response (debug or not), unescaped; QueryException embedded the full SQL
 * in getMessage(); nothing was logged; RAZY_DEBUG did not exist and worker
 * mode dropped debug after the first request (Error::reset() in `finally`).
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Error;
use Razy\Error\ErrorConfig;
use Razy\Error\ErrorRenderer;
use Razy\Error\ErrorReporter;
use Razy\Exception\QueryException;
use Razy\Logger;
use RuntimeException;
use stdClass;
use Stringable;

#[CoversClass(ErrorRenderer::class)]
#[CoversClass(ErrorReporter::class)]
#[CoversClass(ErrorConfig::class)]
class ErrorSafePageTest extends TestCase
{
    private Logger $logger;

    /** @var string|false RAZY_DEBUG as the process had it before the test */
    private string|false $previousDebugEnv = false;

    protected function setUp(): void
    {
        Error::reset();
        $this->previousDebugEnv = \getenv('RAZY_DEBUG');
        \putenv('RAZY_DEBUG');
        $this->logger = new Logger(null, 'debug', 'Y-m-d', true);
        Error::setLogger($this->logger);
    }

    protected function tearDown(): void
    {
        Error::reset();
        Error::setLogger(null);
        \putenv(false === $this->previousDebugEnv ? 'RAZY_DEBUG' : 'RAZY_DEBUG=' . $this->previousDebugEnv);
    }

    // -- Production page ---------------------------------------------------

    public function testProductionPageShowsHeadingAndErrorIdOnly(): void
    {
        $e = new RuntimeException('secret <b>details</b> /var/www/app.php');
        $html = ErrorRenderer::render($e, 'abc123def4567890');

        $this->assertStringContainsString('abc123def4567890', $html);
        $this->assertStringContainsString(Error::DEFAULT_HEADING, $html);
        $this->assertStringNotContainsString('secret', $html, 'message must not reach a production page');
        $this->assertStringNotContainsString(__FILE__, $html, 'file path must not reach a production page');
        $this->assertStringNotContainsString('Stack Trace', $html);
    }

    public function testProductionPageHidesRazyErrorMessageButKeepsHeading(): void
    {
        $e = new Error('internal: user 42 not found', 500, 'Something broke');
        $html = ErrorRenderer::render($e, 'id0000000000000a');

        $this->assertStringContainsString('Something broke', $html);
        $this->assertStringNotContainsString('user 42', $html);
    }

    public function testHeadingIsEscaped(): void
    {
        $e = new Error('x', 500, '<script>alert(1)</script>');
        $html = ErrorRenderer::render($e, 'id0000000000000b');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testDebugPageShowsEscapedDetails(): void
    {
        ErrorConfig::setDebug(true);
        $e = new RuntimeException('bad <img src=x onerror=alert(1)> value');
        $html = ErrorRenderer::render($e, 'id0000000000000c');

        $this->assertStringContainsString('id0000000000000c', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString(\htmlspecialchars(__FILE__), $html);
        $this->assertStringContainsString('Stack Trace', $html);
    }

    public function testDebugPageShowsSqlOfQueryException(): void
    {
        ErrorConfig::setDebug(true);
        $e = new QueryException('SQLSTATE[42S02]: no such table', 500, null, 'SELECT * FROM `missing`');
        $html = ErrorRenderer::render($e, 'id0000000000000d');

        $this->assertStringContainsString('SELECT * FROM `missing`', $html);
    }

    // -- SQL out of getMessage() -------------------------------------------

    public function testQueryExceptionKeepsSqlOutOfMessage(): void
    {
        $e = new QueryException('SQLSTATE[23000]: duplicate', 500, null, "INSERT INTO `u` (`pw`) VALUES ('hunter2')");

        $this->assertSame('SQLSTATE[23000]: duplicate', $e->getMessage());
        $this->assertSame("INSERT INTO `u` (`pw`) VALUES ('hunter2')", $e->getSql());
    }

    public function testQueryExceptionWithoutSqlIsBackwardCompatible(): void
    {
        $e = new QueryException('Invalid SQL');
        $this->assertSame('Invalid SQL', $e->getMessage());
        $this->assertNull($e->getSql());
    }

    public function testCliOutputStillShowsSqlOfQueryException(): void
    {
        $this->expectOutputRegex('/SQL: SELECT \* FROM `missing`/');
        ErrorRenderer::showException(new QueryException('no such table', 500, null, 'SELECT * FROM `missing`'));
    }

    public function testDatabaseExecuteDoesNotPutSqlInMessage(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/library/Razy/Database.php');
        $this->assertStringNotContainsString(
            '->getMessage() . "\n" . $sql',
            $src,
            'Database must pass SQL to QueryException as a separate argument, not concatenate it into the message',
        );
    }

    // -- Error::report() ---------------------------------------------------

    public function testReportLogsAndReturnsId(): void
    {
        $e = new QueryException('boom', 500, null, 'SELECT 1');
        $id = Error::report($e, ['route' => '/x']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $buffer = $this->logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame('error', $buffer[0]['level']);
        $this->assertStringContainsString($id, $buffer[0]['message']);
        $this->assertStringContainsString('boom', $buffer[0]['message']);
        $this->assertSame($id, $buffer[0]['context']['error_id']);
        $this->assertSame('SELECT 1', $buffer[0]['context']['sql']);
        $this->assertSame('/x', $buffer[0]['context']['route']);
        $this->assertSame($e, $buffer[0]['context']['exception']);
    }

    public function testReportIdsAreUnique(): void
    {
        $e = new RuntimeException('x');
        $this->assertNotSame(Error::report($e), Error::report($e));
    }

    public function testReportFallbackSurvivesNonStringSqlContext(): void
    {
        Error::setLogger(null);
        $log = \tempnam(\sys_get_temp_dir(), 'razy_err');
        $previous = \ini_set('error_log', (string) $log);

        try {
            $id = Error::report(new RuntimeException('x'), ['sql' => new stdClass()]);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
            $this->assertStringContainsString($id, (string) \file_get_contents((string) $log));
        } finally {
            \ini_set('error_log', (string) $previous);
            @\unlink((string) $log);
        }
    }

    public function testReportFallbackRecordsLoggerFailure(): void
    {
        $broken = new class(null) extends Logger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk full');
            }
        };
        Error::setLogger($broken);
        $log = \tempnam(\sys_get_temp_dir(), 'razy_err');
        $previous = \ini_set('error_log', (string) $log);

        try {
            $id = Error::report(new RuntimeException('original problem'));
            $written = (string) \file_get_contents((string) $log);
            $this->assertStringContainsString('original problem', $written);
            $this->assertStringContainsString('configured error logger failed', $written);
            $this->assertStringContainsString('disk full', $written);
            $this->assertStringContainsString($id, $written);
        } finally {
            \ini_set('error_log', (string) $previous);
            @\unlink((string) $log);
        }
    }

    // -- RAZY_DEBUG env override -------------------------------------------

    public function testEnvOverrideHonouredOnLoopback(): void
    {
        \putenv('RAZY_DEBUG=1');
        ErrorConfig::configure(['debug' => false], ['SERVER_NAME' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1']);
        $this->assertTrue(ErrorConfig::isDebug());

        ErrorConfig::configure(['debug' => false], ['SERVER_NAME' => '::1', 'REMOTE_ADDR' => '::1']);
        $this->assertTrue(ErrorConfig::isDebug());
    }

    public function testEnvOverrideRefusedOnPublicHost(): void
    {
        \putenv('RAZY_DEBUG=1');
        ErrorConfig::configure(['debug' => false], ['SERVER_NAME' => 'example.com', 'REMOTE_ADDR' => '203.0.113.9']);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    public function testEnvOverrideRefusedWhenHostSpoofedAsLocalhost(): void
    {
        \putenv('RAZY_DEBUG=1');
        ErrorConfig::configure(['debug' => false], ['SERVER_NAME' => 'localhost', 'REMOTE_ADDR' => '203.0.113.9']);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    public function testEnvOverrideRefusedWithoutClientAddress(): void
    {
        \putenv('RAZY_DEBUG=1');
        ErrorConfig::configure(['debug' => false], ['SERVER_NAME' => 'localhost']);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    public function testEnvOverrideCanAlwaysTurnDebugOff(): void
    {
        \putenv('RAZY_DEBUG=0');
        ErrorConfig::configure(['debug' => true], ['SERVER_NAME' => 'example.com', 'REMOTE_ADDR' => '203.0.113.9']);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    public function testNoEnvKeepsConfigValue(): void
    {
        ErrorConfig::configure(['debug' => true], ['SERVER_NAME' => 'example.com']);
        $this->assertTrue(ErrorConfig::isDebug());
    }

    // -- Worker mode keeps debug -------------------------------------------

    public function testWorkerHandlerReappliesConfigEveryRequest(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__) . '/src/main.php');
        $handlerPos = \strpos($src, '$handler = function ()');
        $this->assertNotFalse($handlerPos);
        $finallyPos = \strpos($src, 'Error::reset();', $handlerPos);
        $configurePos = \strpos($src, 'Error::configure($razyConfig);', $handlerPos);

        $this->assertNotFalse($configurePos, 'worker handler must re-apply debug config per request');
        $this->assertLessThan($finallyPos, $configurePos, 'configure must run at request start, before the finally reset');
    }
}
