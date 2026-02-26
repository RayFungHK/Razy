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
 * PSR-14 compatible listener provider interface.
 * Fulfills the PSR-14 specification without requiring psr/event-dispatcher.
 *
 * @package Razy
 * @license MIT
 * @see https://www.php-fig.org/psr/psr-14/
 */

namespace Razy\Contract\EventDispatcher;

/**
 * Mapper from an event to the listeners that are applicable to that event.
 */
interface ListenerProviderInterface
{
    /**
     * @param object $event An event for which to return the relevant listeners.
     *
     * @return iterable An iterable (array, iterator, or generator) of callables.
     *                  Each callable MUST be type-compatible with $event.
     */
    public function getListenersForEvent(object $event): iterable;
}
