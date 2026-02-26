<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Http;

use RuntimeException;

/**
 * Exception thrown when an HTTP request fails.
 *
 * Contains the associated HttpResponse for inspection.
 */
class HttpException extends RuntimeException
{
    /**
     * The HTTP response that triggered the exception.
     */
    private HttpResponse $response;

    /**
     * Create a new HttpException.
     *
     * @param HttpResponse $response The HTTP response
     */
    public function __construct(HttpResponse $response)
    {
        $this->response = $response;

        parent::__construct(
            \sprintf('HTTP request returned status code %d', $response->status()),
            $response->status(),
        );
    }

    /**
     * Get the HTTP response that caused this exception.
     */
    public function getResponse(): HttpResponse
    {
        return $this->response;
    }
}
