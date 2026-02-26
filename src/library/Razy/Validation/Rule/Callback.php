<?php

namespace Razy\Validation\Rule;

use Closure;
use Razy\Validation\ValidationRule;

/**
 * Validates using a user-supplied callback.
 * Mirrors the Custom pipeline plugin.
 *
 * The callback receives (value, field, data) and must return:
 *   - true  → pass
 *   - false → fail
 *   - mixed → pass, with the returned value used as the transformed value
 */
class Callback extends ValidationRule
{
    /** @var Closure(mixed, string, array): (bool|mixed) */
    private Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        $result = ($this->callback)($value, $field, $data);

        if ($result === false) {
            $this->fail();

            return $value;
        }

        $this->pass();

        // true → keep original value; anything else → transformed value
        return $result === true ? $value : $result;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field is invalid.';
    }
}
