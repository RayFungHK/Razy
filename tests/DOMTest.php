<?php

/**
 * Unit tests for Razy\DOM and Razy\DOM\Select.
 *
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\DOM;
use Razy\DOM\Select;

#[CoversClass(DOM::class)]
#[CoversClass(Select::class)]
class DOMTest extends TestCase
{
    // ─── Basic Construction ──────────────────────────────────────────

    #[Test]
    public function constructWithDefaults(): void
    {
        $dom = new DOM();
        // No tag set yet, so saveHTML produces minimal output
        $this->assertInstanceOf(DOM::class, $dom);
    }

    #[Test]
    public function constructWithNameAndId(): void
    {
        $dom = new DOM('myName', 'myId');
        $dom->setTag('div');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('name="myName"', $html);
        $this->assertStringContainsString('id="myId"', $html);
    }

    #[Test]
    public function constructTrimsNameAndId(): void
    {
        $dom = new DOM('  spacedName  ', '  spacedId  ');
        $dom->setTag('span');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('name="spacedName"', $html);
        $this->assertStringContainsString('id="spacedId"', $html);
    }

    // ─── setTag / getTag ─────────────────────────────────────────────

    #[Test]
    public function setTagAndGetTag(): void
    {
        $dom = new DOM();
        $result = $dom->setTag('div');
        $this->assertSame('div', $dom->getTag());
        $this->assertSame($dom, $result, 'setTag should return $this for chaining');
    }

    #[Test]
    public function setTagAcceptsValidNames(): void
    {
        $dom = new DOM();
        foreach (['div', 'span', 'h1', 'input', 'br', 'p', 'section', 'a'] as $tag) {
            $dom->setTag($tag);
            $this->assertSame($tag, $dom->getTag());
        }
    }

    #[Test]
    public function setTagRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DOM())->setTag('');
    }

    #[Test]
    public function setTagRejectsInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DOM())->setTag('div class');
    }

    #[Test]
    public function setTagRejectsStartingWithDigit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DOM())->setTag('1div');
    }

    #[Test]
    public function setTagRejectsSpecialChars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DOM())->setTag('div<>');
    }

    // ─── addClass / removeClass ──────────────────────────────────────

    #[Test]
    public function addClassString(): void
    {
        $dom = new DOM();
        $result = $dom->setTag('div')->addClass('btn');
        $this->assertSame($dom, $result, 'addClass should return $this');
        $this->assertStringContainsString('class="btn"', $dom->saveHTML());
    }

    #[Test]
    public function addClassArray(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass(['btn', 'btn-primary', 'active']);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('btn', $html);
        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('active', $html);
    }

    #[Test]
    public function addClassIgnoresEmptyString(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass('')->addClass('   ');
        $html = $dom->saveHTML();
        $this->assertStringNotContainsString('class=', $html);
    }

    #[Test]
    public function addClassDeduplicates(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass('btn')->addClass('btn');
        $html = $dom->saveHTML();
        // class attribute should only list 'btn' once
        $this->assertSame(1, \substr_count($html, 'btn'));
    }

    #[Test]
    public function removeClassString(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass(['a', 'b', 'c'])->removeClass('b');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('a', $html);
        $this->assertStringNotContainsString(' b', $html);
        $this->assertStringContainsString('c', $html);
    }

    #[Test]
    public function removeClassArray(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass(['x', 'y', 'z'])->removeClass(['x', 'z']);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('class="y"', $html);
    }

    #[Test]
    public function removeClassNonExistentIsNoOp(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass('a');
        $dom->removeClass('nonexistent');
        $this->assertStringContainsString('class="a"', $dom->saveHTML());
    }

    // ─── setAttribute / getAttribute / hasAttribute / removeAttribute ─

    #[Test]
    public function setAndGetAttribute(): void
    {
        $dom = new DOM();
        $dom->setTag('a');
        $result = $dom->setAttribute('href', 'https://example.com');
        $this->assertSame($dom, $result);
        $this->assertSame('https://example.com', $dom->getAttribute('href'));
        $this->assertTrue($dom->hasAttribute('href'));
    }

    #[Test]
    public function setAttributeWithArray(): void
    {
        $dom = new DOM();
        $dom->setTag('input')->setAttribute([
            'type' => 'text',
            'placeholder' => 'Enter name',
        ]);
        $this->assertSame('text', $dom->getAttribute('type'));
        $this->assertSame('Enter name', $dom->getAttribute('placeholder'));
    }

    #[Test]
    public function setAttributeNullCreatesBoolean(): void
    {
        $dom = new DOM();
        $dom->setTag('input')->setAttribute('disabled', null);
        $html = $dom->saveHTML();
        $this->assertStringContainsString(' disabled', $html);
        $this->assertStringNotContainsString('disabled=', $html);
    }

    #[Test]
    public function removeAttribute(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setAttribute('title', 'hello');
        $this->assertTrue($dom->hasAttribute('title'));
        $result = $dom->removeAttribute('title');
        $this->assertSame($dom, $result);
        $this->assertFalse($dom->hasAttribute('title'));
        $this->assertNull($dom->getAttribute('title'));
    }

    #[Test]
    public function getAttributeReturnsNullForMissing(): void
    {
        $dom = new DOM();
        $this->assertNull($dom->getAttribute('nonexistent'));
    }

    // ─── setDataset ──────────────────────────────────────────────────

    #[Test]
    public function setDatasetString(): void
    {
        $dom = new DOM();
        $result = $dom->setTag('div')->setDataset('userId', '42');
        $this->assertSame($dom, $result);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('data-userId="42"', $html);
    }

    #[Test]
    public function setDatasetArray(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setDataset(['key' => 'val', 'num' => 7]);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('data-key="val"', $html);
        $this->assertStringContainsString('data-num="7"', $html);
    }

    #[Test]
    public function setDatasetEscapesValues(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setDataset('html', '<script>alert("xss")</script>');
        $html = $dom->saveHTML();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ─── setText ─────────────────────────────────────────────────────

    #[Test]
    public function setTextOnEmptyElement(): void
    {
        $dom = new DOM();
        $dom->setTag('p');
        $result = $dom->setText('Hello World');
        $this->assertSame($dom, $result);
        $html = $dom->saveHTML();
        $this->assertSame('<p>Hello World</p>', $html);
    }

    #[Test]
    public function setTextReplacesLastTextNode(): void
    {
        $dom = new DOM();
        $dom->setTag('p')->setText('First')->setText('Second');
        $this->assertSame('<p>Second</p>', $dom->saveHTML());
    }

    #[Test]
    public function setTextAfterChildAppend(): void
    {
        $dom = new DOM();
        $dom->setTag('div');
        $child = new DOM();
        $child->setTag('span')->setText('child');
        $dom->append($child)->setText('trailing text');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('<span>child</span>', $html);
        $this->assertStringContainsString('trailing text', $html);
    }

    // ─── Void / Self-Closing Elements ────────────────────────────────

    #[Test]
    public function voidElementRendersWithSelfClosingTag(): void
    {
        $dom = new DOM();
        $dom->setTag('br')->setVoidElement(true);
        $this->assertSame('<br />', $dom->saveHTML());
    }

    #[Test]
    public function voidElementInput(): void
    {
        $dom = new DOM('email', 'emailField');
        $dom->setTag('input')->setVoidElement(true)->setAttribute('type', 'email');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('/>', $html);
        $this->assertStringNotContainsString('</input>', $html);
    }

    #[Test]
    public function voidElementImg(): void
    {
        $dom = new DOM();
        $dom->setTag('img')->setVoidElement(true)->setAttribute('src', 'photo.jpg')->setAttribute('alt', 'A photo');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('src="photo.jpg"', $html);
        $this->assertStringContainsString('alt="A photo"', $html);
        $this->assertStringEndsWith('/>', $html);
    }

    #[Test]
    public function setVoidElementCanBeToggled(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setVoidElement(true);
        $this->assertStringContainsString('/>', $dom->saveHTML());

        $dom->setVoidElement(false)->setText('content');
        $this->assertStringContainsString('</div>', $dom->saveHTML());
    }

    // ─── append / prepend ────────────────────────────────────────────

    #[Test]
    public function appendAddsChildAtEnd(): void
    {
        $parent = new DOM();
        $parent->setTag('ul');

        $li1 = new DOM();
        $li1->setTag('li')->setText('First');
        $li2 = new DOM();
        $li2->setTag('li')->setText('Second');

        $result = $parent->append($li1)->append($li2);
        $this->assertSame($parent, $result);
        $html = $parent->saveHTML();
        $this->assertSame('<ul><li>First</li><li>Second</li></ul>', $html);
    }

    #[Test]
    public function prependAddsChildAtBeginning(): void
    {
        $parent = new DOM();
        $parent->setTag('ul');

        $li1 = new DOM();
        $li1->setTag('li')->setText('First');
        $li2 = new DOM();
        $li2->setTag('li')->setText('Prepended');

        $parent->append($li1);
        $result = $parent->prepend($li2);
        $this->assertSame($parent, $result);
        $html = $parent->saveHTML();
        $this->assertSame('<ul><li>Prepended</li><li>First</li></ul>', $html);
    }

    // ─── __toString ──────────────────────────────────────────────────

    #[Test]
    public function toStringCallsSaveHTML(): void
    {
        $dom = new DOM();
        $dom->setTag('em')->setText('emphasis');
        $this->assertSame('<em>emphasis</em>', (string) $dom);
    }

    // ─── setName ─────────────────────────────────────────────────────

    #[Test]
    public function setNameUpdatesNameAttribute(): void
    {
        $dom = new DOM();
        $dom->setTag('input')->setVoidElement(true);
        $result = $dom->setName('field1');
        $this->assertSame($dom, $result);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('name="field1"', $html);
    }

    #[Test]
    public function setNameTrimsWhitespace(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setName('  trimmed  ');
        $this->assertStringContainsString('name="trimmed"', $dom->saveHTML());
    }

    // ─── getHTMLValue ────────────────────────────────────────────────

    #[Test]
    public function getHTMLValueEscapesScalar(): void
    {
        $dom = new DOM();
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', $dom->getHTMLValue('<b>bold</b>'));
        $this->assertSame('42', $dom->getHTMLValue(42));
        $this->assertSame('3.14', $dom->getHTMLValue(3.14));
        $this->assertSame('1', $dom->getHTMLValue(true));
    }

    #[Test]
    public function getHTMLValueEncodesArrayAsJson(): void
    {
        $dom = new DOM();
        $result = $dom->getHTMLValue(['key' => 'value']);
        $this->assertStringContainsString('key', $result);
        $this->assertStringContainsString('value', $result);
    }

    #[Test]
    public function getHTMLValueReturnsEmptyForResource(): void
    {
        $dom = new DOM();
        $fp = \fopen('php://memory', 'r');
        $this->assertSame('', $dom->getHTMLValue($fp));
        \fclose($fp);
    }

    // ─── Method Chaining (Fluent API) ────────────────────────────────

    #[Test]
    public function fluentApiChaining(): void
    {
        $dom = new DOM('field', 'myField');
        $html = $dom
            ->setTag('div')
            ->addClass('container')
            ->addClass(['active', 'visible'])
            ->setAttribute('title', 'My Div')
            ->setAttribute('role', 'main')
            ->setDataset('id', '123')
            ->setName('wrapper')
            ->setText('Content here')
            ->saveHTML();

        $this->assertStringContainsString('<div', $html);
        $this->assertStringContainsString('class="container active visible"', $html);
        $this->assertStringContainsString('title="My Div"', $html);
        $this->assertStringContainsString('role="main"', $html);
        $this->assertStringContainsString('data-id="123"', $html);
        $this->assertStringContainsString('name="wrapper"', $html);
        $this->assertStringContainsString('Content here', $html);
        $this->assertStringContainsString('</div>', $html);
    }

    // ─── Nested DOM Structures ───────────────────────────────────────

    #[Test]
    public function nestedDomStructure(): void
    {
        $nav = new DOM();
        $nav->setTag('nav')->addClass('navbar');

        $ul = new DOM();
        $ul->setTag('ul')->addClass('nav-list');

        foreach (['Home', 'About', 'Contact'] as $label) {
            $li = new DOM();
            $li->setTag('li')->addClass('nav-item');
            $a = new DOM();
            $a->setTag('a')->setAttribute('href', '#')->setText($label);
            $li->append($a);
            $ul->append($li);
        }

        $nav->append($ul);
        $html = $nav->saveHTML();

        $this->assertStringContainsString('<nav', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('<a href="#"', $html);
        $this->assertStringContainsString('Home', $html);
        $this->assertStringContainsString('About', $html);
        $this->assertStringContainsString('Contact', $html);
        $this->assertStringContainsString('</nav>', $html);
    }

    #[Test]
    public function deeplyNestedStructure(): void
    {
        $outer = new DOM();
        $outer->setTag('div')->addClass('level-1');

        $middle = new DOM();
        $middle->setTag('div')->addClass('level-2');

        $inner = new DOM();
        $inner->setTag('span')->addClass('level-3')->setText('Deep');

        $middle->append($inner);
        $outer->append($middle);

        $html = $outer->saveHTML();
        $this->assertSame(
            '<div class="level-1"><div class="level-2"><span class="level-3">Deep</span></div></div>',
            $html,
        );
    }

    // ─── HTML Output Order ───────────────────────────────────────────

    #[Test]
    public function htmlOutputContainsAllParts(): void
    {
        $dom = new DOM('n', 'i');
        $dom->setTag('div')
            ->setAttribute('style', 'color:red')
            ->setDataset('x', 'y')
            ->addClass('cls');

        $html = $dom->saveHTML();
        // Verify name and id appear before custom attributes
        $namePos = \strpos($html, 'name="n"');
        $idPos = \strpos($html, 'id="i"');
        $stylePos = \strpos($html, 'style=');
        $dataPos = \strpos($html, 'data-x=');
        $classPos = \strpos($html, 'class=');

        $this->assertNotFalse($namePos);
        $this->assertNotFalse($idPos);
        $this->assertLessThan($stylePos, $namePos);
        $this->assertLessThan($stylePos, $idPos);
        $this->assertLessThan($dataPos, $stylePos);
        $this->assertLessThan($classPos, $dataPos);
    }

    // ─── Attribute Value Escaping ────────────────────────────────────

    #[Test]
    public function attributeValuesAreEscaped(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setAttribute('title', 'He said "hello" & <goodbye>');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&lt;', $html);
        $this->assertStringContainsString('&gt;', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    // ─── Select Element Tests ────────────────────────────────────────

    #[Test]
    public function selectConstructorSetsTag(): void
    {
        $select = new Select('mySelect');
        $this->assertSame('select', $select->getTag());
        $this->assertInstanceOf(DOM::class, $select);
    }

    #[Test]
    public function selectAddOptionReturnsOptionDom(): void
    {
        $select = new Select();
        $option = $select->addOption('Apple', 'apple');
        $this->assertInstanceOf(DOM::class, $option);
        $this->assertSame('option', $option->getTag());
        $html = $select->saveHTML();
        $this->assertStringContainsString('<option', $html);
        $this->assertStringContainsString('value="apple"', $html);
        $this->assertStringContainsString('Apple', $html);
    }

    #[Test]
    public function selectMultipleOptions(): void
    {
        $select = new Select();
        $select->addOption('One', '1');
        $select->addOption('Two', '2');
        $select->addOption('Three', '3');
        $html = $select->saveHTML();
        $this->assertSame(3, \substr_count($html, '<option'));
        $this->assertSame(3, \substr_count($html, '</option>'));
    }

    #[Test]
    public function selectApplyOptionsFromArray(): void
    {
        $select = new Select();
        $select->applyOptions([
            'us' => 'United States',
            'uk' => 'United Kingdom',
            'de' => 'Germany',
        ]);
        $html = $select->saveHTML();
        $this->assertStringContainsString('value="us"', $html);
        $this->assertStringContainsString('United States', $html);
        $this->assertStringContainsString('value="uk"', $html);
        $this->assertStringContainsString('United Kingdom', $html);
        $this->assertStringContainsString('value="de"', $html);
        $this->assertStringContainsString('Germany', $html);
    }

    #[Test]
    public function selectApplyOptionsWithConvertor(): void
    {
        $select = new Select();
        $select->applyOptions(
            ['a' => ['label' => 'Alpha', 'val' => '1']],
            function (DOM $option, string $key, array $value) {
                $option->setText($value['label'])->setAttribute('value', $value['val']);
            },
        );
        $html = $select->saveHTML();
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('value="1"', $html);
    }

    #[Test]
    public function selectApplyOptionsThrowsOnNonStringValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $select = new Select();
        $select->applyOptions(['key' => 123]);
    }

    #[Test]
    public function selectSetAndGetValue(): void
    {
        $select = new Select();
        $select->addOption('Apple', 'apple');
        $select->addOption('Banana', 'banana');
        $select->addOption('Cherry', 'cherry');
        $select->setValue('banana');

        $html = $select->saveHTML();
        $this->assertStringContainsString('selected="selected"', $html);

        // Verify only banana is selected
        $this->assertSame(1, \substr_count($html, 'selected="selected"'));
    }

    #[Test]
    public function selectGetValueReturnsNullWhenNoSelection(): void
    {
        $select = new Select();
        $select->addOption('Apple', 'apple');
        $this->assertNull($select->getValue());
    }

    #[Test]
    public function selectSetValueDeselectsPrevious(): void
    {
        $select = new Select();
        $select->addOption('A', 'a');
        $select->addOption('B', 'b');

        $select->setValue('a');
        $this->assertSame(1, \substr_count($select->saveHTML(), 'selected="selected"'));

        $select->setValue('b');
        $html = $select->saveHTML();
        $this->assertSame(1, \substr_count($html, 'selected="selected"'));
    }

    #[Test]
    public function selectIsMultipleEnable(): void
    {
        $select = new Select();
        $result = $select->isMultiple(true);
        $this->assertSame($select, $result);
        $this->assertStringContainsString('multiple="multiple"', $select->saveHTML());
    }

    #[Test]
    public function selectIsMultipleDisable(): void
    {
        $select = new Select();
        $select->isMultiple(true);
        $select->isMultiple(false);
        $this->assertStringNotContainsString('multiple', $select->saveHTML());
    }

    #[Test]
    public function selectIdAppearsInHtml(): void
    {
        $select = new Select('mySelectId');
        $html = $select->saveHTML();
        $this->assertStringContainsString('id="mySelectId"', $html);
    }

    // ─── Edge Cases ──────────────────────────────────────────────────

    #[Test]
    public function emptyNameAndIdOmittedFromOutput(): void
    {
        $dom = new DOM();
        $dom->setTag('div');
        $html = $dom->saveHTML();
        $this->assertStringNotContainsString('name=', $html);
        $this->assertStringNotContainsString('id=', $html);
    }

    #[Test]
    public function noAttributesNoClassesMinimalOutput(): void
    {
        $dom = new DOM();
        $dom->setTag('div');
        $this->assertSame('<div></div>', $dom->saveHTML());
    }

    #[Test]
    public function emptyAttributeNameIsIgnored(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setAttribute('', 'value');
        $this->assertSame('<div></div>', $dom->saveHTML());
    }

    #[Test]
    public function emptyClassNameIsIgnored(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->addClass('');
        $this->assertStringNotContainsString('class=', $dom->saveHTML());
    }

    #[Test]
    public function emptyDatasetNameIsIgnored(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setDataset('', 'value');
        // Should not contain a bare data- attribute
        $this->assertStringNotContainsString('data-=', $dom->saveHTML());
    }

    #[Test]
    public function multipleTextSetsOverwriteLastTextNode(): void
    {
        $dom = new DOM();
        $dom->setTag('p')->setText('First')->setText('Second')->setText('Third');
        $this->assertSame('<p>Third</p>', $dom->saveHTML());
    }

    #[Test]
    public function mixedChildrenAndTextNodes(): void
    {
        $dom = new DOM();
        $dom->setTag('div');

        $child = new DOM();
        $child->setTag('strong')->setText('bold');

        $dom->setText('before ')->append($child)->setText(' after');
        $html = $dom->saveHTML();
        $this->assertStringContainsString('before ', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString(' after', $html);
    }

    #[Test]
    public function complexDatasetValueIsJsonEncoded(): void
    {
        $dom = new DOM();
        $dom->setTag('div')->setDataset('config', ['nested' => true, 'count' => 3]);
        $html = $dom->saveHTML();
        $this->assertStringContainsString('data-config=', $html);
        // JSON values are HTML-escaped
        $this->assertStringContainsString('&quot;', $html);
    }

    #[Test]
    public function removeClassReturnsSelf(): void
    {
        $dom = new DOM();
        $result = $dom->setTag('div')->removeClass('anything');
        $this->assertSame($dom, $result);
    }

    #[Test]
    public function setVoidElementReturnsSelf(): void
    {
        $dom = new DOM();
        $result = $dom->setVoidElement(true);
        $this->assertSame($dom, $result);
    }

    #[Test]
    public function selectAddOptionDefaultEmptyValues(): void
    {
        $select = new Select();
        $option = $select->addOption();
        $html = $select->saveHTML();
        $this->assertStringContainsString('<option value="">', $html);
    }

    #[Test]
    public function voidElementIgnoresChildren(): void
    {
        $dom = new DOM();
        $dom->setTag('br')->setVoidElement(true);
        $child = new DOM();
        $child->setTag('span')->setText('hidden');
        $dom->append($child);
        $html = $dom->saveHTML();
        // Void elements should self-close and not render children
        $this->assertStringNotContainsString('<span>', $html);
        $this->assertStringEndsWith('/>', $html);
    }

    #[Test]
    public function selectInheritsFromDom(): void
    {
        $select = new Select();
        $select->addClass('form-select')->setAttribute('required', null);
        $html = $select->saveHTML();
        $this->assertStringContainsString('class="form-select"', $html);
        $this->assertStringContainsString(' required', $html);
    }
}
