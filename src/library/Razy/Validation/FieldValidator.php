<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Validation;

/**
 * Per-field rule pipeline.
 *
 * Mirrors the Pipeline's Validate plugin: holds an ordered list of rules and
 * processes them sequentially against a field value, stopping on first failure.
 *
 * ```php
 * $fieldValidator = new FieldValidator('email');
 * $fieldValidator->rule(new Required())->rule(new Email());
 * $errors = $fieldValidator->validate('bad', $allData);
 * ```
 *
 * Reuses the same stop-on-reject pattern as `Validate::process()`:
 * ```php
 * foreach ($this->children as $action) {
 *     if ($this->owner->hasRejected($this->name)) break;
 *     $value = $action->process($value, ...);
 * }
 * ```
 */
class FieldValidator
{
    /**
     * Ordered rule chain for this field.
     *
     * @var list<ValidationRuleInterface>
     */
    private array $rules = [];

    /**
     * Whether to stop on the first failing rule (default: true).
     * Mirrors Validate plugin's break-on-reject behaviour.
     */
    private bool $bail = true;

    /**
     * @param string $field The field name this validator targets
     */
    public function __construct(
        private readonly string $field,
    ) {
    }

    /**
     * Append a rule to the chain.
     *
     * Follows Pipeline Action's `then()` chaining pattern.
     *
     * @return $this
     */
    public function rule(ValidationRuleInterface $rule): static
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * Append multiple rules at once.
     *
     * @param list<ValidationRuleInterface> $rules
     *
     * @return $this
     */
    public function rules(array $rules): static
    {
        foreach ($rules as $r) {
            $this->rule($r);
        }

        return $this;
    }

    /**
     * Conditionally append a rule (mirrors Action::when()).
     *
     * @param bool $condition If true, the callback is invoked
     * @param callable $callback fn(FieldValidator): void
     *
     * @return $this
     */
    public function when(bool $condition, callable $callback): static
    {
        if ($condition) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Set whether to stop on the first failure.
     *
     * @return $this
     */
    public function bail(bool $stop = true): static
    {
        $this->bail = $stop;

        return $this;
    }

    /**
     * Run all rules against the field value.
     *
     * Follows Pipeline Validate::process() — sequential, stop on reject.
     *
     * @param mixed $value The raw field value
     * @param array $data The full dataset (for cross-field rules)
     *
     * @return array{value: mixed, errors: list<string>} Processed value and error messages
     */
    public function validate(mixed $value, array $data = []): array
    {
        $errors = [];

        foreach ($this->rules as $rule) {
            $value = $rule->validate($value, $this->field, $data);

            if (!$rule->passed()) {
                $errors[] = $rule->message($this->field);

                if ($this->bail) {
                    break;
                }
            }
        }

        return ['value' => $value, 'errors' => $errors];
    }

    /**
     * Get the field name.
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get all registered rules.
     *
     * @return list<ValidationRuleInterface>
     */
    public function getRules(): array
    {
        return $this->rules;
    }
}
