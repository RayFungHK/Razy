<?php

/**
 * RZ-29 — UI fixtures: `Fixture`, `FixtureRenderer` and `php Razy.phar ui list|render`.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\TemplateException;
use Razy\Http\SecurityHeaders;
use Razy\Template;
use Razy\View\Fixture;
use Razy\View\FixtureRenderer;
use UnexpectedValueException;

#[CoversClass(Fixture::class)]
#[CoversClass(FixtureRenderer::class)]
class UiFixtureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_ui_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/cards/layout', 0o777, true);
        \mkdir($this->dir . '/node_modules/x', 0o777, true);
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
        SecurityHeaders::setCurrent(null);

        $this->put('cards/card.tpl', "<section class=\"card\"><h2>{\$title}</h2><p>{\$body}</p></section>\n");
        $this->put('cards/card.css', ".card{border:1px solid}\n");
        $this->put('cards/card.fixture.php', <<<'PHP'
            <?php
            use Razy\View\Fixture;

            return Fixture::template('card.tpl')
                ->themes('light', 'dark')
                ->widths(1280, 360)
                ->assets(css: ['card.css'])
                ->base(['title' => 'Members', 'body' => 'Hello <b>'])
                ->state('default')
                ->state('empty', ['body' => ''])
                ->state('zh', ['title' => '成員'], lang: 'zh-HK');
            PHP);
        $this->put('node_modules/x/skip.fixture.php', "<?php return 1;\n");
    }

    protected function tearDown(): void
    {
        SecurityHeaders::setCurrent(null);
        $this->rmdir($this->dir);
    }

    // ── Fixture ──────────────────────────────────────────────────────────

    public function testStatesOverlayTheBaseValues(): void
    {
        $fixture = $this->card();

        $this->assertSame('card', $fixture->getName());
        $this->assertSame(['default', 'empty', 'zh'], $fixture->getStates());
        $this->assertSame(['title' => 'Members', 'body' => ''], $fixture->valuesOf('empty'));
        $this->assertSame('zh-HK', $fixture->langOf('zh'));
        $this->assertSame('en', $fixture->langOf('default'));
        $this->assertSame([1280, 360], $fixture->getWidths());
    }

    public function testAFixtureWithoutStatesHasDefault(): void
    {
        $this->assertSame(['default'], Fixture::template('x.tpl')->getStates());
    }

    public function testUnknownStateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->card()->valuesOf('nope');
    }

    public function testRemoteAssetsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixture::template('x.tpl')->assets(css: ['https://cdn.example.com/a.css']);
    }

    public function testWidthsAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixture::template('x.tpl')->widths(10);
    }

    // ── discovery ────────────────────────────────────────────────────────

    public function testDiscoverSkipsVendorAndNodeModules(): void
    {
        $this->assertSame([\realpath($this->dir . '/cards/card.fixture.php')], FixtureRenderer::discover([$this->dir]));
    }

    public function testARootUnderADotFolderIsStillScanned(): void
    {
        \mkdir($this->dir . '/.hidden/views', 0o777, true);
        \copy($this->dir . '/cards/card.fixture.php', $this->dir . '/.hidden/views/card.fixture.php');

        $this->assertSame(
            [\realpath($this->dir . '/.hidden/views/card.fixture.php')],
            FixtureRenderer::discover([$this->dir . '/.hidden/views']),
        );
    }

    public function testSkippedFoldersAreNotEntered(): void
    {
        // An unreadable folder inside node_modules/ must not break discovery: it is never entered.
        \mkdir($this->dir . '/node_modules/locked', 0o000);
        try {
            $this->assertSame([\realpath($this->dir . '/cards/card.fixture.php')], FixtureRenderer::discover([$this->dir]));
        } finally {
            \chmod($this->dir . '/node_modules/locked', 0o755);
        }
    }

    public function testFindByName(): void
    {
        $this->assertSame('card', FixtureRenderer::find('card', [$this->dir])->getName());

        $this->expectException(UnexpectedValueException::class);
        FixtureRenderer::find('nope', [$this->dir]);
    }

    public function testAFixtureFileMustReturnAFixture(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FixtureRenderer::load($this->dir . '/node_modules/x/skip.fixture.php');
    }

    public function testDescribeIsWhatTheHarnessReads(): void
    {
        $this->assertSame([
            'name' => 'card',
            'file' => \realpath($this->dir . '/cards/card.fixture.php'),
            'component' => null,
            'states' => ['default', 'empty', 'zh'],
            'themes' => ['light', 'dark'],
            'widths' => [1280, 360],
        ], FixtureRenderer::describe($this->card()));
    }

    // ── rendering ────────────────────────────────────────────────────────

    public function testRenderProducesAStandaloneEscapedDocument(): void
    {
        $html = (new FixtureRenderer())->render($this->card(), 'default', 'dark');

        $this->assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"en\" data-theme=\"dark\">", $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="assets/css0-card.css">', $html);
        $this->assertStringContainsString('<main id="ui-fixture" data-fixture="card" data-state="default"><section class="card"><h2>Members</h2><p>Hello &lt;b&gt;</p></section>', $html);
        $this->assertStringNotContainsString('rz-anchor', $html);
    }

    public function testStateLangIsUsed(): void
    {
        $html = (new FixtureRenderer())->render($this->card(), 'zh', 'light');

        $this->assertStringContainsString('<html lang="zh-HK" data-theme="light">', $html);
        $this->assertStringContainsString('<h2>成員</h2>', $html);
    }

    public function testPushesFromTheTemplateReachTheFrame(): void
    {
        $this->put('cards/pushy.tpl', "{@push to=\"body.scripts\"}<script type=\"module\" src=\"assets/js0-x.js\"></script>{/push}<p>x</p>\n");
        $fixture = Fixture::template('pushy.tpl')->withFile($this->dir . '/cards/pushy.fixture.php');

        $html = (new FixtureRenderer())->render($fixture, 'default', 'light');

        $this->assertMatchesRegularExpression('#<script nonce="[^"]+" type="module" src="assets/js0-x.js"></script>\n</body>#', $html);
    }

    public function testAPushToAnAnchorTheFrameLacksFails(): void
    {
        $this->put('cards/bad.tpl', "{@push to=\"nowhere\"}<i>x</i>{/push}<p>x</p>\n");
        $fixture = Fixture::template('bad.tpl')->withFile($this->dir . '/cards/bad.fixture.php');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('nowhere');
        (new FixtureRenderer())->render($fixture, 'default', 'light');
    }

    public function testNetworkReferencesFailTheRender(): void
    {
        $this->put('cards/remote.tpl', "<img src=\"https://example.com/a.png\" alt=\"\">\n");
        $fixture = Fixture::template('remote.tpl')->withFile($this->dir . '/cards/remote.fixture.php');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('over the network');
        (new FixtureRenderer())->render($fixture, 'default', 'light');
    }

    public function testUnknownThemeThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new FixtureRenderer())->render($this->card(), 'default', 'sepia');
    }

    public function testLayoutIncludesResolveInTheFixturesLayoutFolder(): void
    {
        $this->put('cards/layout/badge.tpl', "<b>badge</b>\n");
        $this->put('cards/page.tpl', "<p>\n<!-- INCLUDE BLOCK: @layout/badge.tpl -->\n</p>\n");
        $fixture = Fixture::template('page.tpl')->layouts('layout')->withFile($this->dir . '/cards/page.fixture.php');

        $this->assertStringContainsString('<b>badge</b>', (new FixtureRenderer())->render($fixture, 'default', 'light'));
    }

    public function testWriteRendersEveryStateAndThemeAndCopiesAssets(): void
    {
        $out = $this->dir . '/out';
        $files = (new FixtureRenderer())->write($this->card(), $out);

        $this->assertCount(6, $files);
        $this->assertFileExists($out . '/card/zh-dark.html');
        $this->assertFileEquals($this->dir . '/cards/card.css', $out . '/card/assets/css0-card.css');

        $one = (new FixtureRenderer())->write($this->card(), $out, ['empty'], ['light']);
        $this->assertSame([$out . '/card/empty-light.html'], $one);
    }

    // ── CLI ──────────────────────────────────────────────────────────────

    public function testTheCliHasListAndRenderAndNoWidthOption(): void
    {
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/system/terminal/ui.inc.php');

        $this->assertStringContainsString("case 'list':", $src);
        $this->assertStringContainsString("case 'render':", $src);
        $this->assertStringContainsString('--json', $src);
        $this->assertStringContainsString('exit(1);', $src);
        $this->assertStringNotContainsString('--width', $src);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    // ── RZ-28: .rzx component fixtures ───────────────────────────────────

    public function testAComponentFixtureRendersItsStatesWithFillsPushesAndFunctions(): void
    {
        \mkdir($this->dir . '/kit');
        \mkdir($this->dir . '/app');
        $this->put('kit/List.rzx', <<<'RZX'
            <props>
            return ['label' => Prop::string()->required(), 'rows' => Prop::list(Prop::shape(['name' => Prop::string()]))->default([])];
            </props>
            <section aria-label={label}>
              <div class="bar"><Slot name="end" accepts="x-button" max="2" /></div>
              <ul><for each={rows} as="r"><li><App.Name value={r.name} /></li><empty><li>{t('empty')}</li></empty></for></ul>
            </section>
            <push to="body.scripts" key="list"><script type="module" src="list.js" /></push>
            RZX);
        $this->put('app/Name.rzx', "<props>\nreturn ['value' => Prop::string()->required()];\n</props>\n<b>{upper(value)}</b>");
        $this->put('kit/vocabulary.json', \json_encode(['slotMarker' => 'data-x-slot', 'prefixes' => ['x-'], 'elements' => ['x-button' => ['attributes' => []]]]));
        $this->put('kit/List.fixture.php', <<<'PHP'
            <?php
            use Razy\View\Fixture;

            return Fixture::component('Kit.List')
                ->namespaces(['App' => '../app'])
                ->vocabulary('vocabulary.json')
                ->rzxFunction('t', static fn (string $key): string => '[' . $key . ']')
                ->themes('light', 'dark')
                ->base(['label' => 'Members <&>'])
                ->state('default', ['rows' => [['name' => 'ann'], ['name' => '<i>']]])
                ->state('empty', ['rows' => []])
                ->fill('end', '<x-button label="Add"></x-button>');
            PHP);
        $fixture = FixtureRenderer::load($this->dir . '/kit/List.fixture.php');

        $this->assertSame('Kit.List', FixtureRenderer::describe($fixture)['component']);
        $this->assertSame(['default', 'empty'], $fixture->getStates());

        $html = (new FixtureRenderer())->render($fixture, 'default', 'dark');
        $this->assertStringContainsString('<html lang="en" data-theme="dark">', $html);
        $this->assertStringContainsString('<section aria-label="Members &lt;&amp;&gt;"><div class="bar"><x-button label="Add"></x-button></div><ul><li><b>ANN</b></li><li><b>&lt;I&gt;</b></li></ul></section>', $html);
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+" type="module" src="list\.js"></script>\n</body>#', $html, 'the push reached the frame, with the nonce');

        $empty = (new FixtureRenderer())->render($fixture, 'empty', 'light');
        $this->assertStringContainsString('<ul><li>[empty]</li></ul>', $empty);

        $written = (new FixtureRenderer())->write($fixture, $this->dir . '/out');
        $this->assertCount(4, $written);
    }

    public function testAComponentFixtureFailsLikeTheCompilerDoes(): void
    {
        \mkdir($this->dir . '/kit');
        $this->put('kit/Bad.rzx', "<props>\nreturn ['n' => Prop::int()->required()];\n</props>\n<p>{n}</p>");
        $this->put('kit/Bad.fixture.php', "<?php\nreturn Razy\\View\\Fixture::component('Kit.Bad')->state('wrong', ['n' => 'x']);\n");
        $fixture = FixtureRenderer::load($this->dir . '/kit/Bad.fixture.php');

        $this->expectException(\Razy\Rzx\Runtime\RenderError::class);
        $this->expectExceptionMessage('Invalid prop: Kit.Bad.n must be a int, got string');
        (new FixtureRenderer())->render($fixture, 'wrong', 'light');
    }

    public function testComponentFixtureValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fixture::component('kit.card');
    }

    private function card(): Fixture
    {
        return FixtureRenderer::load($this->dir . '/cards/card.fixture.php');
    }

    private function put(string $relative, string $content): void
    {
        \file_put_contents($this->dir . '/' . $relative, $content);
    }

    private function rmdir(string $dir): void
    {
        if (!\is_dir($dir) || \is_link($dir)) {
            @\unlink($dir);

            return;
        }
        foreach (\scandir($dir) as $item) {
            if ('.' !== $item && '..' !== $item) {
                $this->rmdir($dir . '/' . $item);
            }
        }
        \rmdir($dir);
    }
}
