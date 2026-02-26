<?php

/**
 * Comprehensive tests for #15: Form Request Validation.
 *
 * Covers FormRequest — fromArray, rules, authorize, passes/fails,
 * validated, errors, input helpers, response helpers, hooks.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Validation\FormRequest;
use Razy\Validation\Rule\Email;
use Razy\Validation\Rule\MinLength;
use Razy\Validation\Rule\Numeric;
use Razy\Validation\Rule\Required;
use Razy\Validation\ValidationResult;

#[CoversClass(FormRequest::class)]
class FormRequestTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    //  1. Factory Methods
    // ═══════════════════════════════════════════════════════

    public function testFromArrayCreatesInstance(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertInstanceOf(FormRequest::class, $req);
    }

    public function testFromArrayPreservesData(): void
    {
        $data = ['name' => 'Bob', 'email' => 'bob@test.com', 'extra' => 'info'];
        $req = FRTest_UserRequest::fromArray($data);
        $this->assertSame($data, $req->all());
    }

    // ═══════════════════════════════════════════════════════
    //  2. Passing Validation
    // ═══════════════════════════════════════════════════════

    public function testPassesWithValidData(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertTrue($req->passes());
        $this->assertFalse($req->fails());
    }

    public function testValidatedReturnsOnlyValidatedFields(): void
    {
        $req = FRTest_UserRequest::fromArray(
            ['name' => 'Alice', 'email' => 'alice@example.com', 'extra' => 'garbage'],
        );
        $validated = $req->validated();

        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayHasKey('email', $validated);
        // 'extra' is not in rules, so should not appear
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function testErrorsEmptyWhenPassing(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertSame([], $req->errors());
    }

    // ═══════════════════════════════════════════════════════
    //  3. Failing Validation
    // ═══════════════════════════════════════════════════════

    public function testFailsWithInvalidData(): void
    {
        $req = FRTest_UserRequest::fromArray($this->invalidData());
        $this->assertTrue($req->fails());
        $this->assertFalse($req->passes());
    }

    public function testErrorsContainFailedFields(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '', 'email' => '']);
        $errors = $req->errors();

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('name', $errors);
    }

    public function testErrorsContainStringMessages(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '', 'email' => '']);
        foreach ($req->errors() as $field => $messages) {
            $this->assertIsString($field);
            foreach ($messages as $msg) {
                $this->assertIsString($msg);
            }
        }
    }

    public function testMissingFieldsAreInvalid(): void
    {
        $req = FRTest_UserRequest::fromArray([]);
        $this->assertTrue($req->fails());
    }

    // ═══════════════════════════════════════════════════════
    //  4. Authorization
    // ═══════════════════════════════════════════════════════

    public function testAuthorizedByDefault(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertTrue($req->isAuthorized());
    }

    public function testUnauthorizedFailsRegardlessOfValidData(): void
    {
        $req = FRTest_UnauthorizedRequest::fromArray($this->validData());
        $this->assertFalse($req->passes());
        $this->assertTrue($req->fails());
    }

    public function testUnauthorizedShowsAuthorizationError(): void
    {
        $req = FRTest_UnauthorizedRequest::fromArray($this->validData());
        $errors = $req->errors();

        $this->assertArrayHasKey('_authorization', $errors);
        $this->assertSame(['This action is unauthorized.'], $errors['_authorization']);
    }

    public function testUnauthorizedErrorsDoNotContainFieldErrors(): void
    {
        $req = FRTest_UnauthorizedRequest::fromArray(['name' => '', 'email' => '']);
        $errors = $req->errors();

        // Should only have _authorization, not field errors
        $this->assertArrayHasKey('_authorization', $errors);
        $this->assertArrayNotHasKey('name', $errors);
    }

    // ═══════════════════════════════════════════════════════
    //  5. Input Access Methods
    // ═══════════════════════════════════════════════════════

    public function testAllReturnsRawData(): void
    {
        $data = ['name' => 'Ray', 'email' => 'ray@test.com', 'extra' => 'data'];
        $req = FRTest_UserRequest::fromArray($data);
        $this->assertSame($data, $req->all());
    }

    public function testInputReturnsSpecificValue(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertSame('Alice', $req->input('name'));
    }

    public function testInputReturnsDefaultForMissing(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertSame('fallback', $req->input('missing', 'fallback'));
    }

    public function testInputReturnsNullForMissingWithoutDefault(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertNull($req->input('missing'));
    }

    public function testOnlyReturnsSubset(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => 'A', 'email' => 'b', 'extra' => 'c']);
        $only = $req->only(['name', 'email']);

        $this->assertSame(['name' => 'A', 'email' => 'b'], $only);
    }

    public function testOnlyWithNonexistentKeys(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => 'A']);
        $only = $req->only(['name', 'nope']);

        $this->assertSame(['name' => 'A'], $only);
    }

    public function testExceptExcludesKeys(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => 'A', 'email' => 'b', 'extra' => 'c']);
        $except = $req->except(['extra']);

        $this->assertArrayHasKey('name', $except);
        $this->assertArrayHasKey('email', $except);
        $this->assertArrayNotHasKey('extra', $except);
    }

    public function testExceptWithAllKeys(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => 'A']);
        $except = $req->except(['name']);

        $this->assertSame([], $except);
    }

    public function testHasReturnsTrueForPresentKey(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertTrue($req->has('name'));
    }

    public function testHasReturnsFalseForAbsentKey(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertFalse($req->has('absent'));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $req = FRTest_UserRequest::fromArray(['key' => null]);
        $this->assertTrue($req->has('key'));
    }

    public function testFilledReturnsTrueForNonEmpty(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => 'Alice']);
        $this->assertTrue($req->filled('name'));
    }

    public function testFilledReturnsFalseForEmptyString(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '']);
        $this->assertFalse($req->filled('name'));
    }

    public function testFilledReturnsFalseForNull(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => null]);
        $this->assertFalse($req->filled('name'));
    }

    public function testFilledReturnsFalseForMissing(): void
    {
        $req = FRTest_UserRequest::fromArray([]);
        $this->assertFalse($req->filled('name'));
    }

    public function testFilledReturnsTrueForZero(): void
    {
        $req = FRTest_UserRequest::fromArray(['val' => 0]);
        $this->assertTrue($req->filled('val'));
    }

    public function testFilledReturnsTrueForFalse(): void
    {
        $req = FRTest_UserRequest::fromArray(['val' => false]);
        $this->assertTrue($req->filled('val'));
    }

    // ═══════════════════════════════════════════════════════
    //  6. Response Helpers
    // ═══════════════════════════════════════════════════════

    public function testErrorsAsJsonReturnsValidJson(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '', 'email' => '']);
        $json = $req->errorsAsJson();

        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('errors', $decoded);
    }

    public function testErrorsAsJsonWithPrettyPrint(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '', 'email' => '']);
        $json = $req->errorsAsJson(JSON_PRETTY_PRINT);

        $this->assertStringContainsString("\n", $json);
    }

    public function testErrorsAsJsonEmptyWhenValid(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $json = $req->errorsAsJson();

        $decoded = \json_decode($json, true);
        $this->assertSame(['errors' => []], $decoded);
    }

    public function testAllErrorsReturnsFlatList(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '', 'email' => '']);
        $all = $req->allErrors();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        foreach ($all as $msg) {
            $this->assertIsString($msg);
        }
    }

    public function testAllErrorsEmptyWhenValid(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertSame([], $req->allErrors());
    }

    // ═══════════════════════════════════════════════════════
    //  7. Validation Caching
    // ═══════════════════════════════════════════════════════

    public function testValidateIsCached(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $r1 = $req->validate();
        $r2 = $req->validate();

        $this->assertSame($r1, $r2, 'validate() should return the same cached instance');
    }

    public function testValidateReturnsValidationResult(): void
    {
        $req = FRTest_UserRequest::fromArray($this->validData());
        $this->assertInstanceOf(ValidationResult::class, $req->validate());
    }

    // ═══════════════════════════════════════════════════════
    //  8. prepareForValidation Hook
    // ═══════════════════════════════════════════════════════

    public function testPrepareForValidationTransformsData(): void
    {
        $req = FRTest_TrimmingRequest::fromArray(['name' => '  Alice  ', 'email' => 'alice@example.com']);
        $this->assertTrue($req->passes());
        $this->assertSame('Alice', $req->validated()['name']);
    }

    public function testPrepareForValidationDoesNotAffectRawAll(): void
    {
        $req = FRTest_TrimmingRequest::fromArray(['name' => '  Alice  ', 'email' => 'x@x.com']);
        // all() should still return raw data
        $this->assertSame('  Alice  ', $req->all()['name']);
    }

    // ═══════════════════════════════════════════════════════
    //  9. Defaults
    // ═══════════════════════════════════════════════════════

    public function testDefaultsAppliedForMissingField(): void
    {
        $req = FRTest_DefaultsRequest::fromArray(['email' => 'alice@example.com']);
        $this->assertTrue($req->passes());
        $this->assertSame('Guest', $req->validated()['name']);
    }

    public function testDefaultsDoNotOverwriteProvidedValues(): void
    {
        $req = FRTest_DefaultsRequest::fromArray(['name' => 'Ray', 'email' => 'ray@test.com']);
        $this->assertTrue($req->passes());
        $this->assertSame('Ray', $req->validated()['name']);
    }

    // ═══════════════════════════════════════════════════════
    //  10. Multiple Rules per Field
    // ═══════════════════════════════════════════════════════

    public function testMultipleRulesAllPass(): void
    {
        $req = FRTest_StrictRequest::fromArray(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->assertTrue($req->passes());
    }

    public function testMultipleRulesFirstFails(): void
    {
        // Name required fails → bail stops at first rule
        $req = FRTest_StrictRequest::fromArray(['name' => '', 'email' => 'alice@example.com']);
        $this->assertTrue($req->fails());
        $errors = $req->errors();
        $this->assertArrayHasKey('name', $errors);
    }

    public function testMultipleRulesSecondFails(): void
    {
        // Name exists but too short (< 3)
        $req = FRTest_StrictRequest::fromArray(['name' => 'Al', 'email' => 'alice@example.com']);
        $this->assertTrue($req->fails());
    }

    // ═══════════════════════════════════════════════════════
    //  11. Edge Cases
    // ═══════════════════════════════════════════════════════

    public function testNumericNameField(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '123', 'email' => 'x@x.com']);
        $this->assertTrue($req->passes());
    }

    public function testWhitespaceOnlyNameFails(): void
    {
        $req = FRTest_UserRequest::fromArray(['name' => '   ', 'email' => 'x@x.com']);
        $this->assertTrue($req->fails());
    }

    public function testValidDataWithExtraFieldsStillPasses(): void
    {
        $req = FRTest_UserRequest::fromArray([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret',
            'age' => 25,
        ]);
        $this->assertTrue($req->passes());
    }

    // ═══════════════════════════════════════════════════════
    //  12. Numeric-Only Validation Request
    // ═══════════════════════════════════════════════════════

    public function testNumericFieldValidation(): void
    {
        $req = FRTest_NumericRequest::fromArray(['amount' => '42.5']);
        $this->assertTrue($req->passes());
    }

    public function testNumericFieldValidationFails(): void
    {
        $req = FRTest_NumericRequest::fromArray(['amount' => 'not-a-number']);
        $this->assertTrue($req->fails());
    }

    // ═══════════════════════════════════════════════════════
    //  13. passes/fails consistency
    // ═══════════════════════════════════════════════════════

    public function testPassesAndFailsAreAlwaysOpposite(): void
    {
        $valid = FRTest_UserRequest::fromArray($this->validData());
        $invalid = FRTest_UserRequest::fromArray($this->invalidData());

        $this->assertNotSame($valid->passes(), $valid->fails());
        $this->assertNotSame($invalid->passes(), $invalid->fails());
    }
    // ─────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────

    private function validData(): array
    {
        return ['name' => 'Alice', 'email' => 'alice@example.com'];
    }

    private function invalidData(): array
    {
        return ['name' => '', 'email' => 'bad'];
    }
}

// ═══════════════════════════════════════════════════════════════
//  Test Doubles
// ═══════════════════════════════════════════════════════════════

/** @internal */
class FRTest_UserRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required()],
            'email' => [new Required(), new Email()],
        ];
    }
}

/** @internal */
class FRTest_UnauthorizedRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required()],
            'email' => [new Required(), new Email()],
        ];
    }

    protected function authorize(): bool
    {
        return false;
    }
}

/** @internal */
class FRTest_TrimmingRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required()],
            'email' => [new Required(), new Email()],
        ];
    }

    protected function prepareForValidation(array $data): array
    {
        if (isset($data['name'])) {
            $data['name'] = \trim($data['name']);
        }

        return $data;
    }
}

/** @internal */
class FRTest_DefaultsRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required()],
            'email' => [new Required(), new Email()],
        ];
    }

    protected function defaults(): array
    {
        return ['name' => 'Guest'];
    }
}

/** @internal */
class FRTest_StrictRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'name' => [new Required(), new MinLength(3)],
            'email' => [new Required(), new Email()],
        ];
    }
}

/** @internal */
class FRTest_NumericRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'amount' => [new Required(), new Numeric()],
        ];
    }
}
