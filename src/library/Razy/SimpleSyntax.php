<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Parser for the Razy Simple Syntax, a lightweight expression language
 * supporting delimiter-based splitting and nested parenthetical grouping.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;
/**
 * Simple Syntax parser for expression tokenization.
 *
 * Provides static methods to split expressions by configurable delimiters while
 * respecting quoted strings, bracketed expressions, and nested parentheses.
 * The parser first groups parenthetical sub-expressions into nested arrays,
 * then splits each group by the specified delimiter characters.
 *
 * @class SimpleSyntax
 */
class SimpleSyntax
{
    /**
     * Parse the Simple Syntax by given delimiter.
     *
     * @param string $syntax
     * @param string $delimiter
     * @param string $negativeLookahead
     * @param callable|null $parser
     * @param bool $notCaptureDelimiter
     * @return array
     */
    public static function parseSyntax(string $syntax, string $delimiter = ',|', string $negativeLookahead = '', ?callable $parser = null, bool $notCaptureDelimiter = false): array
    {
        $clips = self::parseParens($syntax);

        if (is_callable($parser)) {
            $parser = $parser(...);
        }

        return ($parseExpr = function ($clips) use ($parser, $delimiter, $negativeLookahead, &$parseExpr, $notCaptureDelimiter) {
            $extracted = [];
            foreach ($clips as $clip) {
                if (is_array($clip)) {
                    $extracted[] = $parseExpr($clip);
                } else {
                    $splits = preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\[(?:\\\\.(*SKIP)|[^\[\]])*]|\((?:\\\\.(*SKIP)|[^()])*\)|\\\\.|(\w+\((?:[^()]|(?-1))*\)))(*SKIP)(*FAIL)|\s*([' . preg_quote($delimiter, '/') . ']' . (($negativeLookahead) ? '(?![' . preg_quote($negativeLookahead, '/') . '])' : '') . ')\s*/', $clip, -1, ($notCaptureDelimiter) ? PREG_SPLIT_NO_EMPTY : PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                    if (false === $splits) {
                        throw new \InvalidArgumentException('The delimiter or the ignored lookahead characters is invalid.');
                    }

                    // Apply the optional user-defined parser to each split token
                    if ($parser) {
                        foreach ($splits as &$split) {
                            $split = $parser($split);
                        }
                    }

                    $extracted = array_merge($extracted, $splits);
                }
            }

            return $extracted;
        })($clips);
    }

    /**
     * Extract the string that containing `(` and `)` into a nested array.
     *
     * @param string $text
     * @return array
     */
    public static function parseParens(string $text): array
    {
        // Recursive closure that consumes the input string, grouping content between '(' and ')'
        $closure = function (string &$clip, bool $opening = false) use (&$closure) {
            $extracted = [];
            if (!$clip) {
                return $extracted;
            }

            // Match the next unescaped '(' or ')' while skipping quoted strings,
            // bracketed expressions and nested function calls
            while (preg_match('/(?:\\\\.|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|(\w+\((?:[^()]|(?-1))*\)))(*SKIP)(*FAIL)|[()]/', $clip, $matches, PREG_OFFSET_CAPTURE)) {
                // Capture any text preceding the matched parenthesis
                if ($matches[0][1] > 0) {
                    $extracted[] = substr($clip, 0, $matches[0][1]);
                }

                // Advance past the matched character
                $clip = substr($clip, (int) $matches[0][1] + 1);
                if (')' == $matches[0][0]) {
                    // Closing paren: return the group if we're inside an opening; discard otherwise
                    return ($opening) ? $extracted : [];
                }
                // Opening paren: recurse to capture the nested group
                $extracted[] = $closure($clip, true);
            }

            // Append any remaining text after the last parenthesis match
            if ($clip) {
                $extracted[] = $clip;
            }

            return $extracted;
        };

        return $closure($text);
    }
}
