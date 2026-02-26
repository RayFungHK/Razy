<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Template Function Plugin: if
 *
 * Provides conditional logic within templates. Evaluates a complex expression syntax
 * supporting variables, comparisons (=, !=, <, >, <=, >=, ^=, $=, |=),
 * logical operators (AND via comma, OR via pipe), negation (!), and parenthesized grouping.
 * Supports an optional {@else} block for the false branch.
 * Usage in templates: {@if $var='value'}true content{@else}false content{/if}
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Razy\Template\Entity;
use Razy\Template\Plugin\TFunctionCustom;

use Razy\Util\ArrayUtil;
/**
 * Factory closure that creates and returns the `if` function plugin instance.
 *
 * @param mixed ...$arguments Arguments forwarded to the anonymous TFunctionCustom class constructor
 *
 * @return TFunctionCustom The function plugin instance for conditional rendering
 */
return function (...$arguments) {
    return new class(...$arguments) extends TFunctionCustom {
        /** @var bool This function requires enclosed content between opening and closing tags */
        protected bool $encloseContent = true;

        /**
         * Process the if function by evaluating a conditional expression.
         *
         * Parses the condition syntax into parenthesized groups, then recursively
         * evaluates each group with support for comparison operators, logical
         * AND/OR chaining, and negation.
         *
         * @param Entity $entity      The current template entity context
         * @param string $syntax      The raw condition expression syntax
         * @param string $wrappedText The enclosed content (may contain {@else} divider)
         *
         * @return string The rendered true or false branch based on condition result
         */
        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            $clips = SimpleSyntax::parseParens($syntax);

            /**
             * Recursive evaluator for condition expression clips.
             *
             * Handles nested parenthesized groups, variable resolution, comparison
             * operators, and logical AND (,) / OR (|) chaining with optional negation (!).
             *
             * @param array $clips The parsed expression clips to evaluate
             *
             * @return bool|null The boolean result of the expression evaluation
             */
            $recursive = function (array $clips) use (&$recursive, $entity) {
                $value = null;
                $reverse = false;
                while ($clip = array_shift($clips)) {
                    if (is_array($clip)) {
                        // Recursively evaluate parenthesized sub-expressions
                        $value = $recursive($clip);
                        if ($reverse) {
                            $value = !$value;
                            $reverse = false;
                        }
                    } else {
                        // Match variables, literals, comparison operators, and logical connectors
                        while (preg_match('/^\s*(!)?(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*|-?\d+(?:\.\d+)?|(?P>rq))(?:([><!^$|]?=|<|>)((?P>value)))?([,|](!)?)?\s*/', $clip, $matches, PREG_OFFSET_CAPTURE)) {
                            // Resolve the operand value from the entity context
                            $operand = $entity->parseValue($matches['value'][0]);
                            if (isset($matches[6]) && strlen($matches[6][0])) {
                                // Comparison mode: resolve right-hand operand and compare
                                $compare = $entity->parseValue($matches[6][0]);
                                $value = ArrayUtil::comparison($operand, $compare, $matches[5][0]);
                            } else {
                                // Truthiness check: scalar truthy or non-empty array
                                $value = (is_scalar($operand) && $operand) || (is_array($operand) && !empty($operand));
                            }

                            // Apply the inline negation operator (!) if present
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
                                    // Short-circuit: return true on OR if the previous result was true
                                    return true;
                                }

                                $reverse = isset($matches[8]);
                            }

                            // Advance past the matched portion of the clip string
                            $clip = substr($clip, (int)$matches[0][1] + strlen($matches[0][0]));
                        }
                    }
                }

                return $value;
            };

            // Split the wrapped content into true and false branches at the {@else} marker
            $split = preg_split('/\\.(*SKIP)(*FAIL)|{@else}/', $entity->parseText($wrappedText), 2);
            $trueText = $split[0];
            $falseText = $split[1] ?? '';

            // Evaluate the condition and return the appropriate branch
            return $entity->parseText(($recursive($clips)) ? $trueText : $falseText);
        }
    };
};