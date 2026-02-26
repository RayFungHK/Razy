<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Csrf;

use Closure;
use Razy\Contract\MiddlewareInterface;

/**
 * CSRF protection middleware — enforces CSRF token validation on state-changing requests.
 *
 * Automatically skips validation for safe HTTP methods (GET, HEAD, OPTIONS)
 * and checks the token for state-changing methods (POST, PUT, PATCH, DELETE).
 *
 * The token is extracted from (in priority order):
 * 1. Request body field `_token` (form submissions)
 * 2. HTTP header `X-CSRF-TOKEN` (AJAX/XHR requests)
 *
 * On failure, either delegates to a custom rejection handler or sets
 * HTTP 419 and returns null (default behavior).
 *
 * Usage:
 * ```php
 * $csrf = new CsrfTokenManager($session);
 * $middleware = new CsrfMiddleware($csrf);
 *
 * // With custom rejection handler
 * $middleware = new CsrfMiddleware($csrf, onMismatch: function (array $ctx) {
 *     return ['error' => 'Invalid CSRF token'];
 * });
 *
 * // With route exclusions
 * $middleware = new CsrfMiddleware($csrf, excludedRoutes: ['/api/webhook', '/api/callback']);
 *
 * // With token rotation after each successful validation
 * $middleware = new CsrfMiddleware($csrf, rotateOnSuccess: true);
 * ```
 *
 * @package Razy\Csrf
 */
class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * HTTP methods that are considered "safe" and do not require CSRF validation.
     * RFC 7231 § 4.2.1: Safe methods are those that are essentially read-only.
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Default form field name for the CSRF token.
     */
    public const TOKEN_FIELD = '_token';

    /**
     * Default HTTP header name for the CSRF token (AJAX requests).
     */
    public const TOKEN_HEADER = 'X-CSRF-TOKEN';

    /**
     * @param CsrfTokenManager $tokenManager   The token manager for generation/validation.
     * @param Closure|null      $onMismatch     Optional handler when CSRF validation fails.
     *                                          Receives `(array $context)`, returns mixed.
     *                                          If null, sets HTTP 419 and returns null.
     * @param array<string>     $excludedRoutes Routes to exclude from CSRF validation.
     *                                          Matched against `$context['route']`.
     * @param bool              $rotateOnSuccess Whether to regenerate the token after
     *                                          successful validation (per-request rotation).
     * @param Closure|null      $tokenExtractor Optional custom token extractor.
     *                                          Receives `(array $context)`, returns `?string`.
     *                                          Overrides default form field + header extraction.
     */
    public function __construct(
        private readonly CsrfTokenManager $tokenManager,
        private readonly ?Closure $onMismatch = null,
        private readonly array $excludedRoutes = [],
        private readonly bool $rotateOnSuccess = false,
        private readonly ?Closure $tokenExtractor = null,
    ) {}

    /**
     * Handle the request through the CSRF protection layer.
     *
     * Safe methods (GET, HEAD, OPTIONS) pass through without validation.
     * State-changing methods (POST, PUT, PATCH, DELETE) require a valid CSRF token.
     *
     * {@inheritdoc}
     */
    public function handle(array $context, Closure $next): mixed
    {
        $method = strtoupper($context['method'] ?? 'GET');

        // Safe methods — no CSRF check needed
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $next($context);
        }

        // Check if this route is excluded
        if ($this->isExcluded($context)) {
            return $next($context);
        }

        // Extract the submitted token
        $submittedToken = $this->extractToken($context);

        // Validate
        if ($submittedToken === null || !$this->tokenManager->validate($submittedToken)) {
            return $this->handleMismatch($context);
        }

        // Rotate token if configured
        if ($this->rotateOnSuccess) {
            $this->tokenManager->regenerate();
        }

        return $next($context);
    }

    /**
     * Get the CSRF token manager used by this middleware.
     *
     * @return CsrfTokenManager
     */
    public function getTokenManager(): CsrfTokenManager
    {
        return $this->tokenManager;
    }

    /**
     * Check whether a route is excluded from CSRF validation.
     *
     * @param array $context The middleware context.
     *
     * @return bool True if the route is excluded.
     */
    private function isExcluded(array $context): bool
    {
        if (empty($this->excludedRoutes)) {
            return false;
        }

        $route = $context['route'] ?? '';

        return in_array($route, $this->excludedRoutes, true);
    }

    /**
     * Extract the CSRF token from the request.
     *
     * Checks (in order):
     * 1. Custom token extractor (if provided)
     * 2. `$_POST['_token']` (form submissions)
     * 3. `$_SERVER['HTTP_X_CSRF_TOKEN']` header (AJAX requests)
     * 4. `$context['_token']` (testing / manual injection)
     *
     * @param array $context The middleware context.
     *
     * @return string|null The submitted token, or null if not found.
     */
    private function extractToken(array $context): ?string
    {
        // Custom extractor takes full priority
        if ($this->tokenExtractor !== null) {
            $token = ($this->tokenExtractor)($context);

            return is_string($token) && $token !== '' ? $token : null;
        }

        // 1. Form field (POST body)
        if (isset($_POST[self::TOKEN_FIELD]) && is_string($_POST[self::TOKEN_FIELD]) && $_POST[self::TOKEN_FIELD] !== '') {
            return $_POST[self::TOKEN_FIELD];
        }

        // 2. HTTP header (AJAX/XHR)
        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::TOKEN_HEADER));
        if (isset($_SERVER[$headerKey]) && is_string($_SERVER[$headerKey]) && $_SERVER[$headerKey] !== '') {
            return $_SERVER[$headerKey];
        }

        // 3. Context injection (testing / middleware chaining)
        if (isset($context[self::TOKEN_FIELD]) && is_string($context[self::TOKEN_FIELD]) && $context[self::TOKEN_FIELD] !== '') {
            return $context[self::TOKEN_FIELD];
        }

        return null;
    }

    /**
     * Handle a CSRF token mismatch.
     *
     * Either delegates to the custom rejection handler or sets HTTP 419
     * status and returns null.
     *
     * @param array $context The middleware context.
     *
     * @return mixed Result from the rejection handler, or null.
     */
    private function handleMismatch(array $context): mixed
    {
        if ($this->onMismatch !== null) {
            return ($this->onMismatch)($context);
        }

        http_response_code(419);

        return null;
    }
}
