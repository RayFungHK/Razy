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
 * @license MIT
 */

namespace Razy\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for all database-related errors.
 *
 * Covers connection failures, query execution errors, SQL syntax building,
 * and general database configuration issues.
 */
class DatabaseException extends RuntimeException
{
    /**
     * @param string $message Description of the database error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'A database error occurred.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
