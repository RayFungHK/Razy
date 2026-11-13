<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace Razy\Http;

use Closure;
use LogicException;
use UnexpectedValueException;

/**
 * The dist's not-found answer (RZ-56): runs once when no route of the dist
 * matched the request.
 *
 * A module registers it with `$agent->onNotFound(fn (RequestContext $request): Response => …)`.
 * One module per dist owns it (a second module cannot silently take over;
 * the owner may replace its own). The answer is always sent with status 404,
 * whatever status the Response carries, and a HEAD request gets no body.
 * It runs only after the route table missed, so no route guard (authz,
 * CSRF, readiness) runs first: an unknown URL is 404 for everyone. With no handler the dist keeps
 * today's answer (`Error::show404()`).
 */
final class NotFoundHandler
{
    /** @var (Closure(RequestContext): mixed)|null */
    private ?Closure $handler = null;

    private ?string $owner = null;

    /**
     * @param Closure(RequestContext): mixed $handler should return a Response
     *
     * @throws LogicException When another module already owns the handler
     */
    public function set(Closure $handler, string $owner): void
    {
        if ($this->owner !== null && $this->owner !== $owner) {
            throw new LogicException("The dist not-found handler is owned by '{$this->owner}'; '{$owner}' cannot replace it.");
        }
        $this->handler = $handler;
        $this->owner = $owner;
    }

    public function has(): bool
    {
        return $this->handler !== null;
    }

    /**
     * Answer a route miss: send the handler's 404 and return true, or return
     * false with no handler so the caller keeps `Error::show404()`.
     */
    public function respond(RequestContext $request): bool
    {
        $response = $this->answer($request);
        $response?->send();

        return $response !== null;
    }

    /**
     * Run the handler.
     *
     * @return Response|null The 404 answer, or null when no handler is registered
     *
     * @throws UnexpectedValueException When the handler returns anything but a Response
     */
    public function answer(RequestContext $request): ?Response
    {
        if ($this->handler === null) {
            return null;
        }
        $answer = ($this->handler)($request);
        if (!$answer instanceof Response) {
            throw new UnexpectedValueException("The not-found handler of '{$this->owner}' must return a " . Response::class . ', got ' . \get_debug_type($answer) . '.');
        }

        // A HEAD request gets the status and headers, no body.
        $body = $request->method === 'HEAD' ? '' : $answer->getBody();

        return $answer->getStatus() === 404 && $body === $answer->getBody() ? $answer : new Response(404, $body, $answer->getHeaders());
    }
}
