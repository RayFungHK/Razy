<?php

namespace Razy\Validation\Rule;

use Razy\Validation\ValidationRule;

/**
 * Validates that a value is within a list of allowed values.
 */
class In extends ValidationRule
{
    /**
     * @var list<mixed>
     */
    private array $allowed;

    /**
     * @var bool Whether comparison is strict (===)
     */
    private bool $strict;

    public function __construct(array $allowed, bool $strict = false)
    {
        $this->allowed = $allowed;
        $this->strict  = $strict;
    }

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (!in_array($value, $this->allowed, $this->strict)) {
            $this->fail();

            return $value;
        }

        $this->pass();

        return $value;
    }

    protected function defaultMessage(): string
    {
        $list = implode(', ', array_map('strval', $this->allowed));

        return "The :field field must be one of: {$list}.";
    }
}
