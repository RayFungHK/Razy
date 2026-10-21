<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Server-Sent Events (SSE) helper for streaming real-time data
 * from a PHP backend to the browser via the EventSource API.
 *
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use InvalidArgumentException;
use Razy\SSE\StreamProxy;

/**
 * Server-Sent Events (SSE) stream handler.
 *
 * Manages the HTTP response headers, message formatting, heartbeats and
 * client-abort detection for the W3C Server-Sent Events protocol.
 *
 * RZ-16:
 * - `isAborted()` tells a streaming loop that the client went away (stop and
 *   release upstream resources); `send()`/`comment()` write nothing after that.
 * - `heartbeat()` / `heartbeatIfDue()` send a keep-alive comment every
 *   `$heartbeatInterval` seconds of silence.
 * - `lastEventId()` reads the browser's `Last-Event-ID` on reconnect;
 *   `send()` takes an `id` and `retry()` changes the reconnect delay.
 * - Event names and ids containing CR, LF or NUL are rejected; data is split on
 *   every line ending, so no value can inject a field.
 * - Relaying an upstream stream is {@see StreamProxy}; `proxy()` is deprecated.
 *
 * Output goes through an injectable writer and the abort check through an
 * injectable probe, so streams are testable without a real client.
 *
 * @class SSE
 */
class SSE
{
    /** Default seconds of silence before a heartbeat comment. */
    public const DEFAULT_HEARTBEAT_INTERVAL = 15;

    /** Longest Last-Event-ID accepted from a client. */
    private const MAX_EVENT_ID_LENGTH = 256;

    /** @var bool Whether the SSE stream headers have been sent */
    private bool $started = false;

    /** @var int Client reconnection interval in milliseconds */
    private int $retry;

    /** @var Closure(string): void */
    private Closure $writer;

    /** @var Closure(): bool */
    private Closure $abortProbe;

    /** @var Closure(): float */
    private Closure $clock;

    private bool $aborted = false;

    private float $lastWrite;

    /**
     * SSE constructor.
     *
     * @param int $retry Client reconnection interval in milliseconds
     * @param Closure(string): void|null $writer Receives every output frame (default: echo + flush)
     * @param Closure(): bool|null $abortProbe True when the client is gone (default: connection_aborted())
     * @param int $heartbeatInterval Seconds of silence before heartbeatIfDue() sends a comment (0 = every call)
     * @param Closure(): float|null $clock Seconds as float (default: microtime(true)); a test seam
     */
    public function __construct(
        int $retry = 3000,
        ?Closure $writer = null,
        ?Closure $abortProbe = null,
        private readonly int $heartbeatInterval = self::DEFAULT_HEARTBEAT_INTERVAL,
        ?Closure $clock = null,
    ) {
        $this->retry = $retry;
        $this->writer = $writer ?? function (string $frame): void {
            echo $frame;
            $this->flush();
        };
        $this->abortProbe = $abortProbe ?? static fn (): bool => \connection_aborted() === 1;
        $this->clock = $clock ?? static fn (): float => \microtime(true);
        $this->lastWrite = ($this->clock)();
    }

    /**
     * The `Last-Event-ID` the browser sent on reconnect, or null (absent or unsafe).
     *
     * @param array<string, mixed>|null $server $_SERVER (default) or a fixture
     */
    public static function lastEventIdFrom(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $id = $server['HTTP_LAST_EVENT_ID'] ?? null;
        if (!\is_string($id) || $id === '' || \strlen($id) > self::MAX_EVENT_ID_LENGTH || \strpbrk($id, "\r\n\0") !== false) {
            return null;
        }

        return $id;
    }

    /**
     * Build one event frame (ends with the blank line).
     *
     * @throws InvalidArgumentException when $event or $id contains CR, LF or NUL
     */
    public static function frame(string $data, ?string $event = null, ?string $id = null): string
    {
        $frame = '';
        if ($id !== null) {
            $frame .= 'id: ' . self::field('id', $id) . "\n";
        }
        if ($event !== null) {
            $frame .= 'event: ' . self::field('event', $event) . "\n";
        }
        foreach (self::lines($data) as $line) {
            $frame .= 'data: ' . $line . "\n";
        }

        return $frame . "\n";
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return \preg_split('/\r\n|\r|\n/', \str_replace("\0", '', $text)) ?: [''];
    }

    private static function field(string $name, string $value): string
    {
        if (\strpbrk($value, "\r\n\0") !== false) {
            throw new InvalidArgumentException("SSE {$name} must not contain CR, LF or NUL.");
        }

        return $value;
    }

    /**
     * This request's `Last-Event-ID` (resume point), or null.
     */
    public function lastEventId(): ?string
    {
        return self::lastEventIdFrom();
    }

    /**
     * Initialize the SSE connection headers.
     */
    public function start(): void
    {
        // Set SSE-specific HTTP headers if not already sent
        if (!\headers_sent()) {
            \header('Content-Type: text/event-stream');
            \header('Cache-Control: no-cache');
            \header('Connection: keep-alive');
            \header('X-Accel-Buffering: no');  // Disable Nginx proxy buffering
        }

        $this->started = true;
        // Send the retry interval so the client knows when to reconnect
        $this->write('retry: ' . $this->retry . "\n\n");
    }

    /**
     * Change the client's reconnect delay (sends a `retry:` field).
     *
     * @param int $milliseconds Reconnect delay, >= 0
     */
    public function retry(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('SSE retry must be >= 0.');
        }
        $this->ensureStarted();
        $this->retry = $milliseconds;
        $this->write('retry: ' . $milliseconds . "\n\n");
    }

    /**
     * Send an SSE message.
     *
     * @param string $data Payload; multi-line data becomes several `data:` lines
     * @param string|null $event Event name (no CR/LF/NUL)
     * @param string|null $id Event id the browser echoes as Last-Event-ID (no CR/LF/NUL)
     *
     * @throws InvalidArgumentException when $event or $id contains CR, LF or NUL
     */
    public function send(string $data, ?string $event = null, ?string $id = null): void
    {
        $frame = self::frame($data, $event, $id);
        $this->ensureStarted();
        $this->write($frame);
    }

    /**
     * Send a comment (heartbeat).
     *
     * @param string $comment
     */
    public function comment(string $comment = ''): void
    {
        $this->ensureStarted();
        $frame = '';
        foreach (self::lines($comment) as $line) {
            $frame .= ': ' . $line . "\n";
        }
        $this->write($frame . "\n");
    }

    /**
     * Send a keep-alive comment now.
     */
    public function heartbeat(): void
    {
        $this->comment('ping');
    }

    /**
     * Send a heartbeat when nothing was written for the heartbeat interval.
     *
     * @return bool Whether a heartbeat was sent
     */
    public function heartbeatIfDue(): bool
    {
        if ($this->isAborted() || ($this->clock)() - $this->lastWrite < $this->heartbeatInterval) {
            return false;
        }
        $this->heartbeat();

        return true;
    }

    /**
     * Whether the client went away. Once true it stays true, and nothing more is written.
     *
     * @phpstan-impure
     */
    public function isAborted(): bool
    {
        if (!$this->aborted && ($this->abortProbe)()) {
            $this->aborted = true;
        }

        return $this->aborted;
    }

    /**
     * Write raw, already-framed bytes (used by {@see StreamProxy}).
     *
     * @return bool False when the client is gone and nothing was written
     */
    public function writeRaw(string $bytes): bool
    {
        $this->ensureStarted();

        return $this->write($bytes);
    }

    /**
     * Proxy an SSE-capable endpoint and stream it to the client.
     *
     * @deprecated since RZ-16; use {@see StreamProxy} (abort cancel, heartbeat,
     *             Last-Event-ID, no upstream error text sent to the client).
     *             This method now delegates to it.
     *
     * @param string $url
     * @param array $headers
     * @param string $method
     * @param string|null $body
     * @param int|null $timeout
     */
    public function proxy(string $url, array $headers = [], string $method = 'GET', ?string $body = null, ?int $timeout = null): void
    {
        (new StreamProxy($this))->relay($url, $headers, $method, $body, $timeout);
    }

    /**
     * Close the SSE stream.
     */
    public function close(): void
    {
        $this->comment('closed');
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    private function write(string $frame): bool
    {
        if ($this->isAborted()) {
            return false;
        }
        ($this->writer)($frame);
        $this->lastWrite = ($this->clock)();

        return true;
    }

    /**
     * Flush all output buffers to push data to the client immediately.
     */
    private function flush(): void
    {
        // Drain all stacked output buffers to ensure data reaches the client
        while (\ob_get_level() > 0) {
            \ob_end_flush();
        }

        // Force the web server/PHP SAPI to send buffered output
        \flush();
    }
}
