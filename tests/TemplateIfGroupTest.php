<?php

/**
 * RZ-54 — `{@if}` applies an operator that sits next to a parenthesised group.
 *
 * parseParens turns "(0),(1)" into a group, a ",", and a group, and "!(0)" into
 * "!" and a group. The old evaluator skipped a clip that was only an operator,
 * so the last group won and a leading "!" never flipped.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\SyntaxException;
use Razy\Template;

class TemplateIfGroupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_rz54_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $f) {
            \unlink($f);
        }
        @\rmdir($this->dir);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function conditions(): array
    {
        return [
            'false and true groups' => ['{@if (0),(1)}Y{@else}N{/if}', 'N'],
            'true and false groups' => ['{@if (1),(0)}Y{@else}N{/if}', 'N'],
            'true or false groups' => ['{@if (1)|(0)}Y{@else}N{/if}', 'Y'],
            'false or true groups' => ['{@if (0)|(1)}Y{@else}N{/if}', 'Y'],
            'not false group' => ['{@if !(0)}Y{@else}N{/if}', 'Y'],
            'not true group' => ['{@if !(1)}Y{@else}N{/if}', 'N'],
            'not false then and true' => ['{@if !(0),(1)}Y{@else}N{/if}', 'Y'],
            'not true then and true' => ['{@if !(1),(1)}Y{@else}N{/if}', 'N'],
            'and a negated group' => ['{@if (1),!(0)}Y{@else}N{/if}', 'Y'],
            'and a negated true group' => ['{@if (1),!(1)}Y{@else}N{/if}', 'N'],
            'spaces around the operator' => ['{@if (0) , (1)}Y{@else}N{/if}', 'N'],
            'space after the bang' => ['{@if ! (0)}Y{@else}N{/if}', 'Y'],
            'nested and' => ['{@if ((0),(1))}Y{@else}N{/if}', 'N'],
            'nested not' => ['{@if !((0))}Y{@else}N{/if}', 'Y'],
            'group and a literal' => ['{@if (0),1}Y{@else}N{/if}', 'N'],
            'true group and a false literal' => ['{@if (1),0}Y{@else}N{/if}', 'N'],
            'false group or a literal' => ['{@if (0)|1}Y{@else}N{/if}', 'Y'],
            'false group or true then true' => ['{@if (0)|1,1}Y{@else}N{/if}', 'Y'],
            'false group or a negated false literal' => ['{@if (0)|!0}Y{@else}N{/if}', 'Y'],
            'true group and a negated true literal' => ['{@if (1),!1}Y{@else}N{/if}', 'N'],
            'group equal to a false literal' => ['{@if (1)=0}Y{@else}N{/if}', 'N'],
            'group equal to zero' => ['{@if (0)=0}Y{@else}N{/if}', 'Y'],
            'group greater than a larger literal' => ['{@if (1)>2}Y{@else}N{/if}', 'N'],
            'group less than a smaller literal' => ['{@if (2)<1}Y{@else}N{/if}', 'N'],
            'group not-equal to a literal' => ['{@if (0)!=1}Y{@else}N{/if}', 'Y'],
            'group less-or-equal a smaller literal' => ['{@if (1)<=0}Y{@else}N{/if}', 'N'],
            'group or not-equal to one' => ['{@if (0)|!=1}Y{@else}N{/if}', 'Y'],
            'literal and a group' => ['{@if 1,(0)}Y{@else}N{/if}', 'N'],
            'literal or a group' => ['{@if 0|(1)}Y{@else}N{/if}', 'Y'],
            'literal equal to a group' => ['{@if 1=(1)}Y{@else}N{/if}', 'Y'],
            'literal greater than a group' => ['{@if 2>(1)}Y{@else}N{/if}', 'Y'],
            'greater-than between groups' => ['{@if (2)>(1)}Y{@else}N{/if}', 'Y'],
            'greater-than the other way' => ['{@if (1)>(2)}Y{@else}N{/if}', 'N'],
            'not-equal between groups' => ['{@if (0)!=(1)}Y{@else}N{/if}', 'Y'],
            'not of a comparison group' => ['{@if !(1>0)}Y{@else}N{/if}', 'N'],
            'not of a false comparison group' => ['{@if !(0>1)}Y{@else}N{/if}', 'Y'],
            'dangling comparison is false' => ['{@if (1)>}Y{@else}N{/if}', 'N'],
            'flat and still short-circuits' => ['{@if 0,1}Y{@else}N{/if}', 'N'],
            'flat or still short-circuits' => ['{@if 1|0}Y{@else}N{/if}', 'Y'],
            'flat not zero' => ['{@if !0}Y{@else}N{/if}', 'Y'],
            'flat not one' => ['{@if !1}Y{@else}N{/if}', 'N'],
            'flat not-equal comparison' => ['{@if !1=0}Y{@else}N{/if}', 'Y'],
            'bang after and is the next operand' => ['{@if 1,!0}Y{@else}N{/if}', 'Y'],
            'or with a following bang short-circuits' => ['{@if 1|!1}Y{@else}N{/if}', 'Y'],
            'and with a following bang stays false' => ['{@if 0,!0}Y{@else}N{/if}', 'N'],
            'a single false group' => ['{@if (0)}Y{@else}N{/if}', 'N'],
            'a single true group' => ['{@if (1)}Y{@else}N{/if}', 'Y'],
            'quoted comma stays one operand' => ['{@if (\'a,b\')}Y{@else}N{/if}', 'Y'],
            'false and a quoted script' => ['{@if (0),(\'<script>"\')}Y{@else}N{/if}', 'N'],
            'quoted script alone is true' => ['{@if (\'<script>"\')}Y{@else}N{/if}', 'Y'],
            'not of an empty quote' => ['{@if !(\'\')}Y{@else}N{/if}', 'Y'],
            'double not of a false group' => ['{@if !!(0)}Y{@else}N{/if}', 'N'],
            'and short-circuits before a later or' => ['{@if (0),(1)|(1)}Y{@else}N{/if}', 'N'],
            'newline between groups' => ["{@if (0),\n(1)}Y{@else}N{/if}", 'N'],
            'dangling comma stays true' => ['{@if (1),}Y{@else}N{/if}', 'Y'],
            'trailing bang stays false' => ['{@if !}Y{@else}N{/if}', 'N'],
            'nested greater-than keeps the raw numbers' => ['{@if (2)>((1))}Y{@else}N{/if}', 'Y'],
        ];
    }

    #[DataProvider('conditions')]
    public function testCondition(string $body, string $expected): void
    {
        $this->assertSame($expected, $this->render($body));
    }

    public function testVariableInsideAGroup(): void
    {
        $this->assertSame('N', $this->render('{@if ($z),(1)}Y{@else}N{/if}', ['z' => 0]));
        $this->assertSame('Y', $this->render('{@if !($z)}Y{@else}N{/if}', ['z' => 0]));
        $this->assertSame('Y', $this->render('{@if ($name)=(\'a\')}Y{@else}N{/if}', ['name' => 'a']));
        $this->assertSame('Y', $this->render('{@if (\'a\')|=($list)}Y{@else}N{/if}', ['list' => ['a', 'b']]));
        $this->assertSame('N', $this->render('{@if (\'z\')|=($list)}Y{@else}N{/if}', ['list' => ['a', 'b']]));
        $this->assertSame('Y', $this->render('{@if (\'a\')|=$list}Y{@else}N{/if}', ['list' => ['a', 'b']]));
        $this->assertSame('N', $this->render('{@if (\'z\')|=$list}Y{@else}N{/if}', ['list' => ['a', 'b']]));
    }

    public function testChosenBranchIsNotParsedAgain(): void
    {
        $stored = '{@if 1}Z{/if}';
        $this->assertSame($stored, $this->render('{@if !(0)}{$x}{@else}N{/if}', ['x' => $stored]));
    }

    public function testUnbalancedGroupStillThrows(): void
    {
        $this->expectException(SyntaxException::class);
        $this->render('{@if (0}Y{@else}N{/if}');
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $body, array $vars = []): string
    {
        $file = $this->dir . '/t' . \bin2hex(\random_bytes(3)) . '.tpl';
        \file_put_contents($file, $body);
        $source = (new Template())->load($file);
        if ($vars !== []) {
            $source->getRoot()?->assign($vars);
        }

        return $source->output();
    }
}
