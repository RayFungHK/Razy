<?php

declare(strict_types=1);

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-14 compatible stoppable event interface.
 * Fulfills the PSR-14 specification without requiring psr/event-dispatcher.
 *
 * @package Razy
 * @license MIT
 * @see https://www.php-fig.org/psr/psr-14/
 */

namespace Razy\Contract\EventDispatcher;

/**
 * An Event whose processing may be interrupted when the event has been handled.
 *
 * A Dispatcher implementation MUST check to determine if an Event
 * is marked as stopped after each listener is called. If it is then it should
 * return immediately without calling any further Listeners.
 */
interface StoppableEventInterface
{
    /**
     * Is propagation stopped?
     *
     * This will typically only be used by the Dispatcher to determine if the
     * previous listener halted propagation.
     *
     * @return bool True if the Event is complete and no further listeners should be called.
     *              False to continue calling listeners.
     */
    public function isPropagationStopped(): bool;
}
