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

namespace Razy\Authz;

use Closure;
use LogicException;
use Throwable;

/**
 * RZ-06: the request-scoped principal of one dist.
 *
 * One module registers the resolver (`$agent->identity(fn (array $context): ?Principal => …)`);
 * it runs at most once per request, and the Authz middleware calls reset() at
 * the start of every request (worker mode keeps this object alive). A throwing
 * resolver means "signed out", never a guessed principal.
 */
final class Identity
{
    private ?Closure $resolver = null;

    private ?string $owner = null;

    private bool $resolved = false;

    /** Request marker the cached principal belongs to. */
    private ?string $resolvedIn = null;

    private ?Principal $principal = null;

    private static function requestMarker(): string
    {
        return (string) ($_SERVER['REQUEST_TIME_FLOAT'] ?? '');
    }

    /**
     * @param Closure(array<string, mixed>): ?Principal $resolver
     *
     * @throws LogicException If another module already owns the resolver.
     */
    public function setResolver(Closure $resolver, string $owner): void
    {
        if ($this->owner !== null && $this->owner !== $owner) {
            throw new LogicException("The dist identity resolver is owned by '{$this->owner}'; '{$owner}' cannot replace it.");
        }
        $this->resolver = $resolver;
        $this->owner = $owner;
        $this->reset();
    }

    public function hasResolver(): bool
    {
        return $this->resolver !== null;
    }

    /**
     * The principal for this request (resolved on first call, then cached).
     *
     * @param array<string, mixed> $context routed context, when available
     */
    public function principal(array $context = []): ?Principal
    {
        // Worker mode keeps this object: code that runs before (or without)
        // the middleware's reset() must still never see the last request's
        // principal. FrankenPHP refreshes $_SERVER per request.
        if ($this->resolved && $this->resolvedIn !== self::requestMarker()) {
            $this->reset();
        }
        if ($this->resolved || $this->resolver === null) {
            return $this->principal;
        }
        $this->resolved = true;
        $this->resolvedIn = self::requestMarker();

        try {
            $principal = ($this->resolver)($context);
            $this->principal = $principal instanceof Principal ? $principal : null;
        } catch (Throwable $e) {
            \error_log('[Razy] identity resolver failed (' . $this->owner . '): ' . $e->getMessage());
            $this->principal = null;
        }

        return $this->principal;
    }

    /**
     * Forget the cached principal (start of a request; after sign-in/out).
     */
    public function reset(): void
    {
        $this->resolved = false;
        $this->resolvedIn = null;
        $this->principal = null;
    }
}
