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
use Stringable;

/**
 * One prop in a component's `<props>` schema.
 *
 *     'title' => Prop::string()->required(),
 *     'rows'  => Prop::list(Prop::shape(['id' => Prop::string()]))->default([]),
 *     'size'  => Prop::enum('sm', 'md', 'lg')->default('md'),
 *
 * Immutable: every modifier returns a copy. A prop is optional unless
 * `required()` is called; an optional prop without a default reads as null.
 * The compiler checks literal values against the schema at each use site;
 * `check()` does the same for runtime values at the component boundary.
 */
final class Prop
{
    public const TYPES = ['string', 'int', 'float', 'bool', 'list', 'map', 'shape', 'enum', 'html', 'any'];

    private bool $required = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private int|float|null $min = null;

    private int|float|null $max = null;

    /**
     * @param array<string, Prop> $fields shape fields
     * @param list<string|int> $values enum values
     */
    private function __construct(
        public readonly string $type,
        public readonly ?self $item = null,
        public readonly array $fields = [],
        public readonly array $values = [],
    ) {
    }

    public static function string(): self
    {
        return new self('string');
    }

    public static function int(): self
    {
        return new self('int');
    }

    public static function float(): self
    {
        return new self('float');
    }

    public static function bool(): self
    {
        return new self('bool');
    }

    /** A SafeHtml value (trusted HTML built by code, never a string). */
    public static function html(): self
    {
        return new self('html');
    }

    public static function any(): self
    {
        return new self('any');
    }

    public static function list(?self $item = null): self
    {
        return new self('list', $item);
    }

    /** An array with string keys; values of $value's type when given. */
    public static function map(?self $value = null): self
    {
        return new self('map', $value);
    }

    /**
     * @param array<mixed> $fields name => Prop
     */
    public static function shape(array $fields): self
    {
        $typed = [];
        foreach ($fields as $name => $field) {
            if (!\is_string($name) || !$field instanceof self) {
                throw new InvalidArgumentException('Prop::shape() takes [name => Prop, …]');
            }
            $typed[$name] = $field;
        }

        return new self('shape', null, $typed);
    }

    public static function enum(string|int ...$values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Prop::enum() needs at least one value');
        }

        return new self('enum', null, [], \array_values($values));
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = \is_string($data['type'] ?? null) && \in_array($data['type'], self::TYPES, true) ? $data['type'] : 'any';
        $item = \is_array($data['item'] ?? null) ? self::fromArray($data['item']) : null;
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $name => $field) {
            $fields[(string) $name] = self::fromArray((array) $field);
        }
        /** @var list<string|int> $values */
        $values = \array_values((array) ($data['values'] ?? []));
        $prop = new self($type, $item, $fields, $values);
        $prop->required = (bool) ($data['required'] ?? false);
        $prop->hasDefault = (bool) ($data['hasDefault'] ?? false);
        $prop->default = $data['default'] ?? null;
        $prop->min = \is_int($data['min'] ?? null) || \is_float($data['min'] ?? null) ? $data['min'] : null;
        $prop->max = \is_int($data['max'] ?? null) || \is_float($data['max'] ?? null) ? $data['max'] : null;

        return $prop;
    }

    public function required(): self
    {
        $copy = clone $this;
        $copy->required = true;

        return $copy;
    }

    public function optional(): self
    {
        $copy = clone $this;
        $copy->required = false;

        return $copy;
    }

    public function default(mixed $value): self
    {
        $error = $this->check($value, 'default');
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }
        $copy = clone $this;
        $copy->hasDefault = true;
        $copy->default = $value;

        return $copy;
    }

    /** Lower bound: the value for numbers, the length for strings, the count for lists. */
    public function min(int|float $min): self
    {
        $copy = clone $this;
        $copy->min = $min;

        return $copy;
    }

    public function max(int|float $max): self
    {
        $copy = clone $this;
        $copy->max = $max;

        return $copy;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * Check a value against this prop. Null is accepted for an optional prop.
     *
     * @return string|null the first violation, or null when the value is valid
     */
    public function check(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return $this->required ? $path . ' is required' : null;
        }
        $ok = match ($this->type) {
            'string' => \is_string($value) || $value instanceof Stringable,
            'int' => \is_int($value),
            'float' => \is_int($value) || \is_float($value),
            'bool' => \is_bool($value),
            'html' => $value instanceof SafeHtml,
            'enum' => (\is_string($value) || \is_int($value)) && \in_array($value, $this->values, true),
            'list' => \is_array($value) && \array_is_list($value),
            'map', 'shape' => \is_array($value) || \is_object($value),
            default => true,
        };
        if (!$ok) {
            return $path . ' must be ' . $this->describe() . ', got ' . \get_debug_type($value);
        }

        return $this->checkBounds($value, $path) ?? $this->checkInner($value, $path);
    }

    public function describe(): string
    {
        return match ($this->type) {
            'enum' => 'one of ' . \implode(', ', \array_map(static fn ($v): string => \var_export($v, true), $this->values)),
            'html' => 'a SafeHtml value',
            'list' => 'a list' . ($this->item ? ' of ' . $this->item->describe() : ''),
            'map', 'shape' => 'a map',
            'any' => 'any value',
            default => 'a ' . $this->type,
        };
    }

    /**
     * Plain-array form, for the compiled index (dependencies are checked
     * against a child's schema without re-reading its source).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = \array_filter([
            'type' => $this->type,
            'item' => $this->item?->toArray(),
            'fields' => \array_map(static fn (self $f): array => $f->toArray(), $this->fields),
            'values' => $this->values,
            'required' => $this->required,
            'min' => $this->min,
            'max' => $this->max,
        ], static fn ($v): bool => $v !== null && $v !== [] && $v !== false);
        if ($this->hasDefault) {
            // Kept as given: a default of false, 0, '' or [] must survive the round trip.
            $out['hasDefault'] = true;
            $out['default'] = $this->default;
        }

        return $out;
    }

    private function checkBounds(mixed $value, string $path): ?string
    {
        if ($this->min === null && $this->max === null) {
            return null;
        }
        [$measure, $unit] = match (true) {
            \is_int($value), \is_float($value) => [$value, ''],
            \is_string($value) => [(int) \preg_match_all('/./su', $value), ' characters'],
            \is_array($value) => [\count($value), ' items'],
            default => [null, ''],
        };
        if ($measure === null) {
            return null;
        }
        if ($this->min !== null && $measure < $this->min) {
            return $path . ' must be at least ' . $this->min . $unit;
        }
        if ($this->max !== null && $measure > $this->max) {
            return $path . ' must be at most ' . $this->max . $unit;
        }

        return null;
    }

    private function checkInner(mixed $value, string $path): ?string
    {
        if (($this->type === 'list' || $this->type === 'map') && $this->item !== null) {
            foreach ((array) $value as $key => $item) {
                $error = $this->item->check($item, $path . '[' . $key . ']');
                if ($error !== null) {
                    return $error;
                }
            }
        }
        if ($this->type === 'shape') {
            $data = \is_array($value) ? $value : (\is_object($value) ? \get_object_vars($value) : []);
            foreach ($this->fields as $name => $field) {
                $error = $field->check($data[$name] ?? null, $path . '.' . $name);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }
}
