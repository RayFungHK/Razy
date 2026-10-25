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
use Razy\Rzx\SafeHtml;

/**
 * The fills passed to one component use: slot name (`''` = default slot)
 * to a closure that renders the fill with the caller's variables.
 */
final class SlotBag
{
    /** @var array<string, Closure(): void> */
    private array $fills = [];

    /**
     * @param array<string, Closure(): void|SafeHtml> $fills
     */
    public function __construct(array $fills = [])
    {
        foreach ($fills as $name => $fill) {
            $this->fills[(string) $name] = $fill instanceof SafeHtml
                ? static function () use ($fill): void {
                    echo $fill->html;
                }
            : $fill;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->fills[$name]);
    }

    public function render(string $name): void
    {
        if (isset($this->fills[$name])) {
            ($this->fills[$name])();
        }
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return \array_map(\strval(...), \array_keys($this->fills));
    }
}
