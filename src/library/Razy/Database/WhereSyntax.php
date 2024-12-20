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

use Razy\Error;
use Razy\SimpleSyntax;
use Throwable;

class WhereSyntax
{
    const REGEX_SPLIT_OPERAND = '/(?:\\\\.|\((?:\\\\.(*SKIP)|[^()])*\)|(?<q>[\'"`])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>)(*SKIP)(*FAIL)|\s*([|*^$!#:@~&]?=|><|<>|(?<![\->])[><]=?)\s*/';
    const REGEX_COLUMN = '/^((?<column>`(?:\\\\.(*SKIP)(*FAIL)|.)+`|[a-z]\w*)(?:\.((?P>column)))?)((->>?)([\'"])\$((?:\.[^.]+)+)\6)?$/';
    private const OPERAND_TYPE = [
        ',' => 'AND',
        '|' => 'OR',
    ];

    private array $extracted = [];
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
     * Generate the WHERE statement.
     *
     * @return string
     * @throws Throwable
     *
     */
    public function getSyntax(): string
    {
        $parser = function (array &$extracted) use (&$parser) {
            $parsed = [];
            $negative = false;
            while ($clip = array_shift($extracted)) {
                if (is_array($clip)) {
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
                            throw new Error('Invalid Where syntax');
                        }
                    }
                }

                $operand = array_shift($extracted);
                if ($operand) {
                    if (!preg_match('/^[,|]$/', $operand)) {
                        throw new Error('Invalid Where syntax');
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
     * Parse the expression of the each.
     *
     * @param string $clip
     * @param bool $negative
     *
     * @return string
     * @throws Throwable
     *
     */
    private function parseExpr(string $clip, bool $negative): string
    {
        $splits = preg_split(self::REGEX_SPLIT_OPERAND, $clip, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
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

        if (3 == count($splits)) {
            if ($splits[0] == $splits[2]) {
                if ('?' === $splits[0]) {
                    throw new Error('You cannot set both operand as reference.');
                }
            }

            [$leftOperand, $operator, $rightOperand] = $splits;
            $leftOperand = $this->parseOperand($leftOperand);
            $rightOperand = $this->parseOperand($rightOperand);

            if ('parameter' === $leftOperand['type']) {
                [$leftOperand, $rightOperand] = [$rightOperand, $leftOperand];
            }

            if ('auto' === $rightOperand['type']) {
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
                    throw new Error('You cannot refer the non-column operand as a parameter.');
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

        throw new Error('Invalid Where Syntax.');
    }

    /**
     * Parse the operand syntax
     *
     * @param string $expr
     *
     * @return array
     */
    private function parseOperand(string $expr): array
    {
        if ('?' === $expr) {
            return [
                'type' => 'auto',
            ];
        }

        if ('null' !== strtolower($expr)) {
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
            if (preg_match('/^(?<q>[\'"])((?:(?!\k<q>).)*)\k<q>$/', $expr, $matches)) {
                return [
                    'type' => 'text',
                    'text' => $matches[2],
                    'expr' => '\'' . addslashes($matches[2]) . '\'',
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
     * Generate the operand by given left and right operand with the operator.
     *
     * @param string $operator
     * @param array $leftOperand
     * @param array $rightOperand
     * @param bool $negative
     *
     * @return string
     * @throws Error
     */
    private function comparison(string $operator, array $leftOperand, array $rightOperand, bool $negative): string
    {
        $operand = '';
        if ('|=' === $operator) {
            if ('column' != $leftOperand['type']) {
                $leftExpr = $this->castAsJSON($leftOperand);
                $rightExpr = $this->castAsJSON($rightOperand);

                $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $rightExpr . ', \'$\') > 0';
            } else {
                $rightExpr = $rightOperand['expr'];
                if ('parameter' === $rightOperand['type']) {
                    if (is_array($rightOperand['value'])) {
                        if (count($rightOperand['value'])) {
                            $rightExpr = '';
                            foreach ($rightOperand['value'] as $val) {
                                $val = '\'' . addslashes($val) . '\'';
                                $rightExpr .= ($rightExpr) ? ', ' . $val : $val;
                            }
                        } else {
                            return $leftOperand['expr'] . ' IS ' . (($negative) ? ' NOT' : '') . ' NULL';
                        }
                    }
                }

                $leftExpr = $leftOperand['expr'];

                return $leftExpr . (($negative) ? ' NOT' : '') . ' IN(' . $rightExpr . ')';
            }
        } elseif ('#=' === $operator || '*=' === $operator || '^=' === $operator || '$=' === $operator) {
            $convertor = function ($operand) {
                if ('parameter' === $operand['type']) {
                    if (is_array($operand['value']) || is_scalar($operand['value'])) {
                        return (is_scalar($operand['value'])) ? addslashes($operand['value']) : json_encode($operand['value']);
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

            $leftExpr = ('column' != $leftOperand['type']) ? $convertor($leftOperand) : $leftOperand['expr'];
            if ('#=' === $operator) {
                $comparison = ' REGEXP ';
                $rightExpr = $rightOperand['expr'];
            } else {
                $comparison = 'LIKE';
                $rightExpr = ('column' != $rightOperand['type']) ? $this->insertWildcard($convertor($rightOperand), $operator) : $rightOperand['expr'];
            }

            return $leftExpr . (($negative) ? ' NOT' : '') . ' ' . $comparison . ' ' . $rightExpr;
        } elseif ('@=' === $operator) {
            $leftExpr = $this->castAsJSON($leftOperand, true);

            $rightExpr = '';
            if ('parameter' === $rightOperand['type']) {
                if (is_array($rightOperand['value']) || is_scalar($rightOperand['value'])) {
                    $rightExpr = (is_scalar($rightOperand['value'])) ? addslashes($rightOperand['value']) : json_encode($rightOperand['value']);
                }
            } elseif ('text' === $rightOperand['type']) {
                $rightExpr = addslashes($rightOperand['text']);
            } else if ('expr' === $rightOperand['type']) {
                $rightExpr = $rightOperand['expr'];
            }

            return 'JSON_SEARCH(JSON_KEYS(' . $leftExpr . '), "one", CAST(' . $rightExpr . ' AS CHAR)) IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
        } elseif (':=' === $operator) {
            if ('text' === $rightOperand['type']) {
                if ('$' !== ($rightOperand['text'][0] ?? '')) {
                    throw new Error('JSON path should start with $.');
                }
                $rightExpr = $rightOperand['expr'];
            } elseif ('parameter' === $rightOperand['type']) {
                if (!is_string($rightOperand['value'])) {
                    throw new Error('The JSON path should be a string.');
                }
                if ('$' !== ($rightOperand['value'][0] ?? '')) {
                    throw new Error('JSON path should start with $.');
                }

                $rightExpr = $rightOperand['expr'];
            } else {
                throw new Error('Invalid value passed to JSON_EXTRACT');
            }

            $leftExpr = $this->castAsJSON($leftOperand, true);

            return 'JSON_EXTRACT(' . $leftExpr . ', ' . $rightExpr . ') IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
        } elseif ('~=' === $operator || '&=' === $operator) {
            $leftExpr = $this->castAsJSON($leftOperand, true);
            if ('~=' === $operator) {
                if ('text' == $rightOperand['type'] || ('parameter' == $rightOperand['type'] && is_scalar($rightOperand['value']))) {
                    $rightExpr = '\'"' . addslashes($rightOperand['text'] ?? $rightOperand['value']) . '"\'';
                    $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $rightExpr . ') = 1';
                } else {
                    $operand = 'JSON_CONTAINS(' . $leftExpr . ', ' . $this->castAsJSON($rightOperand, true) . ') = 1';
                }
            } else {
                return 'JSON_SEARCH(' . $leftExpr . ', \'one\', ' . $this->castAsJSON($rightOperand, true) . ') IS ' . (($negative) ? '' : 'NOT ') . 'NULL';
            }
        }

        // Basic operator
        if (!$operand) {
            if ('!=' === $operator || '=' === $operator) {
                if ('!=' === $operator) {
                    $negative = !$negative;
                }

                if ($leftOperand['type'] === 'null' || $rightOperand['type'] === 'null') {
                    $operator = ($negative) ? 'IS NOT' : 'IS';
                } else {
                    $operator = ($negative) ? '<>' : '=';
                }

                return $leftOperand['expr'] . ' ' . $operator . ' ' . $rightOperand['expr'];
            }

            $operand = $leftOperand['expr'] . ' ' . $operator . ' ' . $rightOperand['expr'];
        }

        return ($negative) ? '!(' . $operand . ')' : $operand;
    }

    /**
     * Convert the value as cast as JSON
     *
     * @param array $operand
     * @param bool $acceptObject
     *
     * @return string
     */
    private function castAsJSON(array $operand, bool $acceptObject = false): string
    {
        if ('parameter' === $operand['type']) {
            if (is_array($operand['value'])) {
                return 'CAST(\'' . json_encode(($acceptObject) ? $operand['value'] : array_values($operand['value'])) . '\' AS JSON)';
            }
            if (is_scalar($operand['value'])) {
                return 'CAST(\'' . json_encode([$operand['value']]) . '\' AS JSON)';
            }

            return 'CAST(\'[]\' AS JSON)';
        }
        if ('text' === $operand['type']) {
            return 'CAST(\'' . json_encode([$operand['text']]) . '\' AS JSON)';
        }

        return $operand['expr'];
    }

    /**
     * Insert wildcard into string
     *
     * @param string $value
     * @param string $type
     *
     * @return string
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
     * Parse the Where Simple Syntax.
     *
     * @param string|callable $syntax
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
            throw new Error('Invalid syntax data type, only string is allowed.');
        }

        $this->syntax = trim($syntax);
        $this->extracted = SimpleSyntax::ParseSyntax($this->syntax, ',|', '=');

        return $this;
    }

    /**
     * Check if the WHERE statement is valid.
     *
     * @param string $syntax
     * @param string $prefix
     * @return string
     */
    static public function VerifySyntax(string $syntax, string $prefix = ''): string
    {
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

        $extracted = SimpleSyntax::ParseSyntax($syntax, ',|', '=');

        return $parser($extracted);
    }
}
