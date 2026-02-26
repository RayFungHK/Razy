<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Database\FetchMode;

/**
 * Tests for the FetchMode enum introduced in Phase 4.1.
 */
#[CoversClass(FetchMode::class)]
class FetchModeTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = FetchMode::cases();
        $this->assertCount(3, $cases);
    }

    #[DataProvider('fetchModeValueProvider')]
    public function testEnumValues(FetchMode $mode, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $mode->value);
    }

    public static function fetchModeValueProvider(): array
    {
        return [
            'Standard' => [FetchMode::Standard, 'standard'],
            'Group'    => [FetchMode::Group, 'group'],
            'KeyPair'  => [FetchMode::KeyPair, 'keypair'],
        ];
    }

    public function testFromString(): void
    {
        $mode = FetchMode::from('group');
        $this->assertSame(FetchMode::Group, $mode);
    }

    public function testTryFromInvalidReturnsNull(): void
    {
        $mode = FetchMode::tryFrom('nonexistent');
        $this->assertNull($mode);
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        // Empty string is NOT a valid FetchMode value;
        // 'standard' is the explicit string for Standard mode
        $mode = FetchMode::tryFrom('');
        $this->assertNull($mode);
    }

    public function testEnumComparison(): void
    {
        $a = FetchMode::Group;
        $b = FetchMode::Group;
        $c = FetchMode::KeyPair;

        $this->assertTrue($a === $b);
        $this->assertFalse($a === $c);
    }
}
