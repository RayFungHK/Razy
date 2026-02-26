<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is a valid URL.
 */
class Url extends ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field must be a valid URL.';
    }
}
