<?php

/**
 * RZ-30 — `{@if}` must not re-parse a substituted value.
 *
 * The login-page shape is `{@if $x}{$x}{/if}`. A second parseText on the
 * chosen branch used to execute `{$…}` and `{@…}` that arrived inside `$x`.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template;
use Razy\Template\Entity;

#[CoversClass(Template::class)]
#[CoversClass(Entity::class)]
class TemplateIfInjectionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_if_injection_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (\is_file($f)) {
                \unlink($f);
            }
        }
        @\rmdir($this->dir);
    }

    public function testLoginErrShapePrintsTheValueLiterally(): void
    {
        $vars = ['x' => '{$secret}', 'secret' => 'LEAK'];

        $this->assertSame('{$secret}', $this->render(new Template(), '{@if $x}{$x}{/if}', $vars));
        $this->assertSame(
            '{$secret}',
            $this->render((new Template())->setAutoEscape(true), '{@if $x}{$x}{/if}', $vars),
        );
    }

    public function testFunctionTagInsideTheValueIsText(): void
    {
        $out = $this->render((new Template())->setAutoEscape(true), '{@if $x}{$x}{/if}', [
            'x' => '{@if $secret}PWN{/if}',
            'secret' => 'LEAK',
        ]);

        $this->assertSame('{@if $secret}PWN{/if}', $out);
    }

    public function testConditionComparesTheValueAsAPlainString(): void
    {
        $out = $this->render(new Template(), "{@if \$x='{\$secret}'}EQ{@else}NE{/if}", [
            'x' => '{$secret}',
            'secret' => 'LEAK',
        ]);

        $this->assertSame('EQ', $out);
    }

    public function testElseInAValueDoesNotMoveTheBranch(): void
    {
        $out = $this->render(new Template(), '{@if $ok}{$x}{@else}NO{/if}', [
            'ok' => true,
            'x' => 'LEFT{@else}RIGHT',
        ]);

        $this->assertSame('LEFT{@else}RIGHT', $out);
    }

    public function testNestedElseStillBelongsToTheInnerIf(): void
    {
        $body = '{@if $a}{@if $b}yes{@else}no{/if}{@else}outer{/if}';

        $this->assertSame('no', $this->render(new Template(), $body, ['a' => true, 'b' => false]));
        $this->assertSame('outer', $this->render(new Template(), $body, ['a' => false, 'b' => true]));
    }

    public function testUnchosenBranchDoesNotRun(): void
    {
        $out = $this->render(new Template(), "{@if \$ok}yes{@else}{@def name='nick' value='side'}{/if}[{\$nick}]", [
            'ok' => true,
        ]);

        $this->assertSame('yes[]', $out);
    }

    public function testDefAndRepeatSubstituteOnce(): void
    {
        $vars = ['x' => '{$secret}', 'secret' => 'LEAK'];

        $this->assertSame(
            '{$secret}',
            $this->render(new Template(), "{@def name='nick' value=\$x}{\$nick}", $vars),
        );
        $this->assertSame(
            '{$secret}{$secret}',
            $this->render(new Template(), '{@repeat length=2}{$x}{/repeat}', $vars),
        );
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(Template $tpl, string $body, array $vars): string
    {
        $file = $this->dir . '/t' . \bin2hex(\random_bytes(3)) . '.tpl';
        \file_put_contents($file, $body);
        $source = $tpl->load($file);
        $source->getRoot()?->assign($vars);

        return $source->output();
    }
}
