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
 * Exception thrown for cache-related errors.
 *
 * Covers cache driver failures, storage I/O errors, serialization errors,
 * and cache configuration issues.
 */
class CacheException extends RuntimeException
{
    /**
     * @param string $message Description of the cache error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Cache operation failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
