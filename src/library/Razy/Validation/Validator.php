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
 * Multi-field validation orchestrator.
 *
 * Mirrors the Pipeline::execute() sequential-iteration pattern:
 * iterate registered FieldValidators, collect results, aggregate into
 * a ValidationResult error-bag.
 *
 * Supports builder-style fluent API (mirrors Pipeline::pipe() → Action::then()
 * chaining) and a static `make()` shorthand for one-shot validation.
 *
 * @package Razy\Validation
 */
class Validator
{
    /**
     * @var array<string, FieldValidator> Registered field validators
     */
    private array $fields = [];

    /**
     * @var array<string, mixed> Input data to validate
     */
    private array $data;

    /**
     * @var array<string, mixed> Default values for missing fields
     */
    private array $defaults = [];

    /**
     * @var bool Whether to stop on first field failure (bail globally)
     */
    private bool $stopOnFirstFailure = false;

    /**
     * @var list<callable(array<string, mixed>): array<string, mixed>> Before-hooks (data transforms)
     */
    private array $beforeHooks = [];

    /**
     * @var list<callable(ValidationResult): void> After-hooks
     */
    private array $afterHooks = [];

    /**
     * Create a new Validator instance.
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
     * Get a FieldValidator for the given field name. Creates one if it does
     * not exist yet. Mirrors Pipeline::pipe() returning an Action for further
     * chaining.
     *
     * @return FieldValidator
     */
    public function field(string $name): FieldValidator
    {
        if (!isset($this->fields[$name])) {
            $this->fields[$name] = new FieldValidator($name);
        }

        return $this->fields[$name];
    }

    /**
     * Register multiple fields with rules at once.
     *
     * Accepts an array of field => rules where rules is a list of
     * ValidationRuleInterface instances.
     *
     * @param array<string, list<ValidationRuleInterface>> $fieldRules
     *
     * @return $this
     */
    public function fields(array $fieldRules): static
    {
        foreach ($fieldRules as $name => $rules) {
            $this->field($name)->rules($rules);
        }

        return $this;
    }

    /**
     * Set default values for fields that may be missing from the input.
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
     * Toggle stop-on-first-field-failure (global bail).
     * Mirrors Pipeline::execute() stopping on first Action rejection.
     *
     * @return $this
     */
    public function stopOnFirstFailure(bool $stop = true): static
    {
        $this->stopOnFirstFailure = $stop;

        return $this;
    }

    /**
     * Add a before-hook to transform data before validation.
     * Mirrors Pipeline's relay/storage pre-processing.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     *
     * @return $this
     */
    public function before(callable $callback): static
    {
        $this->beforeHooks[] = $callback;

        return $this;
    }

    /**
     * Add an after-hook to run after validation completes.
     * Mirrors Pipeline's post-execution callbacks.
     *
     * @param callable(ValidationResult): void $callback
     *
     * @return $this
     */
    public function after(callable $callback): static
    {
        $this->afterHooks[] = $callback;

        return $this;
    }

    /**
     * Execute validation across all registered fields.
     *
     * Follows the Pipeline::execute() pattern: iterate sequentially,
     * collect results (errors + validated values), stop early if
     * stopOnFirstFailure is enabled.
     */
    public function validate(): ValidationResult
    {
        $data = array_merge($this->defaults, $this->data);

        // Before-hooks: transform data
        foreach ($this->beforeHooks as $hook) {
            $data = $hook($data);
        }

        $errors    = [];
        $validated = [];

        foreach ($this->fields as $name => $fieldValidator) {
            $value  = $data[$name] ?? null;
            $result = $fieldValidator->validate($value, $data);

            if (!empty($result['errors'])) {
                $errors[$name] = $result['errors'];

                if ($this->stopOnFirstFailure) {
                    break;
                }
            } else {
                $validated[$name] = $result['value'];
            }
        }

        $result = new ValidationResult(
            passed: empty($errors),
            errors: $errors,
            validated: $validated,
        );

        // After-hooks
        foreach ($this->afterHooks as $hook) {
            $hook($result);
        }

        return $result;
    }

    /**
     * One-shot static factory — create, configure, and validate in one call.
     *
     * @param array<string, mixed>                          $data  Input data
     * @param array<string, list<ValidationRuleInterface>>  $rules Field → rule instances
     */
    public static function make(array $data, array $rules): ValidationResult
    {
        return (new static($data))->fields($rules)->validate();
    }
}
