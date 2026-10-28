<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

/**
 * Thrown when a Where Simple Syntax operand is none of the recognised forms
 * (column, `:name` placeholder, quoted text, array literal, NULL, number,
 * bare identifier or an allow-listed zero-argument SQL function) and would
 * otherwise reach the SQL text raw (RZ-41).
 *
 * The operand stays out of the message, since it may carry interpolated input
 * onto an error page; read it with getOperand() for logs.
 */
class InvalidOperandException extends QueryException
{
    /**
     * @param string $operand The refused operand text
     */
    public function __construct(private readonly string $operand)
    {
        parent::__construct('Unrecognised operand in Where syntax; pass values as :name placeholders.');
    }

    /**
     * The refused operand text. For logs and the debug page only.
     */
    public function getOperand(): string
    {
        return $this->operand;
    }
}
