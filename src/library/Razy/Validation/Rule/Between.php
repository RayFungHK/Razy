<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a numeric value is between a min and max (inclusive).
 */
class Between extends ValidationRule
{
    public function __construct(
        private readonly float|int $min,
        private readonly float|int $max,
    ) {
    }

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

        $numeric = (float) $value;

        if ($numeric < $this->min || $numeric > $this->max) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return "The :field field must be between {$this->min} and {$this->max}.";
    }
}
