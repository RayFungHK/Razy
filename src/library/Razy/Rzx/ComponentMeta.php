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
 * A compiled component's public contract: its props schema and its slots.
 * The compiler checks every use site against it.
 */
final class ComponentMeta
{
    /**
     * @param array<string, Prop> $props
     * @param array<string, array{list<string>, ?int}> $slots literal slot name ('' = default) => [accepts, max]
     * @param bool $dynamicSlots the component also has slots with computed names
     */
    public function __construct(
        public readonly string $name,
        public readonly array $props,
        public readonly array $slots,
        public readonly bool $dynamicSlots = false,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $props = [];
        foreach ((array) ($data['props'] ?? []) as $name => $prop) {
            $props[(string) $name] = Prop::fromArray((array) $prop);
        }
        $slots = [];
        foreach ((array) ($data['slots'] ?? []) as $name => $slot) {
            $slot = (array) $slot;
            /** @var list<string> $accepts */
            $accepts = \array_values(\array_filter((array) ($slot[0] ?? []), \is_string(...)));
            $slots[(string) $name] = [$accepts, \is_int($slot[1] ?? null) ? $slot[1] : null];
        }

        return new self(\is_string($data['name'] ?? null) ? $data['name'] : '', $props, $slots, (bool) ($data['dynamicSlots'] ?? false));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'props' => \array_map(static fn (Prop $p): array => $p->toArray(), $this->props),
            'slots' => $this->slots,
            'dynamicSlots' => $this->dynamicSlots,
        ];
    }
}
