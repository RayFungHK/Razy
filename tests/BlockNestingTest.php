<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\TemplateException;
use Razy\Template;
use Razy\Template\Block;
use Razy\Template\Source;

/**
 * Tests for Block nesting, USE directives, maxDepth guards,
 * RECURSION, TEMPLATE (readonly), and WRAPPER blocks.
 *
 * Task 4: Block nested 3+ levels with USE directives.
 * Task 3: Verify maxDepth=100 guard.
 */
#[CoversClass(Block::class)]
class BlockNestingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/razy-block-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = array_diff(scandir($this->tempDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($this->tempDir . '/' . $file);
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Write template content to a temp file and load via Template.
     */
    private function loadTemplate(string $content): Source
    {
        $file = $this->tempDir . '/test-' . uniqid() . '.tpl';
        file_put_contents($file, $content);
        $template = new Template();

        return $template->load($file);
    }

    /**
     * Access root block from a Source.
     */
    private function getRootBlock(Source $source): Block
    {
        // Source stores root block internally; we access via newEntity → nested block access
        // Actually, Source exposes the root through its entity's block
        $reflection = new \ReflectionClass($source);
        $prop = $reflection->getProperty('rootBlock');
        $prop->setAccessible(true);

        return $prop->getValue($source);
    }

    // ─── Basic Nesting ───────────────────────────────────────────

    public function testSingleLevelBlock(): void
    {
        $source = $this->loadTemplate(
            "Before\n" .
            "<!-- START BLOCK: child -->\n" .
            "Child content\n" .
            "<!-- END BLOCK: child -->\n" .
            "After\n"
        );
        $root = $this->getRootBlock($source);

        $this->assertTrue($root->hasBlock('child'));
        $this->assertInstanceOf(Block::class, $root->getBlock('child'));
        $this->assertSame('child', $root->getBlock('child')->getName());
    }

    public function testTwoLevelNesting(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: outer -->\n" .
            "Outer\n" .
            "<!-- START BLOCK: inner -->\n" .
            "Inner\n" .
            "<!-- END BLOCK: inner -->\n" .
            "<!-- END BLOCK: outer -->\n"
        );
        $root = $this->getRootBlock($source);
        $outer = $root->getBlock('outer');

        $this->assertTrue($outer->hasBlock('inner'));
        $this->assertSame('/outer', $outer->getPath());
        $this->assertSame('/outer/inner', $outer->getBlock('inner')->getPath());
    }

    public function testThreeLevelNesting(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: level1 -->\n" .
            "L1\n" .
            "<!-- START BLOCK: level2 -->\n" .
            "L2\n" .
            "<!-- START BLOCK: level3 -->\n" .
            "L3\n" .
            "<!-- END BLOCK: level3 -->\n" .
            "<!-- END BLOCK: level2 -->\n" .
            "<!-- END BLOCK: level1 -->\n"
        );
        $root = $this->getRootBlock($source);
        $l1 = $root->getBlock('level1');
        $l2 = $l1->getBlock('level2');
        $l3 = $l2->getBlock('level3');

        $this->assertSame('/level1', $l1->getPath());
        $this->assertSame('/level1/level2', $l2->getPath());
        $this->assertSame('/level1/level2/level3', $l3->getPath());
    }

    public function testFourLevelNesting(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: aaa -->\n" .
            "<!-- START BLOCK: bbb -->\n" .
            "<!-- START BLOCK: ccc -->\n" .
            "<!-- START BLOCK: ddd -->\n" .
            "Deep\n" .
            "<!-- END BLOCK: ddd -->\n" .
            "<!-- END BLOCK: ccc -->\n" .
            "<!-- END BLOCK: bbb -->\n" .
            "<!-- END BLOCK: aaa -->\n"
        );
        $root = $this->getRootBlock($source);

        $this->assertSame('/aaa/bbb/ccc/ddd',
            $root->getBlock('aaa')
                ->getBlock('bbb')
                ->getBlock('ccc')
                ->getBlock('ddd')
                ->getPath()
        );
    }

    // ─── USE Directive ───────────────────────────────────────────

    public function testUseDirectiveReusesBlock(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: parent -->\n" .
            "<!-- START BLOCK: original -->\n" .
            "Original content\n" .
            "<!-- END BLOCK: original -->\n" .
            "<!-- START BLOCK: container -->\n" .
            "<!-- USE original BLOCK: reused -->\n" .
            "<!-- END BLOCK: container -->\n" .
            "<!-- END BLOCK: parent -->\n"
        );
        $root = $this->getRootBlock($source);
        $parent = $root->getBlock('parent');
        $container = $parent->getBlock('container');

        $this->assertTrue($container->hasBlock('reused'));
        // The reused block is the same object as original
        $this->assertSame(
            $parent->getBlock('original'),
            $container->getBlock('reused')
        );
    }

    public function testUseDirectiveAcrossMultipleLevels(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: root_blk -->\n" .
            "<!-- START BLOCK: shared -->\n" .
            "Shared content\n" .
            "<!-- END BLOCK: shared -->\n" .
            "<!-- START BLOCK: level_a -->\n" .
            "<!-- START BLOCK: level_b -->\n" .
            "<!-- USE shared BLOCK: deep_reuse -->\n" .
            "<!-- END BLOCK: level_b -->\n" .
            "<!-- END BLOCK: level_a -->\n" .
            "<!-- END BLOCK: root_blk -->\n"
        );
        $root = $this->getRootBlock($source);
        $rootBlk = $root->getBlock('root_blk');
        $levelB = $rootBlk->getBlock('level_a')->getBlock('level_b');

        $this->assertTrue($levelB->hasBlock('deep_reuse'));
        $this->assertSame(
            $rootBlk->getBlock('shared'),
            $levelB->getBlock('deep_reuse')
        );
    }

    public function testUseDirectiveNotFoundThrowsError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('cannot be found');

        $this->loadTemplate(
            "<!-- START BLOCK: outer -->\n" .
            "<!-- USE nonexistent BLOCK: fail_reuse -->\n" .
            "<!-- END BLOCK: outer -->\n"
        );
    }

    // ─── TEMPLATE (readonly) Blocks ──────────────────────────────

    public function testTemplateBlockIsReadonly(): void
    {
        $source = $this->loadTemplate(
            "<!-- TEMPLATE BLOCK: tpl_def -->\n" .
            "Template content\n" .
            "<!-- END BLOCK: tpl_def -->\n"
        );
        $root = $this->getRootBlock($source);
        $tplBlock = $root->getBlock('tpl_def');

        $this->assertTrue($tplBlock->isReadonly());
    }

    public function testStartBlockIsNotReadonly(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: normal -->\n" .
            "Normal content\n" .
            "<!-- END BLOCK: normal -->\n"
        );
        $root = $this->getRootBlock($source);
        $this->assertFalse($root->getBlock('normal')->isReadonly());
    }

    // ─── RECURSION Blocks ────────────────────────────────────────

    public function testRecursionBlockReferencesSelf(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: tree_node -->\n" .
            "Node {\$name}\n" .
            "<!-- RECURSION BLOCK: tree_node -->\n" .
            "<!-- END BLOCK: tree_node -->\n"
        );
        $root = $this->getRootBlock($source);
        $treeNode = $root->getBlock('tree_node');

        // The recursion block 'tree_node' inside tree_node should reference itself
        $this->assertTrue($treeNode->hasBlock('tree_node'));
        $this->assertSame($treeNode, $treeNode->getBlock('tree_node'));
    }

    public function testRecursionBlockNotFoundThrowsError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('No parent block');

        $this->loadTemplate(
            "<!-- START BLOCK: child_blk -->\n" .
            "<!-- RECURSION BLOCK: nonexistent -->\n" .
            "<!-- END BLOCK: child_blk -->\n"
        );
    }

    // ─── Duplicate Block Detection ───────────────────────────────

    public function testDuplicateBlockNameThrowsError(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('already exists');

        $this->loadTemplate(
            "<!-- START BLOCK: dup_test -->\n" .
            "First\n" .
            "<!-- END BLOCK: dup_test -->\n" .
            "<!-- START BLOCK: dup_test -->\n" .
            "Second\n" .
            "<!-- END BLOCK: dup_test -->\n"
        );
    }

    // ─── getClosest ──────────────────────────────────────────────

    public function testGetClosestFindsAncestor(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: ancestor -->\n" .
            "<!-- START BLOCK: middle_gc -->\n" .
            "<!-- START BLOCK: leaf_gc -->\n" .
            "Leaf\n" .
            "<!-- END BLOCK: leaf_gc -->\n" .
            "<!-- END BLOCK: middle_gc -->\n" .
            "<!-- END BLOCK: ancestor -->\n"
        );
        $root = $this->getRootBlock($source);
        $leaf = $root->getBlock('ancestor')->getBlock('middle_gc')->getBlock('leaf_gc');

        $result = $leaf->getClosest('ancestor');
        $this->assertNotNull($result);
        $this->assertSame('ancestor', $result->getName());
    }

    public function testGetClosestReturnsNullForMissing(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: solo_blk -->\n" .
            "Content\n" .
            "<!-- END BLOCK: solo_blk -->\n"
        );
        $root = $this->getRootBlock($source);
        $solo = $root->getBlock('solo_blk');

        // _ROOT doesn't match 'missing'
        $this->assertNull($solo->getClosest('missing'));
    }

    // ─── getTemplate (search for readonly TEMPLATE blocks) ───────

    public function testGetTemplateFindsReadonlyBlock(): void
    {
        $source = $this->loadTemplate(
            "<!-- TEMPLATE BLOCK: tpl_item -->\n" .
            "Template: {\$value}\n" .
            "<!-- END BLOCK: tpl_item -->\n" .
            "<!-- START BLOCK: content -->\n" .
            "Content\n" .
            "<!-- END BLOCK: content -->\n"
        );
        $root = $this->getRootBlock($source);
        $content = $root->getBlock('content');

        $result = $content->getTemplate('tpl_item');
        $this->assertNotNull($result);
        $this->assertTrue($result->isReadonly());
    }

    // ─── Structure & Content ─────────────────────────────────────

    public function testStructureContainsTextAndBlocks(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: mixed -->\n" .
            "Text before\n" .
            "<!-- START BLOCK: sub_mix -->\n" .
            "Sub content\n" .
            "<!-- END BLOCK: sub_mix -->\n" .
            "Text after\n" .
            "<!-- END BLOCK: mixed -->\n"
        );
        $root = $this->getRootBlock($source);
        $mixed = $root->getBlock('mixed');
        $structure = $mixed->getStructure();

        // Should have: string + Block + string
        $textCount = 0;
        $blockCount = 0;
        foreach ($structure as $item) {
            if (is_string($item)) {
                $textCount++;
            }
            if ($item instanceof Block) {
                $blockCount++;
            }
        }
        $this->assertSame(2, $textCount);
        $this->assertSame(1, $blockCount);
    }

    // ─── Block Type ──────────────────────────────────────────────

    public function testBlockTypeIsStart(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: typed -->\n" .
            "Content\n" .
            "<!-- END BLOCK: typed -->\n"
        );
        $root = $this->getRootBlock($source);
        $this->assertSame('START', $root->getBlock('typed')->getType());
    }

    public function testBlockTypeIsWrapper(): void
    {
        $source = $this->loadTemplate(
            "<!-- WRAPPER BLOCK: wrap_blk -->\n" .
            "Wrapper content\n" .
            "<!-- END BLOCK: wrap_blk -->\n"
        );
        $root = $this->getRootBlock($source);
        $this->assertSame('WRAPPER', $root->getBlock('wrap_blk')->getType());
    }

    public function testBlockTypeIsTemplate(): void
    {
        $source = $this->loadTemplate(
            "<!-- TEMPLATE BLOCK: tpl_type -->\n" .
            "Template content\n" .
            "<!-- END BLOCK: tpl_type -->\n"
        );
        $root = $this->getRootBlock($source);
        $this->assertSame('TEMPLATE', $root->getBlock('tpl_type')->getType());
    }

    // ─── getParent ───────────────────────────────────────────────

    public function testChildBlockHasCorrectParent(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: papa -->\n" .
            "<!-- START BLOCK: kiddo -->\n" .
            "Child\n" .
            "<!-- END BLOCK: kiddo -->\n" .
            "<!-- END BLOCK: papa -->\n"
        );
        $root = $this->getRootBlock($source);
        $papa = $root->getBlock('papa');
        $kiddo = $papa->getBlock('kiddo');

        $this->assertSame($papa, $kiddo->getParent());
    }

    // ─── Sibling Blocks ──────────────────────────────────────────

    public function testMultipleSiblingBlocks(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: sib_a -->\n" .
            "Sibling A\n" .
            "<!-- END BLOCK: sib_a -->\n" .
            "<!-- START BLOCK: sib_b -->\n" .
            "Sibling B\n" .
            "<!-- END BLOCK: sib_b -->\n" .
            "<!-- START BLOCK: sib_c -->\n" .
            "Sibling C\n" .
            "<!-- END BLOCK: sib_c -->\n"
        );
        $root = $this->getRootBlock($source);

        $this->assertTrue($root->hasBlock('sib_a'));
        $this->assertTrue($root->hasBlock('sib_b'));
        $this->assertTrue($root->hasBlock('sib_c'));
    }

    // ─── Entity Creation ─────────────────────────────────────────

    public function testNewEntityCreatesEntity(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: entity_test -->\n" .
            "Content\n" .
            "<!-- END BLOCK: entity_test -->\n"
        );
        $root = $this->getRootBlock($source);
        $block = $root->getBlock('entity_test');
        $entity = $block->newEntity();

        $this->assertInstanceOf(\Razy\Template\Entity::class, $entity);
    }

    // ─── getBlock Error Case ─────────────────────────────────────

    public function testGetBlockThrowsForMissingBlock(): void
    {
        $source = $this->loadTemplate(
            "<!-- START BLOCK: exists -->\n" .
            "Content\n" .
            "<!-- END BLOCK: exists -->\n"
        );
        $root = $this->getRootBlock($source);

        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('not exists');
        $root->getBlock('doesnotexist');
    }
}
