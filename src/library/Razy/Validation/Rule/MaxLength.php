<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a string value does not exceed a maximum length.
 */
class MaxLength extends ValidationRule
{
    public function __construct(
        private readonly int $max,
    ) {}

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (mb_strlen((string) $value) > $this->max) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return "The :field field must not exceed {$this->max} characters.";
    }
}
