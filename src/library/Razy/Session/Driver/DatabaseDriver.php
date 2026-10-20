<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Database-backed session driver.
 *
 * Persists session data to a database table using PDO. Supports any
 * database backend that Razy connects to (MySQL, PostgreSQL, SQLite).
 * Uses REPLACE INTO for atomic upsert on write, standard DELETE for
 * destroy and GC.
 *
 * Expected table schema:
 *   CREATE TABLE sessions (
 *       id VARCHAR(128) PRIMARY KEY,
 *       data TEXT NOT NULL,
 *       last_activity INTEGER NOT NULL
 *   );
 *
 *
 * @license MIT
 */

namespace Razy\Session\Driver;

use InvalidArgumentException;
use JsonException;
use PDO;
use Razy\Contract\SessionDriverInterface;
use Throwable;

/**
 * Database session storage driver.
 *
 * Stores session data as JSON (RZ-05) in a database table. The driver
 * operates directly on PDO for maximum portability and minimal
 * coupling to the Razy Database class.
 */
class DatabaseDriver implements SessionDriverInterface
{
    /** @var string Default table name */
    private const DEFAULT_TABLE = 'sessions';

    /** @var int Last GC deletion count (for testing) */
    private int $lastGcCount = 0;

    /**
     * DatabaseDriver constructor.
     *
     * @param PDO $pdo The PDO connection to use
     * @param string $table The session table name (default: 'sessions')
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {
        // Validate table name to prevent SQL injection (only alphanumeric + underscore)
        if (!\preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $this->table)) {
            throw new InvalidArgumentException('Invalid session table name: ' . $this->table);
        }
    }

    // ── Accessors ─────────────────────────────────────────────

    /**
     * JSON when the data allows it; values JSON cannot carry (invalid UTF-8,
     * INF/NAN) fall back to serialize() so the write is never lost (such a
     * row is then readable by PHP only).
     *
     * @param array<mixed> $data
     */
    private static function encode(array $data): string
    {
        try {
            return \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            return \serialize($data);
        }
    }

    /**
     * RZ-05: rows are JSON so a non-PHP service sharing the table can read a
     * session it found by sha256(cookie). Rows written with PHP serialize()
     * before RZ-05 still decode (objects are never revived).
     *
     * @return array<mixed>
     */
    private static function decode(string $raw): array
    {
        $first = \ltrim($raw)[0] ?? '';
        if ($first === '{' || $first === '[') {
            $data = \json_decode($raw, true);
        } else {
            $data = @\unserialize($raw, ['allowed_classes' => false]);
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     */
    public function open(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Returns an empty array if no session with the given ID exists.
     */
    public function read(string $id): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM {$this->table} WHERE id = :id LIMIT 1",
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || !isset($row['data'])) {
                return [];
            }

            return self::decode((string) $row['data']);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Uses INSERT OR REPLACE (SQLite) / REPLACE INTO (MySQL) for atomic upsert.
     * For PostgreSQL, uses INSERT ... ON CONFLICT ... DO UPDATE.
     */
    public function write(string $id, array $data): bool
    {
        try {
            $serialized = self::encode($data);
            $time = \time();

            // Use a driver-agnostic approach: try to UPDATE first, INSERT if no rows affected
            $stmt = $this->pdo->prepare(
                "UPDATE {$this->table} SET data = :data, last_activity = :time WHERE id = :id",
            );
            $stmt->execute(['id' => $id, 'data' => $serialized, 'time' => $time]);

            if ($stmt->rowCount() === 0) {
                // Row doesn't exist — insert
                $stmt = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (id, data, last_activity) VALUES (:id, :data, :time)",
                );
                $stmt->execute(['id' => $id, 'data' => $serialized, 'time' => $time]);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "DELETE FROM {$this->table} WHERE id = :id",
            );
            $stmt->execute(['id' => $id]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Deletes sessions where `last_activity` is older than `$maxLifetime` seconds ago.
     */
    public function gc(int $maxLifetime): int
    {
        try {
            $cutoff = \time() - $maxLifetime;

            $stmt = $this->pdo->prepare(
                "DELETE FROM {$this->table} WHERE last_activity < :cutoff",
            );
            $stmt->execute(['cutoff' => $cutoff]);

            $count = $stmt->rowCount();
            $this->lastGcCount = $count;

            return $count;
        } catch (Throwable) {
            $this->lastGcCount = 0;

            return 0;
        }
    }

    /**
     * Get the configured table name.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get the last GC deletion count (for testing).
     */
    public function getLastGcCount(): int
    {
        return $this->lastGcCount;
    }

    /**
     * Create the session table if it does not exist.
     *
     * Utility method for setup/migration. Call once during application
     * bootstrapping or in a migration script.
     *
     * @return bool True on success
     */
    public function createTable(): bool
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                data TEXT NOT NULL DEFAULT '',
                last_activity INTEGER NOT NULL DEFAULT 0
            )");

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
