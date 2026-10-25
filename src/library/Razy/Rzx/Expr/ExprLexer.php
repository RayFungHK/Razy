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

namespace Razy\Rzx\Expr;

use Razy\Rzx\CompileError;
use Razy\Rzx\Source;

/**
 * Tokenizer for the `{…}` expression grammar.
 *
 * It scans from an offset inside a Source either to the end of the text or to
 * the `}` that closes the enclosing `{` (nested `{…}` map literals are
 * counted), so offsets stay absolute and errors point at the `.rzx` file.
 *
 * PHP and JS spellings that the grammar does not have (`$x`, `->`, `::`,
 * `=`, `===`, `*`, a single `&` or `|`, backticks, `;`) are rejected here
 * with a hint, not left to a vaguer parser error.
 */
final class ExprLexer
{
    private const PUNCTS = ['||', '&&', '??', '==', '!=', '<=', '>=', '<', '>', '!', '~', '+', '-', '?', ':', ',', '.', '(', ')', '[', ']', '{', '}'];

    /** @var array<string, array{string, string}> rejected spelling => [code, hint] */
    private const REJECTED = [
        '===' => ['RZX033', '`===` is not supported; `==` already compares strictly'],
        '!==' => ['RZX033', '`!==` is not supported; `!=` already compares strictly'],
        '->' => ['RZX032', 'Use `.` to read a property; `->` is PHP'],
        '::' => ['RZX032', 'Static access `::` is not allowed in an expression'],
        '=' => ['RZX033', 'Assignment is not allowed in an expression; use `==` to compare'],
        '$' => ['RZX032', 'Write a variable as `name`, not `$name`'],
        '`' => ['RZX032', 'Backticks are not allowed in an expression'],
        ';' => ['RZX032', 'An expression cannot contain `;`'],
        '&' => ['RZX033', 'Use `&&` for "and"'],
        '|' => ['RZX033', 'Use `||` for "or"; template modifiers are functions here, e.g. `upper(x)`'],
        '*' => ['RZX033', 'Only `+` and `-` arithmetic is supported'],
        '/' => ['RZX033', 'Only `+` and `-` arithmetic is supported'],
        '%' => ['RZX033', 'Only `+` and `-` arithmetic is supported'],
    ];

    private const ESCAPES = ['\\' => '\\', "'" => "'", '"' => '"', 'n' => "\n", 't' => "\t", 'r' => "\r"];

    /**
     * @param int $start first byte of the expression (just after `{` when $braced)
     * @param bool $braced stop at the unmatched `}`; when false, scan to the end of the text
     *
     * @return array{list<ExprToken>, int} the tokens (last one is END) and the end offset
     *                                     (the offset of the closing `}` when $braced)
     */
    public static function scan(Source $source, int $start, bool $braced = true): array
    {
        $text = $source->text;
        $length = \strlen($text);
        $tokens = [];
        $depth = 0;
        $i = $start;
        while (true) {
            while ($i < $length && \str_contains(" \t\r\n", $text[$i])) {
                ++$i;
            }
            if ($i >= $length) {
                if ($braced) {
                    throw new CompileError('RZX030', 'Unterminated expression: expected `}`', $source, $start - 1);
                }
                break;
            }
            $char = $text[$i];
            if ($char === '}' && $depth === 0 && $braced) {
                break;
            }
            if ($braced && $char === '<' && ($text[$i + 1] ?? '') === '/') {
                throw new CompileError('RZX030', 'Unterminated expression: expected `}` before `</`', $source, $start - 1);
            }
            if ($char === "'" || $char === '"') {
                [$value, $i2] = self::string($source, $i);
                $tokens[] = new ExprToken(ExprToken::STRING, $value, $i);
                $i = $i2;
                continue;
            }
            if (\preg_match('/\G[0-9]+(?:\.[0-9]+)?/', $text, $m, 0, $i)) {
                $number = \str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
                if (\preg_match('/\G[A-Za-z_]/', $text, $unused, 0, $i + \strlen($m[0]))) {
                    throw new CompileError('RZX031', 'A name cannot start with a digit', $source, $i);
                }
                $tokens[] = new ExprToken(ExprToken::NUMBER, $number, $i);
                $i += \strlen($m[0]);
                continue;
            }
            if (\preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $text, $m, 0, $i)) {
                $tokens[] = new ExprToken(ExprToken::IDENT, $m[0], $i);
                $i += \strlen($m[0]);
                continue;
            }
            foreach (['===', '!==', '->', '::'] as $bad) {
                if (\substr_compare($text, $bad, $i, \strlen($bad)) === 0) {
                    [$code, $hint] = self::REJECTED[$bad];
                    throw new CompileError($code, $hint, $source, $i);
                }
            }
            $punct = self::punct($text, $i);
            if ($punct === null) {
                if (isset(self::REJECTED[$char])) {
                    [$code, $hint] = self::REJECTED[$char];
                    throw new CompileError($code, $hint, $source, $i);
                }
                throw new CompileError('RZX031', 'Unexpected character ' . self::show($text, $i) . ' in expression', $source, $i);
            }
            if ($punct === '{') {
                ++$depth;
            } elseif ($punct === '}') {
                --$depth;
            }
            $tokens[] = new ExprToken(ExprToken::PUNCT, $punct, $i);
            $i += \strlen($punct);
        }
        $tokens[] = new ExprToken(ExprToken::END, '', $i);

        return [$tokens, $i];
    }

    private static function punct(string $text, int $i): ?string
    {
        $two = \substr($text, $i, 2);
        if (\strlen($two) === 2 && \in_array($two, self::PUNCTS, true)) {
            return $two;
        }

        return \in_array($text[$i], self::PUNCTS, true) ? $text[$i] : null;
    }

    /**
     * @return array{string, int} decoded value and the offset after the closing quote
     */
    private static function string(Source $source, int $start): array
    {
        $text = $source->text;
        $length = \strlen($text);
        $quote = $text[$start];
        $value = '';
        for ($i = $start + 1; $i < $length; ++$i) {
            $char = $text[$i];
            if ($char === $quote) {
                return [$value, $i + 1];
            }
            if ($char === "\n") {
                break;
            }
            if ($char === '\\') {
                $next = $text[$i + 1] ?? '';
                if (!isset(self::ESCAPES[$next])) {
                    throw new CompileError('RZX035', 'Unknown escape `\\' . $next . '` in string (use \\\ \\\' \" \n \t \r)', $source, $i);
                }
                $value .= self::ESCAPES[$next];
                ++$i;
                continue;
            }
            $value .= $char;
        }

        throw new CompileError('RZX034', 'Unterminated string literal', $source, $start);
    }

    private static function show(string $text, int $i): string
    {
        return \preg_match('/\G./su', $text, $m, 0, $i) ? '`' . $m[0] . '`' : \sprintf('byte 0x%02X', \ord($text[$i]));
    }
}
