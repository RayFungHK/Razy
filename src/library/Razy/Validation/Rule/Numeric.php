<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is numeric.
 */
class Numeric extends ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (!\is_numeric($value)) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field must be a number.';
    }
}
