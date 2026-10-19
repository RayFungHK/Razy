<?php

/**
 * RZ-03 — template auto-escape (regression pins).
 *
 * Before RZ-03 `{$var}` always printed raw HTML: every template had to
 * remember `->escape`, and oaao's /login reflected `next` unescaped (C21).
 * Now a dist opts in with `'auto_escape' => true` in dist.php (new dists get
 * it from the dist.php skeleton); existing dists keep raw output until they
 * opt in. `->raw` is the per-tag opt-out and the lint flags it unless it
 * carries a written justification.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\ConfigurationException;
use Razy\Template;
use Razy\Template\Entity;

#[CoversClass(Template::class)]
#[CoversClass(Entity::class)]
class TemplateAutoEscapeTest extends TestCase
{
    private const XSS = '"><script>alert(1)</script>';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_autoescape_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
        // The shipped modifier/function plugins (escape, upper, raw, if...), as the bootstrap registers them
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

    public function testDefaultTemplateKeepsRawOutput(): void
    {
        $tpl = new Template();
        $this->assertFalse($tpl->isAutoEscape());
        $this->assertSame('<p>' . self::XSS . '</p>', $this->render($tpl, '<p>{$v}</p>', ['v' => self::XSS]));
    }

    public function testAutoEscapeEscapesPlainTags(): void
    {
        $tpl = (new Template())->setAutoEscape(true);
        $out = $this->render($tpl, '<input value="{$v}">', ['v' => self::XSS]);

        $this->assertSame('<input value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">', $out);
    }

    public function testAutoEscapeCoversPathsModifiersAndFallbacks(): void
    {
        $tpl = (new Template())->setAutoEscape(true);
        // `|` falls through only when an alternative is not renderable (here: an array)
        $out = $this->render($tpl, '{$u.name->upper}|{$u|$v}', ['u' => ['name' => '<b>'], 'v' => "'x'"]);

        $this->assertSame('&lt;B&gt;|&#039;x&#039;', $out);
    }

    public function testRawOptsOutAndEscapeIsNotDoubled(): void
    {
        $tpl = (new Template())->setAutoEscape(true);
        $out = $this->render($tpl, '{$v->raw}|{$v->escape}|{$v->trim->raw}', ['v' => '<i>&amp;</i>']);

        $this->assertSame('<i>&amp;</i>|&lt;i&gt;&amp;amp;&lt;/i&gt;|<i>&amp;</i>', $out);
    }

    public function testQuotedTextCannotFakeASafeModifier(): void
    {
        $tpl = (new Template())->setAutoEscape(true);
        $out = $this->render(
            $tpl,
            "{\$u.'bio->raw'}|{\$v->truncate:50:'->raw'}|{\$v->trim:'->escape'}",
            ['u' => ['bio->raw' => '<b>'], 'v' => '<i>'],
        );

        $this->assertSame('&lt;b&gt;|&lt;i&gt;|&lt;i&gt;', $out);
    }

    public function testRawModifierIsANoOpWithoutAutoEscape(): void
    {
        $this->assertSame('<i>', $this->render(new Template(), '{$v->raw}', ['v' => '<i>']));
    }

    public function testAutoEscapeAppliesInsideFunctionTagSegments(): void
    {
        // A segment holding a function tag is rendered through parseText(), not the compiled fast path.
        $tpl = (new Template())->setAutoEscape(true);
        $out = $this->render($tpl, '{@if $v}<b>{$v}</b>{/if}', ['v' => '<x>']);

        $this->assertSame('<b>&lt;x&gt;</b>', $out);
    }

    public function testNonStringScalarsRenderUnchanged(): void
    {
        $tpl = (new Template())->setAutoEscape(true);
        $this->assertSame('42|1.5', $this->render($tpl, '{$a}|{$b}', ['a' => 42, 'b' => 1.5]));
    }

    // -- dist.php flag -------------------------------------------------------

    public function testDistFlagParsing(): void
    {
        $this->assertFalse(Template::autoEscapeFromConfig([], 'site'), 'existing dists keep raw output');
        $this->assertTrue(Template::autoEscapeFromConfig(['auto_escape' => true], 'site'));
        $this->assertFalse(Template::autoEscapeFromConfig(['auto_escape' => false], 'site'));
    }

    public function testDistFlagRejectsExplicitNull(): void
    {
        $this->expectException(ConfigurationException::class);
        Template::autoEscapeFromConfig(['auto_escape' => null], 'site');
    }

    public function testDistFlagRejectsNonBoolean(): void
    {
        $this->expectException(ConfigurationException::class);
        Template::autoEscapeFromConfig(['auto_escape' => 'yes'], 'site');
    }

    public function testNewDistSkeletonOptsIn(): void
    {
        $skeleton = (string) \file_get_contents(SYSTEM_ROOT . '/src/asset/setup/dist.php.tpl');
        $this->assertMatchesRegularExpression("/'auto_escape'\\s*=>\\s*true/", $skeleton);
    }

    public function testDistributorAppliesFlagToItsTemplate(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $this->assertStringContainsString('Template::autoEscapeFromConfig($config', $src);
        $this->assertStringContainsString('->setAutoEscape($this->autoEscape)', $src);
    }

    // -- lint ----------------------------------------------------------------

    public function testLintFlagsUnjustifiedRaw(): void
    {
        \file_put_contents($this->dir . '/bad.tpl', "<div>{\$html->raw}</div>\n");
        \file_put_contents($this->dir . '/ok.tpl', "<!-- lint-allow: RZ-004 sanitized by the markdown renderer -->\n<div>{\$html->raw}</div>\n");
        \file_put_contents($this->dir . '/bare.tpl', "<!-- lint-allow: RZ-004 -->\n<div>{\$html->raw}</div>\n");
        \file_put_contents($this->dir . '/inline.tpl', "<!-- lint-allow: RZ-004 -->{\$html->raw}\n");
        \file_put_contents($this->dir . '/fallback.tpl', "<li>{\$nick|'->raw'}</li>\n");
        \file_put_contents($this->dir . '/pathkey.tpl', "<li>{\$u.'bio->raw'}</li>\n");
        \file_put_contents($this->dir . '/attr-dq.tpl', "<input value=\"{\$html->raw}\">\n");
        \file_put_contents($this->dir . '/attr-sq.tpl', "<a href='{\$url->raw}'>x</a>\n");
        \file_put_contents($this->dir . '/attr-fallback.tpl', "<input value=\"{\$nick|'->raw'}\">\n");

        $out = (string) \shell_exec(\escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg(SYSTEM_ROOT . '/tools/lint-module-discipline.php')
            . ' ' . \escapeshellarg($this->dir) . ' --format=json');
        $report = \json_decode($out, true);
        $this->assertIsArray($report, $out);

        $rawHits = \array_values(\array_filter(
            $report['violations'] ?? [],
            fn (array $v) => \str_contains($v['message'] ?? '', '->raw'),
        ));
        $files = \array_map(fn (array $v) => \basename($v['file']), $rawHits);
        \sort($files);

        $this->assertSame(['attr-dq.tpl', 'attr-sq.tpl', 'bad.tpl', 'bare.tpl', 'inline.tpl'], $files, 'raw output needs a written justification');
        $this->assertSame('error', $rawHits[0]['level']);
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
