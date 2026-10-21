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
 * RZ-15: the framework's one door to response headers.
 *
 * Normally every call goes straight to PHP (`header()`, `http_response_code()`,
 * `setcookie()`, `headers_sent()`). While a capture is active (the in-process
 * test client, `Razy\Testing\Client`) the status, headers and cookies are
 * recorded instead, because the CLI SAPI that runs tests keeps no headers.
 *
 * Code that calls `header()` directly still works; it is just invisible to the
 * test client. Framework code and modules that want their headers in tests use
 * this class (or `Razy\Http\Response`).
 *
 * @license MIT
 */
final class Headers
{
    /** @var list<array{0: string, 1: string}>|null Captured header lines (name, value); null = not capturing */
    private static ?array $captured = null;

    private static int $capturedStatus = 200;

    /**
     * Send a header line, like `header()`.
     *
     * A status line (`HTTP/1.1 404 …`) sets the status. `$replace` drops
     * earlier headers of the same name. A `Location` header turns a 200 into
     * a 302, as PHP does.
     *
     * @param string $header `Name: value` or a status line
     * @param bool $replace Replace earlier headers of the same name
     * @param int $responseCode Status to set as well (0 = leave it)
     */
    public static function send(string $header, bool $replace = true, int $responseCode = 0): void
    {
        if (self::$captured === null) {
            \header($header, $replace, $responseCode);

            return;
        }

        if (\preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $m) === 1) {
            self::$capturedStatus = (int) $m[1];

            return;
        }
        $parts = \explode(':', $header, 2);
        $name = \trim($parts[0]);
        $value = \trim($parts[1] ?? '');
        if ($replace) {
            self::removeCaptured($name);
        }
        self::$captured[] = [$name, $value];
        if ($responseCode > 0) {
            self::$capturedStatus = $responseCode;
        } elseif (\strcasecmp($name, 'Location') === 0 && self::$capturedStatus === 200) {
            self::$capturedStatus = 302;
        }
    }

    /**
     * Set the status (like `http_response_code($code)`), or read it with no argument.
     */
    public static function status(?int $code = null): int
    {
        if (self::$captured === null) {
            if ($code !== null) {
                \http_response_code($code);

                return $code;
            }
            $current = \http_response_code();

            return \is_int($current) ? $current : 200;
        }
        if ($code !== null) {
            self::$capturedStatus = $code;
        }

        return self::$capturedStatus;
    }

    /**
     * Remove a header by name, or every header when $name is null (like `header_remove()`).
     */
    public static function remove(?string $name = null): void
    {
        if (self::$captured === null) {
            $name === null ? \header_remove() : \header_remove($name);

            return;
        }
        if ($name === null) {
            self::$captured = [];

            return;
        }
        self::removeCaptured($name);
    }

    /**
     * Whether headers can no longer be sent (always false while capturing).
     */
    public static function sent(): bool
    {
        return self::$captured === null && \headers_sent();
    }

    /**
     * Set a cookie, like `setcookie($name, $value, $options)`.
     *
     * @param array{expires?: int, path?: string, domain?: string, secure?: bool, httponly?: bool, samesite?: string} $options
     */
    public static function cookie(string $name, string $value, array $options = []): void
    {
        if (self::$captured === null) {
            \setcookie($name, $value, $options);

            return;
        }
        $line = \rawurlencode($name) . '=' . \rawurlencode($value);
        if (($options['expires'] ?? 0) > 0) {
            $line .= '; expires=' . \gmdate('D, d M Y H:i:s \G\M\T', (int) $options['expires']);
        }
        foreach (['path' => 'Path', 'domain' => 'Domain', 'samesite' => 'SameSite'] as $key => $attr) {
            if (($options[$key] ?? '') !== '') {
                $line .= '; ' . $attr . '=' . $options[$key];
            }
        }
        if (!empty($options['secure'])) {
            $line .= '; Secure';
        }
        if (!empty($options['httponly'])) {
            $line .= '; HttpOnly';
        }
        self::send('Set-Cookie: ' . $line, false);
    }

    /**
     * Start recording instead of sending (status 200, no headers).
     */
    public static function startCapture(): void
    {
        self::$captured = [];
        self::$capturedStatus = 200;
    }

    /**
     * Stop recording and return what was recorded.
     *
     * @return array{status: int, headers: list<array{0: string, 1: string}>}
     */
    public static function stopCapture(): array
    {
        $result = ['status' => self::$capturedStatus, 'headers' => self::$captured ?? []];
        self::$captured = null;
        self::$capturedStatus = 200;

        return $result;
    }

    public static function isCapturing(): bool
    {
        return self::$captured !== null;
    }

    private static function removeCaptured(string $name): void
    {
        self::$captured = \array_values(\array_filter(
            self::$captured ?? [],
            static fn (array $h): bool => \strcasecmp($h[0], $name) !== 0,
        ));
    }
}
