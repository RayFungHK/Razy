<?php

/**
 * RZ-14: request id, structured log context and the JSON log formatter.
 *
 * Nothing tied a log line, an error report and an upstream call to the
 * request that caused them. Each request now gets an id (the client's
 * X-Request-ID when the policy accepts it), every log record carries it,
 * HttpClient forwards it, and RAZY_LOG_FORMAT=json writes one JSON object
 * per line to stderr.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Log\LogLevel;
use Razy\Error\ErrorReporter;
use Razy\Http\HttpClient;
use Razy\Http\RequestId;
use Razy\Http\TrustedProxies;
use Razy\Log\JsonFormatter;
use Razy\Log\LineFormatter;
use Razy\Log\LogContext;
use Razy\Log\LogManager;
use Razy\Log\RuntimeLogging;
use Razy\Log\StderrHandler;
use Razy\Logger;
use RuntimeException;
use stdClass;

#[CoversClass(RequestId::class)]
#[CoversClass(LogContext::class)]
#[CoversClass(JsonFormatter::class)]
#[CoversClass(LineFormatter::class)]
#[CoversClass(RuntimeLogging::class)]
final class RequestIdLoggingTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearEnv();
        RequestId::reset();
        ErrorReporter::setLogger(null);
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        RequestId::reset();
        ErrorReporter::setLogger(null);
        TrustedProxies::configure(null);
    }

    public function testValidIncomingIdIsKept(): void
    {
        $id = RequestId::begin(['HTTP_X_REQUEST_ID' => 'edge-1234:abc.DEF_9']);

        $this->assertSame('edge-1234:abc.DEF_9', $id);
        $this->assertSame($id, RequestId::current());
        $this->assertSame(['request_id' => $id], LogContext::all());
    }

    public function testUnsafeIncomingIdIsReplaced(): void
    {
        foreach (["abc\r\nSet-Cookie: x=1", 'has space', '"quoted"', \str_repeat('a', 129), ''] as $bad) {
            $id = RequestId::begin(['HTTP_X_REQUEST_ID' => $bad]);
            $this->assertNotSame($bad, $id);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', RequestId::begin([]));
    }

    public function testAcceptPolicies(): void
    {
        $server = ['HTTP_X_REQUEST_ID' => 'from-client', 'REMOTE_ADDR' => '203.0.113.9'];

        $this->assertNotSame('from-client', RequestId::begin($server, RequestId::ACCEPT_NONE));
        $this->assertNotSame('from-client', RequestId::begin($server, RequestId::ACCEPT_TRUSTED_PROXY));

        TrustedProxies::configure(TrustedProxies::fromList('203.0.113.0/24'));
        $this->assertSame('from-client', RequestId::begin($server, RequestId::ACCEPT_TRUSTED_PROXY));
    }

    public function testPolicyEnvWinsOverConfig(): void
    {
        $this->assertSame('any', RequestId::policyFromConfig([]));
        $this->assertSame('none', RequestId::policyFromConfig(['request_id_accept' => 'none']));
        $this->assertSame('any', RequestId::policyFromConfig(['request_id_accept' => 'bogus']));

        $_ENV['RAZY_REQUEST_ID_ACCEPT'] = 'trusted_proxy';
        $this->assertSame('trusted_proxy', RequestId::policyFromConfig(['request_id_accept' => 'none']));
    }

    public function testEachRequestStartsWithAFreshContext(): void
    {
        RequestId::begin(['HTTP_X_REQUEST_ID' => 'first']);
        LogContext::set('tenant', 'blue');

        RequestId::begin(['HTTP_X_REQUEST_ID' => 'second']);
        $this->assertSame(['request_id' => 'second'], LogContext::all(), 'worker requests must not inherit fields');

        RequestId::reset();
        $this->assertNull(RequestId::current());
        $this->assertSame([], LogContext::all());
    }

    public function testLoggersCarryTheContextAndCallKeysWin(): void
    {
        RequestId::begin(['HTTP_X_REQUEST_ID' => 'rid-1']);
        LogContext::set('tenant', 'blue');

        $logger = new Logger(null, LogLevel::DEBUG, 'Y-m-d', true);
        $logger->info('hello', ['tenant' => 'override']);
        $this->assertSame(['tenant' => 'override', 'request_id' => 'rid-1'], $logger->getBuffer()[0]['context']);

        $manager = new LogManager('app', true);
        $manager->warning('hi');
        $this->assertSame(['request_id' => 'rid-1', 'tenant' => 'blue'], $manager->getBuffer()[0]['context']);
    }

    public function testStderrHandlerWritesJsonLines(): void
    {
        $stream = \fopen('php://memory', 'w+');
        $manager = new LogManager('app');
        $manager->addHandler('app', new StderrHandler(LogLevel::INFO, new JsonFormatter(), $stream));

        RequestId::begin(['HTTP_X_REQUEST_ID' => 'rid-json']);
        $manager->debug('dropped by level');
        $manager->error("line one\nline two", ['user_id' => 7, 'exception' => new RuntimeException('boom')]);

        \rewind($stream);
        $lines = \array_filter(\explode("\n", (string) \stream_get_contents($stream)));
        $this->assertCount(1, $lines);
        $record = \json_decode((string) \reset($lines), true);

        $this->assertSame('error', $record['level']);
        $this->assertSame('app', $record['channel']);
        $this->assertSame("line one\nline two", $record['message'], 'JSON escapes newlines itself');
        $this->assertSame('rid-json', $record['request_id']);
        $this->assertSame(['user_id' => 7], $record['context']);
        $this->assertSame(RuntimeException::class, $record['exception']['class']);
        $this->assertSame('boom', $record['exception']['message']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}[+-]\d\d:\d\d$/', $record['ts']);
    }

    public function testJsonFormatterSurvivesOddValues(): void
    {
        $line = (new JsonFormatter())->format('info', "bad \xB1 utf8", [
            'nan' => \NAN,
            'obj' => new stdClass(),
            'deep' => [[[[[[['x']]]]]]],
        ], '2026-10-21 08:00:00.000000', '');

        $record = \json_decode($line, true);
        $this->assertIsArray($record);
        $this->assertArrayNotHasKey('channel', $record);
        $this->assertSame('NAN', $record['context']['nan']);
        $this->assertSame('[object stdClass]', $record['context']['obj']);
        $this->assertStringContainsString('[depth]', $line);
    }

    public function testTextLinesGetTheRequestId(): void
    {
        $stream = \fopen('php://memory', 'w+');
        (new StderrHandler(LogLevel::DEBUG, null, $stream))
            ->handle('warning', "a\nb", ['request_id' => 'rid-9'], '2026-10-21 08:00:00.000000', 'app');
        \rewind($stream);

        $this->assertSame('[2026-10-21 08:00:00.000000] [app] [WARNING] a b rid=rid-9' . \PHP_EOL, \stream_get_contents($stream));
    }

    public function testHttpClientForwardsTheRequestId(): void
    {
        $client = new class() extends HttpClient {
            /** @return list<string> */
            public function headersFor(array $options = []): array
            {
                return $this->buildRequestHeaders($options);
            }
        };

        $this->assertNotContains('X-Request-Id: rid-up', $client->headersFor());

        RequestId::begin(['HTTP_X_REQUEST_ID' => 'rid-up']);
        $this->assertContains('X-Request-Id: rid-up', $client->headersFor());
        $this->assertContains('X-Request-Id: mine', $client->headersFor(['headers' => ['X-Request-ID' => 'mine']]));
    }

    public function testRuntimeLoggingJsonInstallsAStderrLoggerOnce(): void
    {
        $this->assertNull(RuntimeLogging::configure([]), 'text is the default: nothing changes');
        $this->assertNull(ErrorReporter::getLogger());

        $logger = RuntimeLogging::configure(['log_format' => 'json', 'log_level' => 'warning']);
        $this->assertInstanceOf(LogManager::class, $logger);
        $this->assertSame($logger, ErrorReporter::getLogger());
        $handler = $logger->getHandlers('razy')[0];
        $this->assertInstanceOf(StderrHandler::class, $handler);
        $this->assertInstanceOf(JsonFormatter::class, $handler->getFormatter());
        $this->assertSame('warning', $handler->getMinLevel());

        $this->assertNull(RuntimeLogging::configure(['log_format' => 'json']), 'an existing logger is kept');
    }

    public function testRuntimeLoggingEnvWins(): void
    {
        $_ENV['RAZY_LOG_FORMAT'] = 'JSON';
        $_ENV['RAZY_LOG_LEVEL'] = 'error';
        $this->assertSame('json', RuntimeLogging::format(['log_format' => 'text']));
        $this->assertSame('error', RuntimeLogging::level(['log_level' => 'debug']));
        $this->assertSame('error', RuntimeLogging::level([]), 'env level applies even without config');
    }

    private function clearEnv(): void
    {
        foreach (['RAZY_REQUEST_ID_ACCEPT', 'RAZY_LOG_FORMAT', 'RAZY_LOG_LEVEL'] as $name) {
            unset($_ENV[$name]);
            \putenv($name);
        }
    }
}
