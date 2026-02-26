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
 * Exception thrown when a file operation fails (read, write, not found, not readable).
 */
class FileException extends RuntimeException
{
    public function __construct(string $message = 'File operation failed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
