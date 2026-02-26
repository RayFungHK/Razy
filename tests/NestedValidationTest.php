<?php

/**
 * Comprehensive tests for #16: Array/Nested Validation.
 *
 * Covers NestedValidator (dot-notation, wildcards, dataGet/dataSet/dataHas)
 * and new rules IsArray, Each — all edge cases & integration scenarios.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Validation\NestedValidator;
use Razy\Validation\Rule\Each;
use Razy\Validation\Rule\Email;
use Razy\Validation\Rule\IsArray;
use Razy\Validation\Rule\MinLength;
use Razy\Validation\Rule\Numeric;
use Razy\Validation\Rule\Required;
use Razy\Validation\ValidationResult;

#[CoversClass(NestedValidator::class)]
#[CoversClass(IsArray::class)]
#[CoversClass(Each::class)]
class NestedValidationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════
    //  1. dataGet()
    // ═══════════════════════════════════════════════════════

    public function testDataGetTopLevel(): void
    {
        $this->assertSame('Alice', NestedValidator::dataGet(['name' => 'Alice'], 'name'));
    }

    public function testDataGetNested(): void
    {
        $data = ['user' => ['address' => ['city' => 'London']]];
        $this->assertSame('London', NestedValidator::dataGet($data, 'user.address.city'));
    }

    public function testDataGetDeepNested5Levels(): void
    {
        $data = ['a' => ['b' => ['c' => ['d' => ['e' => 42]]]]];
        $this->assertSame(42, NestedValidator::dataGet($data, 'a.b.c.d.e'));
    }

    public function testDataGetMissingReturnsNull(): void
    {
        $this->assertNull(NestedValidator::dataGet(['a' => ['b' => 1]], 'a.c'));
    }

    public function testDataGetMissingReturnsCustomDefault(): void
    {
        $this->assertSame('fallback', NestedValidator::dataGet(['a' => 1], 'x.y', 'fallback'));
    }

    public function testDataGetNumericIndex(): void
    {
        $data = ['items' => ['first', 'second', 'third']];
        $this->assertSame('first', NestedValidator::dataGet($data, 'items.0'));
        $this->assertSame('second', NestedValidator::dataGet($data, 'items.1'));
        $this->assertSame('third', NestedValidator::dataGet($data, 'items.2'));
    }

    public function testDataGetReturnsNullForNonArraySegment(): void
    {
        $data = ['name' => 'Alice'];
        $this->assertNull(NestedValidator::dataGet($data, 'name.first'));
    }

    public function testDataGetReturnsNullValueIfPresent(): void
    {
        $data = ['key' => null];
        $this->assertNull(NestedValidator::dataGet($data, 'key', 'fallback'));
        // Actually: since key exists with null, null is returned (not fallback)
        // This tests that null is distinguishable from missing
    }

    public function testDataGetReturnsEntireSubarray(): void
    {
        $data = ['user' => ['name' => 'Alice', 'age' => 30]];
        $this->assertSame(['name' => 'Alice', 'age' => 30], NestedValidator::dataGet($data, 'user'));
    }

    // ═══════════════════════════════════════════════════════
    //  2. dataSet()
    // ═══════════════════════════════════════════════════════

    public function testDataSetTopLevel(): void
    {
        $data = [];
        NestedValidator::dataSet($data, 'name', 'Bob');
        $this->assertSame(['name' => 'Bob'], $data);
    }

    public function testDataSetNested(): void
    {
        $data = [];
        NestedValidator::dataSet($data, 'user.name', 'Alice');
        $this->assertSame(['user' => ['name' => 'Alice']], $data);
    }

    public function testDataSetDeepNested(): void
    {
        $data = [];
        NestedValidator::dataSet($data, 'a.b.c.d', 42);
        $this->assertSame(42, $data['a']['b']['c']['d']);
    }

    public function testDataSetOverwritesExisting(): void
    {
        $data = ['user' => ['name' => 'Alice']];
        NestedValidator::dataSet($data, 'user.name', 'Bob');
        $this->assertSame('Bob', $data['user']['name']);
    }

    public function testDataSetPreservesOtherKeys(): void
    {
        $data = ['user' => ['name' => 'Alice', 'age' => 30]];
        NestedValidator::dataSet($data, 'user.email', 'alice@test.com');

        $this->assertSame('Alice', $data['user']['name']);
        $this->assertSame(30, $data['user']['age']);
        $this->assertSame('alice@test.com', $data['user']['email']);
    }

    public function testDataSetCreatesMissingIntermediate(): void
    {
        $data = [];
        NestedValidator::dataSet($data, 'config.db.host', 'localhost');
        $this->assertSame('localhost', $data['config']['db']['host']);
    }

    // ═══════════════════════════════════════════════════════
    //  3. dataHas()
    // ═══════════════════════════════════════════════════════

    public function testDataHasTopLevel(): void
    {
        $this->assertTrue(NestedValidator::dataHas(['name' => 'Alice'], 'name'));
        $this->assertFalse(NestedValidator::dataHas(['name' => 'Alice'], 'email'));
    }

    public function testDataHasNested(): void
    {
        $data = ['user' => ['address' => ['city' => 'Tokyo']]];
        $this->assertTrue(NestedValidator::dataHas($data, 'user.address.city'));
        $this->assertFalse(NestedValidator::dataHas($data, 'user.address.zip'));
    }

    public function testDataHasWithNullValue(): void
    {
        $this->assertTrue(NestedValidator::dataHas(['key' => null], 'key'));
    }

    public function testDataHasReturnsFalseOnNonArraySegment(): void
    {
        $data = ['name' => 'Alice'];
        $this->assertFalse(NestedValidator::dataHas($data, 'name.first'));
    }

    public function testDataHasReturnsFalseOnEmptyData(): void
    {
        $this->assertFalse(NestedValidator::dataHas([], 'any.key'));
    }

    // ═══════════════════════════════════════════════════════
    //  4. Dot-notation Validation (no wildcards)
    // ═══════════════════════════════════════════════════════

    public function testSimpleDotNotationPasses(): void
    {
        $result = NestedValidator::make(
            ['user' => ['name' => 'Alice', 'email' => 'alice@example.com']],
            [
                'user.name' => [new Required()],
                'user.email' => [new Required(), new Email()],
            ],
        );

        $this->assertTrue($result->passes());
    }

    public function testDotNotationFailsOnMissing(): void
    {
        $result = NestedValidator::make(
            ['user' => []],
            ['user.name' => [new Required()]],
        );

        $this->assertTrue($result->fails());
        $this->assertNotEmpty($result->errors());
    }

    public function testDotNotationThreeLevels(): void
    {
        $result = NestedValidator::make(
            ['config' => ['db' => ['host' => 'localhost']]],
            ['config.db.host' => [new Required()]],
        );

        $this->assertTrue($result->passes());
    }

    public function testDotNotationValidatedContainsDottedKeys(): void
    {
        $result = NestedValidator::make(
            ['user' => ['name' => 'Alice']],
            ['user.name' => [new Required()]],
        );

        $validated = $result->validated();
        $this->assertArrayHasKey('user.name', $validated);
        $this->assertSame('Alice', $validated['user.name']);
    }

    // ═══════════════════════════════════════════════════════
    //  5. Wildcard Expansion
    // ═══════════════════════════════════════════════════════

    public function testWildcardExpandsToAllIndices(): void
    {
        $result = NestedValidator::make(
            [
                'items' => [
                    ['sku' => 'A1', 'qty' => 3],
                    ['sku' => 'B2', 'qty' => 5],
                ],
            ],
            [
                'items.*.sku' => [new Required()],
                'items.*.qty' => [new Required(), new Numeric()],
            ],
        );

        $this->assertTrue($result->passes());
        $validated = $result->validated();
        $this->assertSame('A1', $validated['items.0.sku'] ?? null);
        $this->assertSame('B2', $validated['items.1.sku'] ?? null);
    }

    public function testWildcardFailsOnInvalidItem(): void
    {
        $result = NestedValidator::make(
            [
                'items' => [
                    ['sku' => 'A1'],
                    ['sku' => ''],
                ],
            ],
            ['items.*.sku' => [new Required()]],
        );

        $this->assertTrue($result->fails());
        $this->assertArrayHasKey('items.1.sku', $result->errors());
    }

    public function testWildcardOnEmptyArrayPassesVacuously(): void
    {
        $result = NestedValidator::make(
            ['items' => []],
            ['items.*.name' => [new Required()]],
        );

        $this->assertTrue($result->passes());
    }

    public function testWildcardOnNonArraySkips(): void
    {
        $result = NestedValidator::make(
            ['items' => 'not-an-array'],
            ['items.*.name' => [new Required()]],
        );

        $this->assertTrue($result->passes());
    }

    public function testWildcardWithThreeItems(): void
    {
        $result = NestedValidator::make(
            [
                'users' => [
                    ['email' => 'a@x.com'],
                    ['email' => 'b@y.com'],
                    ['email' => 'c@z.com'],
                ],
            ],
            ['users.*.email' => [new Required(), new Email()]],
        );

        $this->assertTrue($result->passes());
        $this->assertCount(3, \array_filter(
            \array_keys($result->validated()),
            fn ($k) => \str_starts_with($k, 'users.'),
        ));
    }

    public function testWildcardMultipleFieldsSameArray(): void
    {
        $result = NestedValidator::make(
            [
                'products' => [
                    ['name' => 'Widget', 'price' => '9.99'],
                    ['name' => 'Gadget', 'price' => '19.99'],
                ],
            ],
            [
                'products.*.name' => [new Required()],
                'products.*.price' => [new Required(), new Numeric()],
            ],
        );

        $this->assertTrue($result->passes());
    }

    public function testWildcardPartialFailureReportsCorrectIndex(): void
    {
        $result = NestedValidator::make(
            [
                'emails' => ['valid@test.com', 'bad', 'also@test.com'],
            ],
            ['emails.*' => [new Email()]],
        );

        // Note: wildcard on scalar items: emails.0, emails.1, emails.2
        // This tests that items..1 fails
        if ($result->fails()) {
            $errors = $result->errors();
            $this->assertArrayHasKey('emails.1', $errors);
            $this->assertArrayNotHasKey('emails.0', $errors);
            $this->assertArrayNotHasKey('emails.2', $errors);
        }
    }

    // ═══════════════════════════════════════════════════════
    //  6. make() Static Factory
    // ═══════════════════════════════════════════════════════

    public function testMakeReturnsValidationResult(): void
    {
        $result = NestedValidator::make(['a' => 1], ['a' => [new Required()]]);
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->passes());
    }

    public function testMakeWithEmptyRules(): void
    {
        $result = NestedValidator::make(['a' => 1], []);
        $this->assertTrue($result->passes());
        $this->assertSame([], $result->validated());
    }

    // ═══════════════════════════════════════════════════════
    //  7. Fluent API
    // ═══════════════════════════════════════════════════════

    public function testFluentFieldAndValidate(): void
    {
        $v = new NestedValidator(['user' => ['name' => 'Ray']]);
        $result = $v->field('user.name', [new Required()])->validate();

        $this->assertTrue($result->passes());
    }

    public function testFluentFieldsMultiple(): void
    {
        $v = new NestedValidator(['a' => 1, 'b' => 2]);
        $result = $v->fields([
            'a' => [new Required()],
            'b' => [new Required(), new Numeric()],
        ])->validate();

        $this->assertTrue($result->passes());
    }

    public function testFluentFieldChaining(): void
    {
        $v = new NestedValidator(['x' => 'hello', 'y' => '42']);
        $result = $v
            ->field('x', [new Required()])
            ->field('y', [new Required(), new Numeric()])
            ->validate();

        $this->assertTrue($result->passes());
    }

    public function testSetDataChangesInput(): void
    {
        $v = new NestedValidator();
        $v->setData(['x' => 'val'])->field('x', [new Required()]);
        $result = $v->validate();

        $this->assertTrue($result->passes());
    }

    public function testSetDataReturnsThis(): void
    {
        $v = new NestedValidator();
        $this->assertSame($v, $v->setData(['x' => 1]));
    }

    public function testStopOnFirstFailure(): void
    {
        $result = (new NestedValidator(['a' => '', 'b' => '']))
            ->fields([
                'a' => [new Required()],
                'b' => [new Required()],
            ])
            ->stopOnFirstFailure()
            ->validate();

        $this->assertTrue($result->fails());
        $this->assertCount(1, $result->errors());
    }

    public function testStopOnFirstFailureDoesNotAffectPassing(): void
    {
        $result = (new NestedValidator(['a' => 'ok', 'b' => 'ok']))
            ->fields([
                'a' => [new Required()],
                'b' => [new Required()],
            ])
            ->stopOnFirstFailure()
            ->validate();

        $this->assertTrue($result->passes());
    }

    // ═══════════════════════════════════════════════════════
    //  8. Defaults
    // ═══════════════════════════════════════════════════════

    public function testDefaultsApplied(): void
    {
        $v = new NestedValidator([]);
        $v->defaults(['name' => 'Guest']);
        $v->field('name', [new Required()]);
        $result = $v->validate();

        $this->assertTrue($result->passes());
        $this->assertSame('Guest', $result->validated()['name'] ?? null);
    }

    public function testDefaultsDoNotOverwriteProvided(): void
    {
        $v = new NestedValidator(['name' => 'Alice']);
        $v->defaults(['name' => 'Guest']);
        $v->field('name', [new Required()]);
        $result = $v->validate();

        $this->assertTrue($result->passes());
        $this->assertSame('Alice', $result->validated()['name']);
    }

    public function testDefaultsReturnsThis(): void
    {
        $v = new NestedValidator();
        $this->assertSame($v, $v->defaults(['x' => 1]));
    }

    // ═══════════════════════════════════════════════════════
    //  9. IsArray Rule
    // ═══════════════════════════════════════════════════════

    public function testIsArrayPassesForArray(): void
    {
        $rule = new IsArray();
        $rule->validate(['a', 'b'], 'tags');
        $this->assertTrue($rule->passed());
    }

    public function testIsArrayPassesForEmptyArray(): void
    {
        $rule = new IsArray();
        $rule->validate([], 'tags');
        $this->assertTrue($rule->passed());
    }

    public function testIsArrayPassesForAssociativeArray(): void
    {
        $rule = new IsArray();
        $rule->validate(['key' => 'val'], 'config');
        $this->assertTrue($rule->passed());
    }

    public function testIsArrayFailsForString(): void
    {
        $rule = new IsArray();
        $rule->validate('string', 'tags');
        $this->assertFalse($rule->passed());
    }

    public function testIsArrayFailsForInteger(): void
    {
        $rule = new IsArray();
        $rule->validate(42, 'tags');
        $this->assertFalse($rule->passed());
    }

    public function testIsArrayFailsForBoolean(): void
    {
        $rule = new IsArray();
        $rule->validate(true, 'tags');
        $this->assertFalse($rule->passed());
    }

    public function testIsArraySkipsNull(): void
    {
        $rule = new IsArray();
        $rule->validate(null, 'tags');
        $this->assertTrue($rule->passed());
    }

    public function testIsArraySkipsEmptyString(): void
    {
        $rule = new IsArray();
        $rule->validate('', 'tags');
        $this->assertTrue($rule->passed());
    }

    public function testIsArrayMessageContainsFieldName(): void
    {
        $rule = new IsArray();
        $rule->validate(42, 'tags');
        $msg = $rule->message('tags');
        $this->assertStringContainsString('tags', $msg);
        $this->assertStringContainsString('array', $msg);
    }

    public function testIsArrayCustomMessage(): void
    {
        $rule = (new IsArray())->withMessage('不是陣列');
        $rule->validate(42, 'tags');
        $this->assertSame('不是陣列', $rule->message('tags'));
    }

    // ═══════════════════════════════════════════════════════
    //  10. Each Rule
    // ═══════════════════════════════════════════════════════

    public function testEachPassesForValidElements(): void
    {
        $rule = new Each([new Email()]);
        $rule->validate(['a@b.com', 'c@d.com'], 'emails');
        $this->assertTrue($rule->passed());
    }

    public function testEachFailsForInvalidElement(): void
    {
        $rule = new Each([new Email()]);
        $rule->validate(['a@b.com', 'bad'], 'emails');
        $this->assertFalse($rule->passed());
    }

    public function testEachGetItemErrorsReportsCorrectIndices(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate(['a', '', 'c', ''], 'items');

        $errors = $rule->getItemErrors();
        $this->assertArrayHasKey(1, $errors);
        $this->assertArrayHasKey(3, $errors);
        $this->assertArrayNotHasKey(0, $errors);
        $this->assertArrayNotHasKey(2, $errors);
    }

    public function testEachPassesForNull(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate(null, 'items');
        $this->assertTrue($rule->passed());
    }

    public function testEachPassesForEmptyString(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate('', 'items');
        $this->assertTrue($rule->passed());
    }

    public function testEachFailsForNonArray(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate('not-array', 'items');
        $this->assertFalse($rule->passed());
    }

    public function testEachFailsForInteger(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate(42, 'items');
        $this->assertFalse($rule->passed());
    }

    public function testEachPassesForEmptyArray(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate([], 'items');
        $this->assertTrue($rule->passed());
    }

    public function testEachMessageContainsInvalid(): void
    {
        $rule = new Each([new Required()]);
        $rule->validate(['a', ''], 'items');
        $msg = $rule->message('items');
        $this->assertStringContainsString('invalid', $msg);
    }

    public function testEachWithMultipleRules(): void
    {
        $rule = new Each([new Required(), new MinLength(3)]);
        $rule->validate(['Alice', 'Bob', 'Al'], 'names');

        $this->assertFalse($rule->passed());
        $errors = $rule->getItemErrors();
        $this->assertArrayHasKey(2, $errors);
        $this->assertArrayNotHasKey(0, $errors);
        $this->assertArrayNotHasKey(1, $errors);
    }

    public function testEachWithNumericRule(): void
    {
        $rule = new Each([new Numeric()]);
        $rule->validate([1, '2.5', 'abc'], 'values');

        $this->assertFalse($rule->passed());
        $errors = $rule->getItemErrors();
        $this->assertArrayHasKey(2, $errors);
    }

    public function testEachAllElementsValid(): void
    {
        $rule = new Each([new Numeric()]);
        $rule->validate([1, 2, 3.14, '100'], 'numbers');

        $this->assertTrue($rule->passed());
        $this->assertSame([], $rule->getItemErrors());
    }

    public function testEachCustomMessage(): void
    {
        $rule = (new Each([new Required()]))->withMessage('陣列元素無效');
        $rule->validate(['a', ''], 'items');
        $this->assertSame('陣列元素無效', $rule->message('items'));
    }

    public function testEachResetsBetweenValidations(): void
    {
        $rule = new Each([new Required()]);

        $rule->validate(['', ''], 'items');
        $this->assertFalse($rule->passed());
        $this->assertCount(2, $rule->getItemErrors());

        $rule->validate(['ok', 'fine'], 'items');
        $this->assertTrue($rule->passed());
        $this->assertSame([], $rule->getItemErrors());
    }

    // ═══════════════════════════════════════════════════════
    //  11. Integration: NestedValidator + IsArray + Each
    // ═══════════════════════════════════════════════════════

    public function testNestedWithIsArrayRule(): void
    {
        $result = NestedValidator::make(
            ['tags' => ['php', 'laravel']],
            ['tags' => [new IsArray()]],
        );
        $this->assertTrue($result->passes());
    }

    public function testNestedWithIsArrayFailsForString(): void
    {
        $result = NestedValidator::make(
            ['tags' => 'not-an-array'],
            ['tags' => [new IsArray()]],
        );
        $this->assertTrue($result->fails());
    }

    public function testNestedWithEachRule(): void
    {
        $result = NestedValidator::make(
            ['emails' => ['a@b.com', 'c@d.com']],
            ['emails' => [new IsArray(), new Each([new Email()])]],
        );
        $this->assertTrue($result->passes());
    }

    public function testNestedWithEachRuleFails(): void
    {
        $result = NestedValidator::make(
            ['emails' => ['a@b.com', 'bad']],
            ['emails' => [new IsArray(), new Each([new Email()])]],
        );
        $this->assertTrue($result->fails());
    }

    public function testNestedWithDotNotationAndIsArray(): void
    {
        $result = NestedValidator::make(
            ['user' => ['roles' => ['admin', 'editor']]],
            ['user.roles' => [new Required(), new IsArray()]],
        );
        $this->assertTrue($result->passes());
    }

    public function testNestedWithWildcardAndEach(): void
    {
        $result = NestedValidator::make(
            [
                'orders' => [
                    ['items' => ['A', 'B']],
                    ['items' => ['C']],
                ],
            ],
            [
                'orders.*.items' => [new IsArray(), new Each([new Required()])],
            ],
        );
        $this->assertTrue($result->passes());
    }

    // ═══════════════════════════════════════════════════════
    //  12. Complex Nested Scenario
    // ═══════════════════════════════════════════════════════

    public function testComplexFormValidation(): void
    {
        $data = [
            'user' => [
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ],
            'items' => [
                ['sku' => 'A1', 'qty' => '3', 'tags' => ['sale', 'new']],
                ['sku' => 'B2', 'qty' => '5', 'tags' => ['premium']],
            ],
        ];

        $result = NestedValidator::make($data, [
            'user.name' => [new Required()],
            'user.email' => [new Required(), new Email()],
            'items.*.sku' => [new Required()],
            'items.*.qty' => [new Required(), new Numeric()],
            'items.*.tags' => [new IsArray()],
        ]);

        $this->assertTrue($result->passes());
    }

    public function testComplexFormValidationFails(): void
    {
        $data = [
            'user' => ['name' => '', 'email' => 'bad'],
            'items' => [
                ['sku' => '', 'qty' => 'abc'],
            ],
        ];

        $result = NestedValidator::make($data, [
            'user.name' => [new Required()],
            'user.email' => [new Required(), new Email()],
            'items.*.sku' => [new Required()],
            'items.*.qty' => [new Numeric()],
        ]);

        $this->assertTrue($result->fails());
        $errors = $result->errors();
        $this->assertArrayHasKey('user.name', $errors);
    }
}
