<?php

/**
 * RZ-31 — `{@each}` encloses its body and renders every item.
 *
 * The enclosure property was misspelled, so the body rendered once as the
 * last item. A value that contains `{$…}` must stay text.
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
class TemplateEachTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_each_' . \bin2hex(\random_bytes(4));
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

    public function testEveryItemIsRendered(): void
    {
        $out = $this->render(new Template(), '{@each source=$items as=\'item\'}[{$item.key}:{$item.value}]{/each}', [
            'items' => ['a' => 'A', 'b' => 'B'],
        ]);

        $this->assertSame('[a:A][b:B]', $out);
    }

    public function testValueContainingATagIsText(): void
    {
        $vars = [
            'items' => ['a' => 'A{$secret}', 'b' => 'B{@if $secret}PWN{/if}'],
            'secret' => 'LEAK',
        ];
        $body = '{@each source=$items as=\'item\'}[{$item.key}:{$item.value}]{/each}';

        $this->assertSame('[a:A{$secret}][b:B{@if $secret}PWN{/if}]', $this->render(new Template(), $body, $vars));
        $this->assertSame(
            '[a:A{$secret}][b:B{@if $secret}PWN{/if}]',
            $this->render((new Template())->setAutoEscape(true), $body, $vars),
        );
    }

    public function testAutoEscapeRunsOnce(): void
    {
        $out = $this->render((new Template())->setAutoEscape(true), '{@each source=$items as=\'item\'}[{$item.value}]{/each}', [
            'items' => ['<b>'],
        ]);

        $this->assertSame('[&lt;b&gt;]', $out);
    }

    public function testBodyIsNotLeftInTheOuterTemplate(): void
    {
        $out = $this->render(new Template(), '{@each source=$items as=\'item\'}[{$item.value}]{/each}-{$tail}', [
            'items' => 'nope',
            'tail' => 'Z',
        ]);

        $this->assertSame('-Z', $out);
    }

    public function testDefaultAliasRendersEveryItem(): void
    {
        $out = $this->render(new Template(), '{@each $items}[{$kvp.key}:{$kvp.value}]{/each}', [
            'items' => ['a' => 'A', 'b' => 'B'],
        ]);

        $this->assertSame('[a:A][b:B]', $out);
    }

    public function testNestedEachRendersEveryItem(): void
    {
        $out = $this->render(new Template(), '{@each source=$groups as=\'group\'}[{$group.key}:{@each source=$group.value as=\'item\'}{$item.value},{/each}]{/each}', [
            'groups' => ['g' => ['a', 'b']],
        ]);

        $this->assertSame('[g:a,b,]', $out);
    }

    public function testTextAfterTheLoopStays(): void
    {
        $out = $this->render(new Template(), '{@each source=$items as=\'item\'}[{$item.value}]{/each}-{$tail}', [
            'items' => ['A', 'B'],
            'tail' => 'Z',
        ]);

        $this->assertSame('[A][B]-Z', $out);
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
