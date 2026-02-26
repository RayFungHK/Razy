<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a field's value matches another field in the data.
 * Commonly used for password confirmation (e.g., password_confirmation).
 *
 * By default confirms against `{field}_confirmation`. A custom
 * confirmation field name can be provided.
 */
class Confirmed extends ValidationRule
{
    public function __construct(
        private readonly ?string $confirmationField = null,
    ) {}

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        $confirmField = $this->confirmationField ?? $field . '_confirmation';
        $confirmValue = $data[$confirmField] ?? null;

        if ($value !== $confirmValue) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field confirmation does not match.';
    }
}
