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
 * PSR-14 compatible event dispatcher interface.
 * Fulfills the PSR-14 specification without requiring psr/event-dispatcher.
 *
 * @package Razy
 *
 * @license MIT
 *
 * @see https://www.php-fig.org/psr/psr-14/
 */

namespace Razy\Contract\EventDispatcher;

/**
 * Defines a dispatcher for events.
 */
interface PsrEventDispatcherInterface
{
    /**
     * Provide all relevant listeners with an event to process.
     *
     * @param object $event The object to process.
     *
     * @return object The Event that was passed, now modified by listeners.
     */
    public function dispatch(object $event): object;
}
