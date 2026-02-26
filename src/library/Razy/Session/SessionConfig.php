<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Session;

/**
 * Immutable value object for session cookie configuration.
 *
 * Replaces Razy's hardcoded `session_set_cookie_params(0, '/', HOSTNAME)` with
 * a configurable, type-safe structure.
 *
 * @package Razy\Session
 */
class SessionConfig
{
    /**
     * @param string      $name     Session cookie name
     * @param int         $lifetime Cookie lifetime in seconds (0 = browser session)
     * @param string      $path     Cookie path
     * @param string      $domain   Cookie domain
     * @param bool        $secure   HTTPS-only flag
     * @param bool        $httpOnly HTTP-only flag (no JS access)
     * @param string      $sameSite SameSite policy: None|Lax|Strict
     * @param int         $gcMaxLifetime  Max session lifetime for GC (seconds)
     * @param int         $gcProbability  GC probability numerator
     * @param int         $gcDivisor      GC probability divisor
     */
    public function __construct(
        public readonly string $name = 'RAZY_SESSION',
        public readonly int $lifetime = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly bool $secure = false,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
        public readonly int $gcMaxLifetime = 1440,
        public readonly int $gcProbability = 1,
        public readonly int $gcDivisor = 100,
    ) {}

    /**
     * Create a new config with one or more values overridden.
     *
     * @param array<string, mixed> $overrides Property name → new value
     */
    public function with(array $overrides): static
    {
        return new static(
            name: $overrides['name'] ?? $this->name,
            lifetime: $overrides['lifetime'] ?? $this->lifetime,
            path: $overrides['path'] ?? $this->path,
            domain: $overrides['domain'] ?? $this->domain,
            secure: $overrides['secure'] ?? $this->secure,
            httpOnly: $overrides['httpOnly'] ?? $this->httpOnly,
            sameSite: $overrides['sameSite'] ?? $this->sameSite,
            gcMaxLifetime: $overrides['gcMaxLifetime'] ?? $this->gcMaxLifetime,
            gcProbability: $overrides['gcProbability'] ?? $this->gcProbability,
            gcDivisor: $overrides['gcDivisor'] ?? $this->gcDivisor,
        );
    }
}
