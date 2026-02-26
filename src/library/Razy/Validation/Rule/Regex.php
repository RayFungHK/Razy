<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value matches a regular expression.
 */
class Regex extends ValidationRule
{
    public function __construct(
        private readonly string $pattern,
    ) {}

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (!preg_match($this->pattern, (string) $value)) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field format is invalid.';
    }
}
