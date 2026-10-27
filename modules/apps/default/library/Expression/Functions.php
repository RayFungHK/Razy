<?php

/**
 * razymod/apps — the closed function list of the expression language.
 *
 * This table IS the language: a name that is not a key here is refused by
 * the type checker (ExpressionError::UNKNOWN_FUNCTION), and the evaluator
 * dispatches with a `match` on these names, never by calling a PHP function
 * named in the input. No user-defined functions, no loops, no regex.
 *
 * Signature fields:
 *   args  parameter types in order; 'T' is the call's generic type, unified
 *         across every 'T' argument; a trailing '...' repeats the last type
 *   min / max  argument count (max null = variadic up to expression_args)
 *   ret   return type ('T' = the unified generic)
 *   sql   true when the function has a SQL translation, so it may appear in
 *         saved filters and row rules (compiled to bound SQL in RZA-C)
 *
 * Parameter type names are Types:: names or a '|' union of them.
 */

declare(strict_types=1);

namespace razymod\apps\Expression;

final class Functions
{
    /** Generic types allowed for 'T' in MIN / MAX. */
    public const ORDERED = ['number', 'text', 'date', 'datetime'];

    /** @var array<string, array{args: list<string>, min: int, max: ?int, ret: string, sql: bool}> */
    public const LIST = [
        // logic
        'IF' => ['args' => ['boolean', 'T', 'T'], 'min' => 3, 'max' => 3, 'ret' => 'T', 'sql' => true],
        'AND' => ['args' => ['boolean', '...'], 'min' => 1, 'max' => null, 'ret' => 'boolean', 'sql' => true],
        'OR' => ['args' => ['boolean', '...'], 'min' => 1, 'max' => null, 'ret' => 'boolean', 'sql' => true],
        'NOT' => ['args' => ['boolean'], 'min' => 1, 'max' => 1, 'ret' => 'boolean', 'sql' => true],
        'ISBLANK' => ['args' => ['any'], 'min' => 1, 'max' => 1, 'ret' => 'boolean', 'sql' => true],
        'COALESCE' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'sql' => true],
        'BLANK' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'blank', 'sql' => true],
        // maths
        'ABS' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'ROUND' => ['args' => ['number', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'number', 'sql' => true],
        'FLOOR' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'CEILING' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'MOD' => ['args' => ['number', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'number', 'sql' => true],
        'POWER' => ['args' => ['number', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'number', 'sql' => true],
        'MIN' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'sql' => true],
        'MAX' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'sql' => true],
        'SUM' => ['args' => ['number', '...'], 'min' => 1, 'max' => null, 'ret' => 'number', 'sql' => true],
        'AVERAGE' => ['args' => ['number', '...'], 'min' => 1, 'max' => null, 'ret' => 'number', 'sql' => true],
        // text
        'LEN' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'LEFT' => ['args' => ['text', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'text', 'sql' => true],
        'RIGHT' => ['args' => ['text', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'text', 'sql' => true],
        'MID' => ['args' => ['text', 'number', 'number'], 'min' => 3, 'max' => 3, 'ret' => 'text', 'sql' => false],
        'UPPER' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'sql' => true],
        'LOWER' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'sql' => true],
        'TRIM' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'sql' => true],
        'CONCAT' => ['args' => ['scalar', '...'], 'min' => 1, 'max' => null, 'ret' => 'text', 'sql' => true],
        'CONTAINS' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'sql' => true],
        'STARTSWITH' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'sql' => true],
        'ENDSWITH' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'sql' => true],
        'SUBSTITUTE' => ['args' => ['text', 'text', 'text'], 'min' => 3, 'max' => 3, 'ret' => 'text', 'sql' => false],
        'VALUE' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => false],
        'TEXT' => ['args' => ['scalar'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'sql' => true],
        // dates and working days (Mon-Fri, holidays from the evaluation context)
        'TODAY' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'date', 'sql' => true],
        'NOW' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'datetime', 'sql' => true],
        'DATE' => ['args' => ['number', 'number', 'number'], 'min' => 3, 'max' => 3, 'ret' => 'date', 'sql' => true],
        'YEAR' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'MONTH' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'DAY' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'WEEKDAY' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'number', 'sql' => true],
        'DAYS' => ['args' => ['date', 'date'], 'min' => 2, 'max' => 2, 'ret' => 'number', 'sql' => true],
        'EDATE' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'sql' => false],
        'EOMONTH' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'sql' => false],
        'WORKDAY' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'sql' => false],
        'NETWORKDAYS' => ['args' => ['date', 'date'], 'min' => 2, 'max' => 2, 'ret' => 'number', 'sql' => false],
        // the acting principal (resolved by the host's principal adapter)
        'CURRENT_USER' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'user', 'sql' => true],
        'MY_GROUPS' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'list:group', 'sql' => true],
        'MY_DEPARTMENTS' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'list:department', 'sql' => true],
    ];

    public static function has(string $name): bool
    {
        return isset(self::LIST[$name]);
    }

    /**
     * @return array{args: list<string>, min: int, max: ?int, ret: string, sql: bool}
     */
    public static function get(string $name): array
    {
        return self::LIST[$name];
    }

    /**
     * The parameter type for argument $i (expanding a trailing '...').
     */
    public static function paramType(string $name, int $i): string
    {
        $args = self::LIST[$name]['args'];
        $n = \count($args);
        if ($n > 0 && $args[$n - 1] === '...') {
            return $i < $n - 1 ? $args[$i] : $args[$n - 2];
        }

        return $args[$i] ?? 'any';
    }
}
