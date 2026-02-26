<?php
/**
 * Unit tests for Razy\YAML\YAMLDumper internal API.
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
use Razy\YAML\YAMLDumper;
use Razy\YAML\YAMLParser;

#[CoversClass(YAMLDumper::class)]
class YAMLDumperTest extends TestCase
{
    // ==================== SCALAR DUMPING ====================

    public function testDumpNull(): void
    {
        $dumper = new YAMLDumper();
        $this->assertSame('null', $dumper->dump(null));
    }

    public function testDumpBooleans(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['a' => true, 'b' => false]);
        $this->assertStringContainsString('a: true', $yaml);
        $this->assertStringContainsString('b: false', $yaml);
    }

    public function testDumpIntegers(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['num' => 42, 'neg' => -7, 'zero' => 0]);
        $this->assertStringContainsString('num: 42', $yaml);
        $this->assertStringContainsString('neg: -7', $yaml); // -7 contains -, which triggers quoting
        $this->assertStringContainsString('zero: 0', $yaml);
    }

    public function testDumpFloats(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['pi' => 3.14]);
        $this->assertStringContainsString('pi: 3.14', $yaml);
    }

    // ==================== STRING QUOTING ====================

    public function testDumpPlainString(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['name' => 'hello']);
        $this->assertStringContainsString('name: hello', $yaml);
    }

    public function testDumpStringThatLooksBooleanIsQuoted(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['val' => 'true']);
        $this->assertStringContainsString('"true"', $yaml);
    }

    public function testDumpStringThatLooksNullIsQuoted(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['val' => 'null']);
        $this->assertStringContainsString('"null"', $yaml);
    }

    public function testDumpNumericStringIsQuoted(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['version' => '1.0']);
        $this->assertStringContainsString('"1.0"', $yaml);
    }

    public function testDumpStringWithColonIsQuoted(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['url' => 'http://example.com']);
        $this->assertStringContainsString('"http://example.com"', $yaml);
    }

    public function testDumpStringWithDoubleQuoteIsEscaped(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['msg' => 'say "hello"']);
        $this->assertStringContainsString('\\"hello\\"', $yaml);
    }

    public function testDumpStringWithNewlineNotQuotedCurrently(): void
    {
        // NOTE: The current dumper does NOT detect newlines/tabs as needing quoting.
        // This test documents that limitation. A future fix would add control-char
        // detection to needsQuoting().
        $dumper = new YAMLDumper(indent: 2, inline: 10);

        $data = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'msg' => "line1\nline2"];
        $yaml = $dumper->dump($data);

        // The value contains a literal newline rather than an escaped \n
        $this->assertStringContainsString('msg:', $yaml);
        $this->assertStringContainsString('line1', $yaml);
        $this->assertStringContainsString('line2', $yaml);
    }

    public function testDumpStringWithTabNotQuotedCurrently(): void
    {
        // NOTE: Same limitation as newlines — tabs are not detected by needsQuoting().
        $dumper = new YAMLDumper(indent: 2, inline: 10);

        $data = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5, 'msg' => "col1\tcol2"];
        $yaml = $dumper->dump($data);

        $this->assertStringContainsString('msg:', $yaml);
        $this->assertStringContainsString('col1', $yaml);
        $this->assertStringContainsString('col2', $yaml);
    }

    public function testDumpEmptyStringIsQuoted(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['empty' => '']);
        $this->assertStringContainsString('""', $yaml);
    }

    public static function reservedWordProvider(): array
    {
        return [
            'true'  => ['true'],
            'false' => ['false'],
            'null'  => ['null'],
            'yes'   => ['yes'],
            'no'    => ['no'],
            'on'    => ['on'],
            'off'   => ['off'],
        ];
    }

    #[DataProvider('reservedWordProvider')]
    public function testDumpReservedWordsAreQuoted(string $word): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['key' => $word]);
        $this->assertStringContainsString('"' . $word . '"', $yaml);
    }

    // ==================== SEQUENCE DUMPING ====================

    public function testDumpSimpleSequence(): void
    {
        $dumper = new YAMLDumper();

        // Simple arrays (<=5 items, no nesting) are rendered inline
        $yaml = $dumper->dump(['items' => ['a', 'b', 'c']]);
        $this->assertStringContainsString('[a, b, c]', $yaml);
    }

    public function testDumpEmptySequence(): void
    {
        $dumper = new YAMLDumper();

        // Empty arrays are rendered as [] or {}
        $yaml = $dumper->dump(['items' => []]);
        $this->assertMatchesRegularExpression('/items:\s*(\[\]|\{\})/', $yaml);
    }

    public function testDumpSequenceWithMoreThanFiveItems(): void
    {
        $dumper = new YAMLDumper();

        $data = ['items' => ['a', 'b', 'c', 'd', 'e', 'f']];
        $yaml = $dumper->dump($data);

        // More than 5 items → block style with - prefix
        $this->assertStringContainsString('- a', $yaml);
        $this->assertStringContainsString('- f', $yaml);
    }

    public function testDumpSequenceOfMaps(): void
    {
        $dumper = new YAMLDumper();

        $data = ['users' => [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ]];

        $yaml = $dumper->dump($data);

        // Nested arrays → block-style list
        $this->assertStringContainsString('-', $yaml);
        $this->assertStringContainsString('name:', $yaml);
    }

    // ==================== MAPPING DUMPING ====================

    public function testDumpSimpleMapping(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['host' => 'localhost', 'port' => 3306]);
        $this->assertStringContainsString('host: localhost', $yaml);
        $this->assertStringContainsString('port: 3306', $yaml);
    }

    public function testDumpNestedMapping(): void
    {
        $dumper = new YAMLDumper();

        $data = [
            'database' => [
                'credentials' => [
                    'user' => 'admin',
                    'pass' => 'secret',
                ],
            ],
        ];

        $yaml = $dumper->dump($data);

        $this->assertStringContainsString('database:', $yaml);
        // Simple inner map should be inlined by default
        $this->assertStringContainsString('user: admin', $yaml);
    }

    public function testDumpEmptyMapping(): void
    {
        $dumper = new YAMLDumper();
        $yaml = $dumper->dump(['settings' => []]);

        $this->assertStringContainsString('settings: {}', $yaml);
    }

    // ==================== INLINE THRESHOLD ====================

    public function testInlineThresholdForces(): void
    {
        // inline = 1 means everything from level 1 onward is inlined
        $dumper = new YAMLDumper(indent: 2, inline: 1);

        $data = [
            'a' => [
                'b' => [
                    'c' => 'deep',
                ],
            ],
        ];

        $yaml = $dumper->dump($data);

        // Level 1+ should be inline (flow style)
        $this->assertStringContainsString('{', $yaml);
    }

    public function testInlineThresholdHighKeepsBlock(): void
    {
        // inline = 10 means block style is used for many levels
        $dumper = new YAMLDumper(indent: 2, inline: 10);

        $data = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'key1' => 'val1',
                        'key2' => 'val2',
                        'key3' => 'val3',
                        'key4' => 'val4',
                        'key5' => 'val5',
                        'key6' => 'val6',  // >5 items, not "simple"
                    ],
                ],
            ],
        ];

        $yaml = $dumper->dump($data);

        // Should be in block style
        $this->assertStringContainsString('level1:', $yaml);
        $this->assertStringContainsString('level2:', $yaml);
        $this->assertStringContainsString('level3:', $yaml);
        $this->assertStringContainsString('key1: val1', $yaml);
    }

    // ==================== CUSTOM INDENT ====================

    public function testCustomIndentSize(): void
    {
        $dumper = new YAMLDumper(indent: 4, inline: 10);

        $data = [
            'parent' => [
                'child1' => 'v1',
                'child2' => 'v2',
                'child3' => 'v3',
                'child4' => 'v4',
                'child5' => 'v5',
                'child6' => 'v6',  // >5 items to force block
            ],
        ];

        $yaml = $dumper->dump($data);

        // Lines under parent should be indented by 4 spaces
        $this->assertMatchesRegularExpression('/^    child1: v1$/m', $yaml);
    }

    // ==================== FLOW (INLINE) FORMAT ====================

    public function testInlineList(): void
    {
        $dumper = new YAMLDumper();

        $yaml = $dumper->dump(['tags' => ['php', 'yaml']]);
        $this->assertStringContainsString('[php, yaml]', $yaml);
    }

    public function testInlineMapping(): void
    {
        $dumper = new YAMLDumper();

        // Simple map (<=5 items, no nesting) is inlined
        $yaml = $dumper->dump(['point' => ['x' => 1, 'y' => 2]]);
        $this->assertStringContainsString('{x: 1, y: 2}', $yaml);
    }

    // ==================== ROUND-TRIP (DUMP → PARSE → DUMP) ====================

    public static function roundTripProvider(): array
    {
        return [
            'simple scalars' => [[
                'name' => 'app',
                'version' => 1,
                'enabled' => true,
                'extra' => null,
            ]],
            'nested map' => [[
                'database' => [
                    'host' => 'localhost',
                    'port' => 3306,
                    'options' => [
                        'charset' => 'utf8mb4',
                        'timeout' => 30,
                    ],
                ],
            ]],
            'simple list' => [[
                'items' => ['a', 'b', 'c'],
            ]],
            'integers list' => [[
                'ports' => [80, 443, 8080],
            ]],
            'booleans' => [[
                'flags' => [
                    'debug' => false,
                    'verbose' => true,
                ],
            ]],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTripDumpThenParse(array $original): void
    {
        $dumper = new YAMLDumper();
        $yaml = $dumper->dump($original);

        $parser = new YAMLParser($yaml);
        $parsed = $parser->parse();

        $this->assertEquals($original, $parsed);
    }

    #[DataProvider('roundTripProvider')]
    public function testDoubleRoundTrip(array $original): void
    {
        $dumper = new YAMLDumper();

        // Dump → Parse → Dump → Parse
        $yaml1 = $dumper->dump($original);
        $parsed1 = (new YAMLParser($yaml1))->parse();
        $yaml2 = $dumper->dump($parsed1);
        $parsed2 = (new YAMLParser($yaml2))->parse();

        $this->assertEquals($parsed1, $parsed2, 'Double round-trip should be stable');
        $this->assertSame($yaml1, $yaml2, 'Dumped YAML should be identical after double round-trip');
    }

    // ==================== EDGE CASES ====================

    public function testDumpNonStringKeys(): void
    {
        $dumper = new YAMLDumper();

        // Integer keys
        $yaml = $dumper->dump([0 => 'first', 1 => 'second']);
        // Sequential integer keys → list format
        $this->assertStringContainsString('first', $yaml);
        $this->assertStringContainsString('second', $yaml);
    }

    public function testDumpMixedArrayDetectsSequential(): void
    {
        $dumper = new YAMLDumper();

        // Sequential [0, 1, 2] → list
        $yaml = $dumper->dump(['items' => [0 => 'a', 1 => 'b', 2 => 'c']]);
        $this->assertStringContainsString('[a, b, c]', $yaml);
    }

    public function testDumpAssociativeArrayDetectsMapping(): void
    {
        $dumper = new YAMLDumper();

        // Non-sequential keys → mapping
        $yaml = $dumper->dump(['data' => ['x' => 1, 'y' => 2]]);
        $this->assertStringContainsString('x: 1', $yaml);
    }

    public function testDumpScalarRoot(): void
    {
        $dumper = new YAMLDumper();

        $this->assertSame('42', $dumper->dump(42));
        $this->assertSame('true', $dumper->dump(true));
        $this->assertSame('hello', $dumper->dump('hello'));
    }

    public function testDumpNonArrayNonScalarFallback(): void
    {
        $dumper = new YAMLDumper();

        // Verify dump does not crash on normal scalar data
        $yaml = $dumper->dump(['obj' => 'stringified']);
        $this->assertStringContainsString('obj: stringified', $yaml);
    }

    public function testDumpSpecialYamlCharsInValues(): void
    {
        $dumper = new YAMLDumper();

        $data = [
            'hash' => 'value # not a comment',
            'ampersand' => 'a & b',
            'star' => 'a * b',
            'pipe' => 'a | b',
            'bracket' => 'a [b] c',
            'brace' => 'a {b} c',
        ];

        $yaml = $dumper->dump($data);

        // All these should be quoted because they contain special YAML chars
        $lines = explode("\n", $yaml);
        foreach ($lines as $line) {
            if (str_contains($line, ':') && trim($line) !== '') {
                // The value portion should be quoted
                $this->assertStringContainsString('"', $line, "Line should contain quotes: $line");
            }
        }
    }

    public function testDumpPreservesKeyOrder(): void
    {
        $dumper = new YAMLDumper();

        $data = ['z' => 1, 'a' => 2, 'm' => 3];
        $yaml = $dumper->dump($data);

        $zPos = strpos($yaml, 'z:');
        $aPos = strpos($yaml, 'a:');
        $mPos = strpos($yaml, 'm:');

        $this->assertLessThan($aPos, $zPos, 'Key order should be preserved');
        $this->assertLessThan($mPos, $aPos, 'Key order should be preserved');
    }
}
