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

use InvalidArgumentException;

/**
 * What a UI kit lets `.rzx` markup use, as data a package registers.
 *
 * Razy knows no element library: every tag, attribute, hook and preset
 * name comes from a vocabulary file a package declares in its package.php
 * (`'rzx' => ['vocabulary' => 'vocabulary.json']`). With no vocabulary,
 * none of these checks run.
 *
 *     {
 *       "prefixes": ["x-"],                       tags with a prefix must be listed
 *       "elements": {"x-button": {"attributes": {
 *           "label": "string", "tone": ["plain", "danger"], "busy": "boolean"}}},
 *       "attributePrefixes": ["x-", "tone"],      on a listed tag, such attributes must be listed
 *       "forbiddenAttributes": ["data-x-part"],   never allowed
 *       "hooks": ["--x-gap"],                     names allowed in hooks={{…}}
 *       "slotMarker": "data-x-slot",              added to each direct child of a named fill
 *       "mappings": {"motion": {"attribute": "data-x-motion", "values": ["fade", "rise"]}},
 *       "profiles": {"screen": {"elements": ["p", "a"], "customElements": true,
 *                               "forbiddenAttributes": ["class"], "hooks": false, "mappings": false}}
 *     }
 *
 * An attribute spec is "string", "boolean", "number", "json", "any", or a
 * list of allowed values.
 */
final class Vocabulary
{
    /**
     * @param list<string> $prefixes
     * @param array<string, array<string, string|list<string>>> $elements tag => attribute => spec
     * @param list<string> $attributePrefixes
     * @param list<string> $forbidden
     * @param list<string>|null $hooks null = any hook name
     * @param array<string, array{attribute: string, values: list<string>}> $mappings
     * @param array<string, Profile> $profiles
     */
    private function __construct(
        public readonly array $prefixes = [],
        public readonly array $elements = [],
        public readonly array $attributePrefixes = [],
        public readonly array $forbidden = [],
        public readonly ?array $hooks = null,
        public readonly ?string $slotMarker = null,
        public readonly array $mappings = [],
        public readonly array $profiles = [],
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function fromFile(string $path): self
    {
        $text = @\file_get_contents($path);
        $data = $text === false ? null : \json_decode($text, true);
        if (!\is_array($data)) {
            throw new InvalidArgumentException('Cannot read the .rzx vocabulary ' . $path . ': ' . ($text === false ? 'unreadable' : \json_last_error_msg()));
        }

        return self::fromArray($data, $path);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $label = 'vocabulary'): self
    {
        /** @return list<string> */
        $strings = static function (mixed $list, string $key) use ($label): array {
            $out = [];
            foreach ($list === null ? [] : (\is_array($list) ? $list : [null]) as $value) {
                if (!\is_string($value)) {
                    throw new InvalidArgumentException($label . ': "' . $key . '" must be a list of strings');
                }
                $out[] = $value;
            }

            return $out;
        };
        $elements = [];
        foreach ((array) ($data['elements'] ?? []) as $tag => $element) {
            if (!\is_string($tag) || !Names::isElement($tag) || !\is_array($element)) {
                throw new InvalidArgumentException($label . ': invalid element entry ' . \var_export($tag, true));
            }
            $attributes = [];
            foreach ((array) ($element['attributes'] ?? []) as $name => $spec) {
                $attributes[(string) $name] = \is_array($spec) ? $strings($spec, 'elements.' . $tag . '.' . $name) : (string) $spec;
            }
            $elements[$tag] = $attributes;
        }
        $mappings = [];
        foreach ((array) ($data['mappings'] ?? []) as $name => $mapping) {
            if (!\is_string($name) || !\is_array($mapping) || !\is_string($mapping['attribute'] ?? null)) {
                throw new InvalidArgumentException($label . ': mapping ' . \var_export($name, true) . ' needs an "attribute"');
            }
            self::dataAttribute($mapping['attribute'], $label . ': mapping ' . $name);
            $mappings[$name] = ['attribute' => $mapping['attribute'], 'values' => $strings($mapping['values'] ?? [], 'mappings.' . $name . '.values')];
        }
        $profiles = [];
        foreach ((array) ($data['profiles'] ?? []) as $name => $profile) {
            if (!\is_string($name) || !\is_array($profile)) {
                throw new InvalidArgumentException($label . ': invalid profile ' . \var_export($name, true));
            }
            $profiles[$name] = Profile::fromArray($name, $profile);
        }
        $marker = $data['slotMarker'] ?? null;
        if ($marker !== null) {
            self::dataAttribute($marker, $label . ': slotMarker');
        }

        return new self(
            $strings($data['prefixes'] ?? null, 'prefixes'),
            $elements,
            $strings($data['attributePrefixes'] ?? null, 'attributePrefixes'),
            $strings($data['forbiddenAttributes'] ?? null, 'forbiddenAttributes'),
            \array_key_exists('hooks', $data) ? $strings($data['hooks'], 'hooks') : null,
            \is_string($marker) && $marker !== '' ? $marker : null,
            $mappings,
            $profiles,
        );
    }

    /**
     * Combine several packages' vocabularies. Lists are joined; a later
     * definition of the same element, mapping or profile replaces the earlier.
     */
    public static function merge(self ...$parts): self
    {
        $out = self::none();
        foreach ($parts as $part) {
            $hooks = $out->hooks === null && $part->hooks === null ? null : [...$out->hooks ?? [], ...$part->hooks ?? []];
            $out = new self(
                \array_values(\array_unique([...$out->prefixes, ...$part->prefixes])),
                [...$out->elements, ...$part->elements],
                \array_values(\array_unique([...$out->attributePrefixes, ...$part->attributePrefixes])),
                \array_values(\array_unique([...$out->forbidden, ...$part->forbidden])),
                $hooks === null ? null : \array_values(\array_unique($hooks)),
                $part->slotMarker ?? $out->slotMarker,
                [...$out->mappings, ...$part->mappings],
                [...$out->profiles, ...$part->profiles],
            );
        }

        return $out;
    }

    /**
     * Names a vocabulary makes the compiler emit (a mapping's target, the slot
     * marker) must be `data-*` attributes: a package can never turn a value
     * into an event handler, a style, a URL or broken markup.
     */
    private static function dataAttribute(mixed $name, string $what): void
    {
        if (!\is_string($name) || !\str_starts_with($name, 'data-') || !Names::isElement(\substr($name, 5))) {
            throw new InvalidArgumentException($what . ' must be a data-* attribute (lowercase, digits, dashes), got ' . \var_export($name, true));
        }
    }

    public function isEmpty(): bool
    {
        return $this->signature() === self::none()->signature();
    }

    public function profile(?string $name): ?Profile
    {
        return $name === null ? null : ($this->profiles[$name] ?? null);
    }

    /** True when a tag falls under a registered prefix (so it must be listed). */
    public function governs(string $tag): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (\str_starts_with($tag, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function governsAttribute(string $name): bool
    {
        foreach ($this->attributePrefixes as $prefix) {
            if (\str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Part of every component's cache hash: a vocabulary change recompiles. */
    public function signature(): string
    {
        return \hash('sha256', \serialize([
            $this->prefixes, $this->elements, $this->attributePrefixes, $this->forbidden, $this->hooks,
            $this->slotMarker, $this->mappings, \array_map(static fn (Profile $p): array => $p->toArray(), $this->profiles),
        ]));
    }
}
