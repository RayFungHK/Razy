<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Error\ErrorConfig;

#[CoversClass(ErrorConfig::class)]
class ErrorConfigTest extends TestCase
{
    protected function setUp(): void
    {
        ErrorConfig::reset();
    }

    protected function tearDown(): void
    {
        ErrorConfig::reset();
    }

    #[Test]
    public function defaultDebugIsFalse(): void
    {
        $this->assertFalse(ErrorConfig::isDebug());
    }

    #[Test]
    public function setDebugEnables(): void
    {
        ErrorConfig::setDebug(true);
        $this->assertTrue(ErrorConfig::isDebug());
    }

    #[Test]
    public function setDebugDisables(): void
    {
        ErrorConfig::setDebug(true);
        ErrorConfig::setDebug(false);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    #[Test]
    public function configureReadsDebugKey(): void
    {
        ErrorConfig::configure(['debug' => true]);
        $this->assertTrue(ErrorConfig::isDebug());
    }

    #[Test]
    public function configureWithoutDebugKeyDefaultsFalse(): void
    {
        ErrorConfig::configure([]);
        $this->assertFalse(ErrorConfig::isDebug());
    }

    #[Test]
    public function configureTruthyValues(): void
    {
        ErrorConfig::configure(['debug' => 1]);
        $this->assertTrue(ErrorConfig::isDebug());

        ErrorConfig::configure(['debug' => 'yes']);
        $this->assertTrue(ErrorConfig::isDebug());
    }

    #[Test]
    public function defaultCachedIsEmpty(): void
    {
        $this->assertSame('', ErrorConfig::getCached());
    }

    #[Test]
    public function setCachedStoresContent(): void
    {
        ErrorConfig::setCached('<html>output</html>');
        $this->assertSame('<html>output</html>', ErrorConfig::getCached());
    }

    #[Test]
    public function debugConsoleWriteAccumulatesMessages(): void
    {
        ErrorConfig::debugConsoleWrite('msg1');
        ErrorConfig::debugConsoleWrite('msg2');
        $this->assertSame(['msg1', 'msg2'], ErrorConfig::getDebugConsole());
    }

    #[Test]
    public function defaultDebugConsoleIsEmpty(): void
    {
        $this->assertSame([], ErrorConfig::getDebugConsole());
    }

    #[Test]
    public function resetClearsAllState(): void
    {
        ErrorConfig::setDebug(true);
        ErrorConfig::setCached('cached');
        ErrorConfig::debugConsoleWrite('msg');

        ErrorConfig::reset();

        $this->assertFalse(ErrorConfig::isDebug());
        $this->assertSame('', ErrorConfig::getCached());
        $this->assertSame([], ErrorConfig::getDebugConsole());
    }
}
