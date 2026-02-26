<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is an array.
 *
 * ```php
 * $rule = new IsArray();
 * $rule->validate(['a', 'b'], 'tags'); // passes
 * $rule->validate('string', 'tags');   // fails
 * ```
 */
class IsArray extends ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (!\is_array($value)) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field must be an array.';
    }
}
