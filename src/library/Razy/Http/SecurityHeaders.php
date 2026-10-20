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

use Razy\Exception\ConfigurationException;

/**
 * The dist's security response headers and per-request CSP nonce (RZ-10).
 *
 * Configured by dist.php 'security_headers' and sent on every response,
 * error pages included:
 *
 * - absent or `true`: the baseline, which never breaks a page —
 *   `X-Content-Type-Options: nosniff`, `Referrer-Policy:
 *   strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN` and
 *   `Content-Security-Policy: frame-ancestors 'self'`;
 * - `false`: send nothing (opt out);
 * - an array with any of:
 *   - `csp`: string or `[directive => source(s)]`; `{nonce}` is replaced by
 *     this request's nonce (`script-src 'self' 'nonce-{nonce}'`), which
 *     templates read as `{$csp_nonce}`;
 *   - `csp_report_only`: send `Content-Security-Policy-Report-Only` instead;
 *   - `frame_ancestors`: sources for `frame-ancestors` (default `'self'`,
 *     `false` to leave it out) unless `csp` names it; `'self'` / `'none'`
 *     also set `X-Frame-Options` SAMEORIGIN / DENY for old browsers;
 *   - `referrer_policy`: string, or `false` to leave it out;
 *   - `hsts`: max-age in seconds, or `['max_age' => int,
 *     'include_subdomains' => bool, 'preload' => bool]`; sent over HTTPS
 *     only, off by default;
 *   - `headers`: `[name => value]` added last; a `false` value removes a header.
 *
 * Every name and value is checked at boot, so a typo or a CR/LF dies there.
 *
 * @license MIT
 */
final class SecurityHeaders
{
    private const KEYS = ['csp', 'csp_report_only', 'frame_ancestors', 'referrer_policy', 'hsts', 'headers'];

    /** @var self|null The dist serving this request; error pages send its headers */
    private static ?self $current = null;

    private ?string $nonce = null;

    /**
     * @param array<string, string> $headers Header name => value, `{nonce}` not yet substituted
     * @param string|null $hsts Strict-Transport-Security value, sent over HTTPS only
     * @param bool $enabled False when the dist opted out
     */
    private function __construct(
        private readonly array $headers,
        private readonly ?string $hsts,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Read dist.php 'security_headers'.
     *
     * @param array<string, mixed> $config The dist.php array
     * @param string $distCode The dist code, for the error message
     *
     * @return self
     *
     * @throws ConfigurationException On an unknown key or an invalid name/value
     */
    public static function fromConfig(array $config, string $distCode): self
    {
        $raw = $config['security_headers'] ?? true;
        if (false === $raw) {
            return new self([], null, false);
        }
        if (true === $raw) {
            $raw = [];
        }
        if (!\is_array($raw)) {
            throw self::invalid($distCode, 'must be true, false or an array', $raw);
        }
        foreach (\array_keys($raw) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw self::invalid($distCode, "has an unknown key '{$key}' (known: " . \implode(', ', self::KEYS) . ')');
            }
        }

        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $referrer = $raw['referrer_policy'] ?? 'strict-origin-when-cross-origin';
        if (false !== $referrer) {
            $headers['Referrer-Policy'] = self::value($referrer, 'referrer_policy', $distCode);
        }

        $directives = self::csp($raw['csp'] ?? [], $distCode);
        $ancestors = $directives['frame-ancestors'] ?? null;
        if (null === $ancestors) {
            $ancestors = $raw['frame_ancestors'] ?? "'self'";
            if (false !== $ancestors) {
                $ancestors = self::sources($ancestors, 'frame_ancestors', $distCode);
                $directives['frame-ancestors'] = $ancestors;
            }
        }
        if (\is_string($ancestors)) {
            $legacy = ["'self'" => 'SAMEORIGIN', "'none'" => 'DENY'][\strtolower($ancestors)] ?? null;
            if (null !== $legacy) {
                $headers['X-Frame-Options'] = $legacy;
            }
        }
        if ($directives) {
            $policy = \implode('; ', \array_map(
                static fn (string $name, string $sources): string => '' === $sources ? $name : $name . ' ' . $sources,
                \array_keys($directives),
                $directives,
            ));
            $reportOnly = $raw['csp_report_only'] ?? false;
            if (!\is_bool($reportOnly)) {
                throw self::invalid($distCode, "'csp_report_only' must be true or false", $reportOnly);
            }
            $headers[$reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy'] = $policy;
        }

        $extra = $raw['headers'] ?? [];
        if (!\is_array($extra)) {
            throw self::invalid($distCode, "'headers' must be an array of name => value", $extra);
        }
        foreach ($extra as $name => $value) {
            if (!\is_string($name) || !\preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw self::invalid($distCode, "'headers' has an invalid header name", $name);
            }
            // Header names are case-insensitive: 'x-frame-options' replaces the baseline's.
            foreach (\array_keys($headers) as $existing) {
                if (0 === \strcasecmp($existing, $name)) {
                    unset($headers[$existing]);
                }
            }
            if (false !== $value) {
                $headers[$name] = self::value($value, "headers.{$name}", $distCode);
            }
        }

        return new self($headers, self::hsts($raw['hsts'] ?? false, $distCode));
    }

    /**
     * The headers of the dist serving this request, or the baseline before a
     * dist has booted (an error page raised that early still gets them).
     *
     * @return self
     */
    public static function current(): self
    {
        return self::$current ??= self::fromConfig([], '');
    }

    /**
     * @param self|null $headers Null resets to the baseline
     */
    public static function setCurrent(?self $headers): void
    {
        self::$current = $headers;
    }

    /**
     * Send the current headers. Called by the error pages.
     */
    public static function sendCurrent(): void
    {
        self::current()->send();
    }

    /**
     * @return array<string, string> Directive name => sources
     */
    private static function csp(mixed $csp, string $distCode): array
    {
        if (\is_string($csp)) {
            $parsed = [];
            foreach (\explode(';', self::value($csp, 'csp', $distCode)) as $directive) {
                $directive = \trim($directive);
                if ('' === $directive) {
                    continue;
                }
                $parts = \preg_split('/\s+/', $directive, 2) ?: [$directive];
                $parsed[\strtolower($parts[0])] = $parts[1] ?? '';
            }

            return $parsed;
        }
        if (!\is_array($csp)) {
            throw self::invalid($distCode, "'csp' must be a string or an array of directive => sources", $csp);
        }
        $parsed = [];
        foreach ($csp as $name => $sources) {
            if (!\is_string($name) || !\preg_match('/^[a-z-]+$/', $name)) {
                throw self::invalid($distCode, "'csp' has an invalid directive name", $name);
            }
            $parsed[$name] = self::sources($sources, "csp.{$name}", $distCode);
        }

        return $parsed;
    }

    private static function sources(mixed $sources, string $key, string $distCode): string
    {
        if (\is_array($sources)) {
            foreach ($sources as $source) {
                if (!\is_string($source)) {
                    throw self::invalid($distCode, "'{$key}' sources must be strings", $source);
                }
            }
            $sources = \implode(' ', $sources);
        }
        $sources = self::value($sources, $key, $distCode);
        if (\str_contains($sources, ';')) {
            throw self::invalid($distCode, "'{$key}' must not contain ';'", $sources);
        }

        return $sources;
    }

    private static function hsts(mixed $hsts, string $distCode): ?string
    {
        if (false === $hsts) {
            return null;
        }
        $options = \is_int($hsts) ? ['max_age' => $hsts] : $hsts;
        if (!\is_array($options) || !\is_int($options['max_age'] ?? null) || $options['max_age'] < 0
            || \array_diff(\array_keys($options), ['max_age', 'include_subdomains', 'preload'])) {
            throw self::invalid($distCode, "'hsts' must be false, a max-age in seconds, or ['max_age' => int, 'include_subdomains' => bool, 'preload' => bool]", $hsts);
        }
        $value = 'max-age=' . $options['max_age'];
        if (true === ($options['include_subdomains'] ?? false)) {
            $value .= '; includeSubDomains';
        }
        if (true === ($options['preload'] ?? false)) {
            $value .= '; preload';
        }

        return $value;
    }

    private static function value(mixed $value, string $key, string $distCode): string
    {
        if (!\is_string($value) || '' === \trim($value) || \preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw self::invalid($distCode, "'{$key}' must be a non-empty single-line string", $value);
        }

        return \trim($value);
    }

    private static function invalid(string $distCode, string $problem, mixed $value = null): ConfigurationException
    {
        $got = \func_num_args() > 2
            ? ', got: ' . (\is_scalar($value) ? \var_export($value, true) : \get_debug_type($value))
            : '';

        return new ConfigurationException("dist.php 'security_headers' {$problem} for dist '{$distCode}'{$got}.");
    }

    /**
     * The headers to send, `{nonce}` substituted.
     *
     * @param bool $ssl Whether the request is HTTPS (HSTS is sent only then)
     *
     * @return array<string, string>
     */
    public function build(bool $ssl): array
    {
        if (!$this->enabled) {
            return [];
        }
        $headers = $this->headers;
        if ($ssl && null !== $this->hsts) {
            $headers['Strict-Transport-Security'] = $this->hsts;
        }
        foreach ($headers as $name => $value) {
            if (\str_contains($value, '{nonce}')) {
                $headers[$name] = \str_replace('{nonce}', $this->nonce(), $value);
            }
        }

        return $headers;
    }

    /**
     * Send the headers (replacing earlier ones of the same name).
     *
     * @param bool|null $ssl Null = ask the request ({@see \Razy\Util\NetworkUtil::isSsl()})
     * @param callable(string): void|null $emit Null = header(), skipped once headers are sent
     */
    public function send(?bool $ssl = null, ?callable $emit = null): void
    {
        if (!$this->enabled) {
            return;
        }
        if (null === $emit) {
            if (\headers_sent()) {
                return;
            }
            $emit = static function (string $line): void {
                \header($line, true);
            };
        }
        foreach ($this->build($ssl ?? \Razy\Util\NetworkUtil::isSsl()) as $name => $value) {
            $emit($name . ': ' . $value);
        }
    }

    /**
     * This request's CSP nonce (base64 of 16 random bytes), created on first use.
     *
     * @return string
     */
    public function nonce(): string
    {
        return $this->nonce ??= \base64_encode(\random_bytes(16));
    }

    /**
     * Start a new request: a fresh nonce (worker mode reuses this object).
     *
     * @return string The new nonce
     */
    public function rotateNonce(): string
    {
        $this->nonce = null;

        return $this->nonce();
    }

    /**
     * @return bool False when the dist opted out ('security_headers' => false)
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
