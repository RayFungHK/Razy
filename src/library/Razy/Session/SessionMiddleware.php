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

namespace Razy\Session;

use Closure;
use Razy\Contract\MiddlewareInterface;
use Razy\Contract\SessionInterface;

/**
 * Session middleware — integrates the session into the middleware pipeline.
 *
 * Starts the session before the route handler runs and saves it after
 * the handler completes. Implements the MiddlewareInterface onion pattern
 * used by RouteDispatcher's MiddlewarePipeline.
 *
 * Usage:
 * ```php
 * $session = new Session($driver, $config);
 * $dispatcher->addGlobalMiddleware(new SessionMiddleware($session));
 * ```
 *
 * @package Razy\Session
 */
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Start session → delegate → save session.
     *
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        $this->session->start();

        try {
            $result = $next($context);
        } finally {
            $this->session->save();
        }

        return $result;
    }

    /**
     * Get the session instance managed by this middleware.
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }
}
