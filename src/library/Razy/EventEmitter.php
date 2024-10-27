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
     * The storage of the responses from other Modules
     * @var array
     */
    private array $responses = [];

	/**
	 * API constructor.
	 *
	 * @param Distributor $distributor
	 * @param Module $module
	 * @param string $event
	 * @param Closure|null $callback
	 */
    public function __construct(private readonly Distributor $distributor, private readonly Module $module, private readonly string $event, private readonly ?Closure $callback = null)
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
        foreach ($this->distributor->getAllModules() as $module) {
            if ($module->eventDispatched($this->event)) {
                $result = $module->fireEvent($this->event, $args);
                $this->callback?->call($this->module, $result, $module->getModuleInfo()->getCode());
                $this->responses[] = $result;
            }
        }

        return $this;
    }
}
