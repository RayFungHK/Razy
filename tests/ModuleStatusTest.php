<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Module\ModuleStatus;

/**
 * Tests for the ModuleStatus enum introduced in Phase 4.1.
 */
#[CoversClass(ModuleStatus::class)]
class ModuleStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = ModuleStatus::cases();
        $this->assertCount(8, $cases);
    }

    #[DataProvider('statusValueProvider')]
    public function testEnumValues(ModuleStatus $status, int $expectedValue): void
    {
        $this->assertSame($expectedValue, $status->value);
    }

    public static function statusValueProvider(): array
    {
        return [
            'Failed'     => [ModuleStatus::Failed, -3],
            'Disabled'   => [ModuleStatus::Disabled, -2],
            'Unloaded'   => [ModuleStatus::Unloaded, -1],
            'Pending'    => [ModuleStatus::Pending, 0],
            'Initialing' => [ModuleStatus::Initialing, 1],
            'Processing' => [ModuleStatus::Processing, 2],
            'InQueue'    => [ModuleStatus::InQueue, 3],
            'Loaded'     => [ModuleStatus::Loaded, 4],
        ];
    }

    public function testFromInt(): void
    {
        $status = ModuleStatus::from(4);
        $this->assertSame(ModuleStatus::Loaded, $status);
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $status = ModuleStatus::tryFrom(999);
        $this->assertNull($status);
    }

    public function testFailedIsNegative(): void
    {
        $this->assertTrue(ModuleStatus::Failed->value < 0);
        $this->assertTrue(ModuleStatus::Disabled->value < 0);
        $this->assertTrue(ModuleStatus::Unloaded->value < 0);
    }

    public function testPendingIsZero(): void
    {
        $this->assertSame(0, ModuleStatus::Pending->value);
    }

    public function testLoadedIsMaxPositive(): void
    {
        $maxValue = max(array_map(fn(ModuleStatus $s) => $s->value, ModuleStatus::cases()));
        $this->assertSame(ModuleStatus::Loaded->value, $maxValue);
    }

    public function testEnumComparison(): void
    {
        $a = ModuleStatus::Loaded;
        $b = ModuleStatus::Loaded;
        $c = ModuleStatus::Pending;

        $this->assertTrue($a === $b);
        $this->assertFalse($a === $c);
    }

    public function testBackwardCompatibleValues(): void
    {
        // Verify enum values match the deprecated class constants
        // to ensure backward compatibility
        $this->assertSame(-3, ModuleStatus::Failed->value);
        $this->assertSame(-2, ModuleStatus::Disabled->value);
        $this->assertSame(-1, ModuleStatus::Unloaded->value);
        $this->assertSame(0, ModuleStatus::Pending->value);
        $this->assertSame(1, ModuleStatus::Initialing->value);
        $this->assertSame(2, ModuleStatus::Processing->value);
        $this->assertSame(3, ModuleStatus::InQueue->value);
        $this->assertSame(4, ModuleStatus::Loaded->value);
    }
}
