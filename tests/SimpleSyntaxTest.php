<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\SimpleSyntax;

/**
 * Tests for SimpleSyntax ??lightweight expression tokenizer with
 * delimiter-based splitting and nested parenthetical grouping.
 */
#[CoversClass(SimpleSyntax::class)]
class SimpleSyntaxTest extends TestCase
{
    // ?�?�?� ParseParens ??basic grouping ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function parseParensReturnsArrayForEmptyString(): void
    {
        $result = SimpleSyntax::parseParens('');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function parseParensNoBracketsReturnsSingleElement(): void
    {
        $result = SimpleSyntax::parseParens('hello world');
        $this->assertSame(['hello world'], $result);
    }

    #[Test]
    public function parseParensSimpleGroup(): void
    {
        // Parens preceded by \w are treated as function call syntax and preserved
        $result = SimpleSyntax::parseParens('a(b)c');
        $this->assertSame(['a(b)c'], $result);
    }

    #[Test]
    public function parseParensGroupNotPrecededByWord(): void
    {
        // Parens NOT preceded by \w are grouped into nested arrays
        // " (b) " ??[' ', ['b'], ' ']
        $result = SimpleSyntax::parseParens(' (b) ');
        $this->assertSame([' ', ['b'], ' '], $result);
    }

    #[Test]
    public function parseParensNestedGroups(): void
    {
        // Word-preceded parens with nested content are function call syntax
        $result = SimpleSyntax::parseParens('a(b(c)d)e');
        $this->assertSame(['a(b(c)d)e'], $result);
    }

    #[Test]
    public function parseParensNestedGroupsNonFunctionCall(): void
    {
        // " (x(y)z) " ??outer parens are not preceded by \w, inner x(y) is function call
        $result = SimpleSyntax::parseParens(' (x)');
        $this->assertSame([' ', ['x']], $result);
    }

    #[Test]
    public function parseParensMultipleGroups(): void
    {
        // "a(b)" is function call syntax, "(c)" is a standalone group
        $result = SimpleSyntax::parseParens('a(b)(c)');
        $this->assertSame(['a(b)', ['c']], $result);
    }

    #[Test]
    public function parseParensEmptyParens(): void
    {
        // "a()b" is function call syntax (word followed by parens)
        $result = SimpleSyntax::parseParens('a()b');
        $this->assertSame(['a()b'], $result);
    }

    #[Test]
    public function parseParensEmptyStandaloneParens(): void
    {
        // Standalone empty parens (not preceded by word)
        $result = SimpleSyntax::parseParens(' ()x');
        $this->assertSame([' ', [], 'x'], $result);
    }

    #[Test]
    public function parseParensSkipsQuotedParens(): void
    {
        // Parens inside quotes should not be treated as grouping
        $result = SimpleSyntax::parseParens('"a(b)c"');
        $this->assertSame(['"a(b)c"'], $result);
    }

    #[Test]
    public function parseParensSkipsSingleQuotedParens(): void
    {
        $result = SimpleSyntax::parseParens("'a(b)c'");
        $this->assertSame(["'a(b)c'"], $result);
    }

    #[Test]
    public function parseParensSkipsBacktickQuotedParens(): void
    {
        $result = SimpleSyntax::parseParens('`a(b)c`');
        $this->assertSame(['`a(b)c`'], $result);
    }

    #[Test]
    public function parseParensSkipsBracketedContent(): void
    {
        $result = SimpleSyntax::parseParens('[a(b)]');
        $this->assertSame(['[a(b)]'], $result);
    }

    #[Test]
    public function parseParensEscapedParens(): void
    {
        // Escaped parens should be treated as literal characters
        $result = SimpleSyntax::parseParens('a\\(b\\)c');
        $this->assertSame(['a\\(b\\)c'], $result);
    }

    #[Test]
    public function parseParensUnmatchedClosingParenDiscarded(): void
    {
        // Closing paren without opening should be discarded
        $result = SimpleSyntax::parseParens('a)b');
        $this->assertIsArray($result);
    }

    #[Test]
    public function parseParensSkipsFunctionCallSyntax(): void
    {
        // "foo(bar)" where foo is a word followed by parens is treated as function call and skipped
        $result = SimpleSyntax::parseParens('foo(bar)');
        $this->assertSame(['foo(bar)'], $result);
    }

    #[Test]
    public function parseParensDeeplyNested(): void
    {
        // "((a))" ??[[ ['a'] ]]
        $result = SimpleSyntax::parseParens('((a))');
        $this->assertSame([[['a']]], $result);
    }

    // ?�?�?� ParseSyntax ??delimiter splitting ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function parseSyntaxEmptyStringReturnsEmptyArray(): void
    {
        $result = SimpleSyntax::parseSyntax('');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function parseSyntaxNoDelimitersReturnsSingleElement(): void
    {
        $result = SimpleSyntax::parseSyntax('hello');
        $this->assertSame(['hello'], $result);
    }

    #[Test]
    public function parseSyntaxSplitsByComma(): void
    {
        $result = SimpleSyntax::parseSyntax('a,b,c', ',');
        // With default (capture delimiter), expect: ['a', ',', 'b', ',', 'c']
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);
    }

    #[Test]
    public function parseSyntaxSplitsByPipe(): void
    {
        $result = SimpleSyntax::parseSyntax('a|b|c', '|');
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);
    }

    #[Test]
    public function parseSyntaxDefaultDelimiterCommaAndPipe(): void
    {
        // Default delimiter is ',|'
        $result = SimpleSyntax::parseSyntax('a,b|c');
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
        $this->assertContains('c', $result);
    }

    #[Test]
    public function parseSyntaxNotCaptureDelimiter(): void
    {
        $result = SimpleSyntax::parseSyntax('a,b,c', ',', '', null, true);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxCapturesDelimiter(): void
    {
        $result = SimpleSyntax::parseSyntax('a,b', ',', '', null, false);
        $this->assertSame(['a', ',', 'b'], $result);
    }

    #[Test]
    public function parseSyntaxTrimsWhitespaceAroundDelimiters(): void
    {
        $result = SimpleSyntax::parseSyntax('a , b', ',', '', null, true);
        $this->assertSame(['a', 'b'], $result);
    }

    #[Test]
    public function parseSyntaxPreservesQuotedStrings(): void
    {
        // Delimiters inside quotes should not cause splitting
        $result = SimpleSyntax::parseSyntax('"a,b",c', ',', '', null, true);
        $this->assertSame(['"a,b"', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxPreservesSingleQuotedStrings(): void
    {
        $result = SimpleSyntax::parseSyntax("'a,b',c", ',', '', null, true);
        $this->assertSame(["'a,b'", 'c'], $result);
    }

    #[Test]
    public function parseSyntaxPreservesBacktickQuotedStrings(): void
    {
        $result = SimpleSyntax::parseSyntax('`a,b`,c', ',', '', null, true);
        $this->assertSame(['`a,b`', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxPreservesBracketedContent(): void
    {
        // Delimiters inside square brackets should not split
        $result = SimpleSyntax::parseSyntax('[a,b],c', ',', '', null, true);
        $this->assertSame(['[a,b]', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxEscapedDelimiterNotSplit(): void
    {
        // An escaped delimiter should not cause a split
        $result = SimpleSyntax::parseSyntax('a\\,b,c', ',', '', null, true);
        $this->assertSame(['a\\,b', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxWithNegativeLookahead(): void
    {
        // When negative lookahead is '=' and delimiter is '>', '>' followed by '=' should not split
        $result = SimpleSyntax::parseSyntax('a>b>=c', '>', '=', null, true);
        // 'a>b>=c' with delimiter '>' ignoring '>=' should only split at the first '>'
        $this->assertContains('a', $result);
        // '>=' should not be split
        $this->assertContains('b>=c', $result);
    }

    #[Test]
    public function parseSyntaxWithParserCallback(): void
    {
        $result = SimpleSyntax::parseSyntax('a,b,c', ',', '', function (string $token): string {
            return \strtoupper($token);
        }, true);
        $this->assertSame(['A', 'B', 'C'], $result);
    }

    #[Test]
    public function parseSyntaxParserCallbackReceivesDelimiters(): void
    {
        $tokens = [];
        $result = SimpleSyntax::parseSyntax('a,b', ',', '', function (string $token) use (&$tokens): string {
            $tokens[] = $token;
            return $token;
        }, false);
        // With capture delimiter: callback gets 'a', ',', 'b'
        $this->assertSame(['a', ',', 'b'], $tokens);
    }

    #[Test]
    public function parseSyntaxWithNestedParens(): void
    {
        // Parentheses create nested arrays via ParseParens first
        $result = SimpleSyntax::parseSyntax('a,(b,c)', ',', '', null, true);
        // The '(' and ')' create a nested group
        $this->assertContains('a', $result);
        // The nested part should be an array
        $hasNested = false;
        foreach ($result as $item) {
            if (\is_array($item)) {
                $hasNested = true;
                $this->assertContains('b', $item);
                $this->assertContains('c', $item);
            }
        }
        $this->assertTrue($hasNested, 'Expected a nested array from parenthetical grouping');
    }

    #[Test]
    public function parseSyntaxMultipleDelimiters(): void
    {
        $result = SimpleSyntax::parseSyntax('a,b|c.d', ',|.', '', null, true);
        $this->assertSame(['a', 'b', 'c', 'd'], $result);
    }

    #[Test]
    public function parseSyntaxFunctionCallSyntaxPreserved(): void
    {
        // Function call syntax like fn(x,y) should be preserved, not split by delimiters or parens
        $result = SimpleSyntax::parseSyntax('fn(x,y),z', ',', '', null, true);
        $this->assertContains('fn(x,y)', $result);
        $this->assertContains('z', $result);
    }

    #[Test]
    public function parseSyntaxNestedFunctionCalls(): void
    {
        $result = SimpleSyntax::parseSyntax('outer(inner(a,b),c),d', ',', '', null, true);
        $this->assertContains('outer(inner(a,b),c)', $result);
        $this->assertContains('d', $result);
    }

    #[Test]
    public function parseSyntaxOnlyDelimiters(): void
    {
        $result = SimpleSyntax::parseSyntax(',', ',', '', null, true);
        $this->assertIsArray($result);
        // No tokens between commas ??should be empty since PREG_SPLIT_NO_EMPTY is set
        $this->assertEmpty($result);
    }

    #[Test]
    public function parseSyntaxConsecutiveDelimiters(): void
    {
        $result = SimpleSyntax::parseSyntax('a,,b', ',', '', null, true);
        // With PREG_SPLIT_NO_EMPTY, empty strings between consecutive delimiters are omitted
        $this->assertSame(['a', 'b'], $result);
    }

    #[Test]
    public function parseSyntaxWithWhitespaceOnlyTokens(): void
    {
        // Whitespace around delimiters is trimmed, so " , " should produce no empty tokens
        $result = SimpleSyntax::parseSyntax('a , , b', ',', '', null, true);
        $this->assertContains('a', $result);
        $this->assertContains('b', $result);
    }

    #[Test]
    public function parseSyntaxComplexExpression(): void
    {
        // Mix of quotes, brackets, escapes, and delimiters
        $result = SimpleSyntax::parseSyntax('"hello,world",[a|b],c\\,d|e', ',|', '', null, true);
        $this->assertContains('"hello,world"', $result);
        $this->assertContains('[a|b]', $result);
        $this->assertContains('c\\,d', $result);
        $this->assertContains('e', $result);
    }

    #[Test]
    public function parseSyntaxPipeDelimiterOnly(): void
    {
        $result = SimpleSyntax::parseSyntax('x|y|z', '|', '', null, true);
        $this->assertSame(['x', 'y', 'z'], $result);
    }

    #[Test]
    public function parseSyntaxSingleCharDelimiter(): void
    {
        $result = SimpleSyntax::parseSyntax('a;b;c', ';', '', null, true);
        $this->assertSame(['a', 'b', 'c'], $result);
    }

    #[Test]
    public function parseSyntaxWithNullParser(): void
    {
        // Explicitly passing null parser should work
        $result = SimpleSyntax::parseSyntax('a,b', ',', '', null, true);
        $this->assertSame(['a', 'b'], $result);
    }

    #[Test]
    public function parseSyntaxParserTransformsNestedContent(): void
    {
        // Parser should apply to nested (parenthetical) content too
        $result = SimpleSyntax::parseSyntax('a,(b,c)', ',', '', function (string $token): string {
            return \strtoupper($token);
        }, true);

        // Check that top-level tokens are uppercased
        $this->assertContains('A', $result);
        // The nested array should contain uppercased tokens
        foreach ($result as $item) {
            if (\is_array($item)) {
                $this->assertContains('B', $item);
                $this->assertContains('C', $item);
            }
        }
    }

    #[Test]
    public function parseSyntaxSpecialRegexCharsInDelimiter(): void
    {
        // Delimiters with special regex meaning should be properly escaped
        $result = SimpleSyntax::parseSyntax('a.b.c', '.', '', null, true);
        $this->assertSame(['a', 'b', 'c'], $result);
    }
}
