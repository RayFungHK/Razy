<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use Razy\Rzx\Runtime\AnchorSink;

/**
 * Records pushes; markers print as `<!--anchor:name-->`.
 */
final class RecordingSink implements AnchorSink
{
    /** @var list<array{string, string, ?string, int}> */
    public array $pushes = [];

    public function push(string $anchor, string $html, ?string $key = null, int $order = 0): void
    {
        $this->pushes[] = [$anchor, $html, $key, $order];
    }

    public function marker(string $name): string
    {
        return '<!--anchor:' . $name . '-->';
    }
}
