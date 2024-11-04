<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunctionCustom;

return function (...$arguments) {
    return new class(...$arguments) extends TFunctionCustom {
        protected bool $encloseContent = true;

        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            $clips = SimpleSyntax::ParseParens($syntax);

            $recursive = function (array $clips) use (&$recursive, $entity) {
                $value = null;
                $reverse = false;
                while ($clip = array_shift($clips)) {
                    if (is_array($clip)) {
                        $value = $recursive($clip);
                        if ($reverse) {
                            $value = !$value;
                            $reverse = false;
                        }
                    } else {
                        while (preg_match('/^\s*(!)?(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*|-?\d+(?:\.\d+)?|(?P>rq))(?:([><!^$|]?=|<|>)((?P>value)))?([,|](!)?)?\s*/', $clip, $matches, PREG_OFFSET_CAPTURE)) {
                            $operand = $entity->parseValue($matches['value'][0]);
                            if (isset($matches[6]) && strlen($matches[6][0])) {
                                $compare = $entity->parseValue($matches[6][0]);
                                $value = comparison($operand, $compare, $matches[5][0]);
                            } else {
                                $value = (is_scalar($operand) && $operand) || (is_array($operand) && !empty($operand));
                            }

                            if ($matches[1][0]) {
                                $value = !$value;
                            }

                            // If the negative operator is given, reverse the bool of the result
                            if ($reverse) {
                                $value = !$value;
                                $reverse = false;
                            }

                            if (!isset($matches[7])) {
                                // If the operator is not exists, the statement should be completed
                                if (strlen($matches[0][0]) !== strlen($clip)) {
                                    return false;
                                }
                            } else {
                                // Return false if the previous comparison is false and the operator is the `AND`
                                if (false === $value && ',' === $matches[7][0]) {
                                    return false;
                                }

                                if (true === $value && '|' === $matches[7][0]) {
                                    return true;
                                }

                                $reverse = isset($matches[8]);
                            }
                            $clip = substr($clip, (int)$matches[0][1] + strlen($matches[0][0]));
                        }
                    }
                }

                return $value;
            };

            $split = preg_split('/\\.(*SKIP)(*FAIL)|{@else}/', $entity->parseText($wrappedText), 2);
            $trueText = $split[0];
            $falseText = $split[1] ?? '';

            return $entity->parseText(($recursive($clips)) ? $trueText : $falseText);
        }
    };
};