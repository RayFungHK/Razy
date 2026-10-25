<?php

declare(strict_types=1);

namespace Razy\Tests\Rzx;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Rzx\Expr\ExprToken;
use Razy\Rzx\Lexer;
use Razy\Rzx\Source;
use Razy\Rzx\Token;

/**
 * RZ-28a: the markup token stream (mode switching between text and tags).
 */
#[CoversClass(Lexer::class)]
class LexerTest extends TestCase
{
    public function testTokenStream(): void
    {
        $tokens = Lexer::tokenize(new Source('<a href={u} download>Hi {n}</a>{/* c */}<br/>'));
        $shape = \array_map(static fn (Token $t): string => $t->type . ($t->value === '' ? '' : ':' . $t->value), $tokens);

        $this->assertSame([
            'open:a', 'attr:href', 'attr:download', 'open_end:>', 'text:Hi ', 'expr', 'close:a',
            'open:br', 'self_close:/>', 'eof',
        ], $shape);
        $this->assertNull($tokens[2]->literal);
        $this->assertNull($tokens[2]->expr);
        $this->assertSame(8, $tokens[1]->bodyOffset, 'offset of the `{` of an attribute expression');
        $expr = $tokens[5]->expr ?? [];
        $this->assertSame([ExprToken::IDENT, ExprToken::END], \array_map(static fn (ExprToken $t): string => $t->type, $expr));
    }

    public function testPropsBodyIsRawAndNotTokenized(): void
    {
        $text = "\xEF\xBB\xBF<props>\nreturn ['a' => '<b>{x}</b>'];\n</props><p></p>";
        $tokens = Lexer::tokenize(new Source($text));

        $this->assertSame(Token::PROPS, $tokens[0]->type);
        $this->assertSame(3, $tokens[0]->offset, 'the UTF-8 BOM is skipped');
        $this->assertSame("\nreturn ['a' => '<b>{x}</b>'];\n", $tokens[0]->value);
        $this->assertSame(10, $tokens[0]->bodyOffset);
        $this->assertSame('open:p', $tokens[1]->type . ':' . $tokens[1]->value);
    }

    public function testQuotedAttributesKeepTheirTextVerbatim(): void
    {
        $tokens = Lexer::tokenize(new Source("<p a='x \"y\"' b=\"{z} &amp;\n w\"></p>"));

        $this->assertSame('x "y"', $tokens[1]->literal);
        $this->assertSame("{z} &amp;\n w", $tokens[2]->literal);
    }

    public function testPropsEndsOnlyAtACloseTagInCode(): void
    {
        $body = "\nreturn ['a' => '</props>', \"b\" => \"x\\\"</props>\"]; // </props> in a comment\n/* </props> */ # </props>\n";
        $tokens = Lexer::tokenize(new Source('<props >' . $body . '</props  ><p></p>'));

        $this->assertSame(Token::PROPS, $tokens[0]->type);
        $this->assertSame($body, $tokens[0]->value);
        $this->assertSame('open:p', $tokens[1]->type . ':' . $tokens[1]->value);
    }

    public function testAVeryLongTagNameIsScannedNotMatchedByRegex(): void
    {
        $name = 'A' . \str_repeat('.B', 20000);
        $tokens = Lexer::tokenize(new Source('<' . $name . ' />'));
        $this->assertSame($name, $tokens[0]->value);
    }
}
