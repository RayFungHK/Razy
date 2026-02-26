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

namespace Razy\Csrf;

use Razy\Contract\SessionInterface;

/**
 * CSRF token manager — generates, stores, and validates CSRF tokens.
 *
 * Tokens are stored in the session and validated using timing-safe
 * comparison (`hash_equals`) to prevent timing attacks.
 *
 * Supports two token strategies:
 * - **Synchronizer token**: A single per-session token (default). Simple, widely
 *   compatible. The token persists across requests until regenerated.
 * - **Per-request token rotation**: Call `regenerate()` after each validation
 *   for stricter security (at the cost of breaking back-button navigation).
 *
 * Usage:
 * ```php
 * $session = new Session($driver, $config);
 * $csrf = new CsrfTokenManager($session);
 *
 * // Generate & embed in form
 * $token = $csrf->token();
 * echo '<input type="hidden" name="_token" value="' . $token . '">';
 *
 * // Validate on submission
 * if ($csrf->validate($_POST['_token'] ?? '')) {
 *     // Valid — process form
 * }
 * ```
 */
class CsrfTokenManager
{
    /**
     * Session key used to store the CSRF token.
     */
    public const SESSION_KEY = '_csrf_token';

    /**
     * Default token length in bytes (produces a 64-character hex string).
     */
    private const TOKEN_BYTES = 32;

    /**
     * @param SessionInterface $session The session instance for token storage.
     */
    public function __construct(
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Get the current CSRF token, generating one if none exists.
     *
     * The token is stored in the session and reused across requests
     * until explicitly regenerated via `regenerate()`.
     *
     * @return string The 64-character hex CSRF token.
     */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if ($token === null || !\is_string($token) || $token === '') {
            $token = $this->generateToken();
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Validate a submitted token against the stored session token.
     *
     * Uses `hash_equals()` for timing-safe comparison to prevent
     * timing side-channel attacks.
     *
     * @param string $submittedToken The token submitted by the client.
     *
     * @return bool True if the token matches the stored token.
     */
    public function validate(string $submittedToken): bool
    {
        $storedToken = $this->session->get(self::SESSION_KEY);

        if ($storedToken === null || !\is_string($storedToken) || $storedToken === '') {
            return false;
        }

        return \hash_equals($storedToken, $submittedToken);
    }

    /**
     * Regenerate the CSRF token.
     *
     * Replaces the stored token with a fresh one. Call this after
     * successful form submissions for per-request token rotation,
     * or after session regeneration.
     *
     * @return string The new 64-character hex CSRF token.
     */
    public function regenerate(): string
    {
        $token = $this->generateToken();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Check whether a CSRF token currently exists in the session.
     *
     * @return bool True if a token is stored.
     */
    public function hasToken(): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return \is_string($token) && $token !== '';
    }

    /**
     * Remove the CSRF token from the session.
     *
     * Useful when explicitly invalidating tokens (e.g., on logout).
     */
    public function clearToken(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * Get the session instance used by this manager.
     *
     * @return SessionInterface
     */
    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * Generate a cryptographically secure random token.
     *
     * @return string A 64-character hex string (32 random bytes).
     */
    private function generateToken(): string
    {
        return \bin2hex(\random_bytes(self::TOKEN_BYTES));
    }
}
