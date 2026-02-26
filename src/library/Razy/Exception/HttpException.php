<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Introduced in Phase 3.3 to replace exit() calls in framework code.
 * Represents an HTTP-level error that should terminate request processing
 * and render an appropriate error response.
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for HTTP error responses.
 *
 * When thrown inside the framework, the top-level request handler in main.php
 * catches this exception and renders the appropriate HTTP error page instead
 * of calling exit() directly, making the framework testable and worker-mode safe.
 */
class HttpException extends RuntimeException
{
    /**
     * @param int $statusCode The HTTP status code (e.g. 400, 404, 500)
     * @param string $message A human-readable error message
     * @param Throwable|null $previous The previous exception for chaining
     */
    public function __construct(
        int $statusCode = 400,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Get the HTTP status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }
}
