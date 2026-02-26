<?php

/**
 * This file is part of Razy v0.5.
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

/**
 * Server-Sent Events (SSE) stream handler.
 *
 * Manages the HTTP response headers, message formatting, heartbeat (comment)
 * sending, and upstream SSE proxy for the W3C Server-Sent Events protocol.
 *
 * @class SSE
 */
class SSE
{
    /** @var bool Whether the SSE stream headers have been sent */
    private bool $started = false;

    /** @var int Client reconnection interval in milliseconds */
    private int $retry;

    /**
     * SSE constructor.
     *
     * @param int $retry Client reconnection interval in milliseconds
     */
    public function __construct(int $retry = 3000)
    {
        $this->retry = $retry;
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

        // Send the retry interval so the client knows when to reconnect
        echo 'retry: ' . $this->retry . "\n\n";
        $this->flush();
        $this->started = true;
    }

    /**
     * Send an SSE message.
     *
     * @param string $data
     * @param string|null $event
     * @param string|null $id
     */
    public function send(string $data, ?string $event = null, ?string $id = null): void
    {
        // Lazily initialize the stream on first message
        if (!$this->started) {
            $this->start();
        }

        // Optional SSE fields: event ID for client-side last-event tracking
        if ($id !== null) {
            echo 'id: ' . $id . "\n";
        }
        // Named event type for addEventListener() on the client
        if ($event !== null) {
            echo 'event: ' . $event . "\n";
        }

        // SSE requires each data line to be prefixed with 'data: '
        $lines = \explode("\n", $data);
        foreach ($lines as $line) {
            echo 'data: ' . $line . "\n";
        }

        // Blank line signals end of this event block
        echo "\n";
        $this->flush();
    }

    /**
     * Send a comment (heartbeat).
     *
     * @param string $comment
     */
    public function comment(string $comment = ''): void
    {
        // Lazily initialize the stream on first comment
        if (!$this->started) {
            $this->start();
        }

        // SSE comments start with ':' and are ignored by EventSource but keep the connection alive
        $lines = \explode("\n", $comment);
        foreach ($lines as $line) {
            echo ': ' . $line . "\n";
        }

        echo "\n";
        $this->flush();
    }

    /**
     * Proxy an SSE-capable endpoint and stream it to the client.
     *
     * @param string $url
     * @param array $headers
     * @param string $method
     * @param string|null $body
     * @param int|null $timeout
     */
    public function proxy(string $url, array $headers = [], string $method = 'GET', ?string $body = null, ?int $timeout = null): void
    {
        // Lazily initialize the stream before proxying upstream data
        if (!$this->started) {
            $this->start();
        }

        // Configure cURL to stream response chunks directly to the client
        $ch = \curl_init($url);
        $method = \strtoupper(\trim($method));

        // Disable cURL internal buffering so chunks are forwarded immediately
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        if ($timeout !== null) {
            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        if ($headers) {
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method !== 'GET') {
            \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($body !== null) {
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Write callback forwards each received chunk to the PHP output stream
        \curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk): int {
            if ($chunk !== '') {
                echo $chunk;
                $this->flush();
            }

            // Return number of bytes handled (cURL requires this)
            return \strlen($chunk);
        });

        $ok = \curl_exec($ch);
        if ($ok === false) {
            $this->send('Proxy error: ' . \curl_error($ch), 'error');
        }

        \curl_close($ch);
    }

    /**
     * Close the SSE stream.
     */
    public function close(): void
    {
        $this->comment('closed');
    }

    /**
     * Flush all output buffers to push data to the client immediately.
     */
    private function flush(): void
    {
        // Drain all stacked output buffers to ensure data reaches the client
        if (\function_exists('ob_get_level')) {
            while (\ob_get_level() > 0) {
                \ob_end_flush();
            }
        }

        // Force the web server/PHP SAPI to send buffered output
        \flush();
    }
}
