<?php

/*
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Template Function Plugin: if.
 *
 * Provides conditional logic within templates. Evaluates a complex expression syntax
 * supporting variables, comparisons (=, !=, <, >, <=, >=, ^=, $=, |=),
 * logical operators (AND via comma, OR via pipe), negation (!), and parenthesized grouping.
 * Supports an optional {@else} block for the false branch.
 * Usage in templates: {@if $var='value'}true content{@else}false content{/if}
 *
 *
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
        /**
         * One operand, an optional comparison, then an optional AND/OR and a bang
         * that belongs to the next operand. The bang after "," or "|" is inside
         * capture 7 (`|!`), so the operator is capture 7's first character (RZ-54).
         */
        private const OPERAND = '/^\s*(!)?(?<value>\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*|-?\d+(?:\.\d+)?|(?P>rq))(?:([><!^$|]?=|<|>)((?P>value)))?([,|](!)?)?\s*/';

        /** @var bool This function requires enclosed content between opening and closing tags */
        protected bool $encloseContent = true;

        // The chosen branch is template text: the caller substitutes its {$…} once (RZ-30).
        protected bool $templateOutput = true;

        /**
         * Process the if function by evaluating a conditional expression.
         *
         * @param Entity $entity The current template entity context
         * @param string $syntax The raw condition expression syntax
         * @param string $wrappedText The enclosed content (may contain {@else} divider)
         *
         * @return string The rendered true or false branch based on condition result
         */
        public function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): string
        {
            // parseParens splits "(0),(1)" into a group, the ",", and a group, and
            // "!(0)" into "!" and a group. Those operator clips used to be skipped,
            // so the last group won and a leading "!" never flipped (RZ-54).
            $events = [];
            $passed = $this->compileEvents(SimpleSyntax::parseParens($syntax), $events)
                && $this->runEvents($entity, $events);

            // The condition is already resolved above, once, from the tag source.
            // Split the source on this body's {@else} before any substitution, then
            // expand function tags on the chosen branch only. Do not parseText here:
            // the caller substitutes {$…} once, and a second pass would execute
            // template syntax that arrived inside a value (RZ-30).
            [$trueText, $falseText] = $entity->splitTopElse($wrappedText);

            return $entity->expandFunctionTags($passed ? $trueText : $falseText);
        }

        /**
         * @param array<int, mixed> $clips
         * @param list<array{0: string, 1?: mixed}> $events
         */
        private function compileEvents(array $clips, array &$events): bool
        {
            foreach ($clips as $clip) {
                if (\is_array($clip)) {
                    $events[] = ['group', $clip];
                    continue;
                }

                if (!\is_string($clip) || '' === \trim($clip)) {
                    continue;
                }

                if (!$this->appendClipEvents($clip, $events)) {
                    return false;
                }
            }

            return true;
        }

        /**
         * @param list<array{0: string, 1?: mixed}> $events
         */
        private function appendClipEvents(string $clip, array &$events): bool
        {
            if ($this->clipIsOnlyOperator($clip, $events)) {
                return true;
            }

            // "(0)|1" arrives as the clip "|1", and "2>(1)" leaves ">" after the "2".
            $glued = $this->peelGluedOperator($clip, $events);

            while (\preg_match(self::OPERAND, $clip, $matches, \PREG_OFFSET_CAPTURE)) {
                $events[] = ['val', $matches['value'][0]];

                if (isset($matches[6][0]) && '' !== $matches[6][0]) {
                    $events[] = ['cmp', $matches[5][0]];
                    $events[] = ['val', $matches[6][0]];
                }

                // The "!" in "!1=0" flips the comparison, not the left number.
                if ('' !== ($matches[1][0] ?? '')) {
                    $events[] = ['flip'];
                }

                $logic = $matches[7][0] ?? '';
                $joined = '' !== $logic && (',' === $logic[0] || '|' === $logic[0]);

                if ($joined) {
                    $events[] = ',' === $logic[0] ? ['and'] : ['or'];

                    if ('' !== ($matches[8][0] ?? '')) {
                        $events[] = ['not'];
                    }
                }

                $clip = \substr($clip, (int) $matches[0][1] + \strlen($matches[0][0]));

                if (!$joined) {
                    // "2>(1)" matched the "2" and left ">". The next clip is the group.
                    return '' === $clip || $this->clipIsOnlyOperator($clip, $events);
                }
            }

            // A leading operator with no operand ("|???") is not a condition.
            return !$glued || '' === $clip;
        }

        /**
         * A clip that is only the operator parseParens placed between two groups.
         *
         * @param list<array{0: string, 1?: mixed}> $events
         */
        private function clipIsOnlyOperator(string $clip, array &$events): bool
        {
            if (\preg_match('/^\s*([,|])?\s*(!*)\s*$/', $clip, $bare) && ('' !== ($bare[1] ?? '') || '' !== ($bare[2] ?? ''))) {
                if (',' === ($bare[1] ?? '')) {
                    $events[] = ['and'];
                } elseif ('|' === ($bare[1] ?? '')) {
                    $events[] = ['or'];
                }

                for ($i = 0, $n = \strlen($bare[2] ?? ''); $i < $n; ++$i) {
                    $events[] = ['not'];
                }

                return true;
            }

            if (\preg_match('/^\s*(!=|<=|>=|[><!^$|]?=|<|>)\s*$/', $clip, $cmp)) {
                $events[] = ['cmp', $cmp[1]];

                return true;
            }

            return false;
        }

        /**
         * parseParens glues the operator to a bare operand that follows a group:
         * "(0)|1" is "|1", "(1),0" is ",0", "(1)=0" is "=0". "|=" is "in",
         * not OR plus equals. A clip that is only the operator was already emitted.
         *
         * @param list<array{0: string, 1?: mixed}> $events
         */
        private function peelGluedOperator(string &$clip, array &$events): bool
        {
            // A condition that starts with an operator has no left side. Leave it
            // unmatched, as before, so "{@if >=1}" stays false instead of comparing null.
            if ([] === $events) {
                return false;
            }

            $peeled = false;

            if (\preg_match('/^\s*(\|=)\s*/', $clip, $contains)) {
                $events[] = ['cmp', $contains[1]];
                $clip = \substr($clip, \strlen($contains[0]));
                $peeled = true;
            } elseif (\preg_match('/^\s*([,|])\s*(!*)\s*/', $clip, $lead)) {
                $events[] = ',' === $lead[1] ? ['and'] : ['or'];

                for ($i = 0, $n = \strlen($lead[2]); $i < $n; ++$i) {
                    $events[] = ['not'];
                }

                $clip = \substr($clip, \strlen($lead[0]));
                $peeled = true;
            }

            if (\preg_match('/^\s*(!=|<=|>=|[><!^$|]?=|<|>)\s*/', $clip, $cmp)) {
                $events[] = ['cmp', $cmp[1]];
                $clip = \substr($clip, \strlen($cmp[0]));
                $peeled = true;
            }

            return $peeled;
        }

        /**
         * A group that is only one operand keeps that operand, so "(2)>(1)" compares
         * the numbers. A group that already combined operators yields its bool.
         *
         * @param list<array{0: string, 1?: mixed}> $events
         *
         * @return array{raw: mixed}|null
         */
        private function pureOperand(Entity $entity, array $events): ?array
        {
            if (1 !== \count($events)) {
                return null;
            }

            if ('val' === $events[0][0]) {
                return ['raw' => $entity->parseValue((string) $events[0][1])];
            }

            if ('group' !== $events[0][0] || !\is_array($events[0][1])) {
                return null;
            }

            $inner = [];

            if (!$this->compileEvents($events[0][1], $inner)) {
                return null;
            }

            return $this->pureOperand($entity, $inner);
        }

        /**
         * @param list<array{0: string, 1?: mixed}> $events
         */
        private function runEvents(Entity $entity, array $events): bool
        {
            $value = null;
            $lhs = null;
            $negate = false;
            $pendingCmp = null;

            foreach ($events as $event) {
                $kind = $event[0];

                if ('not' === $kind) {
                    $negate = !$negate;
                    continue;
                }

                if ('flip' === $kind) {
                    if (null !== $value) {
                        $value = !$value;
                        $lhs = $value;
                    }
                    continue;
                }

                if ('and' === $kind) {
                    if (false === $value) {
                        return false;
                    }
                    continue;
                }

                if ('or' === $kind) {
                    if (true === $value) {
                        return true;
                    }
                    continue;
                }

                if ('cmp' === $kind) {
                    $pendingCmp = $event[1];
                    continue;
                }

                if ('group' === $kind && \is_array($event[1])) {
                    $inner = [];

                    if (!$this->compileEvents($event[1], $inner)) {
                        return false;
                    }

                    $pure = $this->pureOperand($entity, $inner);
                    $next = null !== $pure ? $pure['raw'] : $this->runEvents($entity, $inner);
                } else {
                    $next = $entity->parseValue((string) ($event[1] ?? ''));
                }

                if (null !== $pendingCmp) {
                    $value = ArrayUtil::comparison($lhs, $next, (string) $pendingCmp);
                    $pendingCmp = null;
                    $lhs = $value;
                } else {
                    $value = $this->operandIsTrue($next);
                    $lhs = $next;
                }

                if ($negate) {
                    $value = !$value;
                    $lhs = $value;
                    $negate = false;
                }
            }

            // "(1)>" has a comparison and no right-hand side.
            if (null !== $pendingCmp) {
                return false;
            }

            return true === $value;
        }

        private function operandIsTrue(mixed $operand): bool
        {
            return (\is_scalar($operand) && $operand) || (\is_array($operand) && !empty($operand));
        }
    };
};
