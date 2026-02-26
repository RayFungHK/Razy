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

namespace Razy;

use Closure;
use Razy\Contract\DistributorInterface;
use Throwable;

/**
 * Class EventEmitter.
 *
 * Facilitates event-driven communication between modules within a Distributor.
 * When a module emits an event, EventEmitter iterates over all loaded modules
 * to find listeners for that event, collects their responses, and optionally
 * invokes a callback for each response.
 *
 * @class EventEmitter
 *
 * @method EventEmitter bind(DistributorInterface $distributor)
 */
class EventEmitter
{
    /** @var array<mixed> Collected responses from event listeners after resolve() */
    private array $responses = [];

    /**
     * API constructor.
     *
     * @param DistributorInterface $distributor
     * @param Module $module
     * @param string $event
     * @param Closure|null $callback
     */
    public function __construct(private readonly DistributorInterface $distributor, private readonly Module $module, private readonly string $event, private readonly ?Closure $callback = null)
    {
    }

    /**
     * Get all responses from listeners after the event is resolved.
     *
     * @return array
     */
    public function getAllResponse(): array
    {
        return $this->responses;
    }

    /**
     * Resolve the event listener.
     *
     * @param mixed ...$args The arguments will pass to the callback
     *
     * @throws Throwable
     *
     * @return $this
     */
    public function resolve(...$args): self
    {
        // O(1) lookup via centralized listener index instead of O(n) full module scan
        $sourceCode = $this->module->getModuleInfo()->getCode();
        $listeners = $this->distributor->getRegistry()->getEventListeners($sourceCode, $this->event);

        foreach ($listeners as $module) {
            // Fire the event on the listening module and collect the result
            $result = $module->fireEvent($sourceCode, $this->event, $args);
            // Invoke the optional callback with the result and the responding module code
            $this->callback?->call($this->module, $result, $module->getModuleInfo()->getCode());
            $this->responses[] = $result;
        }

        return $this;
    }
}
