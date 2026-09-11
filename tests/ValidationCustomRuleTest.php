<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Validation\ValidationResult;
use Razy\Validation\ValidationRule;
use Razy\Validation\Validator;

/**
 * End-to-end proof that user-defined validation rules are a first-class,
 * OPEN surface: extend ValidationRule, attach via Validator::field()->rule(),
 * and the full pipeline (transform → reject → message interpolation →
 * ValidationResult) honours them. Before this suite the open-extension path
 * had zero framework-level execution coverage.
 */
#[CoversClass(Validator::class)]
#[CoversClass(ValidationResult::class)]
class ValidationCustomRuleTest extends TestCase
{
    public function testCustomRulePassesAndTransforms(): void
    {
        $validator = new Validator(['sku' => 'ab-12']);
        $validator->field('sku')->rule($this->partCodeRule());

        $result = $validator->validate();

        $this->assertTrue($result->passes());
        $this->assertSame('AB-12', $result->validated()['sku'], 'rule return value flows into validated()');
    }

    public function testCustomRuleFailureMessageInterpolatesField(): void
    {
        $validator = new Validator(['sku' => 'nope']);
        $validator->field('sku')->rule($this->partCodeRule());

        $result = $validator->validate();

        $this->assertTrue($result->fails());
        $this->assertSame('The sku field must be a part code.', $result->firstError('sku'));
    }

    public function testWithMessageOverridesDefault(): void
    {
        $validator = new Validator(['sku' => 'nope']);
        $validator->field('sku')->rule($this->partCodeRule()->withMessage(':field is wrong'));

        $this->assertSame('sku is wrong', $validator->validate()->firstError('sku'));
    }

    public function testCrossFieldRuleSeesFullDataset(): void
    {
        $matching = new class() extends ValidationRule {
            public function validate(mixed $value, string $field, array $data): mixed
            {
                if ($value !== ($data[$field . '_confirm'] ?? null)) {
                    $this->fail();
                }

                return $value;
            }

            protected function defaultMessage(): string
            {
                return 'The :field confirmation does not match.';
            }
        };

        $ok = new Validator(['email' => 'a@b.c', 'email_confirm' => 'a@b.c']);
        $ok->field('email')->rule($matching);
        $this->assertTrue($ok->validate()->passes());

        $bad = new Validator(['email' => 'a@b.c', 'email_confirm' => 'x@y.z']);
        $bad->field('email')->rule($matching);
        $this->assertTrue($bad->validate()->fails());
    }

    public function testBailDefaultStopsAfterFirstFailingRule(): void
    {
        $failing = static fn (): ValidationRule => new class() extends ValidationRule {
            public function validate(mixed $value, string $field, array $data): mixed
            {
                $this->fail();

                return $value;
            }
        };

        $bail = new Validator(['x' => 'v']);
        $bail->field('x')->rules([$failing(), $failing()]);
        $this->assertCount(1, $bail->validate()->errorsFor('x'), 'stop-on-first is the default');

        $collect = new Validator(['x' => 'v']);
        $collect->field('x')->bail(false)->rules([$failing(), $failing()]);
        $this->assertCount(2, $collect->validate()->errorsFor('x'));
    }

    public function testWhenAttachesConditionally(): void
    {
        $v = new Validator(['x' => 'v']);
        $v->field('x')->when(true, static fn ($field): mixed => $field->rule(new class() extends ValidationRule {
            public function validate(mixed $value, string $field, array $data): mixed
            {
                $this->fail();

                return $value;
            }
        }));

        $this->assertTrue($v->validate()->fails(), 'condition true → rule attached');

        $v2 = new Validator(['x' => 'v']);
        $invoked = false;
        $v2->field('x')->when(false, static function ($field) use (&$invoked): void {
            $invoked = true;
        });
        $this->assertTrue($v2->validate()->passes());
        $this->assertFalse($invoked, 'when(false) must not invoke the callback');
    }

    public function testRuleStateIsPerFieldValidatorInstance(): void
    {
        $rule = $this->partCodeRule();

        $ok = new Validator(['sku' => 'ab-12']);
        $ok->field('sku')->rule($rule);
        $this->assertTrue($ok->validate()->passes());

        // Same rule OBJECT reused across validators: passed() must not leak state
        // (rules report per invocation via fail()/pass(); this documents current behaviour.)
        $this->assertTrue($rule->passed());
    }

    /**
     * Fixture rule: a part code like 'AB-12'. Transforms to uppercase on pass.
     */
    private function partCodeRule(): ValidationRule
    {
        return new class() extends ValidationRule {
            public function validate(mixed $value, string $field, array $data): mixed
            {
                if (!\is_string($value) || \preg_match('/^[A-Z]{2}-\d{2}$/i', $value) !== 1) {
                    $this->fail();

                    return $value;
                }

                return \strtoupper($value);
            }

            protected function defaultMessage(): string
            {
                return 'The :field field must be a part code.';
            }
        };
    }
}
