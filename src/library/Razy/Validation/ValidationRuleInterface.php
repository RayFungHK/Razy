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
 * Contract for a single validation rule.
 *
 * Mirrors the Pipeline Action's `process($value)` pattern: each rule receives
 * the current value, validates it, and returns the (possibly transformed) value.
 * If validation fails, the rule must call `$field->reject()` to register an error.
 *
 * Rules are composable units — they attach to a FieldValidator just like Pipeline
 * sub-actions attach to a Validate action.
 *
 * @package Razy\Validation
 */
interface ValidationRuleInterface
{
    /**
     * Validate and optionally transform a field value.
     *
     * @param mixed $value The current field value
     * @param string $field The field name being validated
     * @param array $data The full dataset (for cross-field rules like `confirmed`)
     *
     * @return mixed The processed value (may be transformed)
     */
    public function validate(mixed $value, string $field, array $data): mixed;

    /**
     * Get the error message when this rule fails.
     *
     * @param string $field The field name (for interpolation in messages)
     *
     * @return string Human-readable error message
     */
    public function message(string $field): string;
}
