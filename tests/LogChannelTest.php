<?php
/**
 * Comprehensive tests for #17: Log Channels / Handlers.
 *
 * Covers NullHandler, FileHandler, StderrHandler, LogManager,
 * LogLevel, PSR-3 convenience methods, buffering, and integration.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Log\InvalidArgumentException;
use Razy\Contract\Log\LogHandlerInterface;
use Razy\Contract\Log\LogLevel;
use Razy\Log\FileHandler;
use Razy\Log\LogManager;
use Razy\Log\NullHandler;
use Razy\Log\StderrHandler;

#[CoversClass(LogManager::class)]
#[CoversClass(FileHandler::class)]
#[CoversClass(NullHandler::class)]
#[CoversClass(StderrHandler::class)]
class LogChannelTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/razy_log_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            if ($files) {
                array_map('unlink', $files);
            }
            rmdir($this->tempDir);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  1. NullHandler
    // ═══════════════════════════════════════════════════════

    public function testNullHandlerImplementsInterface(): void
    {
        $h = new NullHandler();
        $this->assertInstanceOf(LogHandlerInterface::class, $h);
    }

    public function testNullHandlerIsHandlingAlwaysTrue(): void
    {
        $h = new NullHandler();
        foreach ([LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::EMERGENCY] as $level) {
            $this->assertTrue($h->isHandling($level));
        }
    }

    public function testNullHandlerHandleDoesNotThrow(): void
    {
        $h = new NullHandler();
        $h->handle(LogLevel::ERROR, 'test message', [], '2025-01-01', 'test');
        $this->addToAssertionCount(1); // Passes if no exception
    }

    // ═══════════════════════════════════════════════════════
    //  2. FileHandler — Construction & Configuration
    // ═══════════════════════════════════════════════════════

    public function testFileHandlerCreatesDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);
        new FileHandler($this->tempDir);
        $this->assertDirectoryExists($this->tempDir);
    }

    public function testFileHandlerGetDirectory(): void
    {
        $h = new FileHandler($this->tempDir);
        $this->assertSame($this->tempDir, $h->getDirectory());
    }

    public function testFileHandlerDefaultMinLevelIsDebug(): void
    {
        $h = new FileHandler($this->tempDir);
        $this->assertSame(LogLevel::DEBUG, $h->getMinLevel());
    }

    public function testFileHandlerSetMinLevel(): void
    {
        $h = new FileHandler($this->tempDir);
        $h->setMinLevel(LogLevel::WARNING);
        $this->assertSame(LogLevel::WARNING, $h->getMinLevel());
    }

    public function testFileHandlerConstructWithMinLevel(): void
    {
        $h = new FileHandler($this->tempDir, LogLevel::ERROR);
        $this->assertSame(LogLevel::ERROR, $h->getMinLevel());
    }

    // ═══════════════════════════════════════════════════════
    //  3. FileHandler — isHandling Level Filtering
    // ═══════════════════════════════════════════════════════

    #[DataProvider('levelFilterProvider')]
    public function testFileHandlerIsHandling(string $minLevel, string $testLevel, bool $expected): void
    {
        $h = new FileHandler($this->tempDir, $minLevel);
        $this->assertSame($expected, $h->isHandling($testLevel));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function levelFilterProvider(): iterable
    {
        yield 'DEBUG handles DEBUG'       => [LogLevel::DEBUG, LogLevel::DEBUG, true];
        yield 'DEBUG handles EMERGENCY'   => [LogLevel::DEBUG, LogLevel::EMERGENCY, true];
        yield 'ERROR handles ERROR'       => [LogLevel::ERROR, LogLevel::ERROR, true];
        yield 'ERROR handles CRITICAL'    => [LogLevel::ERROR, LogLevel::CRITICAL, true];
        yield 'ERROR skips WARNING'       => [LogLevel::ERROR, LogLevel::WARNING, false];
        yield 'ERROR skips DEBUG'         => [LogLevel::ERROR, LogLevel::DEBUG, false];
        yield 'EMERGENCY handles EMERGENCY' => [LogLevel::EMERGENCY, LogLevel::EMERGENCY, true];
        yield 'EMERGENCY skips ALERT'     => [LogLevel::EMERGENCY, LogLevel::ALERT, false];
    }

    // ═══════════════════════════════════════════════════════
    //  4. FileHandler — Writing Logs
    // ═══════════════════════════════════════════════════════

    public function testFileHandlerWritesLogFile(): void
    {
        $h = new FileHandler($this->tempDir);
        $h->handle(LogLevel::INFO, 'Hello world', [], '2025-06-01 12:00:00.000000', 'app');

        $files = glob($this->tempDir . '/*.log');
        $this->assertNotEmpty($files);

        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('Hello world', $content);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('[app]', $content);
    }

    public function testFileHandlerAppendsMultipleLines(): void
    {
        $h = new FileHandler($this->tempDir);
        $h->handle(LogLevel::INFO, 'Line one', [], '2025-06-01 12:00:00', 'app');
        $h->handle(LogLevel::WARNING, 'Line two', [], '2025-06-01 12:00:01', 'app');

        $files = glob($this->tempDir . '/*.log');
        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('Line one', $content);
        $this->assertStringContainsString('Line two', $content);
    }

    public function testFileHandlerSkipsBelowMinLevel(): void
    {
        $h = new FileHandler($this->tempDir, LogLevel::ERROR);
        $h->handle(LogLevel::DEBUG, 'Should not appear', [], '2025-06-01 12:00:00', 'app');

        $files = glob($this->tempDir . '/*.log');
        // No file should be created (or if it exists, no content)
        if (!empty($files)) {
            $this->assertStringNotContainsString('Should not appear', file_get_contents($files[0]));
        } else {
            $this->assertEmpty($files);
        }
    }

    public function testFileHandlerEmptyChannelNoTag(): void
    {
        $h = new FileHandler($this->tempDir);
        $h->handle(LogLevel::INFO, 'no channel', [], '2025-06-01 12:00:00', '');

        $files = glob($this->tempDir . '/*.log');
        $content = file_get_contents($files[0]);
        $this->assertStringNotContainsString('[] ', $content);
    }

    public function testFileHandlerExceptionContext(): void
    {
        $h = new FileHandler($this->tempDir);
        $ex = new \RuntimeException('Oops', 42);
        $h->handle(LogLevel::ERROR, 'Error occurred', ['exception' => $ex], '2025-06-01 12:00:00', 'app');

        $files = glob($this->tempDir . '/*.log');
        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Oops', $content);
    }

    public function testFileHandlerDateBasedFilename(): void
    {
        $h = new FileHandler($this->tempDir);
        $h->handle(LogLevel::INFO, 'test', [], '2025-06-01 12:00:00', 'app');

        $expectedName = date('Y-m-d') . '.log';
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . $expectedName);
    }

    // ═══════════════════════════════════════════════════════
    //  5. StderrHandler
    // ═══════════════════════════════════════════════════════

    public function testStderrHandlerImplementsInterface(): void
    {
        $this->assertInstanceOf(LogHandlerInterface::class, new StderrHandler());
    }

    public function testStderrHandlerDefaultMinLevelDebug(): void
    {
        $h = new StderrHandler();
        $this->assertSame(LogLevel::DEBUG, $h->getMinLevel());
    }

    public function testStderrHandlerMinLevelFilter(): void
    {
        $h = new StderrHandler(LogLevel::WARNING);
        $this->assertTrue($h->isHandling(LogLevel::ERROR));
        $this->assertTrue($h->isHandling(LogLevel::WARNING));
        $this->assertFalse($h->isHandling(LogLevel::INFO));
        $this->assertFalse($h->isHandling(LogLevel::DEBUG));
    }

    public function testStderrHandlerIsHandlingAllWhenDebug(): void
    {
        $h = new StderrHandler(LogLevel::DEBUG);
        foreach ([LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY] as $level) {
            $this->assertTrue($h->isHandling($level), "Should handle $level");
        }
    }

    // ═══════════════════════════════════════════════════════
    //  6. LogManager — Construction & Channel Management
    // ═══════════════════════════════════════════════════════

    public function testLogManagerDefaultChannelName(): void
    {
        $m = new LogManager();
        $this->assertSame('default', $m->getDefaultChannel());
    }

    public function testLogManagerCustomDefaultChannelName(): void
    {
        $m = new LogManager('app');
        $this->assertSame('app', $m->getDefaultChannel());
    }

    public function testLogManagerSetDefaultChannel(): void
    {
        $m = new LogManager('app');
        $m->setDefaultChannel('errors');
        $this->assertSame('errors', $m->getDefaultChannel());
    }

    public function testLogManagerSetDefaultChannelReturnsThis(): void
    {
        $m = new LogManager();
        $this->assertSame($m, $m->setDefaultChannel('x'));
    }

    public function testLogManagerAddHandlerReturnsThis(): void
    {
        $m = new LogManager();
        $this->assertSame($m, $m->addHandler('ch', new NullHandler()));
    }

    public function testLogManagerGetHandlers(): void
    {
        $m = new LogManager();
        $h1 = new NullHandler();
        $h2 = new NullHandler();
        $m->addHandler('ch', $h1)->addHandler('ch', $h2);

        $handlers = $m->getHandlers('ch');
        $this->assertCount(2, $handlers);
        $this->assertSame($h1, $handlers[0]);
        $this->assertSame($h2, $handlers[1]);
    }

    public function testLogManagerGetHandlersEmptyForUnknown(): void
    {
        $m = new LogManager();
        $this->assertSame([], $m->getHandlers('nonexistent'));
    }

    public function testLogManagerHasChannel(): void
    {
        $m = new LogManager();
        $this->assertFalse($m->hasChannel('ch'));
        $m->addHandler('ch', new NullHandler());
        $this->assertTrue($m->hasChannel('ch'));
    }

    public function testLogManagerGetChannelNames(): void
    {
        $m = new LogManager();
        $this->assertSame([], $m->getChannelNames());

        $m->addHandler('alpha', new NullHandler());
        $m->addHandler('beta', new NullHandler());

        $names = $m->getChannelNames();
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);
    }

    // ═══════════════════════════════════════════════════════
    //  7. LogManager — Logging to Default Channel
    // ═══════════════════════════════════════════════════════

    public function testLogToDefaultChannelWritesToFileHandler(): void
    {
        $m = new LogManager('app');
        $m->addHandler('app', new FileHandler($this->tempDir));

        $m->info('Hello');

        $files = glob($this->tempDir . '/*.log');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('Hello', file_get_contents($files[0]));
    }

    public function testLogToDefaultChannelWithBuffer(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->warning('Beware');

        $buf = $m->getBuffer();
        $this->assertCount(1, $buf);
        $this->assertSame('warning', $buf[0]['level']);
        $this->assertSame('Beware', $buf[0]['message']);
        $this->assertSame('app', $buf[0]['channel']);
    }

    // ═══════════════════════════════════════════════════════
    //  8. LogManager — Channel Switching
    // ═══════════════════════════════════════════════════════

    public function testLogToSpecificChannel(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('errors', new NullHandler());

        $m->channel('errors')->error('oops');

        $buf = $m->getBuffer();
        $this->assertCount(1, $buf);
        $this->assertSame('errors', $buf[0]['channel']);
    }

    public function testChannelResetsAfterLog(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());
        $m->addHandler('errors', new NullHandler());

        $m->channel('errors')->error('E1');
        $m->info('I1'); // should go to default

        $buf = $m->getBuffer();
        $this->assertSame('errors', $buf[0]['channel']);
        $this->assertSame('default', $buf[1]['channel']);
    }

    // ═══════════════════════════════════════════════════════
    //  9. LogManager — Stack Channel
    // ═══════════════════════════════════════════════════════

    public function testLogToStack(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('ch1', new NullHandler());
        $m->addHandler('ch2', new NullHandler());

        $m->stack(['ch1', 'ch2'])->critical('boom');

        $buf = $m->getBuffer();
        $this->assertCount(2, $buf);
        $this->assertSame('ch1', $buf[0]['channel']);
        $this->assertSame('ch2', $buf[1]['channel']);
        $this->assertSame('boom', $buf[0]['message']);
        $this->assertSame('boom', $buf[1]['message']);
    }

    public function testStackResetsAfterLog(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());
        $m->addHandler('ch1', new NullHandler());
        $m->addHandler('ch2', new NullHandler());

        $m->stack(['ch1', 'ch2'])->alert('A1');
        $m->debug('D1'); // should go to default

        $buf = $m->getBuffer();
        $this->assertCount(3, $buf);
        $this->assertSame('default', $buf[2]['channel']);
    }

    // ═══════════════════════════════════════════════════════
    //  10. LogManager — PSR-3 Convenience Methods
    // ═══════════════════════════════════════════════════════

    #[DataProvider('psr3MethodProvider')]
    public function testPsr3ConvenienceMethods(string $method, string $expectedLevel): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->{$method}('test message');

        $buf = $m->getBuffer();
        $this->assertCount(1, $buf);
        $this->assertSame($expectedLevel, $buf[0]['level']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function psr3MethodProvider(): iterable
    {
        yield 'emergency' => ['emergency', LogLevel::EMERGENCY];
        yield 'alert'     => ['alert', LogLevel::ALERT];
        yield 'critical'  => ['critical', LogLevel::CRITICAL];
        yield 'error'     => ['error', LogLevel::ERROR];
        yield 'warning'   => ['warning', LogLevel::WARNING];
        yield 'notice'    => ['notice', LogLevel::NOTICE];
        yield 'info'      => ['info', LogLevel::INFO];
        yield 'debug'     => ['debug', LogLevel::DEBUG];
    }

    // ═══════════════════════════════════════════════════════
    //  11. LogManager — Interpolation
    // ═══════════════════════════════════════════════════════

    public function testInterpolationReplacesPlaceholders(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('User {name} logged in from {ip}', ['name' => 'Alice', 'ip' => '127.0.0.1']);

        $buf = $m->getBuffer();
        $this->assertSame('User Alice logged in from 127.0.0.1', $buf[0]['message']);
    }

    public function testInterpolationNoPlaceholders(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('No placeholders here', ['key' => 'val']);

        $this->assertSame('No placeholders here', $m->getBuffer()[0]['message']);
    }

    public function testInterpolationIgnoresNonScalarContext(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('Data: {arr}', ['arr' => [1, 2, 3]]);

        // Array values cannot be interpolated
        $this->assertStringContainsString('{arr}', $m->getBuffer()[0]['message']);
    }

    public function testInterpolationHandlesNullContext(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('Value is {val}', ['val' => null]);

        $this->assertSame('Value is ', $m->getBuffer()[0]['message']);
    }

    public function testInterpolationHandlesBoolContext(): void
    {
        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('Flag: {flag}', ['flag' => true]);

        $this->assertSame('Flag: 1', $m->getBuffer()[0]['message']);
    }

    public function testInterpolationWithStringableObject(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string { return 'Stringified'; }
        };

        $m = new LogManager('default', bufferEnabled: true);
        $m->addHandler('default', new NullHandler());

        $m->info('Object: {obj}', ['obj' => $obj]);

        $this->assertSame('Object: Stringified', $m->getBuffer()[0]['message']);
    }

    // ═══════════════════════════════════════════════════════
    //  12. LogManager — Invalid Level
    // ═══════════════════════════════════════════════════════

    public function testInvalidLevelThrows(): void
    {
        $m = new LogManager();
        $m->addHandler('default', new NullHandler());

        $this->expectException(InvalidArgumentException::class);
        $m->log('bogus', 'test');
    }

    // ═══════════════════════════════════════════════════════
    //  13. LogManager — Buffer Management
    // ═══════════════════════════════════════════════════════

    public function testBufferDisabledByDefault(): void
    {
        $m = new LogManager();
        $m->addHandler('default', new NullHandler());
        $m->info('test');

        $this->assertSame([], $m->getBuffer());
    }

    public function testBufferEnabledAccumulatesEntries(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->info('A');
        $m->warning('B');
        $m->error('C');

        $buf = $m->getBuffer();
        $this->assertCount(3, $buf);
        $this->assertSame('A', $buf[0]['message']);
        $this->assertSame('B', $buf[1]['message']);
        $this->assertSame('C', $buf[2]['message']);
    }

    public function testClearBufferReturnsThis(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $this->assertSame($m, $m->clearBuffer());
    }

    public function testClearBufferRemovesEntries(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->info('A');
        $this->assertCount(1, $m->getBuffer());

        $m->clearBuffer();
        $this->assertSame([], $m->getBuffer());

        $m->info('B');
        $this->assertCount(1, $m->getBuffer());
    }

    public function testBufferCapturesAllFields(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->info('msg', ['key' => 'val']);

        $entry = $m->getBuffer()[0];
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertArrayHasKey('level', $entry);
        $this->assertArrayHasKey('message', $entry);
        $this->assertArrayHasKey('context', $entry);
        $this->assertArrayHasKey('channel', $entry);

        $this->assertSame('info', $entry['level']);
        $this->assertSame('msg', $entry['message']);
        $this->assertSame(['key' => 'val'], $entry['context']);
        $this->assertSame('app', $entry['channel']);
    }

    // ═══════════════════════════════════════════════════════
    //  14. LogManager — Handler Level Filtering
    // ═══════════════════════════════════════════════════════

    public function testHandlerIsNotCalledBelowMinLevel(): void
    {
        $m = new LogManager('app');
        $fh = new FileHandler($this->tempDir, LogLevel::ERROR);
        $m->addHandler('app', $fh);

        $m->debug('should not be written');
        $m->info('also not written');

        $files = glob($this->tempDir . '/*.log');
        $this->assertEmpty($files);
    }

    public function testHandlerCalledAtOrAboveMinLevel(): void
    {
        $m = new LogManager('app');
        $fh = new FileHandler($this->tempDir, LogLevel::WARNING);
        $m->addHandler('app', $fh);

        $m->warning('W');
        $m->error('E');
        $m->critical('C');

        $files = glob($this->tempDir . '/*.log');
        $this->assertNotEmpty($files);
        $content = file_get_contents($files[0]);
        $this->assertStringContainsString('[WARNING]', $content);
        $this->assertStringContainsString('[ERROR]', $content);
        $this->assertStringContainsString('[CRITICAL]', $content);
    }

    // ═══════════════════════════════════════════════════════
    //  15. Integration — LogManager + Multiple Handlers
    // ═══════════════════════════════════════════════════════

    public function testMultipleHandlersOnSameChannel(): void
    {
        $dir1 = $this->tempDir . '/a';
        $dir2 = $this->tempDir . '/b';

        $m = new LogManager('app');
        $m->addHandler('app', new FileHandler($dir1));
        $m->addHandler('app', new FileHandler($dir2));

        $m->info('Logged to both');

        $f1 = glob($dir1 . '/*.log');
        $f2 = glob($dir2 . '/*.log');
        $this->assertNotEmpty($f1);
        $this->assertNotEmpty($f2);
        $this->assertStringContainsString('Logged to both', file_get_contents($f1[0]));
        $this->assertStringContainsString('Logged to both', file_get_contents($f2[0]));

        // Clean up sub-directories too
        array_map('unlink', glob($dir1 . '/*'));
        array_map('unlink', glob($dir2 . '/*'));
        rmdir($dir1);
        rmdir($dir2);
    }

    public function testFileHandlerAndNullHandlerTogether(): void
    {
        $m = new LogManager('app');
        $m->addHandler('app', new FileHandler($this->tempDir));
        $m->addHandler('app', new NullHandler());

        $m->error('test');

        $files = glob($this->tempDir . '/*.log');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('test', file_get_contents($files[0]));
    }

    // ═══════════════════════════════════════════════════════
    //  16. Integration — Stack with FileHandler
    // ═══════════════════════════════════════════════════════

    public function testStackWritesToMultipleFileChannels(): void
    {
        $dir1 = $this->tempDir . '/ch1';
        $dir2 = $this->tempDir . '/ch2';

        $m = new LogManager('default');
        $m->addHandler('ch1', new FileHandler($dir1));
        $m->addHandler('ch2', new FileHandler($dir2));

        $m->stack(['ch1', 'ch2'])->emergency('System down');

        $f1 = glob($dir1 . '/*.log');
        $f2 = glob($dir2 . '/*.log');
        $this->assertNotEmpty($f1);
        $this->assertNotEmpty($f2);
        $this->assertStringContainsString('System down', file_get_contents($f1[0]));
        $this->assertStringContainsString('System down', file_get_contents($f2[0]));

        // Clean up
        array_map('unlink', glob($dir1 . '/*'));
        array_map('unlink', glob($dir2 . '/*'));
        rmdir($dir1);
        rmdir($dir2);
    }

    // ═══════════════════════════════════════════════════════
    //  17. Edge Cases
    // ═══════════════════════════════════════════════════════

    public function testLogToChannelWithNoHandlers(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        // No handlers at all — should not throw
        $m->info('orphan');

        $buf = $m->getBuffer();
        $this->assertCount(1, $buf);
        $this->assertSame('orphan', $buf[0]['message']);
    }

    public function testLogMessageIsStringable(): void
    {
        $obj = new class implements \Stringable {
            public function __toString(): string { return 'stringable message'; }
        };

        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->info($obj);

        $this->assertSame('stringable message', $m->getBuffer()[0]['message']);
    }

    public function testLogManagerChannelMethodReturnsThis(): void
    {
        $m = new LogManager();
        $this->assertSame($m, $m->channel('x'));
    }

    public function testLogManagerStackMethodReturnsThis(): void
    {
        $m = new LogManager();
        $this->assertSame($m, $m->stack(['x', 'y']));
    }

    public function testLogManagerLogViaLogMethod(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->log(LogLevel::NOTICE, 'Notice via log()');

        $this->assertSame('notice', $m->getBuffer()[0]['level']);
    }

    public function testBufferTimestampFormat(): void
    {
        $m = new LogManager('app', bufferEnabled: true);
        $m->addHandler('app', new NullHandler());

        $m->info('test');

        $ts = $m->getBuffer()[0]['timestamp'];
        // Expected format: Y-m-d H:i:s.u
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $ts);
    }
}
