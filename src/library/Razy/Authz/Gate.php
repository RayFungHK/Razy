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
use InvalidArgumentException;
use Throwable;

/**
 * The dist-wide authorization gate (the declarative half of a route's
 * `->can('ability')`).
 *
 * Abilities are named predicates a module registers during __onInit
 * (`Agent::addAuthzAbility`) and routes reference by NAME. The framework
 * never learns what any ability means — the predicate body reads the
 * module's own session/user shape, which keeps the RZ-017 boundary
 * intact: the route layer asks yes/no, the owning module answers.
 *
 * Resolution is FAIL-CLOSED by contract (the CSRF door's posture, applied
 * to authorization): a route declaring an ability no one defined is a
 * misconfiguration, and a misconfiguration must refuse traffic, not wave
 * it through because its guard was never installed.
 *
 * The check closure receives the routed context (module, route, method,
 * params...) and returns bool. It must not throw — a throwing checker is
 * treated as deny (and the throwable surfaces on stderr via the
 * middleware, never as a 200).
 */
class Gate
{
    /** @var array<string, Closure> ability => predicate */
    private array $abilities = [];

    /**
     * Define (or replace) one ability predicate.
     *
     * @throws InvalidArgumentException on an empty ability name
     */
    public function define(string $ability, Closure $check): self
    {
        $ability = \trim($ability);

        if ($ability === '') {
            throw new InvalidArgumentException(
                'addAuthzAbility() requires a non-empty ability name (e.g. "user.manage").',
            );
        }

        $this->abilities[$ability] = $check;

        return $this;
    }

    public function has(string $ability): bool
    {
        return isset($this->abilities[$ability]);
    }

    /**
     * @return array<string> every defined ability (diagnostics/tests)
     */
    public function abilities(): array
    {
        return \array_keys($this->abilities);
    }

    /**
     * Evaluate an ability. Returns null when the ability is UNDEFINED —
     * the caller (AuthzMiddleware) decides the fail-closed consequence,
     * so this class stays free of HTTP posture.
     *
     * @param array<string, mixed> $context The routed context
     */
    public function check(string $ability, array $context): ?bool
    {
        $check = $this->abilities[$ability] ?? null;

        if ($check === null) {
            return null;
        }

        try {
            return $check($context) === true;
        } catch (Throwable) {
            // A throwing checker is a broken guard, not a green light.
            return false;
        }
    }
}
