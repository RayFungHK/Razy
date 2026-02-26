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

/**
 * Exception thrown when a mail operation fails (SMTP connection, payload creation, etc.).
 */
class MailerException extends NetworkException
{
    public function __construct(string $message = 'Mail operation failed.', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
