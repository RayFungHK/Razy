<?php

/**
 * RZ-11: INCLUDE resolution — `@layout/` shared layouts across modules, and a
 * missing INCLUDE throws instead of rendering nothing.
 *
 * This file is part of Razy.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\ConfigurationException;
use Razy\Exception\TemplateException;
use Razy\Template;
use Razy\Template\IncludeResolver;
use Razy\Template\Source;

#[CoversClass(IncludeResolver::class)]
#[CoversClass(Source::class)]
class TemplateIncludeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \sys_get_temp_dir() . '/razy-include-test-' . \uniqid();
        \mkdir($this->dir . '/dist/layout/part', 0o755, true);
        \mkdir($this->dir . '/dist/mod_a/view/include', 0o755, true);
        \mkdir($this->dir . '/dist/mod_b/view', 0o755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function badConfig(): iterable
    {
        yield 'not an array' => ['layout'];
        yield 'unknown key' => [['layouts' => 'x']];
        yield 'empty layout path' => [['layout_path' => ' ']];
        yield 'layout path not a string' => [['layout_path' => 1]];
        yield 'strict not a bool' => [['strict_include' => 'no']];
    }

    public function testRelativeIncludeStillWorks(): void
    {
        $this->put('dist/mod_a/view/include/alert.tpl', "<b>{\$msg}</b>\n");
        $page = $this->put('dist/mod_a/view/page.tpl', "<p>\n<!-- INCLUDE BLOCK: include/alert.tpl -->\n</p>\n");

        $source = $this->template()->load($page);
        $source->getRoot()->assign('msg', 'hi');

        $this->assertSame("<p>\n<b>hi</b>\n</p>\n", $source->output());
    }

    public function testMissingIncludeThrows(): void
    {
        $page = $this->put('dist/mod_a/view/page.tpl', "<p>\n<!-- INCLUDE BLOCK: include/nope.tpl -->\n</p>\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('INCLUDE BLOCK: include/nope.tpl does not resolve');
        $this->template()->load($page);
    }

    public function testMissingIncludeIsSkippedWithDeprecationWhenNotStrict(): void
    {
        $page = $this->put('dist/mod_a/view/page.tpl', "<p>\n<!-- INCLUDE BLOCK: include/nope.tpl -->\n</p>\n");
        $resolver = IncludeResolver::fromConfig(['templates' => ['strict_include' => false]], 'd', $this->dir . '/dist');

        $notices = [];
        \set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
            $notices[] = [$no, $msg];

            return true;
        });
        try {
            $source = (new Template())->setIncludeResolver($resolver)->load($page);
        } finally {
            \restore_error_handler();
        }

        $this->assertSame("<p>\n</p>\n", $source->output());
        $this->assertCount(1, $notices);
        $this->assertSame(E_USER_DEPRECATED, $notices[0][0]);
        $this->assertStringContainsString('include/nope.tpl', $notices[0][1]);
    }

    public function testTwoModulesShareOneLayout(): void
    {
        $this->put('dist/layout/head.tpl', "<head>{\$title}</head>\n<!-- INCLUDE BLOCK: @layout/part/nav.tpl -->\n");
        $this->put('dist/layout/part/nav.tpl', "<nav></nav>\n");
        $a = $this->put('dist/mod_a/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/head.tpl -->\nA\n");
        $b = $this->put('dist/mod_b/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/head.tpl -->\nB\n");

        $template = $this->template();
        $sa = $template->load($a);
        $sa->getRoot()->assign('title', 'one');
        $sb = $template->load($b);
        $sb->getRoot()->assign('title', 'two');

        $this->assertSame("<head>one</head>\n<nav></nav>\nA\n", $sa->output());
        $this->assertSame("<head>two</head>\n<nav></nav>\nB\n", $sb->output());
    }

    public function testLayoutBlocksAreBlocksOfThePage(): void
    {
        $this->put('dist/layout/menu.tpl', "<!-- START BLOCK: item -->\n<li>{\$name}</li>\n<!-- END BLOCK: item -->\n");
        $page = $this->put('dist/mod_a/view/page.tpl', "<ul>\n<!-- INCLUDE BLOCK: @layout/menu.tpl -->\n</ul>\n");

        $source = $this->template()->load($page);
        $source->getRoot()->newBlock('item')->assign('name', 'x');

        $this->assertSame("<ul>\n<li>x</li>\n</ul>\n", $source->output());
    }

    public function testLayoutPathCannotLeaveTheLayoutFolder(): void
    {
        $this->put('dist/secret.tpl', "secret\n");
        $page = $this->put('dist/mod_a/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/../secret.tpl -->\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('outside the layout folder');
        $this->template()->load($page);
    }

    public function testLayoutSymlinkOutOfTheFolderIsRefused(): void
    {
        $this->put('dist/secret.tpl', "secret\n");
        if (!@\symlink($this->dir . '/dist/secret.tpl', $this->dir . '/dist/layout/link.tpl')) {
            $this->markTestSkipped('symlinks unavailable');
        }
        $page = $this->put('dist/mod_a/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/link.tpl -->\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('outside the layout folder');
        $this->template()->load($page);
    }

    public function testLayoutWithoutLayoutFolderThrows(): void
    {
        $page = $this->put('dist/mod_a/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/head.tpl -->\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('no layout folder is configured');
        (new Template())->load($page);
    }

    public function testMissingLayoutFolderThrows(): void
    {
        $page = $this->put('dist/mod_a/view/page.tpl', "<!-- INCLUDE BLOCK: @layout/head.tpl -->\n");
        $resolver = IncludeResolver::fromConfig(['templates' => ['layout_path' => 'shared']], 'd', $this->dir . '/dist');

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('does not exist');
        (new Template())->setIncludeResolver($resolver)->load($page);
    }

    public function testIncludeCycleThrowsInsteadOfLooping(): void
    {
        $page = $this->put('dist/mod_a/view/page.tpl', "x\n<!-- INCLUDE BLOCK: page.tpl -->\n");

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('More than ' . Source::MAX_INCLUDES . ' INCLUDE BLOCKs');
        $this->template()->load($page);
    }

    public function testResolveLayoutFileForControllers(): void
    {
        $this->put('dist/layout/page.tpl', "page\n");
        $resolver = $this->resolver();

        $this->assertSame(\realpath($this->dir . '/dist/layout/page.tpl'), $resolver->resolveLayoutFile('@layout/page'));
        $this->assertSame(\realpath($this->dir . '/dist/layout/page.tpl'), $resolver->resolveLayoutFile('@layout/page.tpl'));
        $this->assertNull($resolver->resolveLayoutFile('page'));

        $this->expectException(TemplateException::class);
        $resolver->resolveLayoutFile('@layout/missing');
    }

    public function testFromConfigDefaults(): void
    {
        $resolver = IncludeResolver::fromConfig([], 'd', '/srv/sites/d');

        $this->assertSame('/srv/sites/d/layout', \str_replace('\\', '/', (string) $resolver->getLayoutRoot()));
        $this->assertTrue($resolver->isStrict());
        $this->assertTrue((new Template())->getIncludeResolver()->isStrict());
        $this->assertNull((new Template())->getIncludeResolver()->getLayoutRoot());
    }

    public function testFromConfigAbsoluteLayoutPath(): void
    {
        $resolver = IncludeResolver::fromConfig(['templates' => ['layout_path' => '/opt/shared']], 'd', '/srv/sites/d');

        $this->assertSame('/opt/shared', $resolver->getLayoutRoot());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badConfig')]
    public function testFromConfigRejectsBadValues(mixed $templates): void
    {
        $this->expectException(ConfigurationException::class);
        IncludeResolver::fromConfig(['templates' => $templates], 'd', '/srv/sites/d');
    }

    private function resolver(): IncludeResolver
    {
        return IncludeResolver::fromConfig([], 'd', $this->dir . '/dist');
    }

    private function template(): Template
    {
        return (new Template())->setIncludeResolver($this->resolver());
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
