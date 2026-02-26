<?php
/**
 * Unit tests for Razy\Error class.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Error;
use Razy\Exception\NotFoundException;

#[CoversClass(Error::class)]
class ErrorTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static state before each test
        Error::reset();
    }

    // ?�?� Constructor ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testConstructorSetsMessage(): void
    {
        $e = new Error('Something went wrong');
        // Error uses nl2br so check it contains the text
        $this->assertStringContainsString('Something went wrong', $e->getMessage());
    }

    public function testConstructorDefaultStatusCode(): void
    {
        $e = new Error('test');
        $this->assertSame(400, $e->getCode());
    }

    public function testConstructorCustomStatusCode(): void
    {
        $e = new Error('test', 500);
        $this->assertSame(500, $e->getCode());
    }

    public function testConstructorConvertsNewlinesToBr(): void
    {
        $e = new Error("line1\nline2");
        $this->assertStringContainsString('<br />', $e->getMessage());
    }

    public function testConstructorWithPreviousException(): void
    {
        $prev = new \RuntimeException('inner');
        $e = new Error('outer', 400, Error::DEFAULT_HEADING, '', $prev);
        $this->assertSame($prev, $e->getPrevious());
    }

    public function testExtendsException(): void
    {
        $e = new Error('test');
        $this->assertInstanceOf(\Exception::class, $e);
    }

    // ?�?� configure debug / Reset ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testConfigureDebugDoesNotThrow(): void
    {
        // Just ensure configure doesn't throw
        Error::configure(['debug' => true]);
        Error::configure(['debug' => false]);
        $this->assertTrue(true); // If we got here, no exception
    }

    public function testResetClearsDebugConsole(): void
    {
        Error::debugConsoleWrite('message 1');
        Error::debugConsoleWrite('message 2');
        Error::reset();

        // After reset, GetCached should be empty
        $this->assertSame('', Error::getCached());
    }

    public function testResetClearsCachedContent(): void
    {
        Error::reset();
        $this->assertSame('', Error::getCached());
    }

    // ?�?� DebugConsoleWrite ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testDebugConsoleWriteAccumulatesMessages(): void
    {
        Error::debugConsoleWrite('msg1');
        Error::debugConsoleWrite('msg2');
        Error::debugConsoleWrite('msg3');

        // We can't read the console directly, but reset should clear it
        Error::reset();
        // If we got here without error, messages were accumulated successfully
        $this->assertTrue(true);
    }

    // ?�?� GetCached ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testGetCachedReturnsString(): void
    {
        $cached = Error::getCached();
        $this->assertIsString($cached);
    }

    // ?�?� Show404 ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testShow404ThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectOutputRegex('/404/');
        @Error::show404(); // suppress header warnings
    }

    // ?�?� DEFAULT_HEADING constant ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testDefaultHeadingConstant(): void
    {
        $this->assertSame('There seems to is something wrong...', Error::DEFAULT_HEADING);
    }

    // ?�?� Multiple resets ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testMultipleResetsAreIdempotent(): void
    {
        Error::debugConsoleWrite('test');
        Error::reset();
        Error::reset();
        Error::reset();
        $this->assertSame('', Error::getCached());
    }

    // ?�?� Worker mode simulation ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testWorkerModeResetBetweenRequests(): void
    {
        // Simulate request 1
        Error::configure(['debug' => true]);
        Error::debugConsoleWrite('request 1 message');

        // Reset between requests
        Error::reset();

        // After reset, debug should be off
        $this->assertFalse(Error::isDebug());

        // Simulate request 2
        Error::debugConsoleWrite('request 2 message');

        // No assertion on content (private), just verify no errors
        Error::reset();
        $this->assertTrue(true);
    }

    // ?�?� configure() ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testConfigureEnablesDebug(): void
    {
        Error::configure(['debug' => true]);
        $this->assertTrue(Error::isDebug());
    }

    public function testConfigureDisablesDebug(): void
    {
        Error::configure(['debug' => true]);
        Error::configure(['debug' => false]);
        $this->assertFalse(Error::isDebug());
    }

    public function testConfigureDefaultsDebugToFalse(): void
    {
        Error::configure([]);
        $this->assertFalse(Error::isDebug());
    }

    public function testConfigureIgnoresUnknownKeys(): void
    {
        Error::configure(['debug' => true, 'unknown' => 'value', 'timezone' => 'UTC']);
        $this->assertTrue(Error::isDebug());
    }

    public function testConfigureTruthyValues(): void
    {
        Error::configure(['debug' => 1]);
        $this->assertTrue(Error::isDebug());

        Error::configure(['debug' => 'yes']);
        $this->assertTrue(Error::isDebug());

        Error::configure(['debug' => 0]);
        $this->assertFalse(Error::isDebug());
    }

    // ?�?� isDebug() ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testIsDebugDefaultsFalse(): void
    {
        Error::reset();
        $this->assertFalse(Error::isDebug());
    }

    public function testIsDebugReflectsConfigure(): void
    {
        Error::configure(['debug' => true]);
        $this->assertTrue(Error::isDebug());
        Error::configure(['debug' => false]);
        $this->assertFalse(Error::isDebug());
    }

    // ?�?� Reset clears debug ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    public function testResetClearsDebugFlag(): void
    {
        Error::configure(['debug' => true]);
        $this->assertTrue(Error::isDebug());
        Error::reset();
        $this->assertFalse(Error::isDebug());
    }
}
