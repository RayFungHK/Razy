<?php

/**
 * RZ-27 — one layout per site, named anchors, {@push}, and the loud RZ-26 error.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor;
use Razy\Exception\ConfigurationException;
use Razy\Exception\TemplateException;
use Razy\Http\SecurityHeaders;
use Razy\Template;
use Razy\Template\IncludeResolver;
use Razy\View\Anchors;

#[CoversClass(Anchors::class)]
#[CoversClass(Template::class)]
class TemplateAnchorsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_anchors_' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir . '/dist/layout', 0o777, true);
        \mkdir($this->dir . '/dist/mod/view', 0o777, true);
        Template::addPluginFolder(SYSTEM_ROOT . '/src/plugins/Template');
        SecurityHeaders::setCurrent(null);
    }

    protected function tearDown(): void
    {
        SecurityHeaders::setCurrent(null);
        $this->rmdir($this->dir);
    }

    // ── anchors and pushes ───────────────────────────────────────────────

    public function testAHeadAnchorPrintedBeforeTheBodyReceivesTheBodysPushes(): void
    {
        $html = $this->render(
            "<head>{@anchor name=\"head.styles\"}</head>\n"
            . "<body>{@push to=\"head.styles\"}<link rel=\"stylesheet\" href=\"{\$css}\">{/push}<p>hi</p></body>\n",
            ['css' => '/a.css'],
        );

        $this->assertSame("<head><link rel=\"stylesheet\" href=\"/a.css\"></head>\n<body><p>hi</p></body>\n", $html);
    }

    public function testDuplicatesAreDroppedByKeyAndBySourceAndOrderIsRespected(): void
    {
        $html = $this->render(
            "{@anchor name=\"s\"}\n"
            . "{@push to=\"s\" order=5}<link href=\"/b.css\">{/push}\n"
            . "{@push to=\"s\"}<link href=\"/a.css\">{/push}\n"
            . "{@push to=\"s\"}<link href=\"/a.css\">{/push}\n"
            . "{@push to=\"s\" key=\"k\"}<i>one</i>{/push}\n"
            . "{@push to=\"s\" key=\"k\"}<i>two</i>{/push}\n",
        );

        $this->assertSame("<link href=\"/a.css\"><i>one</i><link href=\"/b.css\">\n\n\n\n\n\n", $html);
    }

    public function testAPushInAFalseIfBranchDoesNotRegister(): void
    {
        $html = $this->render(
            "{@anchor name=\"s\"}|{@if \$on}{@push to=\"s\"}<i>yes</i>{/push}{@else}{@push to=\"s\"}<i>no</i>{/push}{/if}\n",
            ['on' => false],
        );

        $this->assertSame("<i>no</i>|\n", $html);
    }

    public function testAPushToAnUnprintedAnchorIsHeldAndReported(): void
    {
        $template = $this->template();
        $html = $template->load($this->put('dist/mod/view/p.tpl', "x{@push to=\"nowhere\"}<i>y</i>{/push}\n"))->output();

        $this->assertSame("x\n", $html);
        $this->assertSame(['nowhere' => 1], $template->anchors()->unresolved());

        // a later render in the same response can still print it
        $later = $template->load($this->put('dist/mod/view/later.tpl', "{@anchor name=\"nowhere\"}\n"))->output();
        $this->assertSame("<i>y</i>\n", $later);
        $this->assertSame([], $template->anchors()->unresolved());
    }

    public function testAnAnchorPrintedTwiceGetsItsContentOnce(): void
    {
        $html = $this->render("{@anchor name=\"s\"}|{@anchor name=\"s\"}{@push to=\"s\"}<i>x</i>{/push}\n");

        $this->assertSame("<i>x</i>|\n", $html);
    }

    public function testAPushAfterItsAnchorWasPrintedIsReported(): void
    {
        $anchors = new Anchors();
        $this->assertSame('', $anchors->finalize($anchors->marker('s')));
        $anchors->push('s', '<i>late</i>');

        $this->assertSame(['s' => 1], $anchors->unresolved());
    }

    public function testQueuedSectionsShareAnchors(): void
    {
        $template = $this->template();
        $template->load($this->put('dist/mod/view/head.tpl', "<head>{@anchor name=\"head.styles\"}</head>\n"))->queue('head');
        $template->load($this->put('dist/mod/view/body.tpl', "{@push to=\"head.styles\"}<link href=\"/x.css\">{/push}<body></body>\n"))->queue('body');

        $this->assertSame("<head><link href=\"/x.css\"></head>\n<body></body>\n", $template->outputQueued(['head', 'body']));
    }

    // ── markers ──────────────────────────────────────────────────────────

    public function testMarkupThatLooksLikeAMarkerWithAnotherTokenStaysLiteral(): void
    {
        $fake = '<!--rz-anchor:' . \str_repeat('0', 24) . ':s-->';
        $html = $this->render("{@anchor name=\"s\"}[{\$user->raw}]{@push to=\"s\"}<i>x</i>{/push}\n", ['user' => $fake]);

        $this->assertSame("<i>x</i>[{$fake}]\n", $html);
    }

    public function testTheTokenIsRandomAndChangesOnReset(): void
    {
        $anchors = new Anchors();
        $first = $anchors->marker('s');
        $this->assertMatchesRegularExpression('/^<!--rz-anchor:[0-9a-f]{24}:s-->$/', $first);

        $anchors->reset();
        $anchors->push('s', '<i>x</i>');
        $this->assertSame($first, $anchors->finalize($first), 'a marker from the previous response is not replaced');
        $this->assertSame('<i>x</i>', $anchors->finalize($anchors->marker('s')));
    }

    public function testResetEmptiesEveryAnchor(): void
    {
        $anchors = new Anchors();
        $anchors->push('s', '<i>old</i>');
        $this->assertTrue($anchors->claimImportMap());
        $anchors->reset();

        $this->assertSame('', $anchors->finalize($anchors->marker('s')));
        $this->assertTrue($anchors->claimImportMap(), 'the importmap can print again in the next response');
    }

    // ── CSP ──────────────────────────────────────────────────────────────

    public function testPushedScriptsAndModulePreloadsGetTheRequestNonce(): void
    {
        $headers = SecurityHeaders::fromConfig([], 'site');
        SecurityHeaders::setCurrent($headers);
        $nonce = \htmlspecialchars($headers->nonce(), ENT_QUOTES);

        $html = $this->render(
            "{@anchor name=\"s\"}\n"
            . "{@push to=\"s\"}<script type=\"module\" src=\"/a.js\"></script>{/push}\n"
            . "{@push to=\"s\"}<link rel=\"modulepreload\" href=\"/b.js\">{/push}\n"
            . "{@push to=\"s\"}<script nonce=\"keep\" src=\"/c.js\"></script>{/push}\n",
        );

        $this->assertStringContainsString("<script nonce=\"{$nonce}\" type=\"module\" src=\"/a.js\"></script>", $html);
        $this->assertStringContainsString("<link nonce=\"{$nonce}\" rel=\"modulepreload\" href=\"/b.js\">", $html);
        $this->assertStringContainsString('<script nonce="keep" src="/c.js"></script>', $html);
    }

    public function testAnInlineScriptCannotBePushed(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('An inline <script> cannot be pushed');
        (new Anchors())->push('s', '<script>alert(1)</script>');
    }

    public function testImportmapAndJsonScriptsCanBePushed(): void
    {
        $anchors = new Anchors();
        $anchors->push('s', '<script type="importmap">{"imports":{}}</script>');
        $anchors->push('s', '<script type="application/json" id="d">{"a":1}</script>');

        $this->assertStringContainsString('application/json', $anchors->finalize($anchors->marker('s')));
    }

    public function testTheContentAnchorIsReserved(): void
    {
        $this->expectException(TemplateException::class);
        (new Anchors())->push(Anchors::CONTENT, '<p>x</p>');
    }

    public function testImportmapPrintsOncePerResponse(): void
    {
        $anchors = new Anchors();
        $this->assertTrue($anchors->claimImportMap());
        $this->assertFalse($anchors->claimImportMap());
    }

    // ── layout ───────────────────────────────────────────────────────────

    public function testRenderPagePutsThePageIntoTheLayoutAndFillsItsAnchors(): void
    {
        $this->put('dist/layout/site.tpl', "<html><head>{@anchor name=\"head.styles\"}</head><body>{@anchor name=\"content\"}{@anchor name=\"body.scripts\"}</body></html>\n");
        $page = $this->put('dist/mod/view/page.tpl', '{@push to="head.styles"}<link href="/p.css">{/push}{@push to="body.scripts"}<script src="/p.js"></script>{/push}<main>{$title}</main>');

        $headers = SecurityHeaders::fromConfig([], 'site');
        SecurityHeaders::setCurrent($headers);
        $nonce = \htmlspecialchars($headers->nonce(), ENT_QUOTES);
        $template = $this->template()->setLayout('@layout/site.tpl');
        $source = $template->load($page);
        $source->assign('title', 'Members');

        $this->assertSame(
            "<html><head><link href=\"/p.css\"></head><body><main>Members</main><script nonce=\"{$nonce}\" src=\"/p.js\"></script></body></html>\n",
            $template->renderPage($source),
        );
    }

    public function testRenderPageWithoutALayoutPrintsThePage(): void
    {
        $template = $this->template();
        $page = $template->load($this->put('dist/mod/view/page.tpl', "{@anchor name=\"s\"}<p>x</p>{@push to=\"s\"}<i>y</i>{/push}\n"));

        $this->assertSame("<i>y</i><p>x</p>\n", $template->renderPage($page));
    }

    public function testAMissingLayoutThrows(): void
    {
        $template = $this->template()->setLayout('@layout/nope.tpl');
        $page = $template->load($this->put('dist/mod/view/page.tpl', "<p>x</p>\n"));

        $this->expectException(TemplateException::class);
        $template->renderPage($page);
    }

    public function testLayoutConfig(): void
    {
        $this->assertNull(Distributor::layoutFromConfig([], 'd'));
        $this->assertSame('@layout/site.tpl', Distributor::layoutFromConfig(['layout' => 'site'], 'd'));
        $this->assertSame('@layout/app/shell.tpl', Distributor::layoutFromConfig(['layout' => '@layout/app/shell.tpl'], 'd'));

        $this->expectException(ConfigurationException::class);
        Distributor::layoutFromConfig(['layout' => '../etc/passwd'], 'd');
    }

    public function testTheDistributorResetsAnchorsOnEveryResponse(): void
    {
        // Same source check as the CSP nonce wiring (SecurityHeadersTest): beginResponse()
        // runs for every request, including each request of a long-lived worker.
        $src = (string) \file_get_contents(SYSTEM_ROOT . '/src/library/Razy/Distributor.php');
        $begin = \substr($src, (int) \strpos($src, 'private function beginResponse(): void'));
        $begin = \substr($begin, 0, (int) \strpos($begin, "\n    }\n"));

        $this->assertStringContainsString('->anchors()->reset();', $begin);
        $this->assertStringContainsString('->setLayout($this->layout)', $src);
    }

    // ── RZ-26: structural comments inside a function tag ────────────────

    public function testAnIncludeInsideAnIfIsAParseErrorNamingBothLines(): void
    {
        $this->put('dist/mod/view/part.tpl', "<b>part</b>\n");
        $page = $this->put('dist/mod/view/page.tpl', "<p>\n{@if \$show}\n<!-- INCLUDE BLOCK: part.tpl -->\n{/if}\n</p>\n");

        try {
            $this->template()->load($page);
            $this->fail('expected a TemplateException');
        } catch (TemplateException $e) {
            $this->assertStringContainsString("'INCLUDE BLOCK: part.tpl' at {$page}:3 is inside {@if} opened at {$page}:2", $e->getMessage());
        }
    }

    public function testABlockInsideAnIfIsAParseError(): void
    {
        $page = $this->put('dist/mod/view/page.tpl', "{@if \$x}\n<!-- START BLOCK: row -->\n<i></i>\n<!-- END BLOCK: row -->\n{/if}\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("'START BLOCK: row'");
        $this->template()->load($page);
    }

    public function testAClosedIfBeforeAnIncludeIsFine(): void
    {
        $this->put('dist/mod/view/part.tpl', "<b>part</b>\n");
        $page = $this->put('dist/mod/view/page.tpl', "{@if \$x}x{/if}\n{@unknown}\n<!-- INCLUDE BLOCK: part.tpl -->\n");

        $source = $this->template()->load($page);
        $source->assign('x', true);

        $this->assertSame("x\n{@unknown}\n<b>part</b>\n", $source->output());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $tpl, array $vars = []): string
    {
        $source = $this->template()->load($this->put('dist/mod/view/t' . \bin2hex(\random_bytes(3)) . '.tpl', $tpl));
        if ($vars) {
            $source->assign($vars);
        }

        return $source->output();
    }

    private function template(): Template
    {
        return (new Template())->setIncludeResolver(IncludeResolver::fromConfig([], 'd', $this->dir . '/dist'));
    }

    private function put(string $relative, string $content): string
    {
        $path = $this->dir . '/' . $relative;
        \file_put_contents($path, $content);

        return $path;
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
