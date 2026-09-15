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

namespace Razy\Http;

/**
 * Wraps the result of an HTTP request with fluent accessors.
 *
 * Provides status checking, body parsing, header access, and JSON decoding.
 *
 * Usage:
 * ```php
 * $response = HttpClient::create()->get('https://api.example.com/users');
 *
 * if ($response->successful()) {
 *     $users = $response->json();
 * }
 *
 * // Throw on failure
 * $response->throw();
 * ```
 */
class HttpResponse
{
    /**
     * HTTP status code.
     */
    private int $statusCode;

    /**
     * Raw response body.
     */
    private string $body;

    /**
     * Parsed response headers.
     *
     * @var array<string, string>
     */
    private array $headers;

    /**
     * Cached JSON-decoded body.
     */
    private mixed $decodedJson = null;

    /**
     * Whether JSON has been decoded (to distinguish null decode from not-decoded).
     */
    private bool $jsonDecoded = false;

    /**
     * Create a new HttpResponse.
     *
     * @param int $statusCode HTTP status code
     * @param string $body Raw body
     * @param array<string, string> $headers Response headers (lowercase keys)
     */
    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    /**
     * Get a string representation.
     */
    public function __toString(): string
    {
        return $this->body;
    }

    // ═══════════════════════════════════════════════════════════════
    // Status
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the HTTP status code.
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Whether the request was successful (2xx).
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Alias for successful().
     */
    public function ok(): bool
    {
        return $this->successful();
    }

    /**
     * Whether the request failed (4xx or 5xx).
     */
    public function failed(): bool
    {
        return $this->statusCode >= 400;
    }

    /**
     * Whether the response is a redirect (3xx).
     *
     * Pass an exact status (OAuth dossier S1: `redirect(302)`) to assert the
     * specific redirect shape; with no argument the whole 3xx family matches,
     * exactly as before.
     */
    public function redirect(int $status = 0): bool
    {
        if ($status !== 0) {
            return $this->statusCode === $status;
        }

        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Whether the response is a client error (4xx).
     */
    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Whether the response is a server error (5xx).
     */
    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    // ═══════════════════════════════════════════════════════════════
    // Body
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the raw response body as a string.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decode the response body as JSON.
     *
     * @param bool $assoc When true, returns arrays. When false, returns objects.
     *
     * @return mixed Decoded JSON data or null on decode failure
     */
    public function json(bool $assoc = true): mixed
    {
        if (!$this->jsonDecoded) {
            $this->decodedJson = \json_decode($this->body, $assoc);
            $this->jsonDecoded = true;
        }

        // If assoc differs from cached, re-decode
        if ($assoc) {
            return \is_array($this->decodedJson)
                ? $this->decodedJson
                : \json_decode($this->body, true);
        }

        return $this->decodedJson;
    }

    /**
     * Get a specific value from JSON body using dot notation.
     *
     * @param string $key Dot-notated key (e.g., 'data.users.0.name')
     * @param mixed $default Default value if key not found
     */
    public function jsonGet(string $key, mixed $default = null): mixed
    {
        $data = $this->json(true);
        if (!\is_array($data)) {
            return $default;
        }

        $segments = \explode('.', $key);
        $current = $data;

        foreach ($segments as $segment) {
            if (\is_array($current) && \array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return $default;
            }
        }

        return $current;
    }

    /**
     * Decode the body by CONTENT TYPE (OAuth dossier S1): JSON bodies via
     * json(), and `application/x-www-form-urlencoded` via parse_str — the
     * exact shape GitHub's token endpoint answers with, which a
     * json()-only parser silently dropped.
     *
     * @return array<string,mixed>|null null when the type is neither, or the
     *                                  body does not decode
     */
    public function data(): ?array
    {
        $type = \strtolower((string) $this->header('content-type'));

        if (\str_contains($type, 'json')) {
            $decoded = $this->json(true);

            return \is_array($decoded) ? $decoded : null;
        }

        if (\str_contains($type, 'application/x-www-form-urlencoded')) {
            $parsed = [];
            \parse_str($this->body, $parsed);

            return $parsed;
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Headers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get all response headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific response header value.
     *
     * @param string $name Header name (case-insensitive)
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $name = \strtolower($name);

        return $this->headers[$name] ?? $default;
    }

    /**
     * Check if a response header exists.
     *
     * @param string $name Header name (case-insensitive)
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    /**
     * Get the Content-Type header value.
     */
    public function contentType(): ?string
    {
        return $this->header('content-type');
    }

    // ═══════════════════════════════════════════════════════════════
    // Error Handling
    // ═══════════════════════════════════════════════════════════════

    /**
     * Throw an HttpException if the response indicates failure.
     *
     * @return $this
     *
     * @throws HttpException If the response status is >= 400
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new HttpException($this);
        }

        return $this;
    }

    /**
     * Throw only if the given condition is true and the response failed.
     *
     * @param bool $condition
     *
     * @return $this
     *
     * @throws HttpException
     */
    public function throwIf(bool $condition): static
    {
        if ($condition) {
            return $this->throw();
        }

        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // Conversion
    // ═══════════════════════════════════════════════════════════════

    /**
     * Convert the response to an array representation.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->statusCode,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }
}
