<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Database;

use Razy\Exception\QueryException;
use Razy\Exception\SyntaxException;
use Razy\SimpleSyntax;

/**
 * The query builder's two input checks in one place (RZ-47): Simple Syntax
 * with unbalanced parentheses is a QueryException naming the clause, and a
 * name spliced into Simple Syntax must be one identifier.
 */
final class SyntaxGuard
{
    /**
     * SimpleSyntax::parseSyntax(), with a stray ')' or an unclosed '(' raised as a
     * QueryException. Before RZ-47 a stray ')' dropped everything parsed so far.
     *
     * @param string $clause Clause name for the message (Where, order, update, join)
     *
     * @return array
     *
     * @throws QueryException
     */
    public static function parse(string $clause, string $syntax, string $delimiter = ',|', string $negativeLookahead = '', ?callable $parser = null, bool $notCaptureDelimiter = false): array
    {
        try {
            return SimpleSyntax::parseSyntax($syntax, $delimiter, $negativeLookahead, $parser, $notCaptureDelimiter);
        } catch (SyntaxException $e) {
            throw new QueryException("Invalid {$clause} syntax: unbalanced parenthesis.", 0, $e);
        }
    }

    /**
     * parse() for a flat list (ORDER BY keys): a parenthesised group is refused
     * too (it was a TypeError downstream).
     *
     * @return list<string>
     *
     * @throws QueryException
     */
    public static function parseFlat(string $clause, string $syntax, string $delimiter): array
    {
        $clips = self::parse($clause, $syntax, $delimiter, '', null, true);
        foreach ($clips as $clip) {
            if (!\is_string($clip)) {
                throw new QueryException("Invalid {$clause} syntax: a parenthesised group is not allowed here.");
            }
        }

        return $clips;
    }

    /**
     * parse() for a caller that reports malformed syntax itself: null when the
     * parentheses do not balance.
     *
     * @return array|null
     */
    public static function tryParse(string $syntax, string $delimiter = ',|', string $negativeLookahead = ''): ?array
    {
        try {
            return SimpleSyntax::parseSyntax($syntax, $delimiter, $negativeLookahead);
        } catch (SyntaxException) {
            return null;
        }
    }

    /**
     * A column name about to be spliced into Simple Syntax, returned unchanged
     * once it is one identifier (alias-qualified `t.col` only when $qualified).
     *
     * @throws QueryException
     */
    public static function identifier(string $name, bool $qualified = false): string
    {
        $pattern = $qualified ? '/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?$/D' : '/^[A-Za-z_]\w*$/D';
        if (!\preg_match($pattern, $name)) {
            throw new QueryException('Invalid column name; pass one identifier.');
        }

        return $name;
    }
}
