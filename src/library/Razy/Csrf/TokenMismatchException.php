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

namespace Razy\Csrf;

use RuntimeException;

/**
 * Exception thrown when a CSRF token validation fails.
 *
 * Contains the HTTP 419 status code (non-standard but widely used for
 * expired/missing CSRF tokens, following Laravel's convention).
 *
 * ```php
 * try {
 *     $csrf->validate($submitted) || throw new TokenMismatchException();
 * } catch (TokenMismatchException $e) {
 *     // $e->getCode() → 419
 *     // $e->getMessage() → 'CSRF token mismatch.'
 * }
 * ```
 *
 * @package Razy\Csrf
 */
class TokenMismatchException extends RuntimeException
{
    /**
     * @param string $message Custom message (default: 'CSRF token mismatch.').
     */
    public function __construct(string $message = 'CSRF token mismatch.')
    {
        parent::__construct($message, 419);
    }
}
