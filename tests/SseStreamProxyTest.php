<?php

/**
 * RZ-16: SSE framing, heartbeat, Last-Event-ID, abort detection and the
 * StreamProxy relay.
 *
 * SSE.php had no tests, no abort detection, no heartbeat and no resume, and
 * proxy() sent curl_error() text to the browser.
 *
 * @license MIT
 */

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Error\ErrorReporter;
use Razy\Logger;
use Razy\SSE;
use Razy\SSE\StreamProxy;

#[CoversClass(SSE::class)]
#[CoversClass(StreamProxy::class)]
final class SseStreamProxyTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static ?string $base = null;

    private string $out = '';

    private Logger $log;

    public static function setUpBeforeClass(): void
    {
        $probe = @\stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            return;
        }
        $port = (int) \substr((string) \strrchr((string) \stream_socket_get_name($probe, false), ':'), 1);
        \fclose($probe);
        $cmd = [\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', __DIR__ . '/fixtures/sse'];
        $proc = @\proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($proc)) {
            return;
        }
        self::$server = $proc;
        for ($i = 0; $i < 50; ++$i) {
            $sock = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock !== false) {
                \fclose($sock);
                self::$base = 'http://127.0.0.1:' . $port . '/upstream.php';

                return;
            }
            \usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        self::$server = null;
        self::$base = null;
    }

    protected function setUp(): void
    {
        $this->out = '';
        $this->log = new Logger(null, 'debug', 'Y-m-d', true);
        ErrorReporter::setLogger($this->log);
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
    }

    protected function tearDown(): void
    {
        ErrorReporter::setLogger(null);
        unset($_SERVER['HTTP_LAST_EVENT_ID']);
    }

    public function testFramesAreWellFormed(): void
    {
        $sse = $this->sse();
        $sse->send("line one\r\nline two\rline three\nfour", 'update', '42');

        $this->assertSame(
            "retry: 3000\n\nid: 42\nevent: update\ndata: line one\ndata: line two\ndata: line three\ndata: four\n\n",
            $this->out,
        );
    }

    public function testDataCannotInjectFields(): void
    {
        $frame = SSE::frame("ok\revent: evil\r\nid: 9");
        $this->assertSame("data: ok\ndata: event: evil\ndata: id: 9\n\n", $frame);
    }

    public function testEventAndIdWithLineBreaksAreRejected(): void
    {
        foreach ([["x\ndata: evil", null], [null, "1\r"], [null, "1\0"]] as [$event, $id]) {
            try {
                SSE::frame('d', $event, $id);
                $this->fail('CR/LF/NUL must be rejected');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRetryAndComments(): void
    {
        $sse = $this->sse();
        $sse->retry(500);
        $sse->comment("a\nb");
        $sse->close();

        $this->assertSame("retry: 3000\n\nretry: 500\n\n: a\n: b\n\n: closed\n\n", $this->out);
        $this->expectException(InvalidArgumentException::class);
        $sse->retry(-1);
    }

    public function testHeartbeatOnlyAfterSilence(): void
    {
        $now = 100.0;
        $sse = new SSE(3000, fn (string $f) => $this->out .= $f, fn () => false, 15, function () use (&$now) {
            return $now;
        });
        $sse->send('x');
        $this->assertFalse($sse->heartbeatIfDue());

        $now += 14.9;
        $this->assertFalse($sse->heartbeatIfDue());
        $now += 0.2;
        $this->assertTrue($sse->heartbeatIfDue());
        $this->assertStringEndsWith(": ping\n\n", $this->out);
        $this->assertFalse($sse->heartbeatIfDue(), 'the heartbeat itself resets the timer');
    }

    public function testNothingIsWrittenAfterTheClientLeft(): void
    {
        $gone = false;
        $sse = new SSE(3000, fn (string $f) => $this->out .= $f, function () use (&$gone) {
            return $gone;
        });
        $sse->send('before');
        $gone = true;
        $sse->send('after');
        $gone = false; // a probe that flickers back must not resurrect the stream

        $this->assertTrue($sse->isAborted());
        $this->assertStringContainsString('before', $this->out);
        $this->assertStringNotContainsString('after', $this->out);
        $this->assertFalse($sse->writeRaw('x'));
    }

    public function testLastEventIdIsReadAndValidated(): void
    {
        $this->assertSame('17', SSE::lastEventIdFrom(['HTTP_LAST_EVENT_ID' => '17']));
        $this->assertNull(SSE::lastEventIdFrom([]));
        $this->assertNull(SSE::lastEventIdFrom(['HTTP_LAST_EVENT_ID' => "1\r\nX: y"]));
        $this->assertNull(SSE::lastEventIdFrom(['HTTP_LAST_EVENT_ID' => \str_repeat('a', 257)]));

        $_SERVER['HTTP_LAST_EVENT_ID'] = 'abc';
        $this->assertSame('abc', $this->sse()->lastEventId());
    }

    public function testProxyRelaysUpstreamEvents(): void
    {
        $base = $this->upstream();
        $result = (new StreamProxy($this->sse()))->relay($base . '?mode=events');

        $this->assertSame(StreamProxy::RESULT_COMPLETED, $result);
        $this->assertStringStartsWith("retry: 3000\n\n", $this->out);
        $this->assertStringContainsString("id: 0\ndata: one\n\nid: 1\ndata: two\n\nid: 2\ndata: three\n\n", $this->out);
    }

    public function testProxyForwardsLastEventId(): void
    {
        $base = $this->upstream();
        $_SERVER['HTTP_LAST_EVENT_ID'] = 'resume-7';
        (new StreamProxy($this->sse()))->relay($base . '?mode=echo');
        $this->assertStringContainsString("data: resume-7\n", $this->out);

        $this->out = '';
        (new StreamProxy($this->sse()))->relay($base . '?mode=echo', ['Last-Event-ID: caller']);
        $this->assertStringContainsString("data: caller\n", $this->out, 'a caller-set header wins');
    }

    public function testProxyCancelsTheUpstreamWhenTheClientLeaves(): void
    {
        $base = $this->upstream();
        $sse = new SSE(3000, fn (string $f) => $this->out .= $f, fn () => \str_contains($this->out, 'tick 1'));

        $started = \microtime(true);
        $result = (new StreamProxy($sse))->relay($base . '?mode=slow');

        $this->assertSame(StreamProxy::RESULT_ABORTED, $result);
        $this->assertLessThan(3.0, \microtime(true) - $started, 'the 5 s upstream must be cut short');
        $this->assertStringNotContainsString('tick 3', $this->out);
        $this->assertStringNotContainsString('event: error', $this->out);
    }

    public function testProxySendsHeartbeatsWhileTheUpstreamIsIdle(): void
    {
        $base = $this->upstream();
        $sse = new SSE(3000, fn (string $f) => $this->out .= $f, fn () => false, 1);

        $result = (new StreamProxy($sse))->relay($base . '?mode=idle');

        $this->assertSame(StreamProxy::RESULT_COMPLETED, $result);
        $this->assertStringContainsString(": ping\n\n", $this->out);
        $this->assertLessThan(\strpos($this->out, 'data: late'), \strpos($this->out, ': ping'));
    }

    public function testUpstreamErrorStatusIsNotRelayed(): void
    {
        $base = $this->upstream();
        $result = (new StreamProxy($this->sse()))->relay($base . '?mode=error&token=s3cret');

        $this->assertSame(StreamProxy::RESULT_FAILED, $result);
        $this->assertStringNotContainsString('secret stack', $this->out);
        $this->assertStringEndsWith("event: error\ndata: upstream unavailable\n\n", $this->out);
        $logged = \implode("\n", \array_column($this->log->getBuffer(), 'message'));
        $this->assertStringContainsString('HTTP 500', $logged);
        $this->assertStringNotContainsString('s3cret', $logged, 'query strings are not logged');
    }

    public function testTransportErrorTextNeverReachesTheClient(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $closedPort = \substr((string) \strrchr((string) \stream_socket_get_name($probe, false), ':'), 1);
        \fclose($probe);

        $result = (new StreamProxy($this->sse(), 2))->relay('http://127.0.0.1:' . $closedPort . '/stream');

        $this->assertSame(StreamProxy::RESULT_FAILED, $result);
        $this->assertSame("retry: 3000\n\nevent: error\ndata: upstream unavailable\n\n", $this->out);
        $this->assertStringContainsString('cURL error', \implode("\n", \array_column($this->log->getBuffer(), 'message')));
    }

    public function testDeprecatedProxyDelegatesWithoutLeakingErrors(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($probe);
        $closedPort = \substr((string) \strrchr((string) \stream_socket_get_name($probe, false), ':'), 1);
        \fclose($probe);

        $this->sse()->proxy('http://127.0.0.1:' . $closedPort . '/stream');

        $this->assertStringNotContainsString('Proxy error', $this->out);
        $this->assertStringContainsString("event: error\ndata: upstream unavailable\n\n", $this->out);
    }

    public function testNonHttpSchemesAreRefused(): void
    {
        $result = (new StreamProxy($this->sse()))->relay('file:///etc/passwd');

        $this->assertSame(StreamProxy::RESULT_FAILED, $result);
        $this->assertStringNotContainsString('root:', $this->out);
    }

    private function sse(): SSE
    {
        return new SSE(3000, fn (string $f) => $this->out .= $f, fn () => false);
    }

    private function upstream(): string
    {
        if (self::$base === null) {
            $this->markTestSkipped('Could not start a local php -S upstream.');
        }

        return self::$base;
    }
}
