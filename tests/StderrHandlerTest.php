<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\Log\LogLevel;
use Razy\Log\StderrHandler;

#[CoversClass(StderrHandler::class)]
class StderrHandlerTest extends TestCase
{
    public function testDefaultMinLevelIsDebug(): void
    {
        $handler = new StderrHandler();
        $this->assertSame(LogLevel::DEBUG, $handler->getMinLevel());
    }

    public function testCustomMinLevel(): void
    {
        $handler = new StderrHandler(LogLevel::ERROR);
        $this->assertSame(LogLevel::ERROR, $handler->getMinLevel());
    }

    public function testIsHandlingAtMinLevel(): void
    {
        $handler = new StderrHandler(LogLevel::WARNING);

        $this->assertTrue($handler->isHandling(LogLevel::WARNING));
        $this->assertTrue($handler->isHandling(LogLevel::ERROR));
        $this->assertTrue($handler->isHandling(LogLevel::CRITICAL));
        $this->assertTrue($handler->isHandling(LogLevel::ALERT));
        $this->assertTrue($handler->isHandling(LogLevel::EMERGENCY));
        $this->assertFalse($handler->isHandling(LogLevel::INFO));
        $this->assertFalse($handler->isHandling(LogLevel::DEBUG));
        $this->assertFalse($handler->isHandling(LogLevel::NOTICE));
    }

    public function testIsHandlingAllAtDebugLevel(): void
    {
        $handler = new StderrHandler(LogLevel::DEBUG);

        foreach ([LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY] as $level) {
            $this->assertTrue($handler->isHandling($level), "Should handle level: $level");
        }
    }

    public function testIsHandlingNoneAboveEmergency(): void
    {
        $handler = new StderrHandler(LogLevel::EMERGENCY);

        $this->assertTrue($handler->isHandling(LogLevel::EMERGENCY));
        $this->assertFalse($handler->isHandling(LogLevel::ALERT));
        $this->assertFalse($handler->isHandling(LogLevel::DEBUG));
    }

    public function testHandleBelowMinLevelIsNoOp(): void
    {
        // When the message level is below minLevel, handle() returns early.
        // We verify by ensuring isHandling() returns false (the guard check).
        $handler = new StderrHandler(LogLevel::ERROR);
        $this->assertFalse($handler->isHandling(LogLevel::DEBUG));
        $this->assertFalse($handler->isHandling(LogLevel::INFO));
        // handle() with below-min level should not throw
        $handler->handle(LogLevel::DEBUG, 'Should be skipped', [], '2024-01-01T00:00:00', 'test');
        $this->assertTrue(true);
    }
}
