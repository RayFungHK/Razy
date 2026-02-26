<?php

namespace Razy\Validation\Rule;

use Razy\Validation\FieldValidator;
use Razy\Validation\ValidationRule;
use Razy\Validation\ValidationRuleInterface;

/**
 * Validates each element of an array against a set of rules.
 *
 * ```php
 * $rule = new Each([new Required(), new Email()]);
 * $rule->validate(['a@b.com', 'c@d.com'], 'emails'); // passes
 * $rule->validate(['a@b.com', 'bad'], 'emails');      // fails
 * ```
 */
class Each extends ValidationRule
{
    /**
     * Error details per index.
     *
     * @var array<int|string, list<string>>
     */
    private array $itemErrors = [];

    /**
     * @param list<ValidationRuleInterface> $rules Rules to apply to each element
     */
    public function __construct(
        private readonly array $rules,
    ) {}

    public function validate(mixed $value, string $field, array $data = []): mixed
    {
        $this->itemErrors = [];

        if ($value === null || $value === '') {
            $this->pass();

            return $value;
        }

        if (!is_array($value)) {
            $this->fail();

            return $value;
        }

        $allPassed = true;

        foreach ($value as $index => $item) {
            $fv = new FieldValidator($field . '.' . $index);
            $fv->rules($this->rules);
            $result = $fv->validate($item, $data);

            if (!empty($result['errors'])) {
                $allPassed = false;
                $this->itemErrors[$index] = $result['errors'];
            }
        }

        if ($allPassed) {
            $this->pass();
        } else {
            $this->fail();
        }

        return $value;
    }

    /**
     * Get the per-item validation errors.
     *
     * @return array<int|string, list<string>>
     */
    public function getItemErrors(): array
    {
        return $this->itemErrors;
    }

    protected function defaultMessage(): string
    {
        return 'The :field field contains invalid items.';
    }
}
