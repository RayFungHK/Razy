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
 * Contract for session storage backends.
 *
 * Drivers handle the low-level read/write of session data by session ID.
 * The Session class delegates persistence to a driver, keeping session
 * logic (flash data, regeneration) decoupled from storage.
 */
interface SessionDriverInterface
{
    /**
     * Open the session store. Called once when the session starts.
     *
     * @return bool True on success
     */
    public function open(): bool;

    /**
     * Close the session store. Called after save/destroy.
     *
     * @return bool True on success
     */
    public function close(): bool;

    /**
     * Read session data for the given ID.
     *
     * @param string $id The session ID
     *
     * @return array<string, mixed> The session data (empty array if none)
     */
    public function read(string $id): array;

    /**
     * Write session data for the given ID.
     *
     * @param string $id The session ID
     * @param array $data The session data to persist
     *
     * @return bool True on success
     */
    public function write(string $id, array $data): bool;

    /**
     * Destroy session data for the given ID.
     *
     * @param string $id The session ID
     *
     * @return bool True on success
     */
    public function destroy(string $id): bool;

    /**
     * Garbage-collect expired sessions.
     *
     * @param int $maxLifetime Maximum session lifetime in seconds
     *
     * @return int Number of sessions deleted
     */
    public function gc(int $maxLifetime): int;
}
