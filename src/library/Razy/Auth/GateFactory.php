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

namespace Razy\Auth;

use Closure;

/**
 * Memoized Gate registry — "one Gate per distributor" (PERMISSION-MODULE.md S1).
 *
 * Sugar so several modules in the same distributor share ONE Gate instance
 * (their addBefore()/define() registrations must compose, not fork). Keyed
 * by an app-chosen name, conventionally the distributor code.
 *
 * First registration wins: later make() calls for a known name return the
 * memoized Gate and ignore the passed AuthManager/builder — attaching gates
 * per caller would silently diverve ability state (§6 of the dossier).
 *
 * Worker mode: flush() (or forget($name)) between requests, mirroring
 * Database::resetInstances() house discipline.
 *
 * Usage (typically from a module's __onInit):
 * ```php
 * $gate = GateFactory::make($distCode, $auth, function (Gate $gate): void {
 *     $gate->define('queue.view', fn ($user) => true);
 * });
 * ```
 */
class GateFactory
{
    /**
     * Memoized gates by name.
     *
     * @var array<string, Gate>
     */
    private static array $gates = [];

    /**
     * Get (or first-build) the Gate registered under a name.
     *
     * @param string $name memoization key (conventionally the distributor code)
     * @param AuthManager $auth used ONLY when this name is new
     * @param Closure|null $builder optional fn(Gate $gate): void, run ONLY on first build
     */
    public static function make(string $name, AuthManager $auth, ?Closure $builder = null): Gate
    {
        if (!isset(self::$gates[$name])) {
            $gate = new Gate($auth);

            if ($builder !== null) {
                $builder($gate);
            }

            self::$gates[$name] = $gate;
        }

        return self::$gates[$name];
    }

    /**
     * Whether a Gate is memoized under the name.
     */
    public static function has(string $name): bool
    {
        return isset(self::$gates[$name]);
    }

    /**
     * Drop one memoized gate (e.g., a distributor was removed).
     */
    public static function forget(string $name): void
    {
        unset(self::$gates[$name]);
    }

    /**
     * Drop ALL memoized gates. Worker-mode boundary discipline.
     */
    public static function flush(): void
    {
        self::$gates = [];
    }
}
