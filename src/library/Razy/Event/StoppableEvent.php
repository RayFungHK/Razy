<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Base stoppable event implementing PSR-14 StoppableEventInterface.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Event;

use Razy\Contract\EventDispatcher\StoppableEventInterface;

/**
 * Base event class with propagation stopping support.
 *
 * Concrete event classes should extend this to inherit
 * stop-propagation behaviour out of the box.
 */
class StoppableEvent implements StoppableEventInterface
{
    /**
     * Whether propagation has been stopped.
     */
    private bool $propagationStopped = false;

    /**
     * {@inheritDoc}
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Stop further event propagation.
     *
     * Once called, subsequent listeners will not be invoked.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
