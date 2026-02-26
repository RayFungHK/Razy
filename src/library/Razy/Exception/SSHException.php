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
 * Exception thrown for SSH/SFTP operation errors.
 *
 * Covers SSH connection failures, authentication errors, SFTP directory
 * operations, file transfer failures, and permission errors.
 */
class SSHException extends NetworkException
{
    /**
     * @param string $message Description of the SSH/SFTP error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'SSH/SFTP operation failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
