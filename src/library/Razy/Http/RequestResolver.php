<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Http;

use Closure;
use UnexpectedValueException;

/**
 * The dist's request-resolver stage (RZ-12): runs once per request, after the
 * module lifecycle is ready and the session is started, before the route table.
 *
 * Modules register with `$agent->resolveRequest(fn (RequestContext $request): ?Response => …)`.
 * Resolvers run in registration order (module load order); one per module, a
 * second registration from the same module replaces the first. A resolver
 * returns null to go on (after recording what it found with `$request->set()`),
 * or a Response, which is sent and ends the request: no later resolver and no
 * route runs. This is where host or tenant lookups belong (database I/O is
 * allowed here), not `__onInit` (RZ-009) and never `exit`.
 *
 * An exception is not caught: it reaches the dist's error page like any route
 * error. A resolver that wants a softer answer returns its own Response.
 */
final class RequestResolver
{
    /** @var array<string, Closure(RequestContext): mixed> module code => resolver (checked when it answers) */
    private array $resolvers = [];

    /**
     * @param Closure(RequestContext): mixed $resolver should return null or a Response
     */
    public function add(Closure $resolver, string $owner): void
    {
        $this->resolvers[$owner] = $resolver;
    }

    public function count(): int
    {
        return \count($this->resolvers);
    }

    /**
     * Run the resolvers until one answers.
     *
     * @return Response|null The answer that ends the request, or null to route it
     *
     * @throws UnexpectedValueException When a resolver returns anything but null or a Response
     */
    public function resolve(RequestContext $request): ?Response
    {
        foreach ($this->resolvers as $owner => $resolver) {
            $answer = $resolver($request);
            if ($answer === null) {
                continue;
            }
            if (!$answer instanceof Response) {
                throw new UnexpectedValueException("The request resolver of '{$owner}' must return null or a " . Response::class . ', got ' . \get_debug_type($answer) . '.');
            }

            return $answer;
        }

        return null;
    }
}
