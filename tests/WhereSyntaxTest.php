<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\Database\WhereSyntax;

#[CoversClass(WhereSyntax::class)]
class WhereSyntaxTest extends TestCase
{
    // ─── VerifySyntax (static, no DB required) ──────────────────────

    #[Test]
    public function verifySyntaxSimpleEquality(): void
    {
        $result = WhereSyntax::VerifySyntax('id=1');
        $this->assertSame('id=1', $result);
    }

    #[Test]
    public function verifySyntaxWithPrefix(): void
    {
        $result = WhereSyntax::VerifySyntax('id=1', 'u');
        $this->assertStringContainsString('u.', $result);
    }

    #[Test]
    public function verifySyntaxEmpty(): void
    {
        $result = WhereSyntax::VerifySyntax('');
        $this->assertSame('', $result);
    }

    #[Test]
    public function verifySyntaxWithLogicalAnd(): void
    {
        $result = WhereSyntax::VerifySyntax('name=test,age=25');
        $this->assertStringContainsString(',', $result);
    }

    #[Test]
    public function verifySyntaxWithLogicalOr(): void
    {
        $result = WhereSyntax::VerifySyntax('status=active|status=inactive');
        $this->assertStringContainsString('|', $result);
    }

    #[Test]
    public function verifySyntaxNegation(): void
    {
        $result = WhereSyntax::VerifySyntax('!name=test');
        $this->assertStringContainsString('!', $result);
    }

    #[Test]
    public function verifySyntaxWithGroupParenthesis(): void
    {
        $result = WhereSyntax::VerifySyntax('(name=test,age=25)');
        $this->assertStringContainsString('(', $result);
        $this->assertStringContainsString(')', $result);
    }

    #[Test]
    public function verifySyntaxPrefixQualifiesUnqualifiedColumns(): void
    {
        $result = WhereSyntax::VerifySyntax('name=value', 'tbl');
        // Both column and value side should be prefixed if they look like columns
        $this->assertStringContainsString('tbl.', $result);
    }

    #[Test]
    public function verifySyntaxDoesNotPrefixAlreadyQualifiedColumn(): void
    {
        $result = WhereSyntax::VerifySyntax('tbl.name=value', 'pfx');
        // tbl.name has a dot already, should NOT get prefix
        $this->assertStringContainsString('tbl.name', $result);
    }

    #[Test]
    public function verifySyntaxPatternMatch(): void
    {
        $result = WhereSyntax::VerifySyntax('name*=test');
        $this->assertNotEmpty($result);
    }

    #[Test]
    public function verifySyntaxNotEqual(): void
    {
        $result = WhereSyntax::VerifySyntax('status!=deleted');
        $this->assertStringContainsString('!=', $result);
    }

    #[Test]
    public function verifySyntaxGreaterThan(): void
    {
        $result = WhereSyntax::VerifySyntax('age>18');
        $this->assertStringContainsString('>', $result);
    }

    #[Test]
    public function verifySyntaxLessThanOrEqual(): void
    {
        $result = WhereSyntax::VerifySyntax('price<=100');
        $this->assertStringContainsString('<=', $result);
    }

    // ─── Regex constants ────────────────────────────────────────────

    #[Test]
    public function regexSplitOperandIsValidRegex(): void
    {
        $result = @\preg_match(WhereSyntax::REGEX_SPLIT_OPERAND, '');
        $this->assertNotFalse($result);
    }

    #[Test]
    public function regexColumnMatchesSimpleColumn(): void
    {
        $this->assertMatchesRegularExpression(WhereSyntax::REGEX_COLUMN, 'id');
    }

    #[Test]
    public function regexColumnMatchesBacktickQuoted(): void
    {
        $this->assertMatchesRegularExpression(WhereSyntax::REGEX_COLUMN, '`user_name`');
    }

    #[Test]
    public function regexColumnMatchesTableDotColumn(): void
    {
        $this->assertMatchesRegularExpression(WhereSyntax::REGEX_COLUMN, 'users.`id`');
    }

    #[Test]
    public function regexColumnRejectsNumericStart(): void
    {
        $this->assertDoesNotMatchRegularExpression(WhereSyntax::REGEX_COLUMN, '123col');
    }

    // ─── REGEX_SPLIT_OPERAND splitting ──────────────────────────────

    #[Test]
    public function splitOperandSplitsEquality(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col=val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('col', $parts[0]);
        $this->assertSame('=', $parts[1]);
        $this->assertSame('val', $parts[2]);
    }

    #[Test]
    public function splitOperandSplitsNotEqual(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col!=val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('!=', $parts[1]);
    }

    #[Test]
    public function splitOperandSplitsGreaterThan(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col>val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('>', $parts[1]);
    }

    #[Test]
    public function splitOperandSplitsPatternMatch(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col*=val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('*=', $parts[1]);
    }

    #[Test]
    public function splitOperandNoOperatorReturnsSingleElement(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'column', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(1, $parts);
    }

    #[Test]
    public function splitOperandBetweenOperator(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col><val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('><', $parts[1]);
    }

    #[Test]
    public function splitOperandStartsWithOperator(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col^=val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('^=', $parts[1]);
    }

    #[Test]
    public function splitOperandEndsWithOperator(): void
    {
        $parts = \preg_split(WhereSyntax::REGEX_SPLIT_OPERAND, 'col$=val', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $this->assertCount(3, $parts);
        $this->assertSame('$=', $parts[1]);
    }
}
