<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx\Runtime;

use Closure;
use Razy\Rzx\Prop;

/**
 * One compiled component, as returned by its cache file: the render closure,
 * its props schema (for defaults and the boundary check) and the line map
 * (compiled PHP line => `.rzx` line) used to locate render errors.
 */
final class Compiled
{
    /** @var array<string, Prop> */
    public readonly array $props;

    /** Path of the compiled PHP file, set by the loader; used to map trace lines. */
    public string $compiledFile = '';

    /**
     * @param Closure(array<string, mixed>, SlotBag, Runtime): void $render
     * @param array<string, array<string, mixed>> $props Prop::toArray() per prop
     * @param array<int, int> $lines
     */
    public function __construct(
        public readonly string $name,
        public readonly string $source,
        public readonly Closure $render,
        array $props,
        public readonly array $lines,
    ) {
        $this->props = \array_map(Prop::fromArray(...), $props);
    }

    /**
     * The `.rzx` line for a line of the compiled file, or null.
     */
    public function rzxLine(int $phpLine): ?int
    {
        $found = null;
        foreach ($this->lines as $php => $rzx) {
            if ($php > $phpLine) {
                break;
            }
            $found = $rzx;
        }

        return $found;
    }
}
