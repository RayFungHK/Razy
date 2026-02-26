<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a string value meets a minimum length.
 */
class MinLength extends ValidationRule
{
    public function __construct(
        private readonly int $min,
    ) {
    }

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (\mb_strlen((string) $value) < $this->min) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return "The :field field must be at least {$this->min} characters.";
    }
}
