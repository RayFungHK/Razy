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
 * As of CSRF-RAIL L0 the session also OWNS its cookie: `start()` recognises
 * a valid carried id (reads `$_COOKIE[config name]`), freshly minted and
 * rotated ids are emitted, and `destroy()` expires it — the SessionConfig
 * cookie fields are no longer decoration that only GC consumed.
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

        // CSRF-RAIL L0: recognise our own cookie — a browser carrying a
        // valid session id gets ITS session back, not a fresh one. The
        // shape mirrors generateId() (40 hex chars); anything else is
        // discarded rather than trusted (the driver is never queried with
        // attacker-shaped bytes).
        $carried = $_COOKIE[$this->config->name] ?? '';

        if ($this->id === '' && \is_string($carried)
            && \strlen($carried) === 40 && \ctype_xdigit($carried)) {
            $this->id = $carried;
        }

        $isNew = false;

        if ($this->id === '') {
            $this->id = $this->generateId();
            $isNew = true;
        }

        $this->attributes = $this->driver->read($this->id);
        $this->started = true;

        // A brand-new session must reach the browser or no identity
        // survives the response; an ADOPTED one needs no re-send.
        if ($isNew) {
            $this->emitCookie();
        }

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
        $this->expireCookie();
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

            // The browser must learn the NEW id or the rotation is
            // invisible (and the next request resurrects the old one).
            $this->emitCookie();
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

    /**
     * Build the setcookie() options array from this session's config.
     *
     * Pure function (never sends) so the config→cookie mapping is directly
     * testable; $expire flips it to the "remove now" variant destroy() uses.
     * lifetime 0 means browser-session cookie (expires 0), matching the
     * SessionConfig contract.
     *
     * @return array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    protected function cookieOptions(bool $expire = false): array
    {
        return [
            'expires' => $expire
                ? \time() - 3600
                : ($this->config->lifetime > 0 ? \time() + $this->config->lifetime : 0),
            'path' => $this->config->path,
            'domain' => $this->config->domain,
            'secure' => $this->config->secure,
            'httponly' => $this->config->httpOnly,
            'samesite' => $this->config->sameSite,
        ];
    }

    /**
     * Send the session cookie for the current id (CSRF-RAIL L0).
     *
     * SessionConfig has carried these cookie fields since v0.5 and NOTHING
     * consumed them — razymod/queue-admin's hand-roll even cited "the
     * framework Session subsystem emits NO cookie anywhere" as the reason
     * it bypassed CsrfTokenManager (support/csrf.php docblock). This method
     * makes that confession false by construction. Protected seam: tests
     * override to record; the real one is inert under CLI or once headers
     * have flown (worker mode re-boots per request, so headers_sent()
     * starts false on every handled request).
     */
    protected function emitCookie(): void
    {
        if (\PHP_SAPI === 'cli' || \headers_sent()) {
            return;
        }

        \setcookie($this->config->name, $this->id, $this->cookieOptions());
    }

    /**
     * Ask the client to drop the session cookie (paired with destroy()).
     */
    protected function expireCookie(): void
    {
        if (\PHP_SAPI === 'cli' || \headers_sent()) {
            return;
        }

        \setcookie($this->config->name, '', $this->cookieOptions(true));
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
