<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Database;

use Razy\Exception\QueryException;
use Razy\SimpleSyntax;
use Throwable;

/**
 * Class WhereSyntax
 *
 * Parses and generates SQL WHERE/HAVING clauses from the Where Simple Syntax.
 * Supports comparison operators (=, !=, >, <, >=, <=), pattern matching
 * (*=, ^=, $=, #=), JSON operators (~=, &=, @=, :=, |=), range/BETWEEN (><, <>),
 * NULL checks, array/IN operations, and logical grouping with AND (,) / OR (|).
 *
 * @package Razy
 * @license MIT
 */
class WhereSyntax
{
    /** @var string Regex to split expressions by comparison/custom operators while respecting quotes and escapes */
    const REGEX_SPLIT_OPERAND = '/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*([|*^$!#:@~&]?=|><|<>|(?<![\->])[><]=?)\s*/';
    /** @var string Regex to validate and parse column references (with optional table alias and JSON path) */
    const REGEX_COLUMN = '/^((?<column>`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)(?:\.((?P>column)))?)((->>?)([\'"])\$((?:\.[^.]+)+)\6)?$/';
    /**
     * @var array<string, string> Maps syntax separators to SQL logical operators.
     *   ',' => AND, '|' => OR
     */
    private const OPERAND_TYPE = [
        ',' => 'AND',
        '|' => 'OR',
    ];

    /** @var array Parsed WHERE expression tokens from SimpleSyntax::parseSyntax */
    private array $extracted = [];

    /** @var string Raw syntax string, preserved for callable re-parsing */
    private string $syntax = '';

    /**
     * WhereSyntax constructor.
     *
     * @param Statement $statement
     */
    public function __construct(private readonly Statement $statement)
    {

    }

    /**
     * Generate the WHERE/HAVING clause SQL string.
     * Recursively processes grouped expressions, negation operators,
     * and individual comparison expressions.
     *
     * @return string The generated SQL condition (without WHERE/HAVING keyword)
     * @throws Throwable
     */
    public function getSyntax(): string
    {
        // Recursive parser that walks the token tree and builds SQL
        $parser = function (array &$extracted) use (&$parser) {
            $parsed = [];
            $negative = false;
            while ($clip = array_shift($extracted)) {
                if (is_array($clip)) {
                    // Parenthesized sub-expression: recurse and optionally negate
                    $parsed[] = (($negative) ? '!' : '') . '(' . $parser($clip) . ')';
                    $negative = false;
                } else {
                    if (preg_match('/^!+$/', $clip)) {
                        $negative = (bool)(strlen($clip) % 2);
                    } else {
                        if (',' !== $clip && '|' !== $clip) {
                            if ('!' == $clip[0]) {
                                $negative = true;
                                $clip = substr($clip, 1);
                            }
                            $parsed[] = $this->parseExpr($clip, $negative);
                            $negative = false;
                        } else {
                            throw new QueryException('Invalid Where syntax');
                        }
                    }
                }

                $operand = array_shift($extracted);
                if ($operand) {
                    if (!preg_match('/^[,|]$/', $operand)) {
                        throw new QueryException('Invalid Where syntax');
                    }
                    $parsed[] = self::OPERAND_TYPE[$operand];
                }
            }

            return implode(' ', $parsed);
        };

        $extracted = $this->extracted;

        return $parser($extracted);
    }

    /**
     * Parse a single comparison expression into SQL.
     * Handles single-operand expressions, two-operand comparisons,
     * and all supported operator types.
     *
     * @param string $clip The expression token to parse
     * @param bool $negative Whether this expression is negated
     *
     * @return string The generated SQL expression
     * @throws Throwable
     */
    private function parseExpr(string $clip, bool $negative): string
    {
        // Split expression by operator, preserving quoted strings
        $splits = preg_split(self::REGEX_SPLIT_OPERAND, $clip, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        // Single operand (no operator): return as boolean or column reference
        if (1 == count($splits)) {
            $expr = $this->parseOperand($clip);
            if ('null' === $expr['type']) {
                return ($negative) ? '1' : '0';
            }
            if ('column' === $expr['type']) {
                return (($negative) ? '!' : '') . $expr['expr'];
            }

            if ($negative) {
                return '!(' . $expr['expr'] . ')';
            }

            return $expr['expr'];
        }

        // Two-operand comparison: left OPERATOR right
        if (3 == count($splits)) {
            // Prevent both operands from being auto-references
            if ($splits[0] == $splits[2]) {
                if ('?' === $splits[0]) {
                    throw new QueryException('You cannot set both operand as reference.');
                }
            }

            [$leftOperand, $operator, $rightOperand] = $splits;
            $leftOperand = $this->parseOperand($leftOperand);
            $rightOperand = $this->parseOperand($rightOperand);

            // Normalize: parameter references should always be on the right side
            if ('parameter' === $leftOperand['type']) {
                [$leftOperand, $rightOperand] = [$rightOperand, $leftOperand];
            }

            if ('auto' === $rightOperand['type']) {
                // Auto-reference '?': resolve value from the left column's parameter
                if ('column' === $leftOperand['type']) {
                    $value = $this->statement->getValue($leftOperand['column_name']);
                    if (null === $value) {
                        $rightOperand = [
                            'type' => 'null',
                            'expr' => 'NULL',
                        ];
                    } else {
                        $rightOperand['type'] = 'parameter';
                        $rightOperand['column_name'] = $leftOperand['column_name'];
                        if ($value instanceof Statement) {
                            $rightOperand['expr'] = '(' . $value->getSyntax() . ')';
                        } else {
                            $rightOperand['value'] = $value;
                            $rightOperand['expr'] = (is_string($value)) ? '"' . addslashes($value) . '"' : (float)$value;
                        }
                    }
                } else {
                    throw new QueryException('You cannot refer the non-column operand as a parameter.');
                }
            } elseif ('parameter' === $rightOperand['type']) {
                $value = $this->statement->getValue($rightOperand['name']);
                if ($value instanceof Statement) {
                    $rightOperand['expr'] = '(' . $value->getSyntax() . ')';
                } else {
                    $rightOperand['value'] = $value;
                    $rightOperand['expr'] = (is_string($value)) ? '"' . addslashes($value) . '"' : (float)$value;
                }
            }

            return $this->comparison($operator, $leftOperand, $rightOperand, $negative);
        }

        throw new QueryException('Invalid Where Syntax.');
    }

    /**
     * Parse an operand token into a typed structure.
     * Recognizes: '?' (auto), column references, :parameter references,
     * quoted strings, array literals [val1,val2], NULL, and raw expressions.
     *
     * @param string $expr The operand string to parse
     *
     * @return array Typed operand array with 'type', 'expr', and type-specific keys
     */
    private function parseOperand(string $expr): array
    {
        // Auto-reference: value will be resolved from the column on the other side
        if ('?' === $expr) {
            return [
                'type' => 'auto',
            ];
        }

        if ('null' !== strtolower($expr)) {
            // Column reference: optional table_alias.column_name with JSON path
            if (preg_match(self::REGEX_COLUMN, $expr, $matches)) {
                $table_alias = '';
                if (isset($matches[3]) && $matches[3]) {
                    $table_alias = trim($matches[2], '`');
                    $column_name = trim($matches[3], '`');
                } else {
                    $column_name = trim($matches[2], '`');
                }

                return [
                    'type' => 'column',
                    'column_name' => $column_name . ($matches[7] ?? ''),
                    'expr' => (($table_alias) ? '`' . $table_alias . '`.' : '') . '`' . $column_name . '`' . ($matches[4] ?? ''),
                ];
            }
            if (preg_match('/^(?:(`(?:\\\\.(*SKIP)(*FAIL)|.)+`)|([a-z]\w*))$/', $expr, $matches)) {
                return [
                    'type' => 'column',
                    'column_name' => trim($expr, '`'),
                    'expr' => '`' . trim($expr, '`') . '`',
                ];
            }
            // Named parameter reference :name
            if (preg_match('/^:(\w+)$/', $expr, $matches)) {
                $value = $this->statement->getValue($matches[1]);
                if (null === $value) {
                    return [
                        'type' => 'null',
                        'expr' => 'NULL',
                    ];
                }

                return [
                    'type' => 'parameter',
                    'name' => $matches[1],
                    'value' => $value,
                    'expr' => (is_scalar($value)) ? '"' . addslashes($value) . '"' : null,
                ];
            }
            // Quoted string literal (single or double quoted)
            if (preg_match('/^(?<q>[\'"])((?:(?!\k<q>).)*)\k<q>$/', $expr, $matches)) {
                return [
                    'type' => 'text',
                    'text' => $matches[2],
                    'expr' => '\'' . addslashes($matches[2]) . '\'',
                ];
            }

            // Array literal: [val1,val2,...] or ["str1","str2",...]
            if (preg_match('/^\[(.+)\]$/', $expr, $matches)) {
                $arrayContent = $matches[1];
                // Try to parse as JSON array first
                $jsonArray = json_decode('[' . $arrayContent . ']', true);
                if (is_array($jsonArray)) {
                    return [
                        'type' => 'array',
                        'value' => $jsonArray,
                        'expr' => $expr,
                    ];
                }

                // JSON parsing failed - this is a malformed array literal
                // Return as invalid_array type to allow operators to handle gracefully
                return [
                    'type' => 'invalid_array',
                    'raw' => $expr,
                    'expr' => $expr,
                ];
            }

            return [
                'type' => 'expr',
                'expr' => $expr,
            ];
        }

        return [
            'type' => 'null',
            'expr' => 'NULL',
        ];
    }

    /**
     * Generate the SQL comparison expression for a given operator and operands.
     * Handles all supported operators including: =, !=, >, <, >=, <=,
     * |= (IN), *= (LIKE contains), ^= (LIKE starts with), $= (LIKE ends with),
     * #= (REGEXP), @= (JSON key match), := (JSON_EXTRACT), ~= (JSON_CONTAINS),
     * &= (JSON_SEARCH), >< / <> (BETWEEN).
     *
     * @param string $operator The comparison operator symbol
     * @param array $leftOperand Parsed left operand
     * @param array $rightOperand Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL comparison expression
     * @throws Error
     */
    private function comparison(string $operator, array $leftOperand, array $rightOperand, bool $negative): string
    {
        // Check for invalid array literals early
        if ('invalid_array' === $rightOperand['type']) {
            throw new QueryException('Invalid array syntax: ' . $rightOperand['raw']);
        }

        if ('|=' === $operator) {
            return $this->buildInExpression($leftOperand, $rightOperand, $negative);
        } elseif ('#=' === $operator || '*=' === $operator || '^=' === $operator || '$=' === $operator) {
            return $this->buildPatternMatch($operator, $leftOperand, $rightOperand, $negative);
        } elseif ('@=' === $operator) {
            return $this->buildJsonKeyExists($leftOperand, $rightOperand, $negative);
        } elseif (':=' === $operator) {
            return $this->buildJsonPathExists($leftOperand, $rightOperand, $negative);
        } elseif ('~=' === $operator || '&=' === $operator) {
            return $this->buildJsonContains($operator, $leftOperand, $rightOperand, $negative);
        } elseif ('><' === $operator || '<>' === $operator) {
            return $this->buildBetween($leftOperand, $rightOperand, $negative);
        }

        return $this->buildBasicComparison($operator, $leftOperand, $rightOperand, $negative);
    }

    /**
     * Build an IN expression or JSON_CONTAINS for non-column left operands.
     * Handles the |= operator.
     *
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildInExpression(array $left, array $right, bool $negative): string
    {
        // IN operator: column IN(val1, val2, ...) or JSON_CONTAINS for non-column values
        if ('column' != $left['type']) {
            $leftExpr = $this->castAsJSON($left);
            $rightExpr = $this->castAsJSON($right);

            $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $rightExpr . ', \'$\') > 0';

            return ($negative) ? '!(' . $operand . ')' : $operand;
        }

        $rightExpr = $right['expr'];
        // Handle both 'parameter' and 'array' types with array values
        if (('parameter' === $right['type'] || 'array' === $right['type']) && is_array($right['value'])) {
            if (count($right['value'])) {
                $rightExpr = '';
                foreach ($right['value'] as $val) {
                    $val = is_numeric($val) ? $val : '\'' . addslashes($val) . '\'';
                    $rightExpr .= ($rightExpr) ? ', ' . $val : $val;
                }
            } else {
                return $left['expr'] . ' IS ' . (($negative) ? ' NOT' : '') . ' NULL';
            }
        }

        $leftExpr = $left['expr'];

        return $leftExpr . (($negative) ? ' NOT' : '') . ' IN(' . $rightExpr . ')';
    }

    /**
     * Build a pattern matching expression (REGEXP or LIKE).
     * Handles the #=, *=, ^=, $= operators.
     *
     * @param string $operator The pattern operator symbol
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildPatternMatch(string $operator, array $left, array $right, bool $negative): string
    {
        // Pattern matching operators: #= (REGEXP), *= (LIKE %x%), ^= (LIKE x%), $= (LIKE %x)
        $convertor = function ($operand) {
            if ('parameter' === $operand['type']) {
                if (is_array($operand['value']) || is_scalar($operand['value'])) {
                    return (is_scalar($operand['value'])) ? addslashes($operand['value']) : $this->jsonEncodeForSQL($operand['value']);
                }

                return '';
            }

            if ('text' === $operand['type']) {
                return addslashes($operand['text']);
            }

            if ('expr' === $operand['type']) {
                return $operand['expr'];
            }

            return '';
        };

        $leftExpr = ('column' != $left['type']) ? $convertor($left) : $left['expr'];
        if ('#=' === $operator) {
            $comparison = ' REGEXP ';
            $rightExpr = $right['expr'];
        } else {
            $comparison = 'LIKE';
            $rightExpr = ('column' != $right['type']) ? $this->insertWildcard($convertor($right), $operator) : $right['expr'];
        }

        return $leftExpr . (($negative) ? ' NOT' : '') . ' ' . $comparison . ' ' . $rightExpr;
    }

    /**
     * Build a JSON key existence check using JSON_KEYS.
     * Handles the @= operator.
     *
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildJsonKeyExists(array $left, array $right, bool $negative): string
    {
        // JSON key existence: check if a key exists in JSON object using JSON_KEYS
        $leftExpr = $this->castAsJSON($left, true);

        $isArray = false;
        $rightExpr = '';
        if ('parameter' === $right['type']) {
            if (is_array($right['value'])) {
                $isArray = true;
                $rightExpr = $this->jsonEncodeForSQL($right['value']);
            } elseif (is_scalar($right['value'])) {
                $rightExpr = addslashes($right['value']);
            }
        } elseif ('text' === $right['type']) {
            $rightExpr = addslashes($right['text']);
        } elseif ('expr' === $right['type']) {
            $rightExpr = addslashes($right['expr']);
        } elseif ('array' === $right['type']) {
            // Handle inline array literals for JSON key matching
            $isArray = true;
            $rightExpr = $this->jsonEncodeForSQL($right['value']);
        }

        if ($isArray) {
            // Use JSON_OVERLAPS for array matching (MySQL 8.0+)
            return 'JSON_OVERLAPS(JSON_KEYS(' . $leftExpr . '), CAST(\'' . $rightExpr . '\' AS JSON)) = ' . (($negative) ? '0' : '1');
        }

        return 'JSON_SEARCH(JSON_KEYS(' . $leftExpr . '), "one", CAST(\'' . $rightExpr . '\' AS CHAR)) IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
    }

    /**
     * Build a JSON path existence check using JSON_EXTRACT.
     * Handles the := operator.
     *
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     * @throws QueryException
     */
    private function buildJsonPathExists(array $left, array $right, bool $negative): string
    {
        // JSON path existence: check if a path exists using JSON_EXTRACT
        if ('text' === $right['type']) {
            if ('$' !== ($right['text'][0] ?? '')) {
                throw new QueryException('JSON path should start with $.');
            }
            $rightExpr = $right['expr'];
        } elseif ('parameter' === $right['type']) {
            if (!is_string($right['value'])) {
                throw new QueryException('The JSON path should be a string.');
            }
            if ('$' !== ($right['value'][0] ?? '')) {
                throw new QueryException('JSON path should start with $.');
            }

            $rightExpr = $right['expr'];
        } else {
            throw new QueryException('Invalid value passed to JSON_EXTRACT');
        }

        $leftExpr = $this->castAsJSON($left, true);

        return 'JSON_EXTRACT(' . $leftExpr . ', ' . $rightExpr . ') IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
    }

    /**
     * Build a JSON_CONTAINS or JSON_SEARCH expression.
     * Handles the ~= and &= operators.
     *
     * @param string $operator The JSON operator symbol (~= or &=)
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildJsonContains(string $operator, array $left, array $right, bool $negative): string
    {
        // ~= : JSON_CONTAINS (check if value exists in JSON array/object)
        // &= : JSON_SEARCH (search for text value in JSON)
        $leftExpr = $this->castAsJSON($left, true);
        if ('~=' === $operator) {
            if ('text' == $right['type'] || ('parameter' == $right['type'] && is_scalar($right['value']))) {
                $rightExpr = '\'"' . addslashes($right['text'] ?? $right['value']) . '"\'';
                $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $rightExpr . ') = 1';
            } else {
                $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $this->castAsJSON($right, true) . ') = 1';
            }

            return ($negative) ? '!(' . $operand . ')' : $operand;
        }

        return 'JSON_SEARCH(' . $leftExpr . ', \'one\', ' . $this->castAsJSON($right, true) . ') IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
    }

    /**
     * Build a BETWEEN expression.
     * Handles the >< and <> operators.
     *
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildBetween(array $left, array $right, bool $negative): string
    {
        // BETWEEN operator: col><[min,max] or col<>[min,max]
        $leftExpr = $left['expr'];

        // Right operand should be an array with 2 values (type 'array' or 'parameter' with array value)
        $arrayValue = null;
        if ('array' === $right['type'] && is_array($right['value'])) {
            $arrayValue = $right['value'];
        } elseif ('parameter' === $right['type'] && is_array($right['value'])) {
            $arrayValue = $right['value'];
        }

        if ($arrayValue !== null && count($arrayValue) === 2) {
            $values = array_values($arrayValue);
            $min = is_numeric($values[0]) ? $values[0] : "'" . addslashes($values[0]) . "'";
            $max = is_numeric($values[1]) ? $values[1] : "'" . addslashes($values[1]) . "'";

            return $leftExpr . (($negative) ? ' NOT' : '') . ' BETWEEN ' . $min . ' AND ' . $max;
        }

        // Fallback: just output as-is (shouldn't happen with proper syntax)
        return $leftExpr . ' ' . '><' . ' ' . $right['expr'];
    }

    /**
     * Build a basic comparison expression (=, !=, >, <, >=, <=).
     *
     * @param string $operator The comparison operator symbol
     * @param array $left Parsed left operand
     * @param array $right Parsed right operand
     * @param bool $negative Whether the comparison is negated
     *
     * @return string The generated SQL expression
     */
    private function buildBasicComparison(string $operator, array $left, array $right, bool $negative): string
    {
        // Basic comparison operators (=, !=, >, <, >=, <=)
        if ('!=' === $operator || '=' === $operator) {
            if ('!=' === $operator) {
                $negative = !$negative;
            }

            // Handle array values -> convert to IN/NOT IN for = and != operators
            if ('array' === $right['type'] && is_array($right['value'])) {
                if (count($right['value'])) {
                    $values = '';
                    foreach ($right['value'] as $val) {
                        $val = is_numeric($val) ? $val : '\'' . addslashes($val) . '\'';
                        $values .= ($values) ? ', ' . $val : $val;
                    }
                    return $left['expr'] . (($negative) ? ' NOT' : '') . ' IN(' . $values . ')';
                }
                return $left['expr'] . ' IS ' . (($negative) ? 'NOT ' : '') . 'NULL';
            }

            // NULL comparison uses IS / IS NOT instead of = / <>
            if ($left['type'] === 'null' || $right['type'] === 'null') {
                $operator = ($negative) ? 'IS NOT' : 'IS';
            } else {
                $operator = ($negative) ? '<>' : '=';
            }

            return $left['expr'] . ' ' . $operator . ' ' . $right['expr'];
        }

        $operand = $left['expr'] . ' ' . $operator . ' ' . $right['expr'];

        return ($negative) ? '!(' . $operand . ')' : $operand;
    }

    /**
     * Safely encode value as JSON for use in SQL string literal.
     * Escapes single quotes to prevent SQL injection.
     *
     * @param mixed $value
     * @return string
     */
    private function jsonEncodeForSQL(mixed $value): string
    {
        // json_encode handles all JSON escaping (double quotes, backslashes, unicode, etc.)
        // Then we escape single quotes for SQL string literal context
        return str_replace("'", "\\'", json_encode($value));
    }

    /**
     * Cast a parsed operand value to JSON for use in JSON SQL functions.
     * Wraps the value in CAST(... AS JSON) for SQL compatibility.
     *
     * @param array $operand The parsed operand
     * @param bool $acceptObject When true, preserves associative arrays; otherwise forces indexed arrays
     *
     * @return string The SQL expression with JSON casting
     */
    private function castAsJSON(array $operand, bool $acceptObject = false): string
    {
        if ('parameter' === $operand['type']) {
            if (is_array($operand['value'])) {
                return 'CAST(\'' . $this->jsonEncodeForSQL(($acceptObject) ? $operand['value'] : array_values($operand['value'])) . '\' AS JSON)';
            }
            if (is_scalar($operand['value'])) {
                return 'CAST(\'' . $this->jsonEncodeForSQL([$operand['value']]) . '\' AS JSON)';
            }

            return 'CAST(\'[]\' AS JSON)';
        }
        if ('text' === $operand['type']) {
            return 'CAST(\'' . $this->jsonEncodeForSQL([$operand['text']]) . '\' AS JSON)';
        }

        return $operand['expr'];
    }

    /**
     * Insert SQL LIKE wildcards (%) into a string based on the match operator type.
     * *= (contains) ??%value%, ^= (starts with) ??%value, $= (ends with) ??value%
     *
     * @param string $value The search value
     * @param string $type The operator type (*=, ^=, or $=)
     *
     * @return string The wildcarded value wrapped in quotes
     */
    private function insertWildcard(string $value, string $type = '*='): string
    {
        if ('*=' == $type || '$=' == $type) {
            $value = $value . '%';
        }

        if ('*=' == $type || '^=' == $type) {
            $value = '%' . $value;
        }

        return '\'' . $value . '\'';
    }

    /**
     * Parse the Where Simple Syntax string into structured tokens.
     * Supports callable syntax for dynamic syntax generation.
     * Uses SimpleSyntax::parseSyntax with ',' and '|' as separators
     * and '=' as the operator delimiter.
     *
     * @param string|callable $syntax The Where syntax string or callable returning one
     *
     * @return $this
     * @throws Error
     */
    public function parseSyntax(string|callable $syntax): WhereSyntax
    {
        if (is_callable($syntax)) {
            $syntax = call_user_func($syntax(...), $this->syntax);
        }

        if (!is_string($syntax)) {
            throw new QueryException('Invalid syntax data type, only string is allowed.');
        }

        $this->syntax = trim($syntax);
        $this->extracted = SimpleSyntax::parseSyntax($this->syntax, ',|', '=');

        return $this;
    }

    /**
     * Verify and optionally prefix-qualify a Where Simple Syntax string.
     * Returns the reconstructed syntax with column references prefixed,
     * or an empty string if the syntax is invalid.
     *
     * @param string $syntax The Where syntax to verify
     * @param string $prefix Optional table alias prefix to prepend to column references
     *
     * @return string The verified and prefix-qualified syntax, or empty on failure
     */
    static public function VerifySyntax(string $syntax, string $prefix = ''): string
    {
        // Prepend table alias prefix to unqualified column references in the syntax tree
        $prefix = ($prefix) ? $prefix . '.' : '';
        $parser = function (array &$extracted) use (&$parser, $prefix) {
            $parsed = [];
            $negative = false;
            while ($clip = array_shift($extracted)) {
                if (is_array($clip)) {
                    if (!($sub = $parser($clip))) {
                        return '';
                    }
                    $parsed[] = (($negative) ? '!' : '') . '(' . $sub . ')';
                    $negative = false;
                } else {
                    if (preg_match('/^!+$/', $clip)) {
                        $negative = (bool)(strlen($clip) % 2);
                    } else {
                        if (',' !== $clip && '|' !== $clip) {
                            if ('!' == $clip[0]) {
                                $negative = true;
                                $clip = substr($clip, 1);
                            }
                            $splits = preg_split(self::REGEX_SPLIT_OPERAND, $clip, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                            if (1 == count($splits)) {
                                $parsed[] = (($negative) ? '!' : '') . $clip;
                            } elseif (3 == count($splits)) {
                                if (preg_match(self::REGEX_COLUMN, $splits[0], $matches)) {
                                    if (!isset($matches[3]) || !$matches[3]) {
                                        $splits[0] = $prefix . $splits[0];
                                    }
                                }

                                if (preg_match(self::REGEX_COLUMN, $splits[2], $matches)) {
                                    if (!isset($matches[3]) || !$matches[3]) {
                                        $splits[2] = $prefix . $splits[2];
                                    }
                                }
                                $parsed[] = (($negative) ? '!' : '') . implode('', $splits);
                            } else {
                                return '';
                            }
                            $negative = false;
                        } else {
                            return '';
                        }
                    }
                }

                $operand = array_shift($extracted);
                if ($operand) {
                    if (!preg_match('/^[,|]$/', $operand)) {
                        print_r($parsed);
                        return '';
                    }
                    $parsed[] = $operand;
                }
            }

            return implode('', $parsed);
        };

        $extracted = SimpleSyntax::parseSyntax($syntax, ',|', '=');

        return $parser($extracted);
    }
}
