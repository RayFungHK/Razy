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

/**
 * Exception thrown when a queue operation fails.
 *
 * Examples: handler not found, handler instantiation failure, store errors.
 */
class QueueException extends RuntimeException
{
    public function __construct(string $message = 'Queue operation failed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
