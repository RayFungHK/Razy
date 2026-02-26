<?php

/**
 * Tests for RestartSignal - file-based worker signal mechanism.
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Worker\RestartSignal;

#[CoversClass(RestartSignal::class)]
class RestartSignalTest extends TestCase
{
    private string $signalPath;

    protected function setUp(): void
    {
        $this->signalPath = \sys_get_temp_dir() . '/razy_signal_test_' . \uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (\is_file($this->signalPath)) {
            \unlink($this->signalPath);
        }
        $tmp = $this->signalPath . '.tmp.' . \getmypid();
        if (\is_file($tmp)) {
            \unlink($tmp);
        }
    }

    public function testCheckReturnsNullWhenNoSignalFile(): void
    {
        $this->assertNull(RestartSignal::check($this->signalPath));
    }

    public function testSendAndCheckRestartSignal(): void
    {
        $result = RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART, reason: 'deploy v2');
        $this->assertTrue($result);

        $signal = RestartSignal::check($this->signalPath);
        $this->assertNotNull($signal);
        $this->assertSame('restart', $signal['action']);
        $this->assertSame('deploy v2', $signal['reason']);
        $this->assertArrayHasKey('timestamp', $signal);
    }

    public function testSendSwapSignalWithModules(): void
    {
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_SWAP, ['modA', 'modB'], 'config update');

        $signal = RestartSignal::check($this->signalPath);
        $this->assertSame('swap', $signal['action']);
        $this->assertSame(['modA', 'modB'], $signal['modules']);
        $this->assertSame('config update', $signal['reason']);
    }

    public function testSendTerminateSignal(): void
    {
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_TERMINATE);

        $signal = RestartSignal::check($this->signalPath);
        $this->assertSame('terminate', $signal['action']);
    }

    public function testClearRemovesSignalFile(): void
    {
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART);
        $this->assertTrue(\is_file($this->signalPath));

        $this->assertTrue(RestartSignal::clear($this->signalPath));
        $this->assertFalse(\is_file($this->signalPath));
    }

    public function testClearReturnsTrueWhenFileDoesNotExist(): void
    {
        $this->assertTrue(RestartSignal::clear($this->signalPath));
    }

    public function testCheckReturnsNullForInvalidJson(): void
    {
        \file_put_contents($this->signalPath, 'not json');
        $this->assertNull(RestartSignal::check($this->signalPath));
    }

    public function testCheckReturnsNullForMissingAction(): void
    {
        \file_put_contents($this->signalPath, \json_encode(['timestamp' => \time()]));
        $this->assertNull(RestartSignal::check($this->signalPath));
    }

    public function testCheckReturnsNullForInvalidAction(): void
    {
        \file_put_contents($this->signalPath, \json_encode(['action' => 'invalid']));
        $this->assertNull(RestartSignal::check($this->signalPath));
    }

    public function testCheckReturnsNullForEmptyFile(): void
    {
        \file_put_contents($this->signalPath, '');
        $this->assertNull(RestartSignal::check($this->signalPath));
    }

    public function testIsStaleReturnsTrueForOldSignal(): void
    {
        $signal = ['action' => 'restart', 'timestamp' => \time() - 600];
        $this->assertTrue(RestartSignal::isStale($signal, 300));
    }

    public function testIsStaleReturnsFalseForFreshSignal(): void
    {
        $signal = ['action' => 'restart', 'timestamp' => \time()];
        $this->assertFalse(RestartSignal::isStale($signal, 300));
    }

    public function testIsStaleReturnsTrueForMissingTimestamp(): void
    {
        $signal = ['action' => 'restart'];
        $this->assertTrue(RestartSignal::isStale($signal));
    }

    public function testGetDefaultPath(): void
    {
        $path = RestartSignal::getDefaultPath('/var/www/site');
        $this->assertStringEndsWith('.worker-signal', $path);
    }

    public function testConstants(): void
    {
        $this->assertSame('restart', RestartSignal::ACTION_RESTART);
        $this->assertSame('swap', RestartSignal::ACTION_SWAP);
        $this->assertSame('terminate', RestartSignal::ACTION_TERMINATE);
        $this->assertSame('.worker-signal', RestartSignal::DEFAULT_FILENAME);
    }

    public function testSendWithoutOptionalParams(): void
    {
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART);
        $signal = RestartSignal::check($this->signalPath);

        $this->assertSame('restart', $signal['action']);
        $this->assertArrayHasKey('timestamp', $signal);
        $this->assertArrayNotHasKey('modules', $signal);
        $this->assertArrayNotHasKey('reason', $signal);
    }

    public function testSendOverwritesPreviousSignal(): void
    {
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART, reason: 'first');
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_SWAP, reason: 'second');

        $signal = RestartSignal::check($this->signalPath);
        $this->assertSame('swap', $signal['action']);
        $this->assertSame('second', $signal['reason']);
    }
}
