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

use Throwable;

/**
 * Exception thrown when a database connection cannot be established.
 *
 * Covers driver initialization, unsupported drivers, authentication failures,
 * and missing database extensions.
 */
class ConnectionException extends DatabaseException
{
    /**
     * @param string $message Description of the connection error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Database connection failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
