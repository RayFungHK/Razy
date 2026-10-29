<?php

/**
 * RZ-52 — a "0" token or a 0 key is data, never "the end of the input".
 *
 * `while ($x = \array_shift(...))` and `if ($string)` stop at "0" and at the
 * integer key 0, because both are falsy in PHP (the query-builder sites were
 * fixed in RZ-47). Each case reproduces its defect on master: refactor() of a
 * list returned [], a selector segment "0" returned the whole collection, and a
 * bare "0" version requirement matched nothing.
 *
 * This file is part of Razy.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Collection;
use Razy\Template;
use Razy\Util\ArrayUtil;
use Razy\Util\VersionUtil;

#[CoversClass(ArrayUtil::class)]
#[CoversClass(Collection::class)]
#[CoversClass(VersionUtil::class)]
class FalsyZeroTokenTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/razy_rz52_' . \bin2hex(\random_bytes(4));
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

    public function testRefactorKeepsKeyZero(): void
    {
        // Before: array_keys() of a list starts with 0, so the loop never ran
        $this->assertSame([['a', 'c'], ['b', 'd']], ArrayUtil::refactor([['a', 'b'], ['c', 'd']]));
        $this->assertSame(
            [['name' => 'x', 0 => 'p'], ['name' => 'y', 0 => 'q']],
            ArrayUtil::refactor(['name' => ['x', 'y'], '0' => ['p', 'q']]),
        );
    }

    public function testCollectionSelectorSegmentZero(): void
    {
        $collection = new Collection([['n' => 'first'], ['n' => 'second']]);

        // Before: '0.n' stopped at "0" and returned the whole collection; '0' returned nothing
        $this->assertSame(['$.0.n' => 'first'], $collection('0.n')->getArray());
        $this->assertSame(['$.0' => ['n' => 'first']], $collection('0')->getArray());
    }

    public function testVersionRequirementZero(): void
    {
        // Before: the "0" clip ended the parse and the requirement matched no version
        $this->assertTrue(VersionUtil::vc('0', '0.0.0'));
        $this->assertTrue(VersionUtil::vc('0', '0'));
        $this->assertFalse(VersionUtil::vc('0', '1.0.0'));
    }

    public function testTemplateIfZeroOperand(): void
    {
        // The "0" clip of a group is now evaluated (false) instead of ending the loop (null); both render the
        // else branch, so this pins the behaviour rather than reproducing a visible defect
        $this->assertSame('N', $this->render('{@if (0)}Y{@else}N{/if}'));
        $this->assertSame('N', $this->render('{@if 0}Y{@else}N{/if}'));
        $this->assertSame('Y', $this->render('{@if (1)}Y{@else}N{/if}'));
    }

    private function render(string $body): string
    {
        $file = $this->dir . '/t' . \bin2hex(\random_bytes(3)) . '.tpl';
        \file_put_contents($file, $body);

        return (new Template())->load($file)->output();
    }
}
