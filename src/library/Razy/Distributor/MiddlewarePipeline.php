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

namespace Razy\Distributor;

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * Executes an ordered list of middleware in an "onion" pipeline.
 *
 * Each middleware wraps the next, forming layers around the core handler.
 * The first middleware added is the outermost layer (runs first on entry,
 * last on exit). Middleware can short-circuit by not calling `$next`.
 *
 * Supports both:
 * - Object middleware implementing MiddlewareInterface
 * - Closure middleware with signature: function(array $context, Closure $next): mixed
 *
 * ```
 *  Request ──▶ MW1 ──▶ MW2 ──▶ MW3 ──▶ Handler
 *  Response ◀── MW1 ◀── MW2 ◀── MW3 ◀──┘
 * ```
 *
 * @class MiddlewarePipeline
 * @package Razy\Distributor
 */
class MiddlewarePipeline
{
    /** @var array<MiddlewareInterface|Closure> Ordered middleware stack */
    private array $middleware = [];

    /**
     * Add middleware to the pipeline.
     *
     * Middleware is executed in the order it is added (FIFO).
     * The first middleware added is the outermost layer.
     *
     * @param MiddlewareInterface|Closure $middleware A middleware object or closure
     * @return $this Fluent interface
     * @throws \InvalidArgumentException If the argument is neither MiddlewareInterface nor Closure
     */
    public function pipe(MiddlewareInterface|Closure $middleware): static
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware at once.
     *
     * @param array<MiddlewareInterface|Closure> $middlewareList
     * @return $this Fluent interface
     */
    public function pipeMany(array $middlewareList): static
    {
        foreach ($middlewareList as $mw) {
            $this->pipe($mw);
        }
        return $this;
    }

    /**
     * Execute the middleware pipeline with a core handler at the center.
     *
     * Builds the onion from inside-out: the core handler is wrapped by the
     * last middleware, which is wrapped by the second-to-last, and so on.
     *
     * @param array $context The route context (routedInfo + any extras)
     * @param Closure $coreHandler The final handler: function(array $context): mixed
     * @return mixed The result from the pipeline
     */
    public function process(array $context, Closure $coreHandler): mixed
    {
        // Build the pipeline from inside-out (reverse order)
        $pipeline = $coreHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $pipeline;
            if ($mw instanceof MiddlewareInterface) {
                $pipeline = static function (array $ctx) use ($mw, $next): mixed {
                    return $mw->handle($ctx, $next);
                };
            } else {
                // Closure middleware: function(array $context, Closure $next): mixed
                $pipeline = static function (array $ctx) use ($mw, $next): mixed {
                    return $mw($ctx, $next);
                };
            }
        }

        return $pipeline($context);
    }

    /**
     * Check if the pipeline has any middleware.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return count($this->middleware) === 0;
    }

    /**
     * Get the number of middleware in the pipeline.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->middleware);
    }

    /**
     * Get all registered middleware.
     *
     * @return array<MiddlewareInterface|Closure>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
