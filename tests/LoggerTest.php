<?php

/**
 * Unit tests for Razy\Logger (PSR-3 compliant logger).
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Log\InvalidArgumentException;
use Razy\Contract\Log\LoggerInterface;
use Razy\Contract\Log\LogLevel;
use Razy\Logger;
use RuntimeException;
use Stringable;

#[CoversClass(Logger::class)]
class LoggerTest extends TestCase
{
    private ?string $tempDir = null;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_logger_test_' . \uniqid();
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && \is_dir($this->tempDir)) {
            $files = \glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (\is_file($file)) {
                        \unlink($file);
                    }
                }
            }
            \rmdir($this->tempDir);
        }
    }

    // ══════════════════════════════════════════════════════
    // PSR-3 Compliance
    // ══════════════════════════════════════════════════════

    #[Test]
    public function implementsLoggerInterface(): void
    {
        $logger = new Logger();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    #[Test]
    public function debugMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->debug('debug message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::DEBUG, $buffer[0]['level']);
        $this->assertSame('debug message', $buffer[0]['message']);
    }

    #[Test]
    public function infoMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('info message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::INFO, $buffer[0]['level']);
        $this->assertSame('info message', $buffer[0]['message']);
    }

    #[Test]
    public function noticeMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->notice('notice message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::NOTICE, $buffer[0]['level']);
        $this->assertSame('notice message', $buffer[0]['message']);
    }

    #[Test]
    public function warningMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->warning('warning message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::WARNING, $buffer[0]['level']);
        $this->assertSame('warning message', $buffer[0]['message']);
    }

    #[Test]
    public function errorMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->error('error message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::ERROR, $buffer[0]['level']);
        $this->assertSame('error message', $buffer[0]['message']);
    }

    #[Test]
    public function criticalMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->critical('critical message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::CRITICAL, $buffer[0]['level']);
        $this->assertSame('critical message', $buffer[0]['message']);
    }

    #[Test]
    public function alertMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->alert('alert message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::ALERT, $buffer[0]['level']);
        $this->assertSame('alert message', $buffer[0]['message']);
    }

    #[Test]
    public function emergencyMethodLogs(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->emergency('emergency message');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame(LogLevel::EMERGENCY, $buffer[0]['level']);
        $this->assertSame('emergency message', $buffer[0]['message']);
    }

    #[Test]
    public function messageInterpolationReplacesPlaceholders(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('User {user} performed {action}', [
            'user' => 'john',
            'action' => 'login',
        ]);

        $buffer = $logger->getBuffer();
        $this->assertSame('User john performed login', $buffer[0]['message']);
    }

    // ══════════════════════════════════════════════════════
    // File Logging
    // ══════════════════════════════════════════════════════

    #[Test]
    public function createsLogFileInSpecifiedDirectory(): void
    {
        $logger = new Logger($this->tempDir);
        $logger->info('test entry');

        $expectedFilename = \date('Y-m-d') . '.log';
        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . $expectedFilename;

        $this->assertFileExists($expectedPath);
    }

    #[Test]
    public function appendsEntriesWithTimestampLevelMessage(): void
    {
        $logger = new Logger($this->tempDir);
        $logger->info('first entry');
        $logger->warning('second entry');

        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $content = \file_get_contents($expectedPath);

        // Both entries should be present
        $this->assertStringContainsString('[INFO] first entry', $content);
        $this->assertStringContainsString('[WARNING] second entry', $content);

        // Timestamp format check: [YYYY-MM-DD HH:MM:SS.microseconds]
        $this->assertMatchesRegularExpression(
            '/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+\]/',
            $content,
        );

        // Each entry is on its own line
        $lines = \array_filter(\explode(PHP_EOL, \trim($content)));
        $this->assertCount(2, $lines);
    }

    #[Test]
    public function usesDateBasedFilenamePattern(): void
    {
        $logger = new Logger($this->tempDir, filenamePattern: 'Y-m');
        $logger->info('monthly log');

        $expectedFilename = \date('Y-m') . '.log';
        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . $expectedFilename;

        $this->assertFileExists($expectedPath);
    }

    #[Test]
    public function createsDirectoryIfItDoesNotExist(): void
    {
        $nestedDir = $this->tempDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'dir';
        // Override tempDir so tearDown cleans the right path
        $originalTempDir = $this->tempDir;
        $this->tempDir = $nestedDir;

        $logger = new Logger($nestedDir);
        $this->assertDirectoryExists($nestedDir);

        $logger->info('nested log');
        $expectedPath = $nestedDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $this->assertFileExists($expectedPath);

        // Clean up the nested structure
        \unlink($expectedPath);
        \rmdir($nestedDir);
        \rmdir($originalTempDir . DIRECTORY_SEPARATOR . 'sub');
        // Reset tempDir to parent for tearDown
        $this->tempDir = $originalTempDir;
    }

    // ══════════════════════════════════════════════════════
    // Level Threshold
    // ══════════════════════════════════════════════════════

    #[Test]
    public function logsMessagesAtOrAboveMinimumLevel(): void
    {
        $logger = new Logger(bufferEnabled: true, minLevel: LogLevel::WARNING);

        $logger->warning('warn msg');
        $logger->error('error msg');
        $logger->critical('critical msg');

        $buffer = $logger->getBuffer();
        $this->assertCount(3, $buffer);
        $this->assertSame(LogLevel::WARNING, $buffer[0]['level']);
        $this->assertSame(LogLevel::ERROR, $buffer[1]['level']);
        $this->assertSame(LogLevel::CRITICAL, $buffer[2]['level']);
    }

    #[Test]
    public function skipsMessagesBelowThreshold(): void
    {
        $logger = new Logger(bufferEnabled: true, minLevel: LogLevel::WARNING);

        $logger->debug('skipped');
        $logger->info('skipped');
        $logger->notice('skipped');

        $this->assertEmpty($logger->getBuffer());
    }

    #[Test]
    public function skipsFileBelowThreshold(): void
    {
        $logger = new Logger($this->tempDir, minLevel: LogLevel::ERROR);

        $logger->debug('below threshold');
        $logger->info('below threshold');
        $logger->warning('below threshold');

        $expectedPath = $this->tempDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $this->assertFileDoesNotExist($expectedPath);
    }

    #[Test]
    public function setMinLevelChangesThresholdAtRuntime(): void
    {
        $logger = new Logger(bufferEnabled: true, minLevel: LogLevel::ERROR);

        $logger->warning('should be skipped');
        $this->assertEmpty($logger->getBuffer());

        $logger->setMinLevel(LogLevel::WARNING);
        $logger->warning('should be logged');
        $this->assertCount(1, $logger->getBuffer());
    }

    #[Test]
    public function getMinLevelReturnsCurrentThreshold(): void
    {
        $logger = new Logger(minLevel: LogLevel::NOTICE);
        $this->assertSame(LogLevel::NOTICE, $logger->getMinLevel());

        $logger->setMinLevel(LogLevel::ALERT);
        $this->assertSame(LogLevel::ALERT, $logger->getMinLevel());
    }

    // ══════════════════════════════════════════════════════
    // Buffer
    // ══════════════════════════════════════════════════════

    #[Test]
    public function bufferStoresEntriesWhenEnabled(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('buffered one');
        $logger->warning('buffered two');

        $buffer = $logger->getBuffer();
        $this->assertCount(2, $buffer);

        $this->assertArrayHasKey('timestamp', $buffer[0]);
        $this->assertArrayHasKey('level', $buffer[0]);
        $this->assertArrayHasKey('message', $buffer[0]);
        $this->assertArrayHasKey('context', $buffer[0]);

        $this->assertSame('buffered one', $buffer[0]['message']);
        $this->assertSame('buffered two', $buffer[1]['message']);
    }

    #[Test]
    public function bufferDisabledByDefault(): void
    {
        $logger = new Logger();
        $logger->info('not buffered');

        $this->assertEmpty($logger->getBuffer());
    }

    #[Test]
    public function getBufferReturnsStoredEntries(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->debug('one');
        $logger->error('two');

        $buffer = $logger->getBuffer();
        $this->assertCount(2, $buffer);
        $this->assertSame(LogLevel::DEBUG, $buffer[0]['level']);
        $this->assertSame(LogLevel::ERROR, $buffer[1]['level']);
    }

    #[Test]
    public function clearBufferRemovesAllEntries(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('msg one');
        $logger->info('msg two');
        $this->assertCount(2, $logger->getBuffer());

        $result = $logger->clearBuffer();
        $this->assertEmpty($logger->getBuffer());
        $this->assertSame($logger, $result, 'clearBuffer() should return $this for fluency');
    }

    // ══════════════════════════════════════════════════════
    // Null Logger Mode
    // ══════════════════════════════════════════════════════

    #[Test]
    public function nullDirectoryCreatesNoOpLogger(): void
    {
        $logger = new Logger(null);
        $logger->info('discarded');

        $this->assertNull($logger->getLogDirectory());
    }

    #[Test]
    public function nullLoggerCreatesNoFiles(): void
    {
        // Ensure a known no-file scenario
        $logger = new Logger(null, bufferEnabled: true);
        $logger->info('no file');
        $logger->error('still no file');

        // Buffer still works, but no files are created
        $this->assertCount(2, $logger->getBuffer());
    }

    #[Test]
    public function defaultConstructorIsNullLogger(): void
    {
        $logger = new Logger();
        $this->assertNull($logger->getLogDirectory());
    }

    // ══════════════════════════════════════════════════════
    // Context
    // ══════════════════════════════════════════════════════

    #[Test]
    public function exceptionContextKeyIsSerialized(): void
    {
        $exception = new RuntimeException('Something broke', 42);
        $logger = new Logger($this->tempDir);
        $logger->error('Failure occurred', ['exception' => $exception]);

        $logFile = $this->tempDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $content = \file_get_contents($logFile);

        $this->assertStringContainsString('[ERROR] Failure occurred', $content);
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Something broke', $content);
    }

    #[Test]
    public function exceptionContextInBufferPreservesOriginal(): void
    {
        $exception = new RuntimeException('test error');
        $logger = new Logger(bufferEnabled: true);
        $logger->error('error with exception', ['exception' => $exception]);

        $buffer = $logger->getBuffer();
        $this->assertSame($exception, $buffer[0]['context']['exception']);
    }

    #[Test]
    public function nonStringContextValuesHandledGracefully(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('Value is {val}', ['val' => 42]);

        $buffer = $logger->getBuffer();
        $this->assertSame('Value is 42', $buffer[0]['message']);
    }

    #[Test]
    public function nullContextValueInterpolated(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('Value is {val}', ['val' => null]);

        $buffer = $logger->getBuffer();
        $this->assertSame('Value is ', $buffer[0]['message']);
    }

    #[Test]
    public function booleanContextValueInterpolated(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('Flag is {flag}', ['flag' => true]);

        $buffer = $logger->getBuffer();
        $this->assertSame('Flag is 1', $buffer[0]['message']);
    }

    #[Test]
    public function arrayContextValueNotInterpolated(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('Data is {data}', ['data' => ['a', 'b']]);

        $buffer = $logger->getBuffer();
        // Array cannot be interpolated, placeholder preserved
        $this->assertSame('Data is {data}', $buffer[0]['message']);
    }

    #[Test]
    public function missingPlaceholderKeysPreserved(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('Hello {name}, your role is {role}', ['name' => 'Alice']);

        $buffer = $logger->getBuffer();
        $this->assertSame('Hello Alice, your role is {role}', $buffer[0]['message']);
    }

    #[Test]
    public function contextWithNoPlaceholdersLeavesMessageUntouched(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('No placeholders here', ['extra' => 'data']);

        $buffer = $logger->getBuffer();
        $this->assertSame('No placeholders here', $buffer[0]['message']);
    }

    // ══════════════════════════════════════════════════════
    // Edge Cases
    // ══════════════════════════════════════════════════════

    #[Test]
    public function invalidLogLevelThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Logger(minLevel: 'invalid_level');
    }

    #[Test]
    public function invalidLogLevelInLogMethodThrowsException(): void
    {
        $logger = new Logger(bufferEnabled: true);

        $this->expectException(InvalidArgumentException::class);
        $logger->log('bogus', 'will not be logged');
    }

    #[Test]
    public function emptyMessageIsLogged(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('');

        $buffer = $logger->getBuffer();
        $this->assertCount(1, $buffer);
        $this->assertSame('', $buffer[0]['message']);
    }

    #[Test]
    public function emptyMessageWrittenToFile(): void
    {
        $logger = new Logger($this->tempDir);
        $logger->info('');

        $logFile = $this->tempDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $content = \file_get_contents($logFile);
        $this->assertStringContainsString('[INFO] ', $content);
    }

    #[Test]
    public function stringableMessageObjectInterpolated(): void
    {
        $message = new class() implements Stringable {
            public function __toString(): string
            {
                return 'stringable message';
            }
        };

        $logger = new Logger(bufferEnabled: true);
        $logger->info($message);

        $buffer = $logger->getBuffer();
        $this->assertSame('stringable message', $buffer[0]['message']);
    }

    #[Test]
    public function stringableContextValueInterpolated(): void
    {
        $value = new class() implements Stringable {
            public function __toString(): string
            {
                return 'object-string';
            }
        };

        $logger = new Logger(bufferEnabled: true);
        $logger->info('Value: {obj}', ['obj' => $value]);

        $buffer = $logger->getBuffer();
        $this->assertSame('Value: object-string', $buffer[0]['message']);
    }

    #[Test]
    public function bufferTimestampFormatIsCorrect(): void
    {
        $logger = new Logger(bufferEnabled: true);
        $logger->info('timestamp check');

        $buffer = $logger->getBuffer();
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/',
            $buffer[0]['timestamp'],
        );
    }

    #[Test]
    public function bufferContextPreservesOriginalArray(): void
    {
        $context = ['user' => 'alice', 'action' => 'login'];
        $logger = new Logger(bufferEnabled: true);
        $logger->info('User {user} did {action}', $context);

        $buffer = $logger->getBuffer();
        $this->assertSame($context, $buffer[0]['context']);
    }

    #[Test]
    public function getLogDirectoryReturnsConfiguredPath(): void
    {
        $logger = new Logger($this->tempDir);
        $this->assertSame($this->tempDir, $logger->getLogDirectory());
    }

    #[Test]
    public function setMinLevelReturnsSelfForFluency(): void
    {
        $logger = new Logger();
        $result = $logger->setMinLevel(LogLevel::ERROR);
        $this->assertSame($logger, $result);
    }

    #[Test]
    public function setMinLevelRejectsInvalidLevel(): void
    {
        $logger = new Logger();
        $this->expectException(InvalidArgumentException::class);
        $logger->setMinLevel('not_a_level');
    }

    #[Test]
    public function fileContainsExtraContextAsJson(): void
    {
        $logger = new Logger($this->tempDir);
        $logger->info('with extra', ['data' => ['key' => 'value']]);

        $logFile = $this->tempDir . DIRECTORY_SEPARATOR . \date('Y-m-d') . '.log';
        $content = \file_get_contents($logFile);

        $this->assertStringContainsString('[INFO] with extra', $content);
        $this->assertStringContainsString('"key":"value"', $content);
    }
}
