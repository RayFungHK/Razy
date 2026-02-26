<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Log\NullHandler;

#[CoversClass(NullHandler::class)]
class NullHandlerTest extends TestCase
{
    #[Test]
    public function handleDoesNotThrow(): void
    {
        $handler = new NullHandler();
        $handler->handle('error', 'Something failed', ['key' => 'val'], '2026-01-01T00:00:00', 'app');
        $this->assertTrue(true); // No exception = success
    }

    #[Test]
    public function isHandlingAlwaysReturnsTrue(): void
    {
        $handler = new NullHandler();
        $this->assertTrue($handler->isHandling('emergency'));
        $this->assertTrue($handler->isHandling('debug'));
        $this->assertTrue($handler->isHandling('info'));
        $this->assertTrue($handler->isHandling('warning'));
        $this->assertTrue($handler->isHandling('error'));
        $this->assertTrue($handler->isHandling('critical'));
    }

    #[Test]
    public function handleWithEmptyValues(): void
    {
        $handler = new NullHandler();
        $handler->handle('', '', [], '', '');
        $this->assertTrue(true);
    }

    #[Test]
    public function handleMultipleCallsDoNotAccumulate(): void
    {
        $handler = new NullHandler();
        for ($i = 0; $i < 100; $i++) {
            $handler->handle('info', "Message $i", [], (string) $i, 'test');
        }
        $this->assertTrue(true);
    }
}
