<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Http;

/**
 * What the request resolvers learned about one request (RZ-12).
 *
 * Built fresh at the start of every request (worker mode included), so an
 * attribute never leaks into the next request. Resolvers read the host,
 * method and path, and record what they resolved (`set('tenant', …)`);
 * controllers read it back with `$this->requestContext()->get('tenant')`.
 */
final class RequestContext
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param Request $request Host, scheme, client IP (trusted proxies applied)
     * @param string $method Upper-case HTTP method ('GET' outside a web request)
     * @param string $path The dist-relative URL path being routed
     */
    public function __construct(
        public readonly Request $request,
        public readonly string $method,
        public readonly string $path,
    ) {
    }

    /**
     * Build from the live request.
     *
     * @param string $path The dist-relative URL path being routed
     */
    public static function fromGlobals(string $path): self
    {
        $method = \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        return new self(Request::fromGlobals(), '' === $method ? 'GET' : $method, $path);
    }

    /**
     * The request host (lowercase, no port; a trusted proxy's X-Forwarded-Host wins).
     */
    public function host(): string
    {
        return $this->request->host();
    }

    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->attributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->attributes;
    }
}
