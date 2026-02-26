<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is a valid date string.
 * Optionally checks against a specific date format.
 */
class Date extends ValidationRule
{
    public function __construct(
        private readonly ?string $format = null,
    ) {}

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        $strValue = (string) $value;

        if ($this->format !== null) {
            $parsed = \DateTimeImmutable::createFromFormat($this->format, $strValue);

            if ($parsed === false || $parsed->format($this->format) !== $strValue) {
                $this->fail();

                return $value;
            }
        } else {
            try {
                new \DateTimeImmutable($strValue);
            } catch (\Exception) {
                $this->fail();

                return $value;
            }
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        if ($this->format !== null) {
            return "The :field field must be a valid date in the format {$this->format}.";
        }

        return 'The :field field must be a valid date.';
    }
}
