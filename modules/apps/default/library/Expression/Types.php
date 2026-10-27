<?php

/**
 * razymod/apps — expression value types.
 *
 * Types are strings so a typed AST stays plain JSON:
 *   number  text  boolean  date  datetime        scalars
 *   user  group  department                       principals (ids)
 *   status                                        a workflow state code (compares with text)
 *   ref:<entity>                                  a many-to-one relation value (record id)
 *   list:<type>                                   multi_select, MY_GROUPS(), IN lists
 *   blank                                         BLANK(); fits every other type
 *   opaque                                        files / images; only ISBLANK() may read them
 *
 * Logic is two-valued: a blank never makes a boolean blank. Comparisons
 * involving a blank are FALSE except `=` / `<>` (blank = blank is TRUE), and
 * AND / OR / IF / NOT read a blank condition as FALSE. A SQL translation
 * must keep this (IS [NOT] DISTINCT FROM, COALESCE(…, FALSE)).
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

final class Types
{
    public const SCALARS = [
        'number' => true,
        'text' => true,
        'boolean' => true,
        'date' => true,
        'datetime' => true,
        'user' => true,
        'group' => true,
        'department' => true,
        'status' => true,
    ];

    public const ORDERED = ['number' => true, 'text' => true, 'date' => true, 'datetime' => true];

    public static function isList(string $type): bool
    {
        return \str_starts_with($type, 'list:');
    }

    public static function itemType(string $type): string
    {
        return \substr($type, 5);
    }

    /**
     * Whether an argument of type $arg may be passed where $param is declared.
     */
    public static function accepts(string $param, string $arg): bool
    {
        if ($param === 'any') {
            return true;
        }
        if ($arg === 'blank') {
            return $param !== 'list';
        }
        if ($param === 'scalar') {
            return isset(self::SCALARS[$arg]) || \str_starts_with($arg, 'ref:');
        }
        foreach (\explode('|', $param) as $one) {
            if ($one === $arg || ($one === 'text' && $arg === 'status')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The common type of two branches / operands, or null when they do not mix.
     */
    public static function unify(string $a, string $b): ?string
    {
        if ($a === $b) {
            return $a;
        }
        if ($a === 'blank') {
            return $b;
        }
        if ($b === 'blank') {
            return $a;
        }
        $pair = [$a, $b];
        \sort($pair);
        if ($pair === ['status', 'text']) {
            return 'text';
        }
        if ($pair === ['date', 'datetime']) {
            return 'datetime';
        }

        return null;
    }

    public static function equatable(string $a, string $b): bool
    {
        $u = self::unify($a, $b);

        return $u !== null && $u !== 'opaque' && !self::isList($u);
    }

    public static function ordered(string $a, string $b): bool
    {
        $u = self::unify($a, $b);

        return $u !== null && (isset(self::ORDERED[$u]) || $u === 'blank');
    }
}
