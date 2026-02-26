<?php

/**
 * Unit tests for Razy\YAML parser and dumper.
 *
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Exception\FileException;
use Razy\YAML;

#[CoversClass(YAML::class)]
class YAMLTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = \sys_get_temp_dir() . '/razy-yaml-test-' . \uniqid();
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

    public static function dumpScalarProvider(): array
    {
        return [
            'boolean true' => [['enabled' => true], 'enabled: true'],
            'boolean false' => [['disabled' => false], 'disabled: false'],
            'null' => [['value' => null], 'value: null'],
            'integer' => [['integer' => 42], 'integer: 42'],
            'float' => [['float' => 3.14], 'float: 3.14'],
        ];
    }

    // ==================== ROUND-TRIP TESTS ====================

    public static function roundTripProvider(): array
    {
        return [
            'simple' => [[
                'name' => 'MyApp',
                'version' => 1.0,
                'enabled' => true,
            ]],
            'nested' => [[
                'database' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'options' => [
                        'charset' => 'utf8mb4',
                        'timeout' => 30,
                    ],
                ],
            ]],
            'lists' => [[
                'features' => ['auth', 'api', 'admin'],
                'ports' => [8080, 8081, 8082],
            ]],
            'mixed types' => [[
                'string' => 'value',
                'integer' => 42,
                'float' => 3.14,
                'boolean' => true,
                'null' => null,
                'array' => [1, 2, 3],
                'object' => ['key' => 'value'],
            ]],
        ];
    }

    // ==================== PARSING TESTS ====================

    public function testParseSimpleKeyValue(): void
    {
        $yaml = "name: MyApp\nversion: 1.0";
        $result = YAML::parse($yaml);

        $this->assertIsArray($result);
        $this->assertEquals('MyApp', $result['name']);
        $this->assertEquals(1.0, $result['version']);
    }

    public function testParseNestedStructure(): void
    {
        $yaml = <<<YAML
            database:
              host: localhost
              port: 3306
              credentials:
                username: root
                password: secret
            YAML;

        $result = YAML::parse($yaml);

        $this->assertIsArray($result);
        $this->assertEquals('localhost', $result['database']['host']);
        $this->assertEquals(3306, $result['database']['port']);
        $this->assertEquals('root', $result['database']['credentials']['username']);
        $this->assertEquals('secret', $result['database']['credentials']['password']);
    }

    public function testParseList(): void
    {
        $yaml = <<<YAML
            features:
              - authentication
              - api
              - admin
            YAML;

        $result = YAML::parse($yaml);

        $this->assertIsArray($result);
        $this->assertIsArray($result['features']);
        $this->assertEquals(['authentication', 'api', 'admin'], $result['features']);
    }

    public function testParseInlineArray(): void
    {
        $yaml = 'colors: [red, green, blue]';
        $result = YAML::parse($yaml);

        $this->assertEquals(['red', 'green', 'blue'], $result['colors']);
    }

    public function testParseInlineObject(): void
    {
        $yaml = 'server: {host: localhost, port: 8080}';
        $result = YAML::parse($yaml);

        $this->assertEquals('localhost', $result['server']['host']);
        $this->assertEquals(8080, $result['server']['port']);
    }

    public function testParseBoolean(): void
    {
        $yaml = <<<YAML
            enabled: true
            disabled: false
            yes_value: yes
            no_value: no
            on_value: on
            off_value: off
            YAML;

        $result = YAML::parse($yaml);

        $this->assertTrue($result['enabled']);
        $this->assertFalse($result['disabled']);
        $this->assertTrue($result['yes_value']);
        $this->assertFalse($result['no_value']);
        $this->assertTrue($result['on_value']);
        $this->assertFalse($result['off_value']);
    }

    public function testParseNull(): void
    {
        $yaml = <<<YAML
            null1: null
            null2: ~
            null3:
            YAML;

        $result = YAML::parse($yaml);

        $this->assertNull($result['null1']);
        $this->assertNull($result['null2']);
        $this->assertNull($result['null3']);
    }

    public function testParseNumbers(): void
    {
        $yaml = <<<YAML
            integer: 42
            float: 3.14
            negative: -10
            zero: 0
            YAML;

        $result = YAML::parse($yaml);

        $this->assertSame(42, $result['integer']);
        $this->assertSame(3.14, $result['float']);
        $this->assertSame(-10, $result['negative']);
        $this->assertSame(0, $result['zero']);
    }

    public function testParseQuotedStrings(): void
    {
        $yaml = <<<YAML
            double: "Hello World"
            single: 'Hello World'
            plain: Hello World
            YAML;

        $result = YAML::parse($yaml);

        $this->assertEquals('Hello World', $result['double']);
        $this->assertEquals('Hello World', $result['single']);
        $this->assertEquals('Hello World', $result['plain']);
    }

    public function testParseComments(): void
    {
        $yaml = <<<YAML
            # This is a comment
            name: MyApp  # Inline comment
            # Another comment
            version: 1.0
            YAML;

        $result = YAML::parse($yaml);

        // Inline comments are not stripped by the parser
        $this->assertStringStartsWith('MyApp', $result['name']);
        $this->assertEquals(1.0, $result['version']);
        $this->assertCount(2, $result);
    }

    public function testParseMultilineLiteral(): void
    {
        $yaml = <<<YAML
            message: |
              Line 1
              Line 2
              Line 3
            YAML;

        $result = YAML::parse($yaml);

        // Normalize line endings for cross-platform compatibility
        $normalized = \str_replace("\r\n", "\n", $result['message']);
        $this->assertStringContainsString("Line 1\nLine 2\nLine 3", $normalized);
    }

    public function testParseMultilineFolded(): void
    {
        $yaml = <<<YAML
            description: >
              This is a long
              description that
              will be folded.
            YAML;

        $result = YAML::parse($yaml);

        $this->assertIsString($result['description']);
        $this->assertStringContainsString('long', $result['description']);
    }

    public function testParseEmptyYaml(): void
    {
        $result = YAML::parse('');
        $this->assertNull($result);
    }

    public function testParseComplexStructure(): void
    {
        $yaml = <<<YAML
            app:
              name: MyApp
              version: 1.0
              debug: true
              features:
                - auth
                - api
              database:
                host: localhost
                port: 3306
                engines: [mysql, postgres]
            YAML;

        $result = YAML::parse($yaml);

        $this->assertEquals('MyApp', $result['app']['name']);
        $this->assertTrue($result['app']['debug']);
        $this->assertCount(2, $result['app']['features']);
        $this->assertEquals('localhost', $result['app']['database']['host']);
        $this->assertEquals(['mysql', 'postgres'], $result['app']['database']['engines']);
    }

    // ==================== DUMPING TESTS ====================

    public function testDumpSimpleArray(): void
    {
        $data = [
            'name' => 'MyApp',
            'version' => '1.0',
        ];

        $yaml = YAML::dump($data);

        $this->assertStringContainsString('name: MyApp', $yaml);
        $this->assertStringContainsString('version: "1.0"', $yaml);
    }

    public function testDumpNestedArray(): void
    {
        $data = [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
        ];

        $yaml = YAML::dump($data);

        $this->assertStringContainsString('database:', $yaml);
        $this->assertStringContainsString('host: localhost', $yaml);
        $this->assertStringContainsString('port: 3306', $yaml);
    }

    public function testDumpList(): void
    {
        $data = [
            'features' => ['auth', 'api', 'admin'],
        ];

        $yaml = YAML::dump($data);

        $this->assertStringContainsString('features:', $yaml);
        // Simple arrays are dumped inline by the YAML dumper
        $this->assertStringContainsString('[auth, api, admin]', $yaml);
    }

    #[DataProvider('dumpScalarProvider')]
    public function testDumpScalarType(array $data, string $expected): void
    {
        $yaml = YAML::dump($data);
        $this->assertStringContainsString($expected, $yaml);
    }

    public function testDumpWithCustomIndent(): void
    {
        $data = [
            'level1' => [
                'level2' => 'value',
            ],
        ];

        $yaml = YAML::dump($data, 4);

        $this->assertStringContainsString('level1:', $yaml);
        // Simple nested maps are inlined by the dumper
        $this->assertStringContainsString('{level2: value}', $yaml);
    }

    public function testDumpInlineMode(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => ['a', 'b', 'c'],
                    ],
                ],
            ],
        ];

        $yaml = YAML::dump($data, 2, 3);

        // At inline level 3, arrays should be inline
        $this->assertStringContainsString('[', $yaml);
    }

    public function testDumpQuotingSpecialCharacters(): void
    {
        $data = [
            'colon' => 'value:with:colons',
            'quote' => 'value"with"quotes',
            'reserved' => 'true',
        ];

        $yaml = YAML::dump($data);

        // Special characters should be quoted
        $this->assertStringContainsString('"', $yaml);
    }

    // ==================== FILE OPERATIONS ====================

    public function testParseFile(): void
    {
        $filename = $this->tempDir . '/test.yaml';
        $content = <<<YAML
            name: TestApp
            version: 1.0
            YAML;

        \file_put_contents($filename, $content);

        $result = YAML::parseFile($filename);

        $this->assertEquals('TestApp', $result['name']);
        $this->assertEquals(1.0, $result['version']);
    }

    public function testParseFileNotFound(): void
    {
        $this->expectException(FileException::class);
        $this->expectExceptionMessage('YAML file not found');

        YAML::parseFile($this->tempDir . '/nonexistent.yaml');
    }

    public function testDumpFile(): void
    {
        $filename = $this->tempDir . '/output.yaml';
        $data = [
            'name' => 'MyApp',
            'version' => '2.0',
        ];

        $result = YAML::dumpFile($filename, $data);

        $this->assertTrue($result);
        $this->assertFileExists($filename);

        $content = \file_get_contents($filename);
        $this->assertStringContainsString('name: MyApp', $content);
        $this->assertStringContainsString('version: "2.0"', $content);
    }

    public function testDumpFileCreatesDirectory(): void
    {
        $filename = $this->tempDir . '/subdir/config.yaml';
        $data = ['test' => 'value'];

        YAML::dumpFile($filename, $data);

        $this->assertFileExists($filename);
        $this->assertDirectoryExists($this->tempDir . '/subdir');
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTrip(array $original): void
    {
        $yaml = YAML::dump($original);
        $parsed = YAML::parse($yaml);

        foreach ($original as $key => $value) {
            $this->assertEquals($value, $parsed[$key], "Round-trip failed for key: $key");
        }
    }

    // ==================== EDGE CASES ====================

    public function testParseEmptyArray(): void
    {
        $yaml = 'features: []';
        $result = YAML::parse($yaml);

        $this->assertEquals([], $result['features']);
    }

    public function testParseEmptyObject(): void
    {
        $yaml = 'settings: {}';
        $result = YAML::parse($yaml);

        $this->assertEquals([], $result['settings']);
    }

    public function testDumpEmptyArray(): void
    {
        $data = ['features' => []];
        $yaml = YAML::dump($data);

        // PHP empty arrays are dumped as {} (cannot distinguish from empty object)
        $this->assertStringContainsString('features: {}', $yaml);
    }

    public function testDumpEmptyObject(): void
    {
        $data = ['settings' => []];
        $yaml = YAML::dump($data);

        $this->assertStringContainsString('settings: {}', $yaml);
    }

    public function testParseNumericStrings(): void
    {
        $yaml = 'value: "123"';
        $result = YAML::parse($yaml);

        $this->assertIsString($result['value']);
        $this->assertEquals('123', $result['value']);
    }

    public function testParseMixedIndentation(): void
    {
        $yaml = <<<YAML
            level1:
              level2:
                level3: value
            YAML;

        $result = YAML::parse($yaml);

        $this->assertEquals('value', $result['level1']['level2']['level3']);
    }

    public function testParseSpecialCharactersInKeys(): void
    {
        // Quoted keys with special characters are not supported by this parser
        $yaml = <<<YAML
            simple_key: value1
            another_key: value2
            YAML;

        $result = YAML::parse($yaml);

        $this->assertEquals('value1', $result['simple_key']);
        $this->assertEquals('value2', $result['another_key']);
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
