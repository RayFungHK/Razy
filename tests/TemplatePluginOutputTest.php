<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Http\SecurityHeaders;
use Razy\Template;
use Razy\Template\Entity;

/**
 * RZ-28: a function plugin's output is final. The `{$…}` pass reads the
 * template source only, never what a plugin printed, so a value that looks
 * like a parameter tag stays text (the RZ-30 class of bug, for every plugin).
 */
#[CoversClass(Entity::class)]
class TemplatePluginOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_plugin_out_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/plugins', 0o777, true);
        // A plugin that prints a parameter's value as it is (like any plugin that echoes data).
        \file_put_contents($this->dir . '/plugins/function.echoval.php', <<<'PHP'
            <?php
            return function (...$arguments) {
                return new class(...$arguments) extends Razy\Template\Plugin\TFunction {
                    protected array $allowedParameters = ['value' => ''];

                    protected function processor(Razy\Template\Entity $entity, array $parameters = [], array $arguments = [], string $wrappedText = ''): ?string
                    {
                        return '[' . (string) $parameters['value'] . ']';
                    }
                };
            };
            PHP);
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
        Template::addPluginFolder($this->dir . '/plugins');
        SecurityHeaders::setCurrent(null);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/plugins/*') ?: [] as $f) {
            \unlink($f);
        }
        foreach (\glob($this->dir . '/*.tpl') ?: [] as $f) {
            \unlink($f);
        }
        \rmdir($this->dir . '/plugins');
        \rmdir($this->dir);
    }

    public function testAPluginsOutputIsNotSubstitutedAgain(): void
    {
        $html = $this->render("<p>{@echoval value=\$v} {\$name}</p>\n", ['v' => '{$secret} {$name}', 'secret' => 'LEAK', 'name' => 'Ann']);
        $this->assertSame("<p>[{\$secret} {\$name}] Ann</p>\n", $html);
    }

    public function testAPushBodyValueStaysLiteral(): void
    {
        $template = new Template();
        $page = $template->load($this->put('push.tpl', "{@anchor name=\"s\"}{@push to=\"s\"}<i>{\$v}</i>{/push}<p>x</p>\n"));
        $page->assign(['v' => '{$secret}', 'secret' => 'LEAK']);
        $this->assertSame("<i>{\$secret}</i><p>x</p>\n", $template->renderPage($page));
    }

    public function testIfAndRepeatBranchesAreStillSubstitutedOnce(): void
    {
        $html = $this->render("{@if \$on}<b>{\$v}</b>{@else}no{/if}|{@repeat length=2}<i>{\$n}</i>{/repeat}\n", ['on' => true, 'v' => '{$secret}', 'secret' => 'LEAK', 'n' => 'x']);
        $this->assertSame("<b>{\$secret}</b>|<i>x</i><i>x</i>\n", $html);
    }

    public function testNestedPluginsAndEachStillWork(): void
    {
        $html = $this->render("{@each source=\$rows as=\"r\"}<li>{\$r.value.name} {@echoval value=\$r.value.raw}</li>{/each}\n", ['rows' => [['name' => 'a', 'raw' => '{$x}']], 'x' => 'LEAK']);
        $this->assertStringNotContainsString('LEAK', $html);
        $this->assertStringContainsString('<li>a [{$x}]</li>', $html);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function render(string $source, array $values): string
    {
        $template = new Template();
        $page = $template->load($this->put('t.tpl', $source));
        $page->assign($values);

        return $page->output();
    }

    private function put(string $name, string $content): string
    {
        \file_put_contents($this->dir . '/' . $name, $content);

        return $this->dir . '/' . $name;
    }
}
