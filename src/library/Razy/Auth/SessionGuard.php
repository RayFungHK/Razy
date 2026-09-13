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
use Razy\Contract\AuthenticatableInterface;
use Razy\Contract\GuardInterface;

/**
 * Session-backed authentication guard — the concrete class the AuthManager
 * docblock has advertised since v1.0.2-beta (P1, now true).
 *
 * DESIGN CONTRACT (maintainer decision, PERMISSION-MODULE.md Q1): the
 * framework NEVER owns the user row. This guard persists only the actor's
 * IDENTIFIER under a session key, and hydrates the user through an
 * app-supplied resolver. Where identities live (app table, razymod/accounts,
 * social.user_resolved mapping) is entirely the app's territory.
 *
 * Storage: the native PHP session ($_SESSION) started by
 * Distributor::setSession() on web routes. A guard constructed with no
 * session started (CLI, unit context) falls back to request-lifetime
 * internal storage — login() works, but nothing survives the request.
 *
 * Session-fixation note: login() deliberately does NOT call
 * session_regenerate_id() (the guard stays side-effect-free on session
 * globals); call it in your app bootstrap around login if fixation matters.
 *
 * Usage:
 * ```php
 * $guard = new SessionGuard(
 *     resolver: fn (string|int $id): ?AuthenticatableInterface =>
 *         new GenericUser($appUserRepository->find($id) ?? ['id' => $id]),
 * );
 * $auth = new AuthManager(['web' => $guard], 'web');
 * ```
 *
 * @see CallbackGuard for the closure-delegating sibling
 */
class SessionGuard implements GuardInterface
{
    /**
     * The currently authenticated user.
     */
    private ?AuthenticatableInterface $user = null;

    /**
     * Whether the user has been resolved for this request.
     */
    private bool $resolved = false;

    /**
     * Request-lifetime storage used ONLY when no session is started.
     *
     * @var array<string, string|int>
     */
    private array $fallbackStore = [];

    /**
     * Whether the native session superglobal is available to store in.
     */
    private readonly bool $sessionStarted;

    /**
     * @param Closure $resolver fn(string|int $id): ?AuthenticatableInterface — hydrate an actor
     *                          from its persisted identifier. Return null = actor gone (guest).
     * @param string $sessionKey key holding the actor identifier inside $_SESSION
     * @param Closure|null $credentialValidator Closure(array $credentials): bool (validate() only;
     *                                          this guard NEVER checks passwords itself)
     */
    public function __construct(
        private readonly Closure $resolver,
        private readonly string $sessionKey = '__auth_user_id',
        private readonly ?Closure $credentialValidator = null,
    ) {
        // isset($_SESSION) is the canonical "session started (or test-assigned)"
        // probe; PHP only ever populates it as an array.
        $this->sessionStarted = isset($_SESSION);
    }

    /**
     * {@inheritdoc}
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * {@inheritdoc}
     *
     * Lazily hydrates once per request from the persisted identifier. A
     * resolver returning null (deleted actor) keeps the caller a guest;
     * the stale session value is left in place for the app to observe or
     * logout() — this guard does not silently mutate app session data.
     */
    public function user(): ?AuthenticatableInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;

            $id = $this->readSessionId();

            if ($id !== null) {
                $this->user = ($this->resolver)($id);
            }
        }

        return $this->user;
    }

    /**
     * {@inheritdoc}
     */
    public function id(): string|int|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $credentials): bool
    {
        if ($this->credentialValidator === null) {
            return false;
        }

        return ($this->credentialValidator)($credentials);
    }

    /**
     * {@inheritdoc}
     *
     * Manual authentication ALSO persists the identifier to the session, so
     * the next request hydrates the same actor (setUser is this guard's
     * login).
     */
    public function setUser(AuthenticatableInterface $user): void
    {
        $this->user = $user;
        $this->resolved = true;

        $this->writeSessionId($user->getAuthIdentifier());
    }

    /**
     * Authenticate the given actor for the remainder of the request AND
     * persist its identifier (explicit alias of setUser; kept named for
     * readability at call sites). Does not regenerate the session id —
     * see the fixation note in the class docblock.
     */
    public function login(AuthenticatableInterface $user): void
    {
        $this->setUser($user);
    }

    /**
     * Forget the persisted identifier and clear the resolved user.
     */
    public function logout(): void
    {
        $this->forgetSessionId();

        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Reset guard state (worker mode: one guard object reused across
     * requests). Does NOT touch the session — only this request's cache.
     */
    public function reset(): void
    {
        $this->user = null;
        $this->resolved = false;
    }

    // ── Internal ──────────────────────────────────────────────────

    /**
     * Read the persisted actor identifier; anything that is not a
     * non-empty string|int counts as absent (fail-closed).
     */
    private function readSessionId(): string|int|null
    {
        $raw = $this->sessionStarted
            ? ($_SESSION[$this->sessionKey] ?? null)
            : ($this->fallbackStore[$this->sessionKey] ?? null);

        if (\is_int($raw) || (\is_string($raw) && $raw !== '')) {
            return $raw;
        }

        return null;
    }

    private function writeSessionId(string|int $id): void
    {
        if ($this->sessionStarted) {
            $_SESSION[$this->sessionKey] = $id;

            return;
        }

        $this->fallbackStore[$this->sessionKey] = $id;
    }

    private function forgetSessionId(): void
    {
        if ($this->sessionStarted) {
            unset($_SESSION[$this->sessionKey]);

            return;
        }

        unset($this->fallbackStore[$this->sessionKey]);
    }
}
