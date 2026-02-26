<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-14 compliant event dispatcher.
 *
 *
 * @license MIT
 */

namespace Razy\Event;

use Razy\Contract\EventDispatcher\ListenerProviderInterface;
use Razy\Contract\EventDispatcher\PsrEventDispatcherInterface;
use Razy\Contract\EventDispatcher\StoppableEventInterface;

/**
 * PSR-14 compliant event dispatcher.
 *
 * Dispatches events to listeners obtained from the injected listener provider.
 * Respects StoppableEventInterface to halt propagation when requested.
 */
class EventDispatcher implements PsrEventDispatcherInterface
{
    /**
     * @param ListenerProviderInterface $listenerProvider The provider that supplies listeners for each event
     */
    public function __construct(
        private readonly ListenerProviderInterface $listenerProvider,
    ) {
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * If the event implements StoppableEventInterface and propagation is stopped,
     * remaining listeners will not be called.
     *
     * @param object $event The event object to dispatch
     *
     * @return object The same event object, potentially modified by listeners
     */
    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;

        if ($stoppable && $event->isPropagationStopped()) {
            return $event;
        }

        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}
