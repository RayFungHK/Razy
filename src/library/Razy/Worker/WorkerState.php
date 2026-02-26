<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Worker;

/**
 * Represents the lifecycle state of a persistent worker process.
 *
 * State transitions:
 *   Booting → Ready → Draining → Terminated   (Strategy A: graceful restart)
 *   Booting → Ready → Swapping → Ready         (Strategy B/C: hot-swap)
 */
enum WorkerState: string
{
    /**
     * Check if the worker can accept new requests.
     */
    public function canAcceptRequests(): bool
    {
        return match ($this) {
            self::Ready, self::Swapping => true,
            default => false,
        };
    }

    /**
     * Check if the worker should exit the process loop.
     */
    public function shouldExit(): bool
    {
        return $this === self::Terminated;
    }
    /** Worker is performing initial boot (loading modules). */
    case Booting = 'booting';

    /** Worker is ready and accepting requests. */
    case Ready = 'ready';

    /**
     * Worker is draining: finishing in-flight requests but not accepting new ones.
     * After all requests finish, transitions to Terminated.
     * Used by Strategy A (graceful restart when class changes detected).
     */
    case Draining = 'draining';

    /**
     * Worker is hot-swapping modules in-process (Strategy B or C).
     * Continues accepting requests on the old container while the new one boots.
     */
    case Swapping = 'swapping';

    /** Worker should terminate and let the process supervisor restart it. */
    case Terminated = 'terminated';
}
