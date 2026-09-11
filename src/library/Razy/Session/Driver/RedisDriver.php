<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Redis-backed session driver.
 *
 *
 * @license MIT
 */

namespace Razy\Session\Driver;

use InvalidArgumentException;
use Razy\Contract\SessionDriverInterface;
use Redis;
use RedisException;

/**
 * Redis-backed session storage driver.
 *
 * Each session lives under `{prefix}{id}` with a native Redis TTL equal to the
 * configured lifetime, refreshed on every write (sliding expiration — the same
 * observable semantics as FileDriver's mtime-based garbage collection).
 *
 * Designed for horizontal scale: file sessions serialize concurrent requests
 * per session behind filesystem locks and do not survive multi-node deployments
 * without shared storage; Redis fixes both.
 *
 * Usage:
 * ```php
 * $redis = new \Redis();
 * $redis->connect('127.0.0.1', 6379);
 *
 * $driver = new RedisDriver($redis, 'mysite_sess_', 86400);
 * $session = new Session($driver);
 * ```
 */
class RedisDriver implements SessionDriverInterface
{
    /** @var string Default key prefix */
    private const DEFAULT_PREFIX = 'razy_sess_';

    /** @var int Default session lifetime in seconds (24h) */
    private const DEFAULT_LIFETIME = 86400;

    /** @var int SCAN batch size for garbage collection (never KEYS — production keyspaces are large) */
    private const GC_SCAN_COUNT = 500;

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = self::DEFAULT_PREFIX,
        private readonly int $lifetime = self::DEFAULT_LIFETIME,
    ) {
        if ($this->lifetime < 1) {
            throw new InvalidArgumentException('Session lifetime must be at least 1 second.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * Verifies the connection with PING.
     */
    public function open(): bool
    {
        try {
            return (bool) $this->redis->ping();
        } catch (RedisException) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Connections are long-lived and reused; nothing to release.
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Deserialization forbids object hydration (allowed_classes => false),
     * matching FileDriver's hardening against hostile store contents.
     */
    public function read(string $id): array
    {
        $value = $this->redis->get($this->key($id));

        if (!\is_string($value) || $value === '') {
            return [];
        }

        $data = @\unserialize($value, ['allowed_classes' => false]);

        return \is_array($data) ? $data : [];
    }

    /**
     * {@inheritdoc}
     *
     * STORE-equivalent: SETEX refreshes the TTL on every write, so an active
     * session never expires while it is being used.
     */
    public function write(string $id, array $data): bool
    {
        return (bool) $this->redis->setex($this->key($id), $this->lifetime, \serialize($data));
    }

    /**
     * {@inheritdoc}
     *
     * Idempotent: destroying an absent session reports success (FileDriver parity).
     */
    public function destroy(string $id): bool
    {
        $this->redis->del($this->key($id));

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Native TTL already expires dead sessions; this pass exists so callers
     * using a shorter maxLifetime than the driver's write TTL still get honest
     * eviction. Last-access age is derived from the remaining TTL:
     *     age = lifetime - remaining  (sliding window written by write())
     *
     * Keys with no TTL (foreign writes) are removed as unsafe; keys already
     * expired by Redis are skipped (they are gone).
     */
    public function gc(int $maxLifetime): int
    {
        $deleted = 0;
        $cursor = null;

        try {
            do {
                $keys = $this->redis->scan($cursor, $this->prefix . '*', self::GC_SCAN_COUNT);

                if (!\is_array($keys)) {
                    break;
                }

                foreach ($keys as $key) {
                    $remaining = $this->redis->ttl((string) $key);

                    if ($remaining === -2) {
                        // Expired between scan and ttl — nothing to do.
                        continue;
                    }

                    $aged = $remaining === -1 || ($this->lifetime - $remaining) > $maxLifetime;

                    if ($aged && $this->redis->del((string) $key) > 0) {
                        ++$deleted;
                    }
                }
            } while (\is_int($cursor) && $cursor > 0);
        } catch (RedisException) {
            // GC is best-effort; report what was already deleted.
        }

        return $deleted;
    }

    // ── Accessors ─────────────────────────────────────────────

    public function getRedis(): Redis
    {
        return $this->redis;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    // ── Internal ──────────────────────────────────────────────

    /**
     * Build the storage key, validating the session ID shape.
     *
     * Redis keys are binary-safe, but empty/NUL ids mask caller bugs and
     * produce unaddressable keys — rejected like FileDriver's traversal guard.
     */
    private function key(string $id): string
    {
        if ($id === '' || \str_contains($id, "\x00")) {
            throw new InvalidArgumentException('Invalid session ID format.');
        }

        return $this->prefix . $id;
    }
}
