<?php

/**
 * RZ-53 — vc() applies a combinator after a bare version or a group.
 *
 * A "," or "|" used to be read only after a constraint clip, so
 * `0|>=1.0`, `1.2|>=2.0` and `(>=1.0),(<2.0)` were always false.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\SyntaxException;
use Razy\Util\VersionUtil;

#[CoversClass(VersionUtil::class)]
class VersionUtilCombinatorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function requirements(): array
    {
        return [
            'zero or a floor, the zero' => ['0|>=1.0', '0', true],
            'zero or a floor, the zero spelled out' => ['0|>=1.0', '0.0.0', true],
            'zero or a floor, the floor' => ['0|>=1.0', '1.0.0', true],
            'zero or a floor, above the floor' => ['0|>=1.0', '2.3.0', true],
            'zero or a floor, the gap' => ['0|>=1.0', '0.5.0', false],
            'composer or spelling of the zero' => ['0 || >=1.0', '0', true],
            'composer or spelling of the gap' => ['0 || >=1.0', '0.9.0', false],
            'exact or a floor, the exact' => ['1.2|>=2.0', '1.2', true],
            'exact or a floor, the floor' => ['1.2|>=2.0', '2.0.0', true],
            'exact or a floor, above the floor' => ['1.2|>=2.0', '3.1.0', true],
            'exact or a floor, a later patch' => ['1.2|>=2.0', '1.2.1', false],
            'exact or a floor, between them' => ['1.2|>=2.0', '1.9.0', false],
            'composer or spelling of the exact' => ['1.2 || >=2.0', '1.2.0', true],
            'composer or spelling between them' => ['1.2 || >=2.0', '1.5.0', false],
            'and of two groups, inside' => ['(>=1.0),(<2.0)', '1.5.0', true],
            'and of two groups, the floor' => ['(>=1.0),(<2.0)', '1.0.0', true],
            'and of two groups, just under the ceiling' => ['(>=1.0),(<2.0)', '1.9.9', true],
            'and of two groups, the ceiling' => ['(>=1.0),(<2.0)', '2.0.0', false],
            'and of two groups, under the floor' => ['(>=1.0),(<2.0)', '0.9.0', false],
            'and of two groups, spaces' => ['(>=1.0) , (<2.0)', '1.2.0', true],
            'nested and of two groups' => ['((>=1.0),(<2.0))', '1.2.0', true],
            'or of two groups, the low side' => ['(<1.0)|(>=2.0)', '0.5.0', true],
            'or of two groups, the high side' => ['(<1.0)|(>=2.0)', '2.0.0', true],
            'or of two groups, the gap' => ['(<1.0)|(>=2.0)', '1.5.0', false],
            'group or a constraint, the group' => ['(1.2)|>=2.0', '1.2.0', true],
            'group or a constraint, the constraint' => ['(1.2)|>=2.0', '2.5.0', true],
            'group or a constraint, neither' => ['(1.2)|>=2.0', '1.3.0', false],
            'bare and a constraint, both' => ['1.2,>=1.0', '1.2.0', true],
            'bare and a constraint, not the exact' => ['1.2,>=1.0', '1.3.0', false],
            'wildcard or an exact, the wildcard' => ['2.*|1.0', '2.1.1', true],
            'wildcard or an exact, the exact' => ['2.*|1.0', '1.0.0', true],
            'wildcard or an exact, neither' => ['2.*|1.0', '1.5.0', false],
            'hyphen range or a floor, the range' => ['1.0 - 2.0|>=3.0', '1.5.0', true],
            'hyphen range or a floor, the floor' => ['1.0 - 2.0|>=3.0', '3.0.0', true],
            'hyphen range or a floor, the range end' => ['1.0 - 2.0|>=3.0', '2.0.0', false],
            'hyphen range or a floor, between them' => ['1.0 - 2.0|>=3.0', '2.5.0', false],
            'constraint or still returns on the first match' => ['>=1.0,<1.1||>=2.0,<2.1', '1.0.0', true],
            'constraint or still reaches the second branch' => ['>=1.0,<1.1||>=2.0,<2.1', '2.0.0', true],
            'constraint or still misses the gap' => ['>=1.0,<1.1||>=2.0,<2.1', '1.5.0', false],
            'constraint and is unchanged' => ['>=1.0,<2.0', '1.5.0', true],
            'constraint and still excludes the ceiling' => ['>=1.0,<2.0', '2.0.0', false],
            'or returns before an invalid right side' => ['0|???', '0', true],
            'or does not accept an invalid right side' => ['0|???', '1.0.0', false],
            'and of a miss does not accept a later match' => ['0,>=1.0', '2.0.0', false],
            'two clips with no combinator are false' => ['(>=1.0)(>=2.0)', '2.5.0', false],
            'a constraint glued to a group is false' => ['>=1.0(>=2.0)', '2.5.0', false],
            'a leading combinator is false' => ['|>=1.0', '1.0.0', false],
        ];
    }

    #[DataProvider('requirements')]
    public function testRequirement(string $requirement, string $version, bool $expected): void
    {
        $this->assertSame($expected, VersionUtil::vc($requirement, $version));
    }

    public function testUnbalancedGroupStillThrows(): void
    {
        $this->expectException(SyntaxException::class);
        VersionUtil::vc('(>=1.0', '1.0.0');
    }
}
