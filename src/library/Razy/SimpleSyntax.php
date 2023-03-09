<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;

class SimpleSyntax
{
    /**
     * Parse the Simple Syntax by given delimiter.
     *
     * @param string       $syntax
     * @param string       $delimiter
     * @param string       $negativeLookahead
     * @param Closure|null $parser
     *
     * @return array
     */
    public static function ParseSyntax(string $syntax, string $delimiter = ',|', string $negativeLookahead = '', ?Closure $parser = null): array
    {
        $clips = self::ParseParens($syntax);

        return ($parseExpr = function ($clips) use ($parser, $delimiter, $negativeLookahead, &$parseExpr) {
            $extracted = [];

            foreach ($clips as $clip) {
                if (is_array($clip)) {
                    $extracted[] = $parseExpr($clip);
                } else {
                    $splits = preg_split('/(?:(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\[(?:\\\\.(*SKIP)|[^\[\]])*]|\\\\.)(*SKIP)(*FAIL)|\s*([' . preg_quote($delimiter, '/') . ']' . (($negativeLookahead) ? '(?![' . preg_quote($negativeLookahead, '/') . '])' : '') . ')\s*/', $clip, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                    if (false === $splits) {
                        throw new Error('The delimiter or the ignored lookahead characters is invalid.');
                    }

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
    public static function ParseParens(string $text): array
    {
        $closure = function (string &$clip, bool $opening = false) use (&$closure) {
            $extracted = [];
            if (!$clip) {
                return $extracted;
            }

            while (preg_match('/(?:\\\\.|(?<w>\[)(?:\\\\.(*SKIP)|[^\[\]])*]|(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\w+\([^\(\)]*\))(*SKIP)(*FAIL)|[()]/', $clip, $matches, PREG_OFFSET_CAPTURE)) {
                if ($matches[0][1] > 0) {
                    $extracted[] = substr($clip, 0, $matches[0][1]);
                }

                $clip = substr($clip, $matches[0][1] + 1);
                if (')' == $matches[0][0]) {
                    return ($opening) ? $extracted : [];
                }
                $extracted[] = $closure($clip, true);
            }

            if ($clip) {
                $extracted[] = $clip;
            }

            return $extracted;
        };

        return $closure($text);
    }
}
