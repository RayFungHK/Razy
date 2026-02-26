<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Core interface contract for event dispatching (Phase 2.4).
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Contract;

use Closure;

/**
 * Contract for event dispatcher implementations.
 *
 * Provides the core operations for registering event listeners
 * and checking if listeners are registered.
 */
interface EventDispatcherInterface
{
    /**
     * Register a listener for an event.
     *
     * @param string $event The event identifier
     * @param string|Closure $path The handler closure or path to closure file
     */
    public function listen(string $event, string|Closure $path): void;

    /**
     * Check if a listener is registered for a specific event.
     *
     * @param string $moduleCode The source module code
     * @param string $event The event name
     *
     * @return bool True if listening
     */
    public function isEventListening(string $moduleCode, string $event): bool;
}
