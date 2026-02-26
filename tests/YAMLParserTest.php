<?php
/**
 * Unit tests for Razy\YAML\YAMLParser internal API.
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
use Razy\YAML\YAMLParser;

#[CoversClass(YAMLParser::class)]
class YAMLParserTest extends TestCase
{
    // ==================== ANCHOR & ALIAS TESTS ====================

    public function testAnchorAndAliasScalar(): void
    {
        $yaml = <<<YAML
defaults:
  &timeout timeout: 30
settings:
  request_timeout: *timeout
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(30, $result['defaults']['timeout']);
        $this->assertSame(30, $result['settings']['request_timeout']);
    }

    public function testAnchorAndAliasNestedMap(): void
    {
        $yaml = <<<YAML
defaults:
  &db database:
    host: localhost
    port: 3306
production:
  database: *db
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $expected = ['host' => 'localhost', 'port' => 3306];
        $this->assertEquals($expected, $result['defaults']['database']);
        $this->assertEquals($expected, $result['production']['database']);
    }

    public function testUnknownAliasReturnsNull(): void
    {
        $yaml = <<<YAML
key: *nonexistent
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertNull($result['key']);
    }

    // ==================== MULTILINE BLOCK TESTS ====================

    public function testLiteralBlockPreservesNewlines(): void
    {
        $yaml = <<<YAML
content: |
  Line A
  Line B
  Line C
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $normalized = str_replace("\r\n", "\n", $result['content']);
        $this->assertSame("Line A\nLine B\nLine C", $normalized);
    }

    public function testFoldedBlockJoinsLines(): void
    {
        $yaml = <<<YAML
description: >
  This is a long
  description that
  will be folded.
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('This is a long description that will be folded.', $result['description']);
    }

    public function testLiteralBlockStrip(): void
    {
        // |- should strip trailing newline (chomp indicator)
        $yaml = <<<YAML
content: |-
  Line A
  Line B
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $normalized = str_replace("\r\n", "\n", $result['content']);
        $this->assertSame("Line A\nLine B", $normalized);
    }

    public function testFoldedBlockStrip(): void
    {
        $yaml = <<<YAML
content: >-
  folded
  text
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('folded text', $result['content']);
    }

    public function testMultipleMultilineBlocks(): void
    {
        $yaml = <<<YAML
first: |
  Block one
  content
second: >
  Folded
  content
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $normalized = str_replace("\r\n", "\n", $result['first']);
        $this->assertStringContainsString("Block one\ncontent", $normalized);
        $this->assertStringContainsString('Folded content', $result['second']);
    }

    public function testLiteralBlockWithExtraIndentation(): void
    {
        $yaml = "code: |\n  def hello():\n    print('hi')\n    return True\n";

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $normalized = str_replace("\r\n", "\n", $result['code']);
        $this->assertStringContainsString("def hello():", $normalized);
        $this->assertStringContainsString("  print('hi')", $normalized);
    }

    // ==================== FLOW COLLECTION TESTS ====================

    public function testFlowSequenceSimple(): void
    {
        $yaml = "items: [1, 2, 3]";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame([1, 2, 3], $result['items']);
    }

    public function testFlowMappingSimple(): void
    {
        $yaml = "point: {x: 10, y: 20}";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(10, $result['point']['x']);
        $this->assertSame(20, $result['point']['y']);
    }

    public function testFlowNestedCollections(): void
    {
        $yaml = "matrix: [[1, 2], [3, 4]]";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame([[1, 2], [3, 4]], $result['matrix']);
    }

    public function testFlowMixedNesting(): void
    {
        $yaml = "data: {items: [a, b, c], count: 3}";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(['a', 'b', 'c'], $result['data']['items']);
        $this->assertSame(3, $result['data']['count']);
    }

    public function testFlowEmptySequence(): void
    {
        $yaml = "items: []";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame([], $result['items']);
    }

    public function testFlowEmptyMapping(): void
    {
        $yaml = "obj: {}";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame([], $result['obj']);
    }

    public function testFlowWithQuotedStrings(): void
    {
        $yaml = 'items: ["hello world", \'single quoted\']';
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('hello world', $result['items'][0]);
        $this->assertSame('single quoted', $result['items'][1]);
    }

    // ==================== SCALAR EDGE CASES ====================

    public function testParseScalarAtRoot(): void
    {
        $yaml = "hello";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('hello', $result);
    }

    public function testParseNullVariants(): void
    {
        $yaml = <<<YAML
a: null
b: ~
c:
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertNull($result['a']);
        $this->assertNull($result['b']);
        $this->assertNull($result['c']);
    }

    public function testParseBooleanVariants(): void
    {
        $yaml = <<<YAML
a: true
b: TRUE
c: True
d: yes
e: YES
f: on
g: ON
h: false
i: FALSE
j: no
k: off
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertTrue($result['a']);
        $this->assertTrue($result['b']);
        $this->assertTrue($result['c']);
        $this->assertTrue($result['d']);
        $this->assertTrue($result['e']);
        $this->assertTrue($result['f']);
        $this->assertTrue($result['g']);
        $this->assertFalse($result['h']);
        $this->assertFalse($result['i']);
        $this->assertFalse($result['j']);
        $this->assertFalse($result['k']);
    }

    public function testParseEscapedStrings(): void
    {
        $yaml = 'msg: "line1\\nline2\\ttab"';
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame("line1\nline2\ttab", $result['msg']);
    }

    public function testParseQuotedNumericString(): void
    {
        $yaml = 'version: "1.0"';
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertIsString($result['version']);
        $this->assertSame('1.0', $result['version']);
    }

    public function testParseQuotedBooleanString(): void
    {
        $yaml = 'flag: "true"';
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertIsString($result['flag']);
        $this->assertSame('true', $result['flag']);
    }

    public function testParseSingleQuotedString(): void
    {
        $yaml = "msg: 'hello world'";
        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('hello world', $result['msg']);
    }

    public function testParseNegativeAndZeroNumbers(): void
    {
        $yaml = <<<YAML
neg: -42
zero: 0
neg_float: -3.14
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(-42, $result['neg']);
        $this->assertSame(0, $result['zero']);
        $this->assertSame(-3.14, $result['neg_float']);
    }

    // ==================== STRUCTURE EDGE CASES ====================

    public function testEmptyInput(): void
    {
        $parser = new YAMLParser('');
        $result = $parser->parse();

        $this->assertNull($result);
    }

    public function testOnlyComments(): void
    {
        $yaml = <<<YAML
# This is a comment
# Another comment
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertNull($result);
    }

    public function testDeeplyNestedStructure(): void
    {
        $yaml = <<<YAML
a:
  b:
    c:
      d:
        e: deep
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('deep', $result['a']['b']['c']['d']['e']);
    }

    public function testListOfInlineMaps(): void
    {
        // The parser handles inline key: value in list items
        $yaml = <<<YAML
users:
  - name: Alice, age: 30
  - name: Bob, age: 25
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertCount(2, $result['users']);
        $this->assertSame('Alice', $result['users'][0]['name']);
        $this->assertSame(30, $result['users'][0]['age']);
        $this->assertSame('Bob', $result['users'][1]['name']);
        $this->assertSame(25, $result['users'][1]['age']);
    }

    public function testNestedListsInMap(): void
    {
        $yaml = <<<YAML
env:
  dev:
    - server1
    - server2
  prod:
    - server3
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(['server1', 'server2'], $result['env']['dev']);
        $this->assertSame(['server3'], $result['env']['prod']);
    }

    public function testListItemWithNestedStructure(): void
    {
        $yaml = <<<YAML
items:
  -
    name: item1
    value: 100
  -
    name: item2
    value: 200
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertCount(2, $result['items']);
        $this->assertSame('item1', $result['items'][0]['name']);
        $this->assertSame(200, $result['items'][1]['value']);
    }

    public function testMultipleTopLevelKeys(): void
    {
        $yaml = <<<YAML
first: 1
second: 2
third: 3
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(1, $result['first']);
        $this->assertSame(2, $result['second']);
        $this->assertSame(3, $result['third']);
    }

    public function testBlankLinesBetweenKeys(): void
    {
        $yaml = <<<YAML
a: 1

b: 2

c: 3
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame(1, $result['a']);
        $this->assertSame(2, $result['b']);
        $this->assertSame(3, $result['c']);
    }

    // ==================== COMPLEX / REAL-WORLD TESTS ====================

    public function testComplexConfigStructure(): void
    {
        $yaml = <<<YAML
app:
  name: MyFramework
  version: 2.1
  debug: false
  features:
    - routing
    - templating
    - orm
  database:
    primary:
      host: db.example.com
      port: 5432
      credentials:
        user: admin
        pass: secret
  cache:
    driver: redis
    options: {host: localhost, port: 6379, db: 0}
YAML;

        $parser = new YAMLParser($yaml);
        $result = $parser->parse();

        $this->assertSame('MyFramework', $result['app']['name']);
        $this->assertFalse($result['app']['debug']);
        $this->assertCount(3, $result['app']['features']);
        $this->assertSame('db.example.com', $result['app']['database']['primary']['host']);
        $this->assertSame('admin', $result['app']['database']['primary']['credentials']['user']);
        $this->assertSame('redis', $result['app']['cache']['driver']);
        $this->assertSame('localhost', $result['app']['cache']['options']['host']);
        $this->assertSame(6379, $result['app']['cache']['options']['port']);
    }

    public function testFreshInstanceResetsState(): void
    {
        $yaml1 = "key1: value1";
        $yaml2 = "key2: value2";

        $parser1 = new YAMLParser($yaml1);
        $result1 = $parser1->parse();

        $parser2 = new YAMLParser($yaml2);
        $result2 = $parser2->parse();

        $this->assertSame('value1', $result1['key1']);
        $this->assertArrayNotHasKey('key1', $result2);
        $this->assertSame('value2', $result2['key2']);
    }
}
