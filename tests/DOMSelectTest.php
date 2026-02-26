<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\DOM;
use Razy\DOM\Select;

#[CoversClass(Select::class)]
#[CoversClass(DOM::class)]
class DOMSelectTest extends TestCase
{
    public function testConstructorSetsSelectTag(): void
    {
        $select = new Select();
        $html = (string) $select;
        $this->assertStringStartsWith('<select', $html);
        $this->assertStringEndsWith('</select>', $html);
    }

    public function testConstructorWithId(): void
    {
        $select = new Select('country');
        $html = (string) $select;
        $this->assertStringContainsString('id="country"', $html);
    }

    public function testAddOptionReturnsOptionDom(): void
    {
        $select = new Select();
        $option = $select->addOption('Apple', 'apple');
        $this->assertInstanceOf(DOM::class, $option);
    }

    public function testAddOptionAppearsInHtml(): void
    {
        $select = new Select();
        $select->addOption('Red', 'red');
        $select->addOption('Blue', 'blue');

        $html = (string) $select;
        $this->assertStringContainsString('<option', $html);
        $this->assertStringContainsString('value="red"', $html);
        $this->assertStringContainsString('value="blue"', $html);
        $this->assertStringContainsString('Red', $html);
        $this->assertStringContainsString('Blue', $html);
    }

    public function testApplyOptionsFromArray(): void
    {
        $select = new Select();
        $select->applyOptions(['us' => 'United States', 'uk' => 'United Kingdom']);

        $html = (string) $select;
        $this->assertStringContainsString('value="us"', $html);
        $this->assertStringContainsString('United States', $html);
        $this->assertStringContainsString('value="uk"', $html);
        $this->assertStringContainsString('United Kingdom', $html);
    }

    public function testApplyOptionsWithCallback(): void
    {
        $select = new Select();
        $select->applyOptions([1, 2, 3], function (DOM $option, $key, $value) {
            $option->setText("Item $value")->setAttribute('value', (string) $value);
        });

        $html = (string) $select;
        $this->assertStringContainsString('Item 1', $html);
        $this->assertStringContainsString('value="3"', $html);
    }

    public function testApplyOptionsThrowsForNonStringValue(): void
    {
        $select = new Select();
        $this->expectException(InvalidArgumentException::class);
        $select->applyOptions(['a' => 123]);
    }

    public function testSetValueMarksSelected(): void
    {
        $select = new Select();
        $select->addOption('Apple', 'apple');
        $select->addOption('Banana', 'banana');
        $select->setValue('banana');

        $html = (string) $select;
        $this->assertStringContainsString('selected="selected"', $html);
    }

    public function testGetValueReturnsSelectedValue(): void
    {
        $select = new Select();
        $select->addOption('Apple', 'apple');
        $select->addOption('Banana', 'banana');
        $select->setValue('apple');

        // getValue checks for 'selected' attribute
        $val = $select->getValue();
        $this->assertSame('selected', $val);
    }

    public function testGetValueReturnsNullWhenNoSelection(): void
    {
        $select = new Select();
        $select->addOption('Apple', 'apple');
        $this->assertNull($select->getValue());
    }

    public function testIsMultipleEnables(): void
    {
        $select = new Select();
        $select->isMultiple(true);
        $this->assertStringContainsString('multiple="multiple"', (string) $select);
    }

    public function testIsMultipleDisables(): void
    {
        $select = new Select();
        $select->isMultiple(true);
        $select->isMultiple(false);
        $this->assertStringNotContainsString('multiple', (string) $select);
    }

    public function testFluentInterface(): void
    {
        $select = new Select();
        $result = $select->applyOptions(['a' => 'A', 'b' => 'B']);
        $this->assertSame($select, $result);

        $result2 = $select->isMultiple(true);
        $this->assertSame($select, $result2);
    }
}
