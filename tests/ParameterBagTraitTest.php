<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Exception\TemplateException;
use Razy\Template\ParameterBagTrait;

/**
 * Concrete stub that uses ParameterBagTrait for unit testing.
 */
class ParameterBagStub
{
    use ParameterBagTrait;

    /** @var list<string> Records calls to onParameterAssigned */
    public array $assignedLog = [];

    /** @var array<string, mixed> */
    private array $parameters = [];

    /** Test helper: peek at internal parameters */
    public function getParameter(string $name): mixed
    {
        return $this->parameters[$name] ?? null;
    }

    /** Test helper: peek at all parameters */
    public function getAllParameters(): array
    {
        return $this->parameters;
    }

    protected function onParameterAssigned(string $parameter): void
    {
        $this->assignedLog[] = $parameter;
    }
}

/**
 * Tests for ParameterBagTrait (Phase 4.3).
 */
#[CoversClass(ParameterBagTrait::class)]
class ParameterBagTraitTest extends TestCase
{
    // ─── assign() with string key ─────────────────────────

    public function testAssignStringKey(): void
    {
        $bag = new ParameterBagStub();
        $result = $bag->assign('foo', 'bar');

        $this->assertSame('bar', $bag->getParameter('foo'));
        $this->assertSame($bag, $result, 'assign() should return $this for chaining');
    }

    public function testAssignOverwritesPreviousValue(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('key', 'first');
        $bag->assign('key', 'second');

        $this->assertSame('second', $bag->getParameter('key'));
    }

    public function testAssignNullValue(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('key', 'value');
        $bag->assign('key', null);

        $this->assertNull($bag->getParameter('key'));
    }

    // ─── assign() with array ─────────────────────────

    public function testAssignArray(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(1, $bag->getParameter('a'));
        $this->assertSame(2, $bag->getParameter('b'));
        $this->assertSame(3, $bag->getParameter('c'));
    }

    public function testAssignEmptyArray(): void
    {
        $bag = new ParameterBagStub();
        $result = $bag->assign([]);

        // Should not throw, and should not change parameters
        $this->assertSame([], $bag->getAllParameters());
        $this->assertSame($bag, $result);
    }

    // ─── assign() with Closure ─────────────────────────

    public function testAssignClosureReceivesCurrentValue(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('counter', 10);
        $bag->assign('counter', fn ($current) => $current + 5);

        $this->assertSame(15, $bag->getParameter('counter'));
    }

    public function testAssignClosureReceivesNullWhenNoCurrentValue(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('new_key', fn ($current) => $current === null ? 'default' : $current);

        $this->assertSame('default', $bag->getParameter('new_key'));
    }

    public function testAssignClosureCanReturnAnything(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('val', fn () => ['array', 'value']);

        $this->assertSame(['array', 'value'], $bag->getParameter('val'));
    }

    // ─── assign() with invalid type ─────────────────────────

    public function testAssignInvalidParameterTypeThrows(): void
    {
        $bag = new ParameterBagStub();
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage('Invalid parameter name');
        $bag->assign(42, 'value');
    }

    public function testAssignBoolParameterTypeThrows(): void
    {
        $bag = new ParameterBagStub();
        $this->expectException(TemplateException::class);
        $bag->assign(true, 'value');
    }

    // ─── onParameterAssigned hook ─────────────────────────

    public function testOnParameterAssignedCalledForStringKey(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign('x', 1);
        $bag->assign('y', 2);

        $this->assertSame(['x', 'y'], $bag->assignedLog);
    }

    public function testOnParameterAssignedCalledForEachArrayKey(): void
    {
        $bag = new ParameterBagStub();
        $bag->assign(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertSame(['a', 'b', 'c'], $bag->assignedLog);
    }

    public function testOnParameterAssignedNotCalledForInvalidType(): void
    {
        $bag = new ParameterBagStub();
        try {
            $bag->assign(999, 'value');
        } catch (TemplateException $e) {
            // Expected
        }

        $this->assertSame([], $bag->assignedLog);
    }

    // ─── bind() ─────────────────────────

    public function testBindByReference(): void
    {
        $bag = new ParameterBagStub();
        $value = 'initial';
        $bag->bind('ref', $value);

        $this->assertSame('initial', $bag->getParameter('ref'));

        // Mutate the original variable — bound parameter should reflect it
        $value = 'changed';
        $this->assertSame('changed', $bag->getParameter('ref'));
    }

    public function testBindReturnsSelf(): void
    {
        $bag = new ParameterBagStub();
        $value = 42;
        $result = $bag->bind('num', $value);

        $this->assertSame($bag, $result);
    }

    public function testBindThenAssignWritesThroughReference(): void
    {
        $bag = new ParameterBagStub();
        $value = 'original';
        $bag->bind('key', $value);

        // assign() writes through the reference — both the parameter and $value change
        $bag->assign('key', 'new_value');
        $this->assertSame('new_value', $value, 'assign() writes through the PHP reference');

        // Mutating $value still reflects in the parameter (reference intact)
        $value = 'mutated';
        $this->assertSame('mutated', $bag->getParameter('key'));
    }

    // ─── chaining ─────────────────────────

    public function testMethodChaining(): void
    {
        $bag = new ParameterBagStub();
        $value = 'bound';
        $result = $bag->assign('a', 1)
            ->assign(['b' => 2, 'c' => 3])
            ->bind('d', $value)
            ->assign('e', fn () => 5);

        $this->assertSame(1, $bag->getParameter('a'));
        $this->assertSame(2, $bag->getParameter('b'));
        $this->assertSame(3, $bag->getParameter('c'));
        $this->assertSame('bound', $bag->getParameter('d'));
        $this->assertSame(5, $bag->getParameter('e'));
    }
}
