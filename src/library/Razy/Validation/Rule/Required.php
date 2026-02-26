<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Requires a value to be non-null and non-empty.
 * Mirrors the NoEmpty pipeline plugin.
 */
class Required extends ValidationRule
{
    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            $this->fail();

            return $value;
        }

        if (\is_string($value)) {
            $value = \trim($value);
            if ($value === '') {
                $this->fail();

                return $value;
            }
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field is required.';
    }
}
