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
 * Proxy-aware facts about the current HTTP request (RZ-04).
 *
 * X-Forwarded-Proto / -Host / -Port / -For are read only when the connected
 * peer (REMOTE_ADDR) is a trusted proxy (see TrustedProxies); otherwise the
 * values come from the server variables alone, so a client cannot spoof its
 * scheme, host or address.
 *
 * Build per request (`Request::fromGlobals()`), never cache across requests:
 * in worker mode $_SERVER changes on every request.
 *
 * @license MIT
 */
final class Request
{
    /** @var TrustedProxies The proxies whose forwarded headers are believed */
    private readonly TrustedProxies $proxies;

    /**
     * @param array<string, mixed> $server Server variables ($_SERVER shape)
     * @param TrustedProxies|null $proxies Defaults to the process-wide set
     */
    public function __construct(private readonly array $server, ?TrustedProxies $proxies = null)
    {
        $this->proxies = $proxies ?? TrustedProxies::current();
    }

    /**
     * Build from the live $_SERVER and the configured trusted proxies.
     *
     * @return self
     */
    public static function fromGlobals(): self
    {
        return new self($_SERVER);
    }

    /**
     * Normalise a canonical origin (`scheme://host[:port]`): lowercase scheme
     * and host, default port dropped, a single trailing slash tolerated.
     * Returns null for anything that is not a bare http(s) origin.
     *
     * @param string $origin
     *
     * @return string|null
     */
    public static function normalizeOrigin(string $origin): ?string
    {
        $parts = \parse_url(\trim($origin));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = \strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && '' !== $parts['path'] && '/' !== $parts['path']) {
            return null;
        }
        $host = \strtolower($parts['host']);
        if (!self::isValidHost($host)) {
            return null;
        }

        return self::buildOrigin($scheme, $host, $parts['port'] ?? null);
    }

    /**
     * Split "host:port" / "[v6]:port" into a lowercase host and an optional port.
     *
     * @param string $value
     *
     * @return array{0: string, 1: int|null}
     */
    private static function splitHostPort(string $value): array
    {
        $value = \strtolower(\trim($value));
        if (\str_starts_with($value, '[')) {
            $end = \strpos($value, ']');
            if (false === $end) {
                return [$value, null];
            }
            $host = \substr($value, 1, $end - 1);
            $rest = \substr($value, $end + 1);
            $port = (1 === \preg_match('/^:(\d{1,5})$/', $rest, $m)) ? (int) $m[1] : null;

            return [$host, $port];
        }
        if (1 === \substr_count($value, ':')) {
            [$host, $port] = \explode(':', $value, 2);

            return [$host, (1 === \preg_match('/^\d{1,5}$/', $port)) ? (int) $port : null];
        }

        return [$value, null];
    }

    /**
     * Whether the value is a plausible host: DNS name or IP literal.
     *
     * @param string $host
     *
     * @return bool
     */
    private static function isValidHost(string $host): bool
    {
        if ('' === $host) {
            return false;
        }
        if (false !== \filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return 1 === \preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/', $host);
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param int|null $port
     *
     * @return string
     */
    private static function buildOrigin(string $scheme, string $host, ?int $port): string
    {
        if (\str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $default = 'https' === $scheme ? 443 : 80;

        return $scheme . '://' . $host . ((null !== $port && $port !== $default) ? ':' . $port : '');
    }

    /**
     * Whether the connected peer is a trusted proxy.
     *
     * @return bool
     */
    public function isFromTrustedProxy(): bool
    {
        return $this->proxies->isTrusted($this->remoteAddr());
    }

    /**
     * `https` or `http`.
     *
     * @return string
     */
    public function scheme(): string
    {
        if ($this->isFromTrustedProxy()) {
            $proto = \strtolower(\trim(\explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ('https' === $proto || 'http' === $proto) {
                return $proto;
            }
        }

        $https = (string) ($this->server['HTTPS'] ?? '');
        if (('' !== $https && 'off' !== \strtolower($https))
            || 'https' === \strtolower((string) ($this->server['REQUEST_SCHEME'] ?? ''))
            || 443 === (int) ($this->server['SERVER_PORT'] ?? 0)) {
            return 'https';
        }

        return 'http';
    }

    /**
     * Whether the request reached the edge over TLS.
     *
     * @return bool
     */
    public function isSecure(): bool
    {
        return 'https' === $this->scheme();
    }

    /**
     * The host name (lowercase, no port). A trusted proxy's X-Forwarded-Host
     * wins; otherwise SERVER_NAME, then the Host header. Invalid values are skipped.
     *
     * @return string
     */
    public function host(): string
    {
        foreach ($this->hostCandidates() as $candidate) {
            [$host] = self::splitHostPort($candidate);
            if (self::isValidHost($host)) {
                return $host;
            }
        }

        return '';
    }

    /**
     * The public port: X-Forwarded-Port (trusted), the port in X-Forwarded-Host
     * (trusted), the scheme default behind a trusted proxy, or SERVER_PORT.
     *
     * @return int
     */
    public function port(): int
    {
        if ($this->isFromTrustedProxy()) {
            $forwardedPort = \trim(\explode(',', (string) ($this->server['HTTP_X_FORWARDED_PORT'] ?? ''))[0]);
            if (1 === \preg_match('/^\d{1,5}$/', $forwardedPort) && (int) $forwardedPort <= 65535) {
                return (int) $forwardedPort;
            }
            $forwardedHost = $this->forwardedHost();
            if (null !== $forwardedHost) {
                [, $port] = self::splitHostPort($forwardedHost);

                return $port ?? ('https' === $this->scheme() ? 443 : 80);
            }
        }

        $port = (int) ($this->server['SERVER_PORT'] ?? 0);

        return $port > 0 ? $port : ('https' === $this->scheme() ? 443 : 80);
    }

    /**
     * The client address: REMOTE_ADDR, or the first untrusted hop of
     * X-Forwarded-For when the peer is a trusted proxy.
     *
     * @return string
     */
    public function clientIp(): string
    {
        $remote = $this->remoteAddr();
        if ('' === $remote) {
            return 'UNKNOWN';
        }
        $forwardedFor = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;

        return $this->proxies->resolveClientIp($remote, \is_string($forwardedFor) ? $forwardedFor : null);
    }

    /**
     * `scheme://host[:port]`, the port omitted when it is the scheme default.
     *
     * @return string
     */
    public function origin(): string
    {
        return self::buildOrigin($this->scheme(), $this->host(), $this->port());
    }

    /**
     * @return string The connected peer address, '' when unknown
     */
    private function remoteAddr(): string
    {
        return \trim((string) ($this->server['REMOTE_ADDR'] ?? ''));
    }

    /**
     * The first X-Forwarded-Host value when the peer is trusted.
     *
     * @return string|null
     */
    private function forwardedHost(): ?string
    {
        if (!$this->isFromTrustedProxy()) {
            return null;
        }
        $value = \trim(\explode(',', (string) ($this->server['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
        if ('' === $value) {
            return null;
        }
        [$host] = self::splitHostPort($value);

        return self::isValidHost($host) ? $value : null;
    }

    /**
     * Host header values to try, most trusted first.
     *
     * @return array<int, string>
     */
    private function hostCandidates(): array
    {
        $candidates = [];
        $forwarded = $this->forwardedHost();
        if (null !== $forwarded) {
            $candidates[] = $forwarded;
        }
        foreach (['SERVER_NAME', 'HTTP_HOST'] as $key) {
            if (isset($this->server[$key]) && \is_string($this->server[$key]) && '' !== $this->server[$key]) {
                $candidates[] = $this->server[$key];
            }
        }

        return $candidates;
    }
}
