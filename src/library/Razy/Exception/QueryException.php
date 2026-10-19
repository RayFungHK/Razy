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
     * @param string $message Description of the query error (never include the SQL here)
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     * @param string|null $sql The failing SQL, kept out of the message so it cannot leak
     *                         onto an error page; read it with getSql() (RZ-01)
     */
    public function __construct(string $message = 'Query execution failed.', int $code = 0, ?Throwable $previous = null, private readonly ?string $sql = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The SQL that failed, if known. For logs and the debug page only.
     *
     * @return string|null
     */
    public function getSql(): ?string
    {
        return $this->sql;
    }
}
