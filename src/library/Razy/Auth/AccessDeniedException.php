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

namespace Razy\Auth;

use RuntimeException;

/**
 * Exception thrown when an authorization check fails.
 *
 * @package Razy\Auth
 */
class AccessDeniedException extends RuntimeException
{
    /**
     * The HTTP status code for this exception.
     */
    private int $statusCode;

    /**
     * @param string $message The exception message
     * @param int $statusCode HTTP status code (default: 403 Forbidden)
     */
    public function __construct(string $message = 'This action is unauthorized.', int $statusCode = 403)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    /**
     * Get the HTTP status code.
     *
     * @return int The status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
