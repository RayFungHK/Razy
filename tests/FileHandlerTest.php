<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Log\LogLevel;
use Razy\Log\FileHandler;

#[CoversClass(FileHandler::class)]
class FileHandlerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_file_handler_test_' . \uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up log files and directory
        if (\is_dir($this->logDir)) {
            $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                \array_map('unlink', $files);
            }
            @\rmdir($this->logDir);
        }
    }

    public function testConstructorCreatesDirectory(): void
    {
        $this->assertDirectoryDoesNotExist($this->logDir);
        new FileHandler($this->logDir);
        $this->assertDirectoryExists($this->logDir);
    }

    public function testGetDirectory(): void
    {
        $handler = new FileHandler($this->logDir);
        $this->assertSame($this->logDir, $handler->getDirectory());
    }

    public function testGetMinLevel(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::WARNING);
        $this->assertSame(LogLevel::WARNING, $handler->getMinLevel());
    }

    public function testSetMinLevel(): void
    {
        $handler = new FileHandler($this->logDir);
        $this->assertSame(LogLevel::DEBUG, $handler->getMinLevel());
        $handler->setMinLevel(LogLevel::ERROR);
        $this->assertSame(LogLevel::ERROR, $handler->getMinLevel());
    }

    public function testIsHandling(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::WARNING);

        $this->assertTrue($handler->isHandling(LogLevel::WARNING));
        $this->assertTrue($handler->isHandling(LogLevel::ERROR));
        $this->assertTrue($handler->isHandling(LogLevel::EMERGENCY));
        $this->assertFalse($handler->isHandling(LogLevel::INFO));
        $this->assertFalse($handler->isHandling(LogLevel::DEBUG));
    }

    public function testHandleWritesLogFile(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::DEBUG);

        $handler->handle(
            LogLevel::INFO,
            'Test log message',
            [],
            '2024-01-15T10:30:00',
            'app',
        );

        // Find the log file
        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files, 'Log file should have been created');

        $content = \file_get_contents($files[0]);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('Test log message', $content);
        $this->assertStringContainsString('[app]', $content);
        $this->assertStringContainsString('2024-01-15T10:30:00', $content);
    }

    public function testHandleSkipsBelowMinLevel(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::ERROR);

        $handler->handle(
            LogLevel::INFO,
            'Should not be written',
            [],
            '2024-01-15T10:30:00',
            'test',
        );

        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertEmpty($files, 'No log file should be created for below-min-level');
    }

    public function testHandleEmptyChannel(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::DEBUG);

        $handler->handle(
            LogLevel::INFO,
            'No channel',
            [],
            '2024-01-15T10:30:00',
            '',
        );

        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files);

        $content = \file_get_contents($files[0]);
        // Should NOT contain the channel tag brackets
        $this->assertStringNotContainsString('[]', $content);
        $this->assertStringContainsString('[INFO]', $content);
    }

    public function testHandleContextWithException(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::DEBUG);

        $exception = new \RuntimeException('Something went wrong', 42);
        $handler->handle(
            LogLevel::ERROR,
            'Error occurred',
            ['exception' => $exception],
            '2024-01-15T10:30:00',
            'app',
        );

        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files);

        $content = \file_get_contents($files[0]);
        $this->assertStringContainsString('Error occurred', $content);
        $this->assertStringContainsString('RuntimeException', $content);
        $this->assertStringContainsString('Something went wrong', $content);
    }

    public function testHandleContextWithExtraData(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::DEBUG);

        $handler->handle(
            LogLevel::INFO,
            'With context',
            ['extra' => ['key' => 'value']],
            '2024-01-15T10:30:00',
            '',
        );

        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files);

        $content = \file_get_contents($files[0]);
        $this->assertStringContainsString('key', $content);
        $this->assertStringContainsString('value', $content);
    }

    public function testMultipleHandleCallsAppend(): void
    {
        $handler = new FileHandler($this->logDir, LogLevel::DEBUG);

        $handler->handle(LogLevel::INFO, 'First', [], '2024-01-15T10:30:00', '');
        $handler->handle(LogLevel::INFO, 'Second', [], '2024-01-15T10:30:01', '');

        $files = \glob($this->logDir . DIRECTORY_SEPARATOR . '*.log');
        $this->assertNotEmpty($files);

        $content = \file_get_contents($files[0]);
        $this->assertStringContainsString('First', $content);
        $this->assertStringContainsString('Second', $content);
    }
}
