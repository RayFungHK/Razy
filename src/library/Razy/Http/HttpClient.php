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

use Closure;
use CurlHandle;

/**
 * Fluent HTTP client wrapper around PHP's cURL extension.
 *
 * Provides a clean, chainable API for making HTTP requests:
 *
 * ```php
 * // Simple GET
 * $response = HttpClient::create()
 *     ->baseUrl('https://api.example.com')
 *     ->withToken('your-api-token')
 *     ->get('/users');
 *
 * // POST with JSON body
 * $response = HttpClient::create()
 *     ->timeout(10)
 *     ->withHeaders(['Accept' => 'application/json'])
 *     ->post('https://api.example.com/users', [
 *         'name' => 'John',
 *         'email' => 'john@example.com',
 *     ]);
 *
 * // Form data
 * $response = HttpClient::create()
 *     ->asForm()
 *     ->post('/login', ['user' => 'admin', 'pass' => 'secret']);
 *
 * // Error handling
 * $response = HttpClient::create()
 *     ->get('/might-fail')
 *     ->throw(); // throws HttpException on 4xx/5xx
 * ```
 *
 * Features:
 * - Fluent configuration (base URL, headers, auth, timeout, SSL)
 * - JSON and form-encoded request bodies
 * - Bearer token, Basic auth support
 * - Response wrapper with status helpers, JSON parsing, header access
 * - Request/response interceptors
 * - Retry support
 */
class HttpClient
{
    /**
     * Base URL prepended to relative request URLs.
     */
    private string $baseUrl = '';

    /**
     * Default request headers.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * Request timeout in seconds.
     */
    private int $timeout = 30;

    /**
     * Connection timeout in seconds.
     */
    private int $connectTimeout = 10;

    /**
     * Whether to verify SSL certificates.
     */
    private bool $verifySsl = true;

    /**
     * Body encoding format: 'json' or 'form'.
     */
    private string $bodyFormat = 'json';

    /**
     * Query parameters to append to every request.
     *
     * @var array<string, mixed>
     */
    private array $queryParams = [];

    /**
     * Basic auth credentials.
     *
     * @var array{username: string, password: string}|null
     */
    private ?array $basicAuth = null;

    /**
     * Bearer token.
     */
    private ?string $bearerToken = null;

    /**
     * cURL options to set on every request.
     *
     * @var array<int, mixed>
     */
    private array $curlOptions = [];

    /**
     * Number of retries on failure.
     */
    private int $retries = 0;

    /**
     * Delay between retries in milliseconds.
     */
    private int $retryDelay = 100;

    /**
     * HTTP status codes that trigger a retry.
     *
     * @var list<int>
     */
    private array $retryOnStatus = [429, 500, 502, 503, 504];

    /**
     * Before-request interceptor.
     *
     * @var Closure|null fn(CurlHandle, string, string, array): void
     */
    private ?Closure $beforeCallback = null;

    /**
     * After-response interceptor.
     *
     * @var Closure|null fn(HttpResponse, string, string): void
     */
    private ?Closure $afterCallback = null;

    /**
     * Custom User-Agent string.
     */
    private ?string $userAgent = null;

    /**
     * Create a new HttpClient instance.
     */
    public function __construct()
    {
        // Defaults set via property declarations
    }

    /**
     * Static factory.
     */
    public static function create(): static
    {
        return new static();
    }

    // ═══════════════════════════════════════════════════════════════
    // Configuration (all fluent)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Set the base URL for relative request paths.
     *
     * @param string $url e.g. 'https://api.example.com/v1'
     */
    public function baseUrl(string $url): static
    {
        $this->baseUrl = \rtrim($url, '/');

        return $this;
    }

    /**
     * Set request headers (merges with existing).
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[\strtolower($name)] = $value;
        }

        return $this;
    }

    /**
     * Set a single header.
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[\strtolower($name)] = $value;

        return $this;
    }

    /**
     * Set a Bearer token for Authorization header.
     */
    public function withToken(string $token): static
    {
        $this->bearerToken = $token;

        return $this;
    }

    /**
     * Set Basic authentication credentials.
     */
    public function withBasicAuth(string $username, string $password): static
    {
        $this->basicAuth = ['username' => $username, 'password' => $password];

        return $this;
    }

    /**
     * Set the request timeout in seconds.
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the connection timeout in seconds.
     */
    public function connectTimeout(int $seconds): static
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * Disable SSL certificate verification.
     *
     * **WARNING**: Only use for development / self-signed certificates.
     */
    public function withoutVerifying(): static
    {
        $this->verifySsl = false;

        return $this;
    }

    /**
     * Enable SSL certificate verification (default).
     */
    public function withVerifying(): static
    {
        $this->verifySsl = true;

        return $this;
    }

    /**
     * Send request bodies as JSON (default).
     */
    public function asJson(): static
    {
        $this->bodyFormat = 'json';

        return $this;
    }

    /**
     * Send request bodies as form-encoded data.
     */
    public function asForm(): static
    {
        $this->bodyFormat = 'form';

        return $this;
    }

    /**
     * Send request bodies as multipart form data.
     */
    public function asMultipart(): static
    {
        $this->bodyFormat = 'multipart';

        return $this;
    }

    /**
     * Set default query parameters for all requests.
     *
     * @param array<string, mixed> $params
     */
    public function withQuery(array $params): static
    {
        $this->queryParams = \array_merge($this->queryParams, $params);

        return $this;
    }

    /**
     * Set custom User-Agent string.
     */
    public function userAgent(string $agent): static
    {
        $this->userAgent = $agent;

        return $this;
    }

    /**
     * Set a raw cURL option.
     *
     * @param int $option CURLOPT_* constant
     * @param mixed $value Option value
     */
    public function withCurlOption(int $option, mixed $value): static
    {
        $this->curlOptions[$option] = $value;

        return $this;
    }

    /**
     * Configure retry behavior for failed requests.
     *
     * @param int $times Number of retry attempts
     * @param int $delay Delay between retries in milliseconds
     * @param list<int> $onStatus HTTP status codes that trigger retry
     */
    public function retry(int $times, int $delay = 100, array $onStatus = []): static
    {
        $this->retries = $times;
        $this->retryDelay = $delay;
        if (!empty($onStatus)) {
            $this->retryOnStatus = $onStatus;
        }

        return $this;
    }

    /**
     * Register a before-request interceptor.
     *
     * @param Closure $callback fn(CurlHandle $ch, string $method, string $url, array $options): void
     */
    public function beforeSending(Closure $callback): static
    {
        $this->beforeCallback = $callback;

        return $this;
    }

    /**
     * Register an after-response interceptor.
     *
     * @param Closure $callback fn(HttpResponse $response, string $method, string $url): void
     */
    public function afterResponse(Closure $callback): static
    {
        $this->afterCallback = $callback;

        return $this;
    }

    // ═══════════════════════════════════════════════════════════════
    // HTTP Verbs
    // ═══════════════════════════════════════════════════════════════

    /**
     * Send a GET request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $query Additional query parameters
     */
    public function get(string $url, array $query = []): HttpResponse
    {
        return $this->send('GET', $url, ['query' => $query]);
    }

    /**
     * Send a POST request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $data Request body data
     */
    public function post(string $url, array $data = []): HttpResponse
    {
        return $this->send('POST', $url, ['body' => $data]);
    }

    /**
     * Send a PUT request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $data Request body data
     */
    public function put(string $url, array $data = []): HttpResponse
    {
        return $this->send('PUT', $url, ['body' => $data]);
    }

    /**
     * Send a PATCH request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $data Request body data
     */
    public function patch(string $url, array $data = []): HttpResponse
    {
        return $this->send('PATCH', $url, ['body' => $data]);
    }

    /**
     * Send a DELETE request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $data Request body data
     */
    public function delete(string $url, array $data = []): HttpResponse
    {
        return $this->send('DELETE', $url, ['body' => $data]);
    }

    /**
     * Send a HEAD request.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $query Additional query parameters
     */
    public function head(string $url, array $query = []): HttpResponse
    {
        return $this->send('HEAD', $url, ['query' => $query]);
    }

    /**
     * Send an OPTIONS request.
     *
     * @param string $url URL or path
     */
    public function options(string $url): HttpResponse
    {
        return $this->send('OPTIONS', $url);
    }

    // ═══════════════════════════════════════════════════════════════
    // Request Execution
    // ═══════════════════════════════════════════════════════════════

    /**
     * Send an HTTP request.
     *
     * @param string $method HTTP method
     * @param string $url Full URL or relative path
     * @param array<string,mixed> $options Request options: 'query', 'body', 'headers'
     *
     * @return HttpResponse
     */
    public function send(string $method, string $url, array $options = []): HttpResponse
    {
        $fullUrl = $this->buildUrl($url, $options['query'] ?? []);
        $attempt = 0;
        $maxAttempts = 1 + $this->retries;

        do {
            $attempt++;

            $response = $this->executeRequest($method, $fullUrl, $options);

            // Check if retry is needed
            if ($attempt < $maxAttempts && \in_array($response->status(), $this->retryOnStatus, true)) {
                \usleep($this->retryDelay * 1000);
                continue;
            }

            break;
        } while (true);

        if ($this->afterCallback !== null) {
            ($this->afterCallback)($response, $method, $fullUrl);
        }

        return $response;
    }

    // ═══════════════════════════════════════════════════════════════
    // State Accessors (for testing and introspection)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the configured base URL.
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Get the configured headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get the configured timeout.
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Get the configured connection timeout.
     */
    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * Get whether SSL verification is enabled.
     */
    public function getVerifySsl(): bool
    {
        return $this->verifySsl;
    }

    /**
     * Get the body format ('json', 'form', 'multipart').
     */
    public function getBodyFormat(): string
    {
        return $this->bodyFormat;
    }

    /**
     * Get the configured query parameters.
     *
     * @return array<string, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    /**
     * Get the basic auth credentials.
     *
     * @return array{username: string, password: string}|null
     */
    public function getBasicAuth(): ?array
    {
        return $this->basicAuth;
    }

    /**
     * Get the bearer token.
     */
    public function getBearerToken(): ?string
    {
        return $this->bearerToken;
    }

    /**
     * Get the number of retries.
     */
    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * Get the retry delay in milliseconds.
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Get the User-Agent string.
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    // ═══════════════════════════════════════════════════════════════
    // Internal
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build the full URL from base URL, path, and query parameters.
     *
     * @param string $url URL or path
     * @param array<string,mixed> $query Per-request query params
     */
    protected function buildUrl(string $url, array $query = []): string
    {
        // If URL is relative (no scheme), prepend base URL
        if (!\preg_match('#^https?://#i', $url)) {
            $url = $this->baseUrl . '/' . \ltrim($url, '/');
        }

        // Merge query parameters
        $allQuery = \array_merge($this->queryParams, $query);
        if (!empty($allQuery)) {
            $separator = \str_contains($url, '?') ? '&' : '?';
            $url .= $separator . \http_build_query($allQuery, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Execute a single HTTP request using cURL.
     *
     * @param string $method HTTP method
     * @param string $url Full URL
     * @param array<string,mixed> $options Request options
     */
    protected function executeRequest(string $method, string $url, array $options = []): HttpResponse
    {
        $ch = \curl_init();

        // URL & method
        \curl_setopt($ch, CURLOPT_URL, $url);
        \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, \strtoupper($method));
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Capture headers
        $responseHeaders = [];
        \curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $headerLine) use (&$responseHeaders) {
            $parts = \explode(':', $headerLine, 2);
            if (\count($parts) === 2) {
                $responseHeaders[\strtolower(\trim($parts[0]))] = \trim($parts[1]);
            }

            return \strlen($headerLine);
        });

        // Timeouts
        \curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        // SSL
        if (!$this->verifySsl) {
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // Follow redirects
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        // User-Agent
        if ($this->userAgent !== null) {
            \curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        }

        // Build headers
        $requestHeaders = $this->buildRequestHeaders($options);
        if (!empty($requestHeaders)) {
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
        }

        // Authentication
        if ($this->bearerToken !== null) {
            \curl_setopt($ch, CURLOPT_HTTPHEADER, \array_merge(
                $requestHeaders,
                ['Authorization: Bearer ' . $this->bearerToken],
            ));
        }

        if ($this->basicAuth !== null) {
            \curl_setopt($ch, CURLOPT_USERPWD, $this->basicAuth['username'] . ':' . $this->basicAuth['password']);
        }

        // Body
        if (isset($options['body']) && !empty($options['body'])) {
            $this->applyBody($ch, $options['body'], $requestHeaders);
        }

        // Custom cURL options
        foreach ($this->curlOptions as $opt => $val) {
            \curl_setopt($ch, $opt, $val);
        }

        // Before callback
        if ($this->beforeCallback !== null) {
            ($this->beforeCallback)($ch, $method, $url, $options);
        }

        // Execute
        $body = \curl_exec($ch);
        $statusCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false) {
            $error = \curl_error($ch);
            $errno = \curl_errno($ch);
            \curl_close($ch);

            // Return a synthetic error response
            return new HttpResponse(0, \json_encode([
                'error' => $error,
                'errno' => $errno,
            ]), []);
        }

        \curl_close($ch);

        return new HttpResponse($statusCode, $body, $responseHeaders);
    }

    /**
     * Build request headers array for cURL.
     *
     * @param array<string,mixed> $options Request options
     *
     * @return list<string> Headers in 'Name: Value' format
     */
    protected function buildRequestHeaders(array $options = []): array
    {
        $headers = [];

        // Merge per-request headers
        $allHeaders = $this->headers;
        if (isset($options['headers']) && \is_array($options['headers'])) {
            foreach ($options['headers'] as $k => $v) {
                $allHeaders[\strtolower($k)] = $v;
            }
        }

        // Set default Content-Type if not present
        if (!isset($allHeaders['content-type'])) {
            if ($this->bodyFormat === 'json') {
                $allHeaders['content-type'] = 'application/json';
            } elseif ($this->bodyFormat === 'form') {
                $allHeaders['content-type'] = 'application/x-www-form-urlencoded';
            }
        }

        // Set default Accept if not present
        if (!isset($allHeaders['accept'])) {
            $allHeaders['accept'] = 'application/json';
        }

        foreach ($allHeaders as $name => $value) {
            // Capitalize header names for readability
            $headerName = \implode('-', \array_map('ucfirst', \explode('-', $name)));
            $headers[] = $headerName . ': ' . $value;
        }

        return $headers;
    }

    /**
     * Apply the request body to the cURL handle.
     *
     * @param CurlHandle $ch cURL handle
     * @param array<string,mixed> $body Body data
     * @param list<string> $headers Current request headers (may be augmented)
     */
    protected function applyBody(CurlHandle $ch, array $body, array &$headers): void
    {
        if ($this->bodyFormat === 'json') {
            $encoded = \json_encode($body, JSON_THROW_ON_ERROR);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        } elseif ($this->bodyFormat === 'form') {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \http_build_query($body, '', '&'));
        } elseif ($this->bodyFormat === 'multipart') {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
}
