<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template;
use Razy\Template\Block;

/**
 * RZ-22: plain HTML comments in a template are dropped, including comments that span
 * several lines. Before, only the opening line was dropped and the rest of the comment
 * (up to "-->") rendered as visible page text.
 */
#[CoversClass(Block::class)]
class TemplateCommentTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = \sys_get_temp_dir() . '/razy-comment-test-' . \uniqid();
        \mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tempDir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->tempDir);
    }

    public function testSingleLineCommentIsDropped(): void
    {
        $out = $this->render("<nav>\n<!-- note: one line -->\n<a>Chat</a>\n</nav>\n");

        $this->assertSame("<nav>\n<a>Chat</a>\n</nav>\n", $out);
    }

    public function testMultiLineCommentIsDroppedUpToItsCloser(): void
    {
        $out = $this->render(
            "<aside>\n" .
            "    <!-- Soft DRAFT: the rail stays across SPA swaps.\n" .
            "         UXH-22b: Knowledge is one entry. -->\n" .
            "    <nav>Rail</nav>\n" .
            "</aside>\n",
        );

        $this->assertStringNotContainsString('UXH-22b', $out);
        $this->assertStringNotContainsString('-->', $out);
        $this->assertSame("<aside>\n    <nav>Rail</nav>\n</aside>\n", $out);
    }

    public function testLongCommentBodyAndBlocksAfterIt(): void
    {
        $source = $this->load(
            "<!-- first\n" .
            "middle {\$name}\n" .
            "last -->\n" .
            "<!-- START BLOCK: row -->\n" .
            "<li>{\$name}</li>\n" .
            "<!-- END BLOCK: row -->\n",
        );
        $source->getRoot()->newBlock('row')->assign(['name' => 'Ada']);

        $this->assertSame("<li>Ada</li>\n", $source->output());
    }

    public function testClosedCommentFollowedByOpenOneOnTheSameLine(): void
    {
        $out = $this->render("<!-- a --> <!-- b\nstill b -->\n<p>shown</p>\n");

        $this->assertSame("<p>shown</p>\n", $out);
    }

    public function testInlineBlockTagCommentStillParsed(): void
    {
        $source = $this->load("<ul>\n<!-- START BLOCK: item -->\n<li>{\$v}</li>\n<!-- END BLOCK: item -->\n</ul>\n");
        $source->getRoot()->newBlock('item')->assign(['v' => '1']);

        $this->assertSame("<ul>\n<li>1</li>\n</ul>\n", $source->output());
    }

    private function load(string $content): Template\Source
    {
        $file = $this->tempDir . '/t-' . \uniqid() . '.tpl';
        \file_put_contents($file, $content);

        return (new Template())->load($file);
    }

    private function render(string $content): string
    {
        return $this->load($content)->output();
    }
}
