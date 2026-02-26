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

namespace Razy\Session;

use Razy\Contract\SessionDriverInterface;
use Razy\Contract\SessionInterface;

/**
 * Core session implementation.
 *
 * Manages session data, flash messages, and session ID lifecycle. Delegates
 * persistence to a `SessionDriverInterface` driver, fully decoupled from
 * PHP's native `$_SESSION` / `session_*()` functions.
 *
 * Flash data uses a two-generation lifecycle:
 *   - On `flash('key', value)`: key is placed in `_flash.new`
 *   - On `save()`: `_flash.old` items are removed, `_flash.new` → `_flash.old`
 *   - Next request's `start()` loads both; `getFlash()` reads from flash storage
 */
class Session implements SessionInterface
{
    /**
     * Internal keys for flash data bookkeeping.
     */
    private const FLASH_NEW = '_flash.new';

    private const FLASH_OLD = '_flash.old';

    private const FLASH_DATA = '_flash.data';

    /**
     * @var array<string, mixed> Session attributes
     */
    private array $attributes = [];

    /**
     * @var string Current session ID
     */
    private string $id = '';

    /**
     * @var bool Whether the session has been started
     */
    private bool $started = false;

    public function __construct(
        private readonly SessionDriverInterface $driver,
        private readonly SessionConfig $config = new SessionConfig(),
    ) {
    }

    // ── Lifecycle ─────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        $this->driver->open();

        if ($this->id === '') {
            $this->id = $this->generateId();
        }

        $this->attributes = $this->driver->read($this->id);
        $this->started = true;

        // Probabilistic GC
        if ($this->config->gcDivisor > 0
            && \random_int(1, $this->config->gcDivisor) <= $this->config->gcProbability
        ) {
            $this->driver->gc($this->config->gcMaxLifetime);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        $this->ageFlashData();

        $this->driver->write($this->id, $this->attributes);
        $this->driver->close();
        $this->started = false;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(): void
    {
        $this->driver->destroy($this->id);
        $this->attributes = [];
        $this->driver->close();
        $this->started = false;
    }

    /**
     * {@inheritdoc}
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    // ── ID Management ─────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    /**
     * {@inheritdoc}
     */
    public function regenerate(bool $destroyOld = false): bool
    {
        if ($destroyOld && $this->id !== '') {
            $this->driver->destroy($this->id);
        }

        $this->id = $this->generateId();

        if ($this->started) {
            $this->driver->write($this->id, $this->attributes);
        }

        return true;
    }

    // ── Data Access ───────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->attributes);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->attributes = [];
    }

    // ── Flash Data ────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function flash(string $key, mixed $value): void
    {
        $flashData = $this->attributes[self::FLASH_DATA] ?? [];
        $flashData[$key] = $value;
        $this->attributes[self::FLASH_DATA] = $flashData;

        // Track in new list, remove from old
        $new = $this->attributes[self::FLASH_NEW] ?? [];
        if (!\in_array($key, $new, true)) {
            $new[] = $key;
        }
        $this->attributes[self::FLASH_NEW] = $new;

        $old = $this->attributes[self::FLASH_OLD] ?? [];
        $this->attributes[self::FLASH_OLD] = \array_values(\array_diff($old, [$key]));
    }

    /**
     * {@inheritdoc}
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        $flashData = $this->attributes[self::FLASH_DATA] ?? [];

        return $flashData[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function hasFlash(string $key): bool
    {
        $flashData = $this->attributes[self::FLASH_DATA] ?? [];

        return \array_key_exists($key, $flashData);
    }

    /**
     * {@inheritdoc}
     */
    public function reflash(): void
    {
        $old = $this->attributes[self::FLASH_OLD] ?? [];
        $new = $this->attributes[self::FLASH_NEW] ?? [];

        $this->attributes[self::FLASH_NEW] = \array_values(\array_unique(\array_merge($new, $old)));
        $this->attributes[self::FLASH_OLD] = [];
    }

    /**
     * {@inheritdoc}
     */
    public function keep(array $keys): void
    {
        $old = $this->attributes[self::FLASH_OLD] ?? [];
        $new = $this->attributes[self::FLASH_NEW] ?? [];

        $kept = \array_intersect($old, $keys);

        $this->attributes[self::FLASH_NEW] = \array_values(\array_unique(\array_merge($new, $kept)));
        $this->attributes[self::FLASH_OLD] = \array_values(\array_diff($old, $keys));
    }

    /**
     * Get the session configuration.
     */
    public function getConfig(): SessionConfig
    {
        return $this->config;
    }

    /**
     * Get the underlying driver (for testing/inspection).
     */
    public function getDriver(): SessionDriverInterface
    {
        return $this->driver;
    }

    // ── Internal ──────────────────────────────────────────────

    /**
     * Age flash data: remove old, promote new → old.
     */
    private function ageFlashData(): void
    {
        $flashData = $this->attributes[self::FLASH_DATA] ?? [];
        $old = $this->attributes[self::FLASH_OLD] ?? [];

        // Remove old flash entries
        foreach ($old as $key) {
            unset($flashData[$key]);
        }

        $this->attributes[self::FLASH_DATA] = $flashData;

        // New → old for next request
        $this->attributes[self::FLASH_OLD] = $this->attributes[self::FLASH_NEW] ?? [];
        $this->attributes[self::FLASH_NEW] = [];
    }

    /**
     * Generate a cryptographically random session ID.
     */
    private function generateId(): string
    {
        return \bin2hex(\random_bytes(20));
    }
}
