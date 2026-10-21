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

use Razy\Log\LogContext;

/**
 * RZ-14: one id per request, for correlating log lines, error reports and
 * upstream calls.
 *
 * {@see begin()} runs at the start of every web request (worker mode
 * included). It takes the incoming `X-Request-ID` when the accept policy
 * allows it and the value is safe to log, otherwise it generates one. The id
 * is echoed in the `X-Request-ID` response header, added to every log record
 * through {@see LogContext}, and forwarded by `Razy\Http\HttpClient`.
 *
 * Accept policy (`RAZY_REQUEST_ID_ACCEPT` env, else config.inc.php
 * `request_id_accept`):
 *  - `any` (default): take a valid incoming id from any client
 *  - `trusted_proxy`: only when the request came through a trusted proxy (RZ-04)
 *  - `none`: always generate
 *
 * @license MIT
 */
final class RequestId
{
    /** Request and response header that carries the id. */
    public const HEADER = 'X-Request-ID';

    public const ACCEPT_ANY = 'any';

    public const ACCEPT_TRUSTED_PROXY = 'trusted_proxy';

    public const ACCEPT_NONE = 'none';

    /** Ids are 1-128 chars of [A-Za-z0-9._:-]: no spaces, quotes or CR/LF (log and header safe). */
    private const VALID = '/^[A-Za-z0-9._:\-]{1,128}$/';

    private static ?string $current = null;

    /**
     * Start a request: pick the id, record it in the log context, and send the
     * response header (web mode, before output).
     *
     * @param array<string, mixed>|null $server $_SERVER (default) or a test fixture
     * @param string $accept One of the ACCEPT_* policies
     *
     * @return string The request id
     */
    public static function begin(?array $server = null, string $accept = self::ACCEPT_ANY): string
    {
        $server ??= $_SERVER;
        $incoming = $server['HTTP_X_REQUEST_ID'] ?? null;
        $id = null;
        if (\is_string($incoming) && self::isValid($incoming) && self::acceptsIncoming($server, $accept)) {
            $id = $incoming;
        }
        self::$current = $id ?? self::generate();

        LogContext::reset();
        LogContext::set('request_id', self::$current);

        if (\defined('WEB_MODE') && WEB_MODE && !\headers_sent()) {
            \header(self::HEADER . ': ' . self::$current);
        }

        return self::$current;
    }

    /**
     * The current request's id, or null outside a request (CLI, before begin()).
     */
    public static function current(): ?string
    {
        return self::$current;
    }

    /**
     * A new random id: 32 lowercase hex characters.
     */
    public static function generate(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * Whether $id is safe to accept, log and echo in a header.
     */
    public static function isValid(string $id): bool
    {
        return \preg_match(self::VALID, $id) === 1;
    }

    /**
     * Resolve the accept policy: env `RAZY_REQUEST_ID_ACCEPT` wins over config
     * `request_id_accept`; unknown values fall back to `any`.
     *
     * @param array<string, mixed> $config config.inc.php values
     */
    public static function policyFromConfig(array $config): string
    {
        $env = $_ENV['RAZY_REQUEST_ID_ACCEPT'] ?? \getenv('RAZY_REQUEST_ID_ACCEPT');
        $value = (\is_string($env) && $env !== '') ? $env : ($config['request_id_accept'] ?? self::ACCEPT_ANY);
        $value = \strtolower(\trim((string) $value));

        return \in_array($value, [self::ACCEPT_ANY, self::ACCEPT_TRUSTED_PROXY, self::ACCEPT_NONE], true)
            ? $value
            : self::ACCEPT_ANY;
    }

    /**
     * Forget the current id (worker cleanup, tests).
     */
    public static function reset(): void
    {
        self::$current = null;
        LogContext::reset();
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function acceptsIncoming(array $server, string $accept): bool
    {
        return match ($accept) {
            self::ACCEPT_NONE => false,
            self::ACCEPT_TRUSTED_PROXY => TrustedProxies::current()->isTrusted((string) ($server['REMOTE_ADDR'] ?? '')),
            default => true,
        };
    }
}
