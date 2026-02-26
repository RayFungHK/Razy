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

use RuntimeException;
use Throwable;

/**
 * Exception thrown for network-related errors.
 *
 * Base class for all network operation failures including
 * FTP, SFTP/SSH, and other protocol-specific errors.
 */
class NetworkException extends RuntimeException
{
    /**
     * @param string $message Description of the network error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Network operation failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
