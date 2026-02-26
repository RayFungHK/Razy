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
 *
 * @license MIT
 */

namespace Razy\Validation;

/**
 * Immutable validation result — the error bag.
 *
 * Replaces the ad-hoc `$rejected` array in the Pipeline's FormWorker with a
 * structured, queryable result object.
 *
 * @package Razy\Validation
 */
class ValidationResult
{
    /**
     * @param bool $passed Whether all fields passed
     * @param array<string, list<string>> $errors Field → error messages
     * @param array<string, mixed> $validated Processed/sanitised values for valid fields
     */
    public function __construct(
        private readonly bool $passed,
        private readonly array $errors,
        private readonly array $validated,
    ) {
    }

    /**
     * Whether all validation rules passed.
     */
    public function passes(): bool
    {
        return $this->passed;
    }

    /**
     * Whether any validation rule failed.
     */
    public function fails(): bool
    {
        return !$this->passed;
    }

    /**
     * Get all errors grouped by field.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field.
     *
     * @return list<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get the first error for a field, or null.
     */
    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * Get all first-errors (one per field).
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $result = [];
        foreach ($this->errors as $field => $messages) {
            if (!empty($messages)) {
                $result[$field] = $messages[0];
            }
        }

        return $result;
    }

    /**
     * Whether a specific field has errors.
     */
    public function hasError(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    /**
     * Get the validated (processed) data — only fields that passed.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Get a single validated value.
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->validated[$field] ?? $default;
    }

    /**
     * Get all error messages as a flat list.
     *
     * @return list<string>
     */
    public function allErrors(): array
    {
        $all = [];
        foreach ($this->errors as $messages) {
            foreach ($messages as $msg) {
                $all[] = $msg;
            }
        }

        return $all;
    }

    /**
     * Total number of errors across all fields.
     */
    public function errorCount(): int
    {
        $count = 0;
        foreach ($this->errors as $messages) {
            $count += \count($messages);
        }

        return $count;
    }
}
