<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Validation;

/**
 * Nested/dot-notation validator — supports array/wildcard validation.
 *
 * Extends flat Validator with dot-notation field paths and wildcard `*`
 * expansion for validating arrays and deeply nested data structures.
 *
 * Usage:
 * ```php
 * $result = NestedValidator::make(
 *     [
 *         'user' => ['name' => 'Ray', 'email' => 'ray@example.com'],
 *         'items' => [
 *             ['sku' => 'A1', 'qty' => 3],
 *             ['sku' => 'B2', 'qty' => 0],
 *         ],
 *     ],
 *     [
 *         'user.name'   => [new Required()],
 *         'user.email'  => [new Required(), new Email()],
 *         'items.*.sku' => [new Required()],
 *         'items.*.qty' => [new Required(), new Numeric()],
 *     ]
 * );
 * ```
 *
 * @package Razy\Validation
 */
class NestedValidator
{
    /**
     * @var array<string, mixed> Input data to validate
     */
    private array $data;

    /**
     * @var array<string, list<ValidationRuleInterface>> Field => rules
     */
    private array $fieldRules = [];

    /**
     * @var array<string, mixed> Default values for missing fields
     */
    private array $defaults = [];

    /**
     * @var bool Whether to stop on first field failure
     */
    private bool $stopOnFirstFailure = false;

    /**
     * Create a new NestedValidator instance.
     *
     * @param array<string, mixed> $data Input data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Set input data.
     *
     * @return $this
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Register rules for a field (supports dot-notation and wildcards).
     *
     * @param string $path  Dot-notation path (e.g. 'user.name', 'items.*.sku')
     * @param list<ValidationRuleInterface> $rules
     *
     * @return $this
     */
    public function field(string $path, array $rules): static
    {
        $this->fieldRules[$path] = $rules;

        return $this;
    }

    /**
     * Register multiple fields at once.
     *
     * @param array<string, list<ValidationRuleInterface>> $fieldRules
     *
     * @return $this
     */
    public function fields(array $fieldRules): static
    {
        foreach ($fieldRules as $path => $rules) {
            $this->field($path, $rules);
        }

        return $this;
    }

    /**
     * Set default values (dot-notation not supported in defaults).
     *
     * @param array<string, mixed> $defaults
     *
     * @return $this
     */
    public function defaults(array $defaults): static
    {
        $this->defaults = array_merge($this->defaults, $defaults);

        return $this;
    }

    /**
     * Toggle stop-on-first-field-failure.
     *
     * @return $this
     */
    public function stopOnFirstFailure(bool $stop = true): static
    {
        $this->stopOnFirstFailure = $stop;

        return $this;
    }

    /**
     * Execute validation.
     *
     * Expands wildcard paths and validates each resolved field path
     * against its rule set.
     */
    public function validate(): ValidationResult
    {
        $data = array_merge($this->defaults, $this->data);

        $errors    = [];
        $validated = [];

        foreach ($this->fieldRules as $pattern => $rules) {
            // Expand wildcards
            $expandedPaths = $this->expandWildcards($pattern, $data);

            foreach ($expandedPaths as $resolvedPath) {
                $value = self::dataGet($data, $resolvedPath);
                $fieldValidator = new FieldValidator($resolvedPath);
                $fieldValidator->rules($rules);

                $result = $fieldValidator->validate($value, $data);

                if (!empty($result['errors'])) {
                    $errors[$resolvedPath] = $result['errors'];

                    if ($this->stopOnFirstFailure) {
                        break 2;
                    }
                } else {
                    $validated[$resolvedPath] = $result['value'];
                }
            }
        }

        return new ValidationResult(
            passed: empty($errors),
            errors: $errors,
            validated: $validated,
        );
    }

    /**
     * One-shot static factory.
     *
     * @param array<string, mixed>                          $data  Input data
     * @param array<string, list<ValidationRuleInterface>>  $rules Field path → rule instances
     */
    public static function make(array $data, array $rules): ValidationResult
    {
        return (new static($data))->fields($rules)->validate();
    }

    /**
     * Get a value from a nested array using dot notation.
     *
     * @param array  $data    The data array
     * @param string $path    Dot-separated path (e.g. 'user.address.city')
     * @param mixed  $default Default value if path not found
     *
     * @return mixed
     */
    public static function dataGet(array $data, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a value in a nested array using dot notation.
     *
     * @param array  $data  The data array (modified by reference)
     * @param string $path  Dot-separated path
     * @param mixed  $value The value to set
     */
    public static function dataSet(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Check if a value exists in a nested array using dot notation.
     *
     * @param array  $data The data array
     * @param string $path Dot-separated path
     *
     * @return bool
     */
    public static function dataHas(array $data, string $path): bool
    {
        $segments = explode('.', $path);
        $current = $data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * Expand a wildcard path pattern against the data.
     *
     * 'items.*.name' with data {'items': [{name: 'a'}, {name: 'b'}]}
     * → ['items.0.name', 'items.1.name']
     *
     * A path without wildcards returns itself unchanged.
     *
     * @param string $pattern The path pattern with potential wildcards
     * @param array  $data    The data array
     *
     * @return list<string> Expanded concrete paths
     */
    private function expandWildcards(string $pattern, array $data): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }

        $segments = explode('.', $pattern);

        return $this->expandSegments($segments, 0, $data, '');
    }

    /**
     * Recursively expand wildcard segments.
     *
     * @param list<string> $segments All segments of the pattern
     * @param int          $index    Current segment index
     * @param mixed        $current  Current data node
     * @param string       $prefix   Accumulated path prefix
     *
     * @return list<string>
     */
    private function expandSegments(array $segments, int $index, mixed $current, string $prefix): array
    {
        if ($index >= count($segments)) {
            return [$prefix];
        }

        $segment = $segments[$index];
        $nextPrefix = $prefix === '' ? $segment : $prefix . '.' . $segment;

        if ($segment === '*') {
            if (!is_array($current)) {
                return [];
            }

            $results = [];
            foreach (array_keys($current) as $key) {
                $expandedPrefix = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                $results = array_merge(
                    $results,
                    $this->expandSegments($segments, $index + 1, $current[$key], $expandedPrefix)
                );
            }

            return $results;
        }

        if (!is_array($current) || !array_key_exists($segment, $current)) {
            // Path doesn't exist — still return the concrete path so
            // Required rule etc. can flag it as missing
            $remaining = array_slice($segments, $index);

            return [$prefix === '' ? implode('.', $remaining) : $prefix . '.' . implode('.', $remaining)];
        }

        return $this->expandSegments($segments, $index + 1, $current[$segment], $nextPrefix);
    }
}
