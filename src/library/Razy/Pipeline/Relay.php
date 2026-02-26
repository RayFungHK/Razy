<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Relay class for broadcasting method calls to all Actions
 * registered in a Pipeline. Uses magic `__call` to proxy any method
 * to each Action, silently ignoring individual failures.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Pipeline;

use Exception;
use Razy\Pipeline;

/**
 * Broadcasts method calls to all Actions within a Pipeline.
 *
 * The Relay acts as a proxy that forwards any method call to every
 * Action registered in the Pipeline. Exceptions from individual Actions
 * are silently caught to ensure all Actions receive the broadcast.
 *
 * @class Relay
 */
class Relay
{
    /**
     * Relay constructor.
     *
     * @param Pipeline $pipeline The Pipeline whose Actions will receive broadcasts
     */
    public function __construct(private readonly Pipeline $pipeline)
    {
    }

    /**
     * Broadcast a method call to all Actions in the Pipeline.
     *
     * @param string $method The method name to invoke on each Action
     * @param array $arguments The arguments to pass to the method
     * @return $this Chainable
     */
    public function __call(string $method, array $arguments): static
    {
        foreach ($this->pipeline->getActions() as $action) {
            try {
                call_user_func_array([$action, $method], $arguments);
            } catch (Exception) {
            }
        }
        return $this;
    }
}
