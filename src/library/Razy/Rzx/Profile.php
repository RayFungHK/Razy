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

namespace Razy\Rzx;

/**
 * Rules for one kind of `.rzx` file, registered by name in a Vocabulary.
 *
 * A kit can, for example, keep screens to data and copy: a "screen" profile
 * with a short list of plain elements, no `class`, no hooks and no mapped
 * attributes like `motion`, while its "composition" profile allows them.
 * Which profile a file uses comes from where it is registered (a component
 * namespace or the screens of a module).
 */
final class Profile
{
    /**
     * @param list<string>|null $elements plain (dash-less) HTML elements allowed; null = any
     * @param list<string> $forbiddenAttributes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?array $elements = null,
        public readonly bool $customElements = true,
        public readonly array $forbiddenAttributes = [],
        public readonly bool $hooks = true,
        public readonly bool $mappings = true,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(string $name, array $data): self
    {
        $list = static fn (mixed $v): array => \array_values(\array_filter((array) $v, \is_string(...)));

        return new self(
            $name,
            \array_key_exists('elements', $data) && $data['elements'] !== null ? $list($data['elements']) : null,
            (bool) ($data['customElements'] ?? true),
            $list($data['forbiddenAttributes'] ?? []),
            (bool) ($data['hooks'] ?? true),
            (bool) ($data['mappings'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'elements' => $this->elements, 'customElements' => $this->customElements,
            'forbiddenAttributes' => $this->forbiddenAttributes, 'hooks' => $this->hooks, 'mappings' => $this->mappings,
        ];
    }
}
