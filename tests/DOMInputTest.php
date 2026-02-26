<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\DOM;
use Razy\DOM\Input;

#[CoversClass(Input::class)]
#[CoversClass(DOM::class)]
class DOMInputTest extends TestCase
{
    #[Test]
    public function constructCreatesInputElement(): void
    {
        $input = new Input();
        $html = $input->saveHTML();
        $this->assertStringContainsString('<input', $html);
    }

    #[Test]
    public function constructWithId(): void
    {
        $input = new Input('email-field');
        $html = $input->saveHTML();
        $this->assertStringContainsString('id="email-field"', $html);
    }

    #[Test]
    public function setTypeAddsTypeAttribute(): void
    {
        $input = new Input();
        $result = $input->setType('email');
        $this->assertSame($input, $result);
        $this->assertStringContainsString('type="email"', $input->saveHTML());
    }

    #[Test]
    public function setNameAddsNameAttribute(): void
    {
        $input = new Input();
        $input->setName('username');
        $this->assertStringContainsString('name="username"', $input->saveHTML());
    }

    #[Test]
    public function setValueAddsValueAttribute(): void
    {
        $input = new Input();
        $input->setValue('hello');
        $this->assertStringContainsString('value="hello"', $input->saveHTML());
    }

    #[Test]
    public function setPlaceholderAddsPlaceholderAttribute(): void
    {
        $input = new Input();
        $input->setPlaceholder('Enter text...');
        $this->assertStringContainsString('placeholder="Enter text..."', $input->saveHTML());
    }

    #[Test]
    public function fluentInterface(): void
    {
        $input = new Input('my-input');
        $html = $input->setType('text')
            ->setName('field')
            ->setValue('val')
            ->setPlaceholder('hint')
            ->saveHTML();

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="field"', $html);
        $this->assertStringContainsString('value="val"', $html);
        $this->assertStringContainsString('placeholder="hint"', $html);
    }

    #[Test]
    public function isVoidElement(): void
    {
        $input = new Input();
        $html = $input->saveHTML();
        // Void element should not have a closing tag
        $this->assertStringNotContainsString('</input>', $html);
    }

    #[Test]
    public function setNameCastsToString(): void
    {
        $input = new Input();
        $input->setName(42);
        $this->assertStringContainsString('name="42"', $input->saveHTML());
    }

    #[Test]
    public function multipleTypeChanges(): void
    {
        $input = new Input();
        $input->setType('text');
        $input->setType('password');
        $html = $input->saveHTML();
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringNotContainsString('type="text"', $html);
    }
}
