<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\SSE;

use CurlHandle;
use Razy\Error\ErrorReporter;
use Razy\SSE;

/**
 * RZ-16: relay an upstream SSE (or other chunked) HTTP stream to the client.
 *
 * - **Abort cancel**: when the client goes away ({@see SSE::isAborted()}) the
 *   transfer is cancelled at once (the write/progress callbacks tell cURL to
 *   stop), so the upstream connection closes instead of streaming into the void.
 * - **Heartbeat**: while the upstream is silent, the client gets a keep-alive
 *   comment every heartbeat interval of the {@see SSE} stream.
 * - **Resume**: the client's `Last-Event-ID` is forwarded upstream unless the
 *   caller sets that header.
 * - **No leaks**: transport errors and upstream 4xx/5xx are logged through
 *   `ErrorReporter`; the client only gets `event: error` / `data: upstream unavailable`.
 *
 * Upstream bytes are relayed verbatim (the upstream is trusted to speak SSE).
 * Only http(s) is allowed, redirects are capped, and the connect timeout is
 * bounded. `relay()` returns one of the RESULT_* constants.
 *
 * @license MIT
 */
final class StreamProxy
{
    public const RESULT_COMPLETED = 'completed';

    public const RESULT_ABORTED = 'aborted';

    public const RESULT_FAILED = 'failed';

    /** Generic text sent to the client when the upstream fails. */
    public const ERROR_MESSAGE = 'upstream unavailable';

    private int $status = 0;

    private bool $upstreamError = false;

    private bool $cancelled = false;

    /**
     * @param SSE $sse The client stream
     * @param int $connectTimeout Seconds to connect upstream (0 = cURL default)
     * @param int $maxRedirects Redirects followed (0 = none)
     */
    public function __construct(
        private readonly SSE $sse,
        private readonly int $connectTimeout = 10,
        private readonly int $maxRedirects = 3,
    ) {
    }

    /**
     * Relay $url to the client until the upstream ends, fails, or the client leaves.
     *
     * @param string $url http(s) URL of the upstream stream
     * @param list<string> $headers Request headers as `Name: value` lines
     * @param string $method HTTP method
     * @param string|null $body Request body
     * @param int|null $timeout Total transfer limit in seconds (null = none)
     *
     * @return string One of the RESULT_* constants
     */
    public function relay(string $url, array $headers = [], string $method = 'GET', ?string $body = null, ?int $timeout = null): string
    {
        $this->status = 0;
        $this->upstreamError = false;
        $this->cancelled = false;

        if ($this->sse->isAborted()) {
            return self::RESULT_ABORTED;
        }
        $this->sse->writeRaw(''); // headers + retry before the first upstream byte

        $previousIgnore = \ignore_user_abort(true); // detect the abort ourselves, then clean up
        try {
            $ch = $this->handle($url, $this->withLastEventId($headers), $method, $body, $timeout);
            $ok = \curl_exec($ch);
            $errno = \curl_errno($ch);
            $error = \curl_error($ch);
            // An error status with an empty body never reaches onChunk(): check it here too.
            $code = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 400) {
                $this->status = $code;
                $this->upstreamError = true;
            }
        } finally {
            \ignore_user_abort((bool) $previousIgnore);
        }

        // The cURL callbacks set these flags during curl_exec().
        if ($this->flag('cancelled') || $this->sse->isAborted()) {
            return self::RESULT_ABORTED;
        }
        $upstreamError = $this->flag('upstreamError');
        if ($ok === false || $errno !== 0 || $upstreamError) {
            $detail = $upstreamError ? 'HTTP ' . $this->status : "cURL error {$errno}: {$error}";
            ErrorReporter::warn('SSE stream proxy: upstream ' . $this->describe($url) . ' failed: ' . $detail);
            $this->sse->send(self::ERROR_MESSAGE, 'error');

            return self::RESULT_FAILED;
        }

        return self::RESULT_COMPLETED;
    }

    /** Read a flag the cURL callbacks may have set (opaque to static analysis on purpose). */
    private function flag(string $name): bool
    {
        return match ($name) {
            'cancelled' => $this->cancelled,
            default => $this->upstreamError,
        };
    }

    /**
     * @param list<string> $headers
     */
    private function handle(string $url, array $headers, string $method, ?string $body, ?int $timeout): CurlHandle
    {
        $ch = \curl_init($url);
        $method = \strtoupper(\trim($method));

        \curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_FOLLOWLOCATION => $this->maxRedirects > 0,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => $this->onHeader(...),
            CURLOPT_WRITEFUNCTION => $this->onChunk(...),
            // The progress callback runs about once a second even while the
            // upstream is silent: the place for heartbeats and abort checks.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => $this->onProgress(...),
        ]);
        if ($timeout !== null) {
            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }
        if ($headers !== []) {
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if ($method !== 'GET') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return $ch;
    }

    private function onHeader(CurlHandle $ch, string $line): int
    {
        if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
            $this->status = (int) $m[1]; // the last status line wins (redirects)
        }

        return \strlen($line);
    }

    private function onChunk(CurlHandle $ch, string $chunk): int
    {
        if ($this->status >= 400) {
            $this->upstreamError = true; // never relay an upstream error body

            return \strlen($chunk);
        }
        if ($chunk !== '' && !$this->sse->writeRaw($chunk)) {
            $this->cancelled = true;

            return 0; // client gone: cURL aborts the transfer
        }

        return \strlen($chunk);
    }

    private function onProgress(CurlHandle $ch, int $downloadTotal, int $downloaded, int $uploadTotal, int $uploaded): int
    {
        if ($this->sse->isAborted()) {
            $this->cancelled = true;

            return 1; // non-zero aborts the transfer
        }
        // Also before the upstream's headers arrive: a slow first byte is idle time too.
        if ($this->status < 400) {
            $this->sse->heartbeatIfDue();
        }

        return 0;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private function withLastEventId(array $headers): array
    {
        foreach ($headers as $header) {
            if (\stripos($header, 'last-event-id:') === 0) {
                return $headers;
            }
        }
        $id = $this->sse->lastEventId();
        if ($id !== null) {
            $headers[] = 'Last-Event-ID: ' . $id;
        }

        return $headers;
    }

    /** Scheme, host and path only: never log query-string credentials. */
    private function describe(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts)) {
            return '(invalid url)';
        }

        return ($parts['scheme'] ?? '?') . '://' . ($parts['host'] ?? '?')
            . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
    }
}
