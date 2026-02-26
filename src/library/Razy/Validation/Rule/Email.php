<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is a valid email address.
 */
class Email extends ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            // Skip empty — use Required for presence checks
            $this->pass();

            return $value;
        }

        if (\filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field must be a valid email address.';
    }
}
