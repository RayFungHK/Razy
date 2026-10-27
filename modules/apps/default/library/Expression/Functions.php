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
 *   ret   return type ('T' = the unified generic; 'N' = decimal when every
 *         numeric argument is a decimal, number (float) when any is)
 *   blank what a blank argument (null or '') does, enforced in one place by
 *         the evaluator (and by the SQL translation, RZA-C):
 *         propagate        any blank argument gives a blank result
 *         skip             blank arguments are left out (SUM of none is 0;
 *                          AVERAGE / MIN / MAX / COALESCE of none is blank)
 *         empty_text       text arguments read a blank as ''; a blank number
 *                          argument (a count, a position) gives ''
 *                          (CONTAINS(blank, blank) is TRUE: '' contains '')
 *         false            a blank condition is FALSE (NOT(blank) is TRUE)
 *         condition_false  IF: a blank condition takes the else branch
 *         test             ISBLANK: the question itself
 *         none             takes no arguments
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
    public const ORDERED = ['decimal', 'number', 'text', 'date', 'datetime'];

    public const BLANK_POLICIES = ['propagate', 'skip', 'empty_text', 'false', 'condition_false', 'test', 'none'];

    /** @var array<string, array{args: list<string>, min: int, max: ?int, ret: string, blank: string, sql: bool}> */
    public const LIST = [
        // logic
        'IF' => ['args' => ['boolean', 'T', 'T'], 'min' => 3, 'max' => 3, 'ret' => 'T', 'blank' => 'condition_false', 'sql' => true],
        'AND' => ['args' => ['boolean', '...'], 'min' => 1, 'max' => null, 'ret' => 'boolean', 'blank' => 'false', 'sql' => true],
        'OR' => ['args' => ['boolean', '...'], 'min' => 1, 'max' => null, 'ret' => 'boolean', 'blank' => 'false', 'sql' => true],
        'NOT' => ['args' => ['boolean'], 'min' => 1, 'max' => 1, 'ret' => 'boolean', 'blank' => 'false', 'sql' => true],
        'ISBLANK' => ['args' => ['any'], 'min' => 1, 'max' => 1, 'ret' => 'boolean', 'blank' => 'test', 'sql' => true],
        'COALESCE' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'blank' => 'skip', 'sql' => true],
        'BLANK' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'blank', 'blank' => 'none', 'sql' => true],
        // maths
        'ABS' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'N', 'blank' => 'propagate', 'sql' => true],
        'ROUND' => ['args' => ['number', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'N', 'blank' => 'propagate', 'sql' => true],
        'FLOOR' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'N', 'blank' => 'propagate', 'sql' => true],
        'CEILING' => ['args' => ['number'], 'min' => 1, 'max' => 1, 'ret' => 'N', 'blank' => 'propagate', 'sql' => true],
        'MOD' => ['args' => ['number', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'N', 'blank' => 'propagate', 'sql' => true],
        'POWER' => ['args' => ['number', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'number', 'blank' => 'propagate', 'sql' => true],
        'MIN' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'blank' => 'skip', 'sql' => true],
        'MAX' => ['args' => ['T', '...'], 'min' => 1, 'max' => null, 'ret' => 'T', 'blank' => 'skip', 'sql' => true],
        'SUM' => ['args' => ['number', '...'], 'min' => 1, 'max' => null, 'ret' => 'N', 'blank' => 'skip', 'sql' => true],
        'AVERAGE' => ['args' => ['number', '...'], 'min' => 1, 'max' => null, 'ret' => 'N', 'blank' => 'skip', 'sql' => true],
        // text
        'LEN' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'empty_text', 'sql' => true],
        'LEFT' => ['args' => ['text', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'RIGHT' => ['args' => ['text', 'number'], 'min' => 1, 'max' => 2, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'MID' => ['args' => ['text', 'number', 'number'], 'min' => 3, 'max' => 3, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => false],
        'UPPER' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'LOWER' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'TRIM' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'CONCAT' => ['args' => ['scalar', '...'], 'min' => 1, 'max' => null, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        'CONTAINS' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'blank' => 'empty_text', 'sql' => true],
        'STARTSWITH' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'blank' => 'empty_text', 'sql' => true],
        'ENDSWITH' => ['args' => ['text', 'text'], 'min' => 2, 'max' => 2, 'ret' => 'boolean', 'blank' => 'empty_text', 'sql' => true],
        'SUBSTITUTE' => ['args' => ['text', 'text', 'text'], 'min' => 3, 'max' => 3, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => false],
        'VALUE' => ['args' => ['text'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => false],
        'TEXT' => ['args' => ['scalar'], 'min' => 1, 'max' => 1, 'ret' => 'text', 'blank' => 'empty_text', 'sql' => true],
        // dates and working days (Mon-Fri, holidays from the evaluation context)
        'TODAY' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'date', 'blank' => 'none', 'sql' => true],
        'NOW' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'datetime', 'blank' => 'none', 'sql' => true],
        'DATE' => ['args' => ['number', 'number', 'number'], 'min' => 3, 'max' => 3, 'ret' => 'date', 'blank' => 'propagate', 'sql' => true],
        'YEAR' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => true],
        'MONTH' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => true],
        'DAY' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => true],
        'WEEKDAY' => ['args' => ['date|datetime'], 'min' => 1, 'max' => 1, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => true],
        'DAYS' => ['args' => ['date', 'date'], 'min' => 2, 'max' => 2, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => true],
        'EDATE' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'blank' => 'propagate', 'sql' => false],
        'EOMONTH' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'blank' => 'propagate', 'sql' => false],
        'WORKDAY' => ['args' => ['date', 'number'], 'min' => 2, 'max' => 2, 'ret' => 'date', 'blank' => 'propagate', 'sql' => false],
        'NETWORKDAYS' => ['args' => ['date', 'date'], 'min' => 2, 'max' => 2, 'ret' => 'decimal', 'blank' => 'propagate', 'sql' => false],
        // the acting principal (resolved by the host's principal adapter)
        'CURRENT_USER' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'user', 'blank' => 'none', 'sql' => true],
        'MY_GROUPS' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'list:group', 'blank' => 'none', 'sql' => true],
        'MY_DEPARTMENTS' => ['args' => [], 'min' => 0, 'max' => 0, 'ret' => 'list:department', 'blank' => 'none', 'sql' => true],
    ];

    public static function has(string $name): bool
    {
        return isset(self::LIST[$name]);
    }

    /**
     * @return array{args: list<string>, min: int, max: ?int, ret: string, blank: string, sql: bool}
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
