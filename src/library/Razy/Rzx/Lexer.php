<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

use Razy\Rzx\Expr\ExprLexer;

/**
 * Markup tokenizer for `.rzx` files (the JSX subset).
 *
 * It has two modes. Content mode reads text, `{expr}`, `{/* comment *\/}`
 * (dropped), `<tag`, `</tag>` and the raw `<props>` block. Tag mode, entered
 * after `<name`, reads attributes up to `>` or `/>`. Expressions are tokenized
 * by ExprLexer in place, so nested `{…}` maps and quoted `}` work.
 *
 * A bare `<` or `}` in text, an HTML comment and `<!DOCTYPE` are errors with
 * a hint, so a typo never silently becomes text.
 */
final class Lexer
{
    private const TAG_NAME = '/\G[A-Za-z][A-Za-z0-9]*(?:[.\-:][A-Za-z0-9]+)*/';

    private const ATTR_NAME = '/\G[A-Za-z_@:][A-Za-z0-9_.:\-]*/';

    private string $text;

    private int $length;

    private int $pos = 0;

    /** @var list<Token> */
    private array $tokens = [];

    private function __construct(private readonly Source $source)
    {
        $this->text = $source->text;
        $this->length = \strlen($source->text);
    }

    /**
     * @return list<Token> ending with an EOF token
     */
    public static function tokenize(Source $source): array
    {
        $lexer = new self($source);
        $lexer->run();

        return $lexer->tokens;
    }

    private function run(): void
    {
        if (\str_starts_with($this->text, "\xEF\xBB\xBF")) {
            $this->pos = 3;
        }
        while ($this->pos < $this->length) {
            $char = $this->text[$this->pos];
            if ($char === '{') {
                $this->brace();
            } elseif ($char === '<') {
                $this->angle();
            } elseif ($char === '}') {
                throw $this->error('RZX003', "Unexpected `}` in text; write {'}'} for a literal brace", $this->pos);
            } else {
                $end = \strcspn($this->text, '{}<', $this->pos) + $this->pos;
                $this->tokens[] = new Token(Token::TEXT, \substr($this->text, $this->pos, $end - $this->pos), $this->pos);
                $this->pos = $end;
            }
        }
        $this->tokens[] = new Token(Token::EOF, '', $this->length);
    }

    private function brace(): void
    {
        $start = $this->pos;
        if (\preg_match('/\G\{\s*\/\*/', $this->text, $m, 0, $start)) {
            $close = \strpos($this->text, '*/', $start + \strlen($m[0]));
            if ($close === false || !\preg_match('/\G\*\/\s*\}/', $this->text, $end, 0, $close)) {
                throw $this->error('RZX005', 'Unterminated comment: expected `*/}`', $start);
            }
            $this->pos = $close + \strlen($end[0]);

            return;
        }
        [$tokens, $end] = ExprLexer::scan($this->source, $start + 1);
        $this->tokens[] = new Token(Token::EXPR, '', $start, null, $tokens);
        $this->pos = $end + 1;
    }

    private function angle(): void
    {
        $start = $this->pos;
        $next = $this->text[$start + 1] ?? '';
        if ($next === '/') {
            $this->closeTag($start);

            return;
        }
        if ($next === '!') {
            $hint = \str_starts_with(\substr($this->text, $start, 4), '<!--')
                ? 'HTML comments are not allowed; write {/* … */}'
                : '`<!…>` declarations are not allowed; the layout emits the doctype';
            throw $this->error('RZX006', $hint, $start);
        }
        if (!\preg_match(self::TAG_NAME, $this->text, $m, 0, $start + 1)) {
            throw $this->error('RZX002', 'Unexpected `<` in text; write &lt; for a literal less-than sign', $start);
        }
        $name = $m[0];
        $this->pos = $start + 1 + \strlen($name);
        if (!\str_contains(" \t\r\n/>", $this->text[$this->pos] ?? ' ')) {
            $bad = \substr($this->text, $start + 1, \strcspn($this->text, " \t\r\n/>", $start + 1));
            throw $this->error('RZX017', 'Invalid tag name <' . $bad . '>; names use letters, digits, `-` and `.`', $start);
        }
        if ($name === 'props' && ($this->text[$this->pos] ?? '') === '>') {
            $this->props($start);

            return;
        }
        $this->tokens[] = new Token(Token::OPEN, $name, $start);
        $this->attributes($start, $name);
    }

    private function closeTag(int $start): void
    {
        if (!\preg_match(self::TAG_NAME, $this->text, $m, 0, $start + 2)) {
            throw $this->error('RZX007', 'Expected a tag name after `</`', $start + 2);
        }
        $this->pos = $start + 2 + \strlen($m[0]);
        $this->skipSpace();
        if (($this->text[$this->pos] ?? '') !== '>') {
            throw $this->error('RZX007', 'Expected `>` to end the closing tag </' . $m[0] . '>', $this->pos);
        }
        ++$this->pos;
        $this->tokens[] = new Token(Token::CLOSE, $m[0], $start);
    }

    private function props(int $start): void
    {
        $bodyOffset = $this->pos + 1;
        $close = \strpos($this->text, '</props>', $bodyOffset);
        if ($close === false) {
            throw $this->error('RZX008', '<props> is never closed; expected </props>', $start);
        }
        $this->tokens[] = new Token(Token::PROPS, \substr($this->text, $bodyOffset, $close - $bodyOffset), $start, null, null, $bodyOffset);
        $this->pos = $close + \strlen('</props>');
    }

    private function attributes(int $tagStart, string $name): void
    {
        while (true) {
            $this->skipSpace();
            if ($this->pos >= $this->length) {
                throw $this->error('RZX001', 'Unterminated tag <' . $name . '>: expected `>` or `/>`', $tagStart);
            }
            $char = $this->text[$this->pos];
            if ($char === '>') {
                $this->tokens[] = new Token(Token::OPEN_END, '>', $this->pos++);

                return;
            }
            if ($char === '/') {
                if (($this->text[$this->pos + 1] ?? '') !== '>') {
                    throw $this->error('RZX009', 'Expected `/>` to self-close <' . $name . '>', $this->pos);
                }
                $this->tokens[] = new Token(Token::SELF_CLOSE, '/>', $this->pos);
                $this->pos += 2;

                return;
            }
            if ($char === '{') {
                $hint = \preg_match('/\G\{\s*\.\.\./', $this->text, $m, 0, $this->pos)
                    ? 'Spread attributes `{...x}` are not supported; pass each attribute'
                    : 'Expected an attribute name; an expression needs a name, e.g. label={…}';
                throw $this->error('RZX011', $hint, $this->pos);
            }
            $this->attribute($name);
        }
    }

    private function attribute(string $tag): void
    {
        $start = $this->pos;
        if (!\preg_match(self::ATTR_NAME, $this->text, $m, 0, $start)) {
            throw $this->error('RZX010', 'Unexpected ' . $this->show($start) . ' in <' . $tag . '>; expected an attribute name, `>` or `/>`', $start);
        }
        $attr = $m[0];
        $this->pos = $start + \strlen($attr);
        $this->skipSpace();
        if (($this->text[$this->pos] ?? '') !== '=') {
            $this->tokens[] = new Token(Token::ATTR, $attr, $start);

            return;
        }
        ++$this->pos;
        $this->skipSpace();
        $char = $this->text[$this->pos] ?? '';
        if ($char === '"' || $char === "'") {
            $close = \strpos($this->text, $char, $this->pos + 1);
            if ($close === false) {
                throw $this->error('RZX004', 'Unterminated attribute value for ' . $attr, $this->pos);
            }
            $this->tokens[] = new Token(Token::ATTR, $attr, $start, \substr($this->text, $this->pos + 1, $close - $this->pos - 1));
            $this->pos = $close + 1;

            return;
        }
        if ($char === '{') {
            $brace = $this->pos;
            [$tokens, $end] = ExprLexer::scan($this->source, $brace + 1);
            $this->tokens[] = new Token(Token::ATTR, $attr, $start, null, $tokens, $brace);
            $this->pos = $end + 1;

            return;
        }

        throw $this->error('RZX010', 'Expected a quoted value or {expression} after ' . $attr . '=', $this->pos);
    }

    private function skipSpace(): void
    {
        $this->pos += \strspn($this->text, " \t\r\n", $this->pos);
    }

    private function show(int $offset): string
    {
        return \preg_match('/\G./su', $this->text, $m, 0, $offset) ? '`' . $m[0] . '`' : 'end of file';
    }

    private function error(string $code, string $reason, int $offset): CompileError
    {
        return new CompileError($code, $reason, $this->source, $offset);
    }
}
