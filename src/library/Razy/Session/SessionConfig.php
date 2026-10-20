<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Session;

use Razy\Exception\ConfigurationException;
use Razy\Http\Request;

/**
 * Immutable value object for session cookie configuration.
 *
 * Replaces Razy's hardcoded `session_set_cookie_params(0, '/', HOSTNAME)` with
 * a configurable, type-safe structure.
 * $domain defaults to '' (omit Domain — host-only cookie; never Domain=.apex).
 */
class SessionConfig
{
    /**
     * @param string $name Session cookie name
     * @param int $lifetime Cookie lifetime in seconds (0 = browser session)
     * @param string $path Cookie path
     * @param string $domain Cookie Domain; empty string omits Domain (host-only; never .apex)
     * @param bool|null $secure HTTPS-only flag; null (default, RZ-05) = Secure when the request is HTTPS
     * @param bool $httpOnly HTTP-only flag (no JS access)
     * @param string $sameSite SameSite policy: None|Lax|Strict
     * @param int $gcMaxLifetime Max session lifetime for GC (seconds)
     * @param int $gcProbability GC probability numerator
     * @param int $gcDivisor GC probability divisor
     */
    public function __construct(
        public readonly string $name = 'RAZY_SESSION',
        public readonly int $lifetime = 0,
        public readonly string $path = '/',
        public readonly string $domain = '',
        public readonly ?bool $secure = null,
        public readonly bool $httpOnly = true,
        public readonly string $sameSite = 'Lax',
        public readonly int $gcMaxLifetime = 1440,
        public readonly int $gcProbability = 1,
        public readonly int $gcDivisor = 100,
    ) {
    }

    /**
     * Build from a dist.php `'session'` cookie section (RZ-05). Keys: `name`,
     * `lifetime`, `path`, `domain`, `secure` (`'auto'`|bool), `http_only`,
     * `same_site` (`Lax`|`Strict`|`None`), `gc_max_lifetime`. Unknown keys and
     * bad values are boot errors, not silent defaults.
     *
     * @param array<string, mixed> $cookie
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $cookie): self
    {
        $known = ['name', 'lifetime', 'path', 'domain', 'secure', 'http_only', 'same_site', 'gc_max_lifetime'];
        $unknown = \array_diff(\array_keys($cookie), $known);
        if ($unknown !== []) {
            throw new ConfigurationException('Unknown session option(s): ' . \implode(', ', $unknown) . '. Known: ' . \implode(', ', $known) . '.');
        }

        $name = $cookie['name'] ?? 'RAZY_SESSION';
        if (!\is_string($name) || !\preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
            throw new ConfigurationException("Session 'name' must be letters, digits, '_' or '-'.");
        }
        $secure = $cookie['secure'] ?? 'auto';
        if ($secure !== 'auto' && !\is_bool($secure)) {
            throw new ConfigurationException("Session 'secure' must be 'auto', true or false.");
        }
        $sameSite = \ucfirst(\strtolower((string) ($cookie['same_site'] ?? 'Lax')));
        if (!\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new ConfigurationException("Session 'same_site' must be Lax, Strict or None.");
        }
        $lifetime = $cookie['lifetime'] ?? 0;
        $gcMax = $cookie['gc_max_lifetime'] ?? 1440;
        if (!\is_int($lifetime) || $lifetime < 0 || !\is_int($gcMax) || $gcMax < 1) {
            throw new ConfigurationException("Session 'lifetime' must be an int >= 0 and 'gc_max_lifetime' an int >= 1.");
        }
        foreach (['path', 'domain'] as $key) {
            if (isset($cookie[$key]) && !\is_string($cookie[$key])) {
                throw new ConfigurationException("Session '{$key}' must be a string.");
            }
        }

        return new self(
            name: $name,
            lifetime: $lifetime,
            path: $cookie['path'] ?? '/',
            domain: $cookie['domain'] ?? '',
            secure: $secure === 'auto' ? null : $secure,
            httpOnly: (bool) ($cookie['http_only'] ?? true),
            sameSite: $sameSite,
            gcMaxLifetime: $gcMax,
        );
    }

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
            secure: \array_key_exists('secure', $overrides) ? $overrides['secure'] : $this->secure,
            httpOnly: $overrides['httpOnly'] ?? $this->httpOnly,
            sameSite: $overrides['sameSite'] ?? $this->sameSite,
            gcMaxLifetime: $overrides['gcMaxLifetime'] ?? $this->gcMaxLifetime,
            gcProbability: $overrides['gcProbability'] ?? $this->gcProbability,
            gcDivisor: $overrides['gcDivisor'] ?? $this->gcDivisor,
        );
    }

    /**
     * The Secure flag for this request: the configured value, or (auto) whether
     * the request is HTTPS, including behind a trusted proxy (RZ-04).
     */
    public function resolveSecure(): bool
    {
        return $this->secure ?? Request::fromGlobals()->isSecure();
    }
}
