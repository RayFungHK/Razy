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
 * Abstract base for validation rules.
 *
 * Provides default `message()` implementation with placeholder substitution.
 * Subclasses only need to implement `validate()` and optionally override `message()`.
 *
 * Follows the Pipeline Action pattern: a rule is a small, composable unit of work
 * that processes a value and signals failure via external rejection (FieldValidator).
 *
 * @package Razy\Validation
 */
abstract class ValidationRule implements ValidationRuleInterface
{
    /**
     * Whether the last validation check passed.
     */
    protected bool $passed = true;

    /**
     * Custom error message override (set via `withMessage()`).
     */
    private ?string $customMessage = null;

    /**
     * Set a custom error message that overrides the default.
     *
     * @return $this
     */
    public function withMessage(string $message): static
    {
        $this->customMessage = $message;

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Substitutes `:field` placeholder with the actual field name.
     * Subclasses should override `defaultMessage()` for their error text.
     */
    public function message(string $field): string
    {
        $msg = $this->customMessage ?? $this->defaultMessage();

        return \str_replace(':field', $field, $msg);
    }

    /**
     * Whether the last validation passed.
     */
    public function passed(): bool
    {
        return $this->passed;
    }

    /**
     * Mark the rule as failed.
     */
    protected function fail(): void
    {
        $this->passed = false;
    }

    /**
     * Mark the rule as passed.
     */
    protected function pass(): void
    {
        $this->passed = true;
    }

    /**
     * Default error message. Override in subclasses.
     */
    protected function defaultMessage(): string
    {
        return 'The :field field is invalid.';
    }
}
