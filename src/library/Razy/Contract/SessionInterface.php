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

namespace Razy\Contract;

/**
 * Contract for the session service.
 *
 * Provides a clean API for session data management, flash messages,
 * session ID regeneration, and session lifecycle control.
 *
 * Implementations decouple session logic from PHP's native session
 * functions, enabling testability (ArrayDriver) and flexible storage
 * backends (database, cache, etc.).
 */
interface SessionInterface
{
    // ── Lifecycle ─────────────────────────────────────────────

    /**
     * Start the session. Loads data from the driver.
     *
     * @return bool True if session was successfully started
     */
    public function start(): bool;

    /**
     * Save session data to the driver and close.
     */
    public function save(): void;

    /**
     * Destroy the session — clears all data and the session ID.
     */
    public function destroy(): void;

    /**
     * Whether the session has been started.
     */
    public function isStarted(): bool;

    // ── ID Management ─────────────────────────────────────────

    /**
     * Get the current session ID.
     */
    public function getId(): string;

    /**
     * Set the session ID (must be called before start).
     */
    public function setId(string $id): void;

    /**
     * Regenerate the session ID (CSRF/session fixation protection).
     *
     * @param bool $destroyOld Whether to destroy the old session data
     *
     * @return bool True on success
     */
    public function regenerate(bool $destroyOld = false): bool;

    // ── Data Access ───────────────────────────────────────────

    /**
     * Get a value from the session.
     *
     * @param string $key The session key
     * @param mixed $default Default if key does not exist
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in the session.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check whether a key exists in the session.
     */
    public function has(string $key): bool;

    /**
     * Remove a key from the session.
     */
    public function remove(string $key): void;

    /**
     * Get all session data.
     *
     * @return array<string, mixed>
     */
    public function all(): array;

    /**
     * Clear all session data (but keep the session alive).
     */
    public function clear(): void;

    // ── Flash Data ────────────────────────────────────────────

    /**
     * Set a flash value — available only for the next request.
     */
    public function flash(string $key, mixed $value): void;

    /**
     * Get a flash value (and mark it as consumed).
     */
    public function getFlash(string $key, mixed $default = null): mixed;

    /**
     * Whether a flash key exists.
     */
    public function hasFlash(string $key): bool;

    /**
     * Keep all current flash data for one more request.
     */
    public function reflash(): void;

    /**
     * Keep specific flash keys for one more request.
     *
     * @param list<string> $keys
     */
    public function keep(array $keys): void;
}
