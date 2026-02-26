<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Validation\FieldValidator;
use Razy\Validation\ValidationResult;
use Razy\Validation\ValidationRule;
use Razy\Validation\ValidationRuleInterface;
use Razy\Validation\Validator;
use Razy\Validation\Rule\Between;
use Razy\Validation\Rule\Callback;
use Razy\Validation\Rule\Confirmed;
use Razy\Validation\Rule\Date;
use Razy\Validation\Rule\Email;
use Razy\Validation\Rule\In;
use Razy\Validation\Rule\MaxLength;
use Razy\Validation\Rule\MinLength;
use Razy\Validation\Rule\Numeric;
use Razy\Validation\Rule\Regex;
use Razy\Validation\Rule\Required;
use Razy\Validation\Rule\Url;

/**
 * Tests for P8: Validation System.
 *
 * Covers core classes (Validator, FieldValidator, ValidationResult,
 * ValidationRule) and all built-in rules (Required, Email, MinLength,
 * MaxLength, Numeric, In, Regex, Callback, Between, Confirmed, Url, Date).
 */
#[CoversClass(Validator::class)]
#[CoversClass(FieldValidator::class)]
#[CoversClass(ValidationResult::class)]
#[CoversClass(ValidationRule::class)]
#[CoversClass(Required::class)]
#[CoversClass(Email::class)]
#[CoversClass(MinLength::class)]
#[CoversClass(MaxLength::class)]
#[CoversClass(Numeric::class)]
#[CoversClass(In::class)]
#[CoversClass(Regex::class)]
#[CoversClass(Callback::class)]
#[CoversClass(Between::class)]
#[CoversClass(Confirmed::class)]
#[CoversClass(Url::class)]
#[CoversClass(Date::class)]
class ValidationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Section 1: ValidationResult (Error Bag)
    // ═══════════════════════════════════════════════════════════════

    public function testResultPassesWhenNoErrors(): void
    {
        $result = new ValidationResult(true, [], ['name' => 'Ray']);
        $this->assertTrue($result->passes());
        $this->assertFalse($result->fails());
    }

    public function testResultFailsWhenHasErrors(): void
    {
        $result = new ValidationResult(false, ['name' => ['Name is required.']], []);
        $this->assertFalse($result->passes());
        $this->assertTrue($result->fails());
    }

    public function testResultErrorsReturnsAllErrors(): void
    {
        $errors = ['name' => ['Required.'], 'email' => ['Invalid.', 'Too short.']];
        $result = new ValidationResult(false, $errors, []);
        $this->assertSame($errors, $result->errors());
    }

    public function testResultErrorsForField(): void
    {
        $result = new ValidationResult(false, ['email' => ['Invalid.', 'Too short.']], []);
        $this->assertSame(['Invalid.', 'Too short.'], $result->errorsFor('email'));
        $this->assertSame([], $result->errorsFor('name'));
    }

    public function testResultFirstError(): void
    {
        $result = new ValidationResult(false, ['email' => ['First.', 'Second.']], []);
        $this->assertSame('First.', $result->firstError('email'));
        $this->assertNull($result->firstError('name'));
    }

    public function testResultFirstErrors(): void
    {
        $errors = ['name' => ['A.', 'B.'], 'email' => ['C.']];
        $result = new ValidationResult(false, $errors, []);
        $this->assertSame(['name' => 'A.', 'email' => 'C.'], $result->firstErrors());
    }

    public function testResultHasError(): void
    {
        $result = new ValidationResult(false, ['name' => ['Required.']], []);
        $this->assertTrue($result->hasError('name'));
        $this->assertFalse($result->hasError('email'));
    }

    public function testResultValidated(): void
    {
        $result = new ValidationResult(true, [], ['name' => 'Ray', 'age' => 30]);
        $this->assertSame(['name' => 'Ray', 'age' => 30], $result->validated());
    }

    public function testResultGet(): void
    {
        $result = new ValidationResult(true, [], ['name' => 'Ray']);
        $this->assertSame('Ray', $result->get('name'));
        $this->assertNull($result->get('missing'));
        $this->assertSame('fallback', $result->get('missing', 'fallback'));
    }

    public function testResultAllErrors(): void
    {
        $result = new ValidationResult(false, ['a' => ['E1.'], 'b' => ['E2.', 'E3.']], []);
        $this->assertSame(['E1.', 'E2.', 'E3.'], $result->allErrors());
    }

    public function testResultErrorCount(): void
    {
        $result = new ValidationResult(false, ['a' => ['E1.'], 'b' => ['E2.', 'E3.']], []);
        $this->assertSame(3, $result->errorCount());
    }

    public function testResultEmptyErrorCount(): void
    {
        $result = new ValidationResult(true, [], ['x' => 1]);
        $this->assertSame(0, $result->errorCount());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 2: Required Rule (mirrors NoEmpty plugin)
    // ═══════════════════════════════════════════════════════════════

    public function testRequiredFailsOnNull(): void
    {
        $rule = new Required();
        $rule->validate(null, 'name');
        $this->assertFalse($rule->passed());
    }

    public function testRequiredFailsOnEmptyString(): void
    {
        $rule = new Required();
        $rule->validate('', 'name');
        $this->assertFalse($rule->passed());
    }

    public function testRequiredFailsOnWhitespaceOnly(): void
    {
        $rule = new Required();
        $rule->validate('   ', 'name');
        $this->assertFalse($rule->passed());
    }

    public function testRequiredFailsOnEmptyArray(): void
    {
        $rule = new Required();
        $rule->validate([], 'tags');
        $this->assertFalse($rule->passed());
    }

    public function testRequiredPassesOnNonEmptyString(): void
    {
        $rule = new Required();
        $rule->validate('hello', 'name');
        $this->assertTrue($rule->passed());
    }

    public function testRequiredPassesOnZero(): void
    {
        $rule = new Required();
        $rule->validate(0, 'count');
        $this->assertTrue($rule->passed());
    }

    public function testRequiredPassesOnFalse(): void
    {
        $rule = new Required();
        $rule->validate(false, 'active');
        $this->assertTrue($rule->passed());
    }

    public function testRequiredTrimsStringValue(): void
    {
        $rule = new Required();
        $result = $rule->validate('  hello  ', 'name');
        $this->assertTrue($rule->passed());
        $this->assertSame('hello', $result);
    }

    public function testRequiredDefaultMessage(): void
    {
        $rule = new Required();
        $rule->validate(null, 'name');
        $this->assertSame('The name field is required.', $rule->message('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 3: Email Rule
    // ═══════════════════════════════════════════════════════════════

    public function testEmailPassesValidEmails(): void
    {
        $rule = new Email();
        $rule->validate('user@example.com', 'email');
        $this->assertTrue($rule->passed());
    }

    public function testEmailFailsInvalidEmail(): void
    {
        $rule = new Email();
        $rule->validate('not-an-email', 'email');
        $this->assertFalse($rule->passed());
    }

    public function testEmailFailsEmailWithoutDomain(): void
    {
        $rule = new Email();
        $rule->validate('user@', 'email');
        $this->assertFalse($rule->passed());
    }

    public function testEmailSkipsNull(): void
    {
        $rule = new Email();
        $rule->validate(null, 'email');
        $this->assertTrue($rule->passed());
    }

    public function testEmailSkipsEmptyString(): void
    {
        $rule = new Email();
        $rule->validate('', 'email');
        $this->assertTrue($rule->passed());
    }

    public function testEmailDefaultMessage(): void
    {
        $rule = new Email();
        $rule->validate('bad', 'email');
        $this->assertStringContainsString('email', $rule->message('email'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 4: MinLength Rule
    // ═══════════════════════════════════════════════════════════════

    public function testMinLengthPassesWhenMet(): void
    {
        $rule = new MinLength(3);
        $rule->validate('abc', 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMinLengthPassesWhenExceeded(): void
    {
        $rule = new MinLength(3);
        $rule->validate('abcdef', 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMinLengthFailsWhenTooShort(): void
    {
        $rule = new MinLength(3);
        $rule->validate('ab', 'name');
        $this->assertFalse($rule->passed());
    }

    public function testMinLengthSkipsNull(): void
    {
        $rule = new MinLength(3);
        $rule->validate(null, 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMinLengthHandlesMultibyte(): void
    {
        $rule = new MinLength(3);
        $rule->validate('日本語', 'name'); // 3 chars
        $this->assertTrue($rule->passed());
    }

    public function testMinLengthDefaultMessage(): void
    {
        $rule = new MinLength(5);
        $rule->validate('ab', 'name');
        $msg = $rule->message('name');
        $this->assertStringContainsString('5', $msg);
        $this->assertStringContainsString('name', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 5: MaxLength Rule
    // ═══════════════════════════════════════════════════════════════

    public function testMaxLengthPassesWhenWithinLimit(): void
    {
        $rule = new MaxLength(5);
        $rule->validate('abc', 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMaxLengthPassesAtExactLimit(): void
    {
        $rule = new MaxLength(5);
        $rule->validate('abcde', 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMaxLengthFailsWhenExceeded(): void
    {
        $rule = new MaxLength(5);
        $rule->validate('abcdef', 'name');
        $this->assertFalse($rule->passed());
    }

    public function testMaxLengthSkipsNull(): void
    {
        $rule = new MaxLength(5);
        $rule->validate(null, 'name');
        $this->assertTrue($rule->passed());
    }

    public function testMaxLengthHandlesMultibyte(): void
    {
        $rule = new MaxLength(2);
        $rule->validate('日本語', 'name'); // 3 chars — exceeds
        $this->assertFalse($rule->passed());
    }

    public function testMaxLengthDefaultMessage(): void
    {
        $rule = new MaxLength(10);
        $rule->validate('this is a very long string', 'title');
        $msg = $rule->message('title');
        $this->assertStringContainsString('10', $msg);
        $this->assertStringContainsString('title', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 6: Numeric Rule
    // ═══════════════════════════════════════════════════════════════

    public function testNumericPassesInteger(): void
    {
        $rule = new Numeric();
        $rule->validate(42, 'age');
        $this->assertTrue($rule->passed());
    }

    public function testNumericPassesFloat(): void
    {
        $rule = new Numeric();
        $rule->validate(3.14, 'price');
        $this->assertTrue($rule->passed());
    }

    public function testNumericPassesNumericString(): void
    {
        $rule = new Numeric();
        $rule->validate('100', 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testNumericPassesNegativeNumber(): void
    {
        $rule = new Numeric();
        $rule->validate('-5', 'temp');
        $this->assertTrue($rule->passed());
    }

    public function testNumericFailsNonNumericString(): void
    {
        $rule = new Numeric();
        $rule->validate('abc', 'age');
        $this->assertFalse($rule->passed());
    }

    public function testNumericSkipsNull(): void
    {
        $rule = new Numeric();
        $rule->validate(null, 'age');
        $this->assertTrue($rule->passed());
    }

    public function testNumericDefaultMessage(): void
    {
        $rule = new Numeric();
        $rule->validate('x', 'age');
        $this->assertStringContainsString('number', $rule->message('age'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 7: In Rule
    // ═══════════════════════════════════════════════════════════════

    public function testInPassesWhenInList(): void
    {
        $rule = new In(['admin', 'editor', 'viewer']);
        $rule->validate('editor', 'role');
        $this->assertTrue($rule->passed());
    }

    public function testInFailsWhenNotInList(): void
    {
        $rule = new In(['admin', 'editor']);
        $rule->validate('hacker', 'role');
        $this->assertFalse($rule->passed());
    }

    public function testInSkipsNull(): void
    {
        $rule = new In(['a', 'b']);
        $rule->validate(null, 'x');
        $this->assertTrue($rule->passed());
    }

    public function testInStrictMode(): void
    {
        $rule = new In([1, 2, 3], strict: true);
        $rule->validate('1', 'id'); // string '1' != int 1 in strict
        $this->assertFalse($rule->passed());
    }

    public function testInStrictModePassesExactType(): void
    {
        $rule = new In([1, 2, 3], strict: true);
        $rule->validate(2, 'id');
        $this->assertTrue($rule->passed());
    }

    public function testInDefaultMessage(): void
    {
        $rule = new In(['a', 'b', 'c']);
        $rule->validate('z', 'field');
        $msg = $rule->message('field');
        $this->assertStringContainsString('a, b, c', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 8: Regex Rule
    // ═══════════════════════════════════════════════════════════════

    public function testRegexPassesMatchingPattern(): void
    {
        $rule = new Regex('/^[A-Z]{3}$/');
        $rule->validate('ABC', 'code');
        $this->assertTrue($rule->passed());
    }

    public function testRegexFailsNonMatchingPattern(): void
    {
        $rule = new Regex('/^[A-Z]{3}$/');
        $rule->validate('abc', 'code');
        $this->assertFalse($rule->passed());
    }

    public function testRegexSkipsNull(): void
    {
        $rule = new Regex('/\d+/');
        $rule->validate(null, 'num');
        $this->assertTrue($rule->passed());
    }

    public function testRegexDefaultMessage(): void
    {
        $rule = new Regex('/^x$/');
        $rule->validate('y', 'code');
        $this->assertStringContainsString('format', $rule->message('code'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 9: Callback Rule (mirrors Custom plugin)
    // ═══════════════════════════════════════════════════════════════

    public function testCallbackPassesWhenReturnsTrue(): void
    {
        $rule = new Callback(fn($v) => $v === 'yes');
        $result = $rule->validate('yes', 'agree');
        $this->assertTrue($rule->passed());
        $this->assertSame('yes', $result);
    }

    public function testCallbackFailsWhenReturnsFalse(): void
    {
        $rule = new Callback(fn($v) => $v === 'yes');
        $rule->validate('no', 'agree');
        $this->assertFalse($rule->passed());
    }

    public function testCallbackTransformsValue(): void
    {
        $rule = new Callback(fn($v) => strtoupper($v));
        $result = $rule->validate('hello', 'name');
        $this->assertTrue($rule->passed());
        $this->assertSame('HELLO', $result);
    }

    public function testCallbackReceivesFieldAndData(): void
    {
        $receivedField = null;
        $receivedData  = null;
        $rule = new Callback(function ($v, $field, $data) use (&$receivedField, &$receivedData) {
            $receivedField = $field;
            $receivedData  = $data;
            return true;
        });
        $rule->validate('x', 'myfield', ['myfield' => 'x', 'other' => 'y']);
        $this->assertSame('myfield', $receivedField);
        $this->assertSame(['myfield' => 'x', 'other' => 'y'], $receivedData);
    }

    public function testCallbackDefaultMessage(): void
    {
        $rule = new Callback(fn() => false);
        $rule->validate('x', 'f');
        $this->assertStringContainsString('invalid', $rule->message('f'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 10: Between Rule
    // ═══════════════════════════════════════════════════════════════

    public function testBetweenPassesWithinRange(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(5, 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenPassesAtMin(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(1, 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenPassesAtMax(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(10, 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenFailsBelowMin(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(0, 'qty');
        $this->assertFalse($rule->passed());
    }

    public function testBetweenFailsAboveMax(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(11, 'qty');
        $this->assertFalse($rule->passed());
    }

    public function testBetweenFailsNonNumeric(): void
    {
        $rule = new Between(1, 10);
        $rule->validate('abc', 'qty');
        $this->assertFalse($rule->passed());
    }

    public function testBetweenSkipsNull(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(null, 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenWithFloats(): void
    {
        $rule = new Between(1.5, 3.5);
        $rule->validate(2.5, 'price');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenDefaultMessage(): void
    {
        $rule = new Between(1, 10);
        $rule->validate(99, 'qty');
        $msg = $rule->message('qty');
        $this->assertStringContainsString('1', $msg);
        $this->assertStringContainsString('10', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 11: Confirmed Rule
    // ═══════════════════════════════════════════════════════════════

    public function testConfirmedPassesWhenMatches(): void
    {
        $rule = new Confirmed();
        $data = ['password' => 'secret', 'password_confirmation' => 'secret'];
        $rule->validate('secret', 'password', $data);
        $this->assertTrue($rule->passed());
    }

    public function testConfirmedFailsWhenMismatch(): void
    {
        $rule = new Confirmed();
        $data = ['password' => 'secret', 'password_confirmation' => 'wrong'];
        $rule->validate('secret', 'password', $data);
        $this->assertFalse($rule->passed());
    }

    public function testConfirmedFailsWhenConfirmationMissing(): void
    {
        $rule = new Confirmed();
        $data = ['password' => 'secret'];
        $rule->validate('secret', 'password', $data);
        $this->assertFalse($rule->passed());
    }

    public function testConfirmedCustomFieldName(): void
    {
        $rule = new Confirmed('repeat_email');
        $data = ['email' => 'a@b.com', 'repeat_email' => 'a@b.com'];
        $rule->validate('a@b.com', 'email', $data);
        $this->assertTrue($rule->passed());
    }

    public function testConfirmedDefaultMessage(): void
    {
        $rule = new Confirmed();
        $rule->validate('a', 'password', []);
        $this->assertStringContainsString('confirmation', $rule->message('password'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 12: Url Rule
    // ═══════════════════════════════════════════════════════════════

    public function testUrlPassesValidUrls(): void
    {
        $rule = new Url();
        $rule->validate('https://example.com', 'website');
        $this->assertTrue($rule->passed());
    }

    public function testUrlPassesUrlWithPath(): void
    {
        $rule = new Url();
        $rule->validate('https://example.com/path?q=1', 'website');
        $this->assertTrue($rule->passed());
    }

    public function testUrlFailsInvalidUrl(): void
    {
        $rule = new Url();
        $rule->validate('not-a-url', 'website');
        $this->assertFalse($rule->passed());
    }

    public function testUrlSkipsNull(): void
    {
        $rule = new Url();
        $rule->validate(null, 'website');
        $this->assertTrue($rule->passed());
    }

    public function testUrlDefaultMessage(): void
    {
        $rule = new Url();
        $rule->validate('bad', 'website');
        $this->assertStringContainsString('URL', $rule->message('website'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 13: Date Rule
    // ═══════════════════════════════════════════════════════════════

    public function testDatePassesValidDateString(): void
    {
        $rule = new Date();
        $rule->validate('2024-01-15', 'start_date');
        $this->assertTrue($rule->passed());
    }

    public function testDatePassesUnixTimestampLikeString(): void
    {
        $rule = new Date();
        $rule->validate('next Monday', 'start_date');
        $this->assertTrue($rule->passed());
    }

    public function testDateFailsInvalidDateString(): void
    {
        $rule = new Date();
        $rule->validate('not-a-date', 'start_date');
        $this->assertFalse($rule->passed());
    }

    public function testDateWithFormatPasses(): void
    {
        $rule = new Date('Y-m-d');
        $rule->validate('2024-01-15', 'start_date');
        $this->assertTrue($rule->passed());
    }

    public function testDateWithFormatFailsWrongFormat(): void
    {
        $rule = new Date('Y-m-d');
        $rule->validate('15/01/2024', 'start_date');
        $this->assertFalse($rule->passed());
    }

    public function testDateWithFormatRejectsInvalidDate(): void
    {
        $rule = new Date('Y-m-d');
        $rule->validate('2024-13-45', 'start_date'); // month 13 invalid
        $this->assertFalse($rule->passed());
    }

    public function testDateSkipsNull(): void
    {
        $rule = new Date();
        $rule->validate(null, 'start_date');
        $this->assertTrue($rule->passed());
    }

    public function testDateDefaultMessageWithoutFormat(): void
    {
        $rule = new Date();
        $rule->validate('xyz', 'd');
        $this->assertStringContainsString('valid date', $rule->message('d'));
    }

    public function testDateDefaultMessageWithFormat(): void
    {
        $rule = new Date('Y-m-d');
        $rule->validate('x', 'd');
        $msg = $rule->message('d');
        $this->assertStringContainsString('Y-m-d', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 14: ValidationRule Base – withMessage / message
    // ═══════════════════════════════════════════════════════════════

    public function testWithMessageOverridesDefault(): void
    {
        $rule = (new Required())->withMessage('Provide your :field now!');
        $rule->validate(null, 'name');
        $this->assertSame('Provide your name now!', $rule->message('name'));
    }

    public function testWithMessageIsFluentAndReturnsStatic(): void
    {
        $rule = new Required();
        $result = $rule->withMessage('custom');
        $this->assertSame($rule, $result);
    }

    public function testMessageSubstitutesFieldPlaceholder(): void
    {
        $rule = new Email();
        $rule->validate('bad', 'contact_email');
        $msg = $rule->message('contact_email');
        $this->assertStringContainsString('contact_email', $msg);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 15: FieldValidator
    // ═══════════════════════════════════════════════════════════════

    public function testFieldValidatorGetField(): void
    {
        $fv = new FieldValidator('username');
        $this->assertSame('username', $fv->getField());
    }

    public function testFieldValidatorNoRulesPassesAnything(): void
    {
        $fv     = new FieldValidator('x');
        $result = $fv->validate('any');
        $this->assertSame('any', $result['value']);
        $this->assertSame([], $result['errors']);
    }

    public function testFieldValidatorSingleRulePass(): void
    {
        $fv = new FieldValidator('name');
        $fv->rule(new Required());
        $result = $fv->validate('Ray');
        $this->assertEmpty($result['errors']);
    }

    public function testFieldValidatorSingleRuleFail(): void
    {
        $fv = new FieldValidator('name');
        $fv->rule(new Required());
        $result = $fv->validate('');
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('required', $result['errors'][0]);
    }

    public function testFieldValidatorChainedRules(): void
    {
        $fv = new FieldValidator('email');
        $fv->rule(new Required())->rule(new Email());
        $result = $fv->validate('test@example.com');
        $this->assertEmpty($result['errors']);
    }

    public function testFieldValidatorBailStopsOnFirstFailure(): void
    {
        $fv = new FieldValidator('email');
        $fv->bail(true)->rule(new Required())->rule(new Email());
        $result = $fv->validate('');
        // Only Required fails; Email never runs
        $this->assertCount(1, $result['errors']);
    }

    public function testFieldValidatorNoBailCollectsAllErrors(): void
    {
        $fv = new FieldValidator('email');
        $fv->bail(false)
           ->rule(new Required())
           ->rule((new Callback(fn() => false))->withMessage('Extra check failed.'));
        $result = $fv->validate('');
        // Both rules fail (Required on empty, Callback always fails)
        $this->assertCount(2, $result['errors']);
    }

    public function testFieldValidatorRulesMethodBulkAdd(): void
    {
        $fv = new FieldValidator('name');
        $fv->rules([new Required(), new MinLength(3)]);
        $this->assertCount(2, $fv->getRules());
    }

    public function testFieldValidatorWhenTrueAppliesCallback(): void
    {
        $fv = new FieldValidator('name');
        $fv->when(true, fn(FieldValidator $f) => $f->rule(new Required()));
        $this->assertCount(1, $fv->getRules());
    }

    public function testFieldValidatorWhenFalseSkipsCallback(): void
    {
        $fv = new FieldValidator('name');
        $fv->when(false, fn(FieldValidator $f) => $f->rule(new Required()));
        $this->assertCount(0, $fv->getRules());
    }

    public function testFieldValidatorValueTransformation(): void
    {
        $fv = new FieldValidator('name');
        $fv->rule(new Required()); // Required trims strings
        $result = $fv->validate('  hello  ');
        $this->assertSame('hello', $result['value']);
    }

    public function testFieldValidatorCrossFieldData(): void
    {
        $fv = new FieldValidator('password');
        $fv->rule(new Confirmed());
        $data = ['password' => 'secret', 'password_confirmation' => 'secret'];
        $result = $fv->validate('secret', $data);
        $this->assertEmpty($result['errors']);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 16: Validator (Multi-field Orchestrator)
    // ═══════════════════════════════════════════════════════════════

    public function testValidatorPassesCleanData(): void
    {
        $v = new Validator(['name' => 'Ray', 'email' => 'r@e.com']);
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Required())->rule(new Email());
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame('Ray', $result->get('name'));
        $this->assertSame('r@e.com', $result->get('email'));
    }

    public function testValidatorFailsInvalidData(): void
    {
        $v = new Validator(['name' => '', 'email' => 'bad']);
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Email());
        $result = $v->validate();
        $this->assertTrue($result->fails());
        $this->assertTrue($result->hasError('name'));
        $this->assertTrue($result->hasError('email'));
    }

    public function testValidatorMixedPassAndFail(): void
    {
        $v = new Validator(['name' => 'Ray', 'email' => 'bad']);
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Email());
        $result = $v->validate();
        $this->assertTrue($result->fails());
        $this->assertFalse($result->hasError('name'));
        $this->assertTrue($result->hasError('email'));
        $this->assertSame('Ray', $result->get('name'));
    }

    public function testValidatorFieldCreatesOnce(): void
    {
        $v = new Validator();
        $fv1 = $v->field('name');
        $fv2 = $v->field('name');
        $this->assertSame($fv1, $fv2);
    }

    public function testValidatorFieldsMethodBulkAdd(): void
    {
        $v = new Validator(['name' => 'Ray', 'age' => '25']);
        $result = $v->fields([
            'name' => [new Required()],
            'age'  => [new Required(), new Numeric()],
        ])->validate();
        $this->assertTrue($result->passes());
    }

    public function testValidatorSetData(): void
    {
        $v = new Validator();
        $v->field('name')->rule(new Required());
        $v->setData(['name' => 'Test']);
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testValidatorDefaults(): void
    {
        $v = new Validator(['name' => 'Ray']);
        $v->defaults(['role' => 'viewer']);
        $v->field('name')->rule(new Required());
        $v->field('role')->rule(new In(['admin', 'viewer']));
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame('viewer', $result->get('role'));
    }

    public function testValidatorDefaultsOverriddenByInput(): void
    {
        $v = new Validator(['role' => 'admin']);
        $v->defaults(['role' => 'viewer']);
        $v->field('role')->rule(new In(['admin', 'viewer']));
        $result = $v->validate();
        $this->assertSame('admin', $result->get('role'));
    }

    public function testValidatorStopOnFirstFailure(): void
    {
        $v = new Validator(['name' => '', 'email' => 'bad']);
        $v->stopOnFirstFailure();
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Email());
        $result = $v->validate();
        $this->assertTrue($result->fails());
        // Only name errors; email was never reached
        $this->assertTrue($result->hasError('name'));
        $this->assertFalse($result->hasError('email'));
    }

    public function testValidatorBeforeHook(): void
    {
        $v = new Validator(['name' => '  Ray  ']);
        $v->before(fn(array $data) => array_map('trim', $data));
        $v->field('name')->rule(new Required());
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testValidatorAfterHook(): void
    {
        $afterCalled = false;
        $v = new Validator(['name' => 'Ray']);
        $v->field('name')->rule(new Required());
        $v->after(function (ValidationResult $r) use (&$afterCalled) {
            $afterCalled = true;
        });
        $v->validate();
        $this->assertTrue($afterCalled);
    }

    public function testValidatorMissingFieldUsesNull(): void
    {
        $v = new Validator(['email' => 'a@b.c']);
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Email());
        $result = $v->validate();
        $this->assertTrue($result->fails());
        $this->assertTrue($result->hasError('name'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 17: Validator::make() Static Shorthand
    // ═══════════════════════════════════════════════════════════════

    public function testMakeStaticPasses(): void
    {
        $result = Validator::make(
            ['name' => 'Ray', 'email' => 'r@e.com'],
            [
                'name'  => [new Required()],
                'email' => [new Required(), new Email()],
            ]
        );
        $this->assertTrue($result->passes());
    }

    public function testMakeStaticFails(): void
    {
        $result = Validator::make(
            ['name' => '', 'email' => 'bad'],
            [
                'name'  => [new Required()],
                'email' => [new Email()],
            ]
        );
        $this->assertTrue($result->fails());
        $this->assertTrue($result->hasError('name'));
        $this->assertTrue($result->hasError('email'));
    }

    public function testMakeReturnsValidationResult(): void
    {
        $result = Validator::make(['x' => 1], ['x' => [new Required()]]);
        $this->assertInstanceOf(ValidationResult::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 18: Integration — Complex Validation Scenarios
    // ═══════════════════════════════════════════════════════════════

    public function testRegistrationFormValidation(): void
    {
        $data = [
            'username'              => 'john_doe',
            'email'                 => 'john@example.com',
            'password'              => 'SecureP4ss',
            'password_confirmation' => 'SecureP4ss',
            'age'                   => '25',
        ];

        $v = new Validator($data);
        $v->field('username')->rule(new Required())->rule(new MinLength(3))->rule(new MaxLength(20));
        $v->field('email')->rule(new Required())->rule(new Email());
        $v->field('password')->rule(new Required())->rule(new MinLength(8))->rule(new Confirmed());
        $v->field('age')->rule(new Numeric())->rule(new Between(13, 120));

        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame('john_doe', $result->get('username'));
    }

    public function testRegistrationFormValidationFails(): void
    {
        $data = [
            'username'              => 'jd',
            'email'                 => 'not-email',
            'password'              => 'short',
            'password_confirmation' => 'mismatch',
            'age'                   => '5',
        ];

        $v = new Validator($data);
        $v->field('username')->rule(new Required())->rule(new MinLength(3));
        $v->field('email')->rule(new Required())->rule(new Email());
        $v->field('password')->rule(new Required())->rule(new MinLength(8));
        $v->field('age')->rule(new Numeric())->rule(new Between(13, 120));

        $result = $v->validate();
        $this->assertTrue($result->fails());
        $this->assertTrue($result->hasError('username'));
        $this->assertTrue($result->hasError('email'));
        $this->assertTrue($result->hasError('password'));
        $this->assertTrue($result->hasError('age'));
    }

    public function testCallbackWithCrossFieldValidation(): void
    {
        $data = ['start' => '2024-01-01', 'end' => '2024-06-01'];
        $v = new Validator($data);
        $v->field('start')->rule(new Date('Y-m-d'));
        $v->field('end')->rule(new Date('Y-m-d'))->rule(new Callback(
            fn($v, $f, $d) => strtotime($v) > strtotime($d['start'])
        ));
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testCallbackWithCrossFieldValidationFails(): void
    {
        $data = ['start' => '2024-06-01', 'end' => '2024-01-01'];
        $v = new Validator($data);
        $v->field('start')->rule(new Date('Y-m-d'));
        $v->field('end')->rule(new Date('Y-m-d'))->rule(
            (new Callback(fn($v, $f, $d) => strtotime($v) > strtotime($d['start'])))
                ->withMessage('End date must be after start date.')
        );
        $result = $v->validate();
        $this->assertTrue($result->fails());
        $this->assertSame('End date must be after start date.', $result->firstError('end'));
    }

    public function testConditionalRulesWithWhen(): void
    {
        $isAdmin = true;
        $data = ['name' => 'Ray', 'access_level' => '5'];
        $v = new Validator($data);
        $v->field('name')->rule(new Required());
        $v->field('access_level')
          ->when($isAdmin, fn(FieldValidator $f) => $f->rule(new Required())->rule(new Between(1, 10)));
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testConditionalRulesSkippedWhenFalse(): void
    {
        $isAdmin = false;
        $data = ['name' => 'Ray'];
        $v = new Validator($data);
        $v->field('name')->rule(new Required());
        $v->field('access_level')
          ->when($isAdmin, fn(FieldValidator $f) => $f->rule(new Required())->rule(new Between(1, 10)));
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testBeforeHookTransformsData(): void
    {
        $data = ['EMAIL' => 'RAY@EXAMPLE.COM', 'NAME' => 'RAY'];
        $v = new Validator($data);
        $v->before(function (array $d) {
            return array_change_key_case($d, CASE_LOWER);
        });
        $v->field('email')->rule(new Required())->rule(new Email());
        $v->field('name')->rule(new Required());
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testMultipleBeforeHooksChain(): void
    {
        $data = ['name' => '  RAY  '];
        $v = new Validator($data);
        $v->before(fn(array $d) => array_map('trim', $d));
        $v->before(fn(array $d) => array_map('strtolower', $d));
        $v->field('name')->rule(new Required());
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame('ray', $result->get('name'));
    }

    public function testValueTransformationThroughRulePipeline(): void
    {
        $data = ['name' => '  Ray  '];
        $v = new Validator($data);
        $v->field('name')
          ->rule(new Required())  // trims to 'Ray'
          ->rule(new Callback(fn($v) => strtoupper($v)));  // transforms to 'RAY'
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame('RAY', $result->get('name'));
    }

    public function testErrorCountAcrossMultipleFields(): void
    {
        $data = ['name' => '', 'email' => 'bad', 'age' => 'abc'];
        $v = new Validator($data);
        $v->field('name')->rule(new Required());
        $v->field('email')->rule(new Email());
        $v->field('age')->rule(new Numeric());
        $result = $v->validate();
        $this->assertSame(3, $result->errorCount());
    }

    public function testAllErrorsFlattened(): void
    {
        $data = ['a' => '', 'b' => 'bad-email'];
        $result = Validator::make($data, [
            'a' => [new Required()],
            'b' => [new Email()],
        ]);
        $this->assertCount(2, $result->allErrors());
    }

    public function testValidatedDataExcludesFailedFields(): void
    {
        $data = ['good' => 'ok', 'bad' => ''];
        $result = Validator::make($data, [
            'good' => [new Required()],
            'bad'  => [new Required()],
        ]);
        $this->assertArrayHasKey('good', $result->validated());
        $this->assertArrayNotHasKey('bad', $result->validated());
    }

    public function testRegexAlphanumericUsername(): void
    {
        $result = Validator::make(
            ['username' => 'user_123'],
            ['username' => [new Required(), new Regex('/^[a-zA-Z0-9_]+$/')]]
        );
        $this->assertTrue($result->passes());
    }

    public function testRegexAlphanumericUsernameFails(): void
    {
        $result = Validator::make(
            ['username' => 'user@123!'],
            ['username' => [new Required(), new Regex('/^[a-zA-Z0-9_]+$/')]]
        );
        $this->assertTrue($result->fails());
    }

    public function testUrlInProfileValidation(): void
    {
        $result = Validator::make(
            ['website' => 'https://mysite.com', 'name' => 'Ray'],
            [
                'name'    => [new Required()],
                'website' => [new Url()],
            ]
        );
        $this->assertTrue($result->passes());
    }

    public function testEmptyOptionalFieldsPass(): void
    {
        // Email, Url, etc. skip null/empty — only Required enforces presence
        $result = Validator::make(
            ['name' => 'Ray', 'website' => '', 'email' => null],
            [
                'name'    => [new Required()],
                'website' => [new Url()],
                'email'   => [new Email()],
            ]
        );
        $this->assertTrue($result->passes());
    }

    public function testPasswordStrengthWithMultipleRules(): void
    {
        $result = Validator::make(
            ['password' => 'Abcd1234', 'password_confirmation' => 'Abcd1234'],
            [
                'password' => [
                    new Required(),
                    new MinLength(8),
                    new MaxLength(64),
                    new Regex('/[A-Z]/'),     // at least one uppercase
                    new Regex('/[a-z]/'),     // at least one lowercase
                    new Regex('/[0-9]/'),     // at least one digit
                    new Confirmed(),
                ],
            ]
        );
        $this->assertTrue($result->passes());
    }

    public function testPasswordStrengthFailsNoUppercase(): void
    {
        $result = Validator::make(
            ['password' => 'abcd1234', 'password_confirmation' => 'abcd1234'],
            [
                'password' => [
                    new Required(),
                    new MinLength(8),
                    new Regex('/[A-Z]/'),
                ],
            ]
        );
        $this->assertTrue($result->fails());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 19: Custom Rule Implementation
    // ═══════════════════════════════════════════════════════════════

    public function testCustomRuleClassImplementation(): void
    {
        $rule = new class extends ValidationRule {
            public function validate(mixed $value, string $field, array $data = []): mixed
            {
                if (!is_string($value) || !str_starts_with($value, 'SKU-')) {
                    $this->fail();
                    return $value;
                }
                $this->pass();
                return $value;
            }

            protected function defaultMessage(): string
            {
                return 'The :field must start with SKU-.';
            }
        };

        $rule->validate('SKU-123', 'product_code');
        $this->assertTrue($rule->passed());

        $rule->validate('ABC-123', 'product_code');
        $this->assertFalse($rule->passed());
        $this->assertSame('The product_code must start with SKU-.', $rule->message('product_code'));
    }

    public function testCustomRuleInValidator(): void
    {
        $skuRule = new class extends ValidationRule {
            public function validate(mixed $value, string $field, array $data = []): mixed
            {
                if (!is_string($value) || !str_starts_with($value, 'SKU-')) {
                    $this->fail();
                    return $value;
                }
                $this->pass();
                return $value;
            }

            protected function defaultMessage(): string
            {
                return 'The :field must start with SKU-.';
            }
        };

        $result = Validator::make(
            ['sku' => 'SKU-001'],
            ['sku' => [new Required(), $skuRule]]
        );
        $this->assertTrue($result->passes());
    }

    // ═══════════════════════════════════════════════════════════════
    // Section 20: Edge Cases
    // ═══════════════════════════════════════════════════════════════

    public function testValidatingEmptyDataSetWithNoRules(): void
    {
        $v = new Validator([]);
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame([], $result->validated());
    }

    public function testValidatingWithNoFieldsRegistered(): void
    {
        $v = new Validator(['name' => 'Ray']);
        $result = $v->validate();
        $this->assertTrue($result->passes());
    }

    public function testFieldValidatorInterfaceContract(): void
    {
        $rule = new Required();
        $this->assertInstanceOf(ValidationRuleInterface::class, $rule);
    }

    public function testFieldValidatorAbstractBase(): void
    {
        $rule = new Required();
        $this->assertInstanceOf(ValidationRule::class, $rule);
    }

    public function testRulePassedDefaultsToTrue(): void
    {
        $rule = new Required();
        // Before validate() is called, passed() defaults to true
        $this->assertTrue($rule->passed());
    }

    public function testRuleStateResetsOnReValidation(): void
    {
        $rule = new Required();
        $rule->validate('', 'f');
        $this->assertFalse($rule->passed());
        $rule->validate('ok', 'f');
        $this->assertTrue($rule->passed());
    }

    public function testBetweenWithStringNumeric(): void
    {
        $rule = new Between(1, 100);
        $rule->validate('50', 'qty');
        $this->assertTrue($rule->passed());
    }

    public function testInWithMixedTypes(): void
    {
        $rule = new In([1, 'two', 3.0, true]);
        $rule->validate('two', 'val');
        $this->assertTrue($rule->passed());
    }

    public function testCallbackReturnValueZeroIsNotFalse(): void
    {
        // `0` is not `false`, so callback should pass and return 0
        $rule = new Callback(fn($v) => 0);
        $result = $rule->validate('any', 'f');
        $this->assertTrue($rule->passed());
        $this->assertSame(0, $result);
    }

    public function testCallbackReturnValueEmptyStringIsNotFalse(): void
    {
        $rule = new Callback(fn($v) => '');
        $result = $rule->validate('any', 'f');
        $this->assertTrue($rule->passed());
        $this->assertSame('', $result);
    }

    public function testCallbackReturnValueNullIsNotFalse(): void
    {
        $rule = new Callback(fn($v) => null);
        $result = $rule->validate('any', 'f');
        $this->assertTrue($rule->passed());
        $this->assertNull($result);
    }

    public function testMultipleDefaultsCallsMerge(): void
    {
        $v = new Validator([]);
        $v->defaults(['a' => 1]);
        $v->defaults(['b' => 2]);
        $v->field('a')->rule(new Numeric());
        $v->field('b')->rule(new Numeric());
        $result = $v->validate();
        $this->assertTrue($result->passes());
        $this->assertSame(1, $result->get('a'));
        $this->assertSame(2, $result->get('b'));
    }

    public function testDateWithTimeFormat(): void
    {
        $rule = new Date('Y-m-d H:i:s');
        $rule->validate('2024-06-15 14:30:00', 'timestamp');
        $this->assertTrue($rule->passed());
    }

    public function testDateWithTimeFormatFails(): void
    {
        $rule = new Date('Y-m-d H:i:s');
        $rule->validate('2024-06-15', 'timestamp');
        $this->assertFalse($rule->passed());
    }

    public function testConfirmedWithStrictTypeComparison(): void
    {
        // Confirmed uses !== so types must match
        $rule = new Confirmed();
        $data = ['pin' => 123, 'pin_confirmation' => '123'];
        $rule->validate(123, 'pin', $data);
        $this->assertFalse($rule->passed());
    }

    public function testValidatorFluentApiChaining(): void
    {
        $v = new Validator();
        $same = $v->setData(['x' => 1])
                   ->defaults(['y' => 2])
                   ->stopOnFirstFailure(false)
                   ->before(fn(array $d) => $d)
                   ->after(fn(ValidationResult $r) => null);
        $this->assertSame($v, $same);
    }

    public function testFieldValidatorGetRulesReturnsAllRules(): void
    {
        $fv = new FieldValidator('test');
        $r1 = new Required();
        $r2 = new Email();
        $fv->rule($r1)->rule($r2);
        $rules = $fv->getRules();
        $this->assertSame($r1, $rules[0]);
        $this->assertSame($r2, $rules[1]);
    }
}
