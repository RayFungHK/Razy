<?php

/**
 * Unit tests for Razy\Template.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Template;
use Razy\Template\Source;

#[CoversClass(Template::class)]
class TemplateTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = \sys_get_temp_dir() . '/razy-template-test-' . \uniqid();
        if (!\is_dir($this->tempDir)) {
            \mkdir($this->tempDir, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (\is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    // ==================== BASIC FUNCTIONALITY ====================

    public function testConstructor(): void
    {
        $template = new Template();
        $this->assertInstanceOf(Template::class, $template);
    }

    public function testLoadSimpleTemplate(): void
    {
        $file = $this->tempDir . '/simple.tpl';
        \file_put_contents($file, 'Hello, World!');

        $template = new Template();
        $source = $template->load($file);

        $this->assertInstanceOf(Source::class, $source);
    }

    // ==================== PARAMETER PARSING ====================

    public function testParseContentSimpleParameter(): void
    {
        $content = 'Hello, {$name}!';
        $params = ['name' => 'John'];

        $result = Template::ParseContent($content, $params);

        $this->assertEquals('Hello, John!', $result);
    }

    public function testParseContentMultipleParameters(): void
    {
        $content = '{$greeting}, {$name}! You have {$count} messages.';
        $params = [
            'greeting' => 'Hello',
            'name' => 'Alice',
            'count' => 5,
        ];

        $result = Template::ParseContent($content, $params);

        $this->assertEquals('Hello, Alice! You have 5 messages.', $result);
    }

    public function testParseContentNestedParameter(): void
    {
        $content = 'User: {$user.name}, Email: {$user.email}';
        $params = [
            'user' => [
                'name' => 'Bob',
                'email' => 'bob@example.com',
            ],
        ];

        $result = Template::ParseContent($content, $params);

        $this->assertEquals('User: Bob, Email: bob@example.com', $result);
    }

    public function testParseContentObjectProperty(): void
    {
        $content = 'Product: {$product.name}, Price: {$product.price}';

        $product = new class() {
            public string $name = 'iPhone';

            public int $price = 999;
        };

        $result = Template::ParseContent($content, ['product' => $product]);

        $this->assertEquals('Product: iPhone, Price: 999', $result);
    }

    public function testParseContentMissingParameter(): void
    {
        $content = 'Hello, {$name}!';
        $params = [];

        $result = Template::ParseContent($content, $params);

        $this->assertEquals('Hello, !', $result);
    }

    // ==================== VALUE BY PATH ====================

    public function testGetValueByPathSimple(): void
    {
        $value = Template::GetValueByPath('test', '');
        $this->assertEquals('test', $value);
    }

    public function testGetValueByPathNested(): void
    {
        $data = [
            'user' => [
                'profile' => [
                    'name' => 'John',
                ],
            ],
        ];

        $value = Template::GetValueByPath($data, '.user.profile.name');
        $this->assertEquals('John', $value);
    }

    public function testGetValueByPathObject(): void
    {
        $obj = new class() {
            public string $name = 'Test';

            public array $data = ['key' => 'value'];
        };

        $this->assertEquals('Test', Template::GetValueByPath($obj, '.name'));
        $this->assertEquals('value', Template::GetValueByPath($obj, '.data.key'));
    }

    public function testGetValueByPathInvalid(): void
    {
        $data = ['key' => 'value'];

        $value = Template::GetValueByPath($data, '.nonexistent');
        $this->assertNull($value);
    }

    // ==================== ASSIGN & BIND ====================

    public function testAssignSingleParameter(): void
    {
        $template = new Template();
        $template->assign('name', 'Alice');

        $this->assertEquals('Alice', $template->getValue('name'));
    }

    public function testAssignMultipleParameters(): void
    {
        $template = new Template();
        $template->assign([
            'name' => 'Bob',
            'age' => 30,
            'active' => true,
        ]);

        $this->assertEquals('Bob', $template->getValue('name'));
        $this->assertEquals(30, $template->getValue('age'));
        $this->assertTrue($template->getValue('active'));
    }

    public function testAssignClosure(): void
    {
        $template = new Template();
        $template->assign('counter', 0);
        $template->assign('counter', fn ($current) => ($current ?? 0) + 1);

        $this->assertEquals(1, $template->getValue('counter'));
    }

    public function testBindReference(): void
    {
        $template = new Template();
        $value = 'initial';

        $template->bind('ref', $value);
        $this->assertEquals('initial', $template->getValue('ref'));

        // bind() stores a reference pointer; subsequent changes to $value
        // ARE reflected — the value is deferred until render time
        $value = 'changed';
        $this->assertEquals('changed', $template->getValue('ref'));
    }

    public function testAssignChaining(): void
    {
        $template = new Template();
        $result = $template
            ->assign('a', 1)
            ->assign('b', 2)
            ->assign('c', 3);

        $this->assertInstanceOf(Template::class, $result);
        $this->assertEquals(1, $template->getValue('a'));
        $this->assertEquals(2, $template->getValue('b'));
        $this->assertEquals(3, $template->getValue('c'));
    }

    // ==================== TEMPLATE LOADING ====================

    public function testLoadTemplateWithParameters(): void
    {
        $file = $this->tempDir . '/param.tpl';
        \file_put_contents($file, 'Hello, {$name}!');

        $template = new Template();
        $source = $template->load($file);
        $template->assign('name', 'World');

        $output = $source->output();

        $this->assertStringContainsString('World', $output);
    }

    public function testLoadMultipleSources(): void
    {
        $file1 = $this->tempDir . '/first.tpl';
        $file2 = $this->tempDir . '/second.tpl';

        \file_put_contents($file1, 'First template');
        \file_put_contents($file2, 'Second template');

        $template = new Template();
        $source1 = $template->load($file1);
        $source2 = $template->load($file2);

        $this->assertInstanceOf(Source::class, $source1);
        $this->assertInstanceOf(Source::class, $source2);
        $this->assertNotEquals($source1->getID(), $source2->getID());
    }

    public function testStaticLoadFile(): void
    {
        $file = $this->tempDir . '/static.tpl';
        \file_put_contents($file, 'Static load test');

        $source = Template::loadFile($file);

        $this->assertInstanceOf(Source::class, $source);
    }

    // ==================== QUEUE SYSTEM ====================

    public function testAddQueue(): void
    {
        $file = $this->tempDir . '/queue.tpl';
        \file_put_contents($file, 'Queued content');

        $template = new Template();
        $source = $template->load($file);
        $result = $template->addQueue($source, 'test-queue');

        $this->assertInstanceOf(Template::class, $result);
    }

    public function testOutputQueued(): void
    {
        $file1 = $this->tempDir . '/queue1.tpl';
        $file2 = $this->tempDir . '/queue2.tpl';

        \file_put_contents($file1, 'First');
        \file_put_contents($file2, 'Second');

        $template = new Template();
        $source1 = $template->load($file1);
        $source2 = $template->load($file2);

        $template->addQueue($source1, 'q1');
        $template->addQueue($source2, 'q2');

        $output = $template->outputQueued(['q1', 'q2']);

        $this->assertStringContainsString('First', $output);
        $this->assertStringContainsString('Second', $output);
    }

    // ==================== GLOBAL TEMPLATES ====================

    public function testLoadGlobalTemplate(): void
    {
        $file = $this->tempDir . '/global.tpl';
        \file_put_contents($file, 'Global template');

        $template = new Template();
        $template->loadTemplate('mytemplate', $file);

        $block = $template->getTemplate('mytemplate');
        $this->assertNotNull($block);
    }

    public function testLoadGlobalTemplateArray(): void
    {
        $file1 = $this->tempDir . '/tpl1.tpl';
        $file2 = $this->tempDir . '/tpl2.tpl';

        \file_put_contents($file1, 'Template 1');
        \file_put_contents($file2, 'Template 2');

        $template = new Template();
        $template->loadTemplate([
            $file1 => 'first',
            $file2 => 'second',
        ]);

        $this->assertNotNull($template->getTemplate('first'));
        $this->assertNotNull($template->getTemplate('second'));
    }

    public function testGetTemplateNonExistent(): void
    {
        $template = new Template();
        $block = $template->getTemplate('nonexistent');

        $this->assertNull($block);
    }

    // ==================== INSERT SOURCE ====================

    public function testInsertSource(): void
    {
        $file = $this->tempDir . '/external.tpl';
        \file_put_contents($file, 'External source');

        $template = new Template();
        $source = new Source($file, new Template());

        $result = $template->insert($source);

        $this->assertInstanceOf(Template::class, $result);
    }

    // ==================== DATA TYPES ====================

    public function testParseContentBoolean(): void
    {
        $content = 'Active: {$active}, Inactive: {$inactive}';
        $params = [
            'active' => true,
            'inactive' => false,
        ];

        $result = Template::ParseContent($content, $params);

        // PHP casts true to '1' and false to '' (empty string)
        $this->assertEquals('Active: 1, Inactive: ', $result);
    }

    public function testParseContentNumeric(): void
    {
        $content = 'Int: {$int}, Float: {$float}';
        $params = [
            'int' => 42,
            'float' => 3.14,
        ];

        $result = Template::ParseContent($content, $params);

        $this->assertStringContainsString('42', $result);
        $this->assertStringContainsString('3.14', $result);
    }

    public function testParseContentArray(): void
    {
        $content = 'First: {$items.0}, Second: {$items.1}';
        $params = [
            'items' => ['apple', 'banana'],
        ];

        $result = Template::ParseContent($content, $params);

        $this->assertStringContainsString('apple', $result);
        $this->assertStringContainsString('banana', $result);
    }

    // ==================== EDGE CASES ====================

    public function testParseContentEmptyString(): void
    {
        $result = Template::ParseContent('', ['key' => 'value']);
        $this->assertEquals('', $result);
    }

    public function testParseContentNoParameters(): void
    {
        $content = 'Static content only';
        $result = Template::ParseContent($content, []);

        $this->assertEquals('Static content only', $result);
    }

    public function testGetValueNull(): void
    {
        $template = new Template();
        $value = $template->getValue('nonexistent');

        $this->assertNull($value);
    }

    public function testDeepNestedPath(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => 'deep value',
                    ],
                ],
            ],
        ];

        $value = Template::GetValueByPath($data, '.level1.level2.level3.level4');
        $this->assertEquals('deep value', $value);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $files = \array_diff(\scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            \is_dir($path) ? $this->deleteDirectory($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
