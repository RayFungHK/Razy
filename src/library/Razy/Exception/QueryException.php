<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Exception;

use Throwable;

/**
 * Exception thrown when a SQL query fails to execute or build.
 *
 * Covers PDO execution errors, invalid SQL syntax construction,
 * statement building failures, and result set processing errors.
 */
class QueryException extends DatabaseException
{
    /**
     * @param string $message Description of the query error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Query execution failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
