<?php

/**
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Throwable;

/**
 * Class EventEmitter.
 *
 * @method EventEmitter bind(Distributor $distributor)
 */
class EventEmitter
{
    /**
     * The closure of the event
     * @var null|Closure
     */
    private ?Closure $callback;
    /**
     * The Distributor entity
     * @var null|Distributor
     */
    private ?Distributor $distributor;
    /**
     * Event name
     * @var string
     */
    private string $event;
    /**
     * The Module entity
     * @var Module
     */
    private Module $module;
    /**
     * The storage of the responses from other Modules
     * @var array
     */
    private array $responses = [];

    /**
     * API constructor.
     *
     * @param Distributor   $distributor
     * @param Module        $module
     * @param string        $event
     * @param null|callable $callback
     */
    public function __construct(Distributor $distributor, Module $module, string $event, ?callable $callback = null)
    {
        $this->module      = $module;
        $this->event       = $event;
        $this->callback    = $callback;
        $this->distributor = $distributor;
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
        foreach ($this->distributor->getAllModules() as $module) {
            if ($module->eventDispatched($this->event)) {
                $result = $module->fireEvent($this->event, $args);
                if (null !== $this->callback) {
                    $this->callback->call($this->module, $result, $module->getCode());
                }
                $this->responses[] = $result;
            }
        }

        return $this;
    }
}
