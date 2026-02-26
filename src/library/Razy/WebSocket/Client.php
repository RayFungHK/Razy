<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * WebSocket client that connects to a remote RFC 6455 server,
 * performs the opening handshake, and provides send/receive methods.
 *
 *
 * @license MIT
 */

namespace Razy\WebSocket;

use Closure;
use RuntimeException;
use Throwable;

/**
 * WebSocket client for connecting to a remote WebSocket server.
 *
 * Usage:
 * ```php
 * $client = Client::connect('ws://localhost:8080/chat');
 * $client->sendText('Hello');
 * $frame = $client->receive();
 * echo $frame->getPayload();
 * $client->close();
 * ```
 *
 * Supports both `ws://` and `wss://` (TLS) schemes.
 *
 * @class Client
 */
class Client
{
    /** @var Connection The underlying connection wrapper */
    private Connection $connection;

    /** @var string Raw host for the Host header */
    private string $host;

    /** @var string The request path */
    private string $path;

    /**
     * Use Client::connect() factory instead.
     *
     * @param Connection $connection
     * @param string $host
     * @param string $path
     */
    private function __construct(Connection $connection, string $host, string $path)
    {
        $this->connection = $connection;
        $this->host = $host;
        $this->path = $path;
    }

    // ── Factory ──────────────────────────────────────────────────

    /**
     * Connect to a WebSocket server and perform the opening handshake.
     *
     * @param string $url Full URL (ws://host:port/path or wss://...)
     * @param array<string,string> $headers Extra HTTP headers for the handshake
     * @param int $timeout Connection timeout in seconds
     *
     * @return static
     *
     * @throws RuntimeException on connection or handshake failure
     */
    public static function connect(string $url, array $headers = [], int $timeout = 5): static
    {
        $parsed = self::parseUrl($url);

        $scheme = $parsed['scheme'];
        $host = $parsed['host'];
        $port = $parsed['port'];
        $path = $parsed['path'];
        $address = ($scheme === 'wss' ? 'tls' : 'tcp') . "://{$host}:{$port}";

        $context = \stream_context_create();
        $stream = @\stream_socket_client(
            $address,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (!$stream) {
            throw new RuntimeException("Failed to connect to {$address}: [{$errno}] {$errstr}");
        }

        \stream_set_blocking($stream, true);
        \stream_set_timeout($stream, $timeout);

        // Client connections MUST mask outgoing frames (RFC 6455 §5.3)
        $connection = new Connection($stream, maskOutput: true);
        $client = new static($connection, $host . ':' . $port, $path);

        $client->performHandshake($headers);

        // Switch to non-blocking for normal operation
        \stream_set_blocking($stream, false);

        return $client;
    }

    /**
     * Parse a ws:// or wss:// URL into components.
     *
     * @return array{scheme: string, host: string, port: int, path: string}
     *
     * @throws RuntimeException on invalid URL
     */
    private static function parseUrl(string $url): array
    {
        $parsed = \parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            throw new RuntimeException('Invalid WebSocket URL: ' . $url);
        }

        $scheme = \strtolower($parsed['scheme'] ?? 'ws');
        if (!\in_array($scheme, ['ws', 'wss'], true)) {
            throw new RuntimeException("Unsupported scheme: {$scheme} (use ws:// or wss://)");
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = ($parsed['path'] ?? '/');

        if (isset($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        return \compact('scheme', 'host', 'port', 'path');
    }

    // ── Messaging ────────────────────────────────────────────────

    /**
     * Send a UTF-8 text message.
     */
    public function sendText(string $text): void
    {
        $this->connection->sendText($text);
    }

    /**
     * Send a binary message.
     */
    public function sendBinary(string $data): void
    {
        $this->connection->sendBinary($data);
    }

    /**
     * Send a ping frame.
     */
    public function sendPing(string $payload = ''): void
    {
        $this->connection->sendPing($payload);
    }

    /**
     * Receive the next data frame from the server (blocking or non-blocking
     * depending on the stream mode).
     *
     * @return Frame|null
     */
    public function receive(): ?Frame
    {
        return $this->connection->readFrame();
    }

    /**
     * Block until a frame is received or the timeout expires.
     *
     * @param int $timeoutSeconds Maximum wait time
     *
     * @return Frame|null
     */
    public function receiveBlocking(int $timeoutSeconds = 30): ?Frame
    {
        $stream = $this->connection->getStream();
        if (!\is_resource($stream)) {
            return null;
        }

        // Temporarily switch to blocking with a timeout
        \stream_set_blocking($stream, true);
        \stream_set_timeout($stream, $timeoutSeconds);

        $frame = $this->connection->readFrame();

        \stream_set_blocking($stream, false);

        return $frame;
    }

    /**
     * Send a close frame and disconnect.
     *
     * @param int $code Status code (default: 1000 normal closure)
     * @param string $reason Reason string
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        try {
            $this->connection->sendClose($code, $reason);

            // Try to read the server's close response
            $stream = $this->connection->getStream();
            if (\is_resource($stream)) {
                \stream_set_blocking($stream, true);
                \stream_set_timeout($stream, 2);
                $this->connection->readFrame();
            }
        } catch (Throwable) {
            // Best-effort close
        } finally {
            $this->connection->disconnect();
        }
    }

    /**
     * Whether the connection is still open.
     */
    public function isOpen(): bool
    {
        return $this->connection->isOpen();
    }

    /**
     * Get the underlying Connection object.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    // ── Run loop convenience ─────────────────────────────────────

    /**
     * Run a receive loop, calling $onMessage for each data frame.
     * Stops when the connection closes or $onMessage returns false.
     *
     * @param Closure $onMessage Called for each text/binary frame
     * @param int $pollMs Polling interval in milliseconds
     */
    public function listen(Closure $onMessage, int $pollMs = 50): void
    {
        $stream = $this->connection->getStream();

        while ($this->connection->isOpen()) {
            $read = [$stream];
            $write = null;
            $except = null;
            $changed = @\stream_select($read, $write, $except, 0, $pollMs * 1000);

            if ($changed === false) {
                break;
            }

            if ($changed > 0) {
                $frames = $this->connection->readFrames();
                foreach ($frames as $frame) {
                    if ($frame->isClose()) {
                        $this->connection->disconnect();

                        return;
                    }
                    if ($frame->isText() || $frame->isBinary()) {
                        $result = $onMessage($frame);
                        if ($result === false) {
                            $this->close();

                            return;
                        }
                    }
                }
            }

            // Check for EOF
            if (\feof($stream)) {
                break;
            }
        }
    }

    // ── Internal ─────────────────────────────────────────────────

    /**
     * Send the client opening handshake and validate the server response.
     *
     * @param array<string,string> $extraHeaders
     *
     * @throws RuntimeException on handshake failure
     */
    private function performHandshake(array $extraHeaders): void
    {
        $key = \base64_encode(\random_bytes(16));

        $headers = [
            "GET {$this->path} HTTP/1.1",
            "Host: {$this->host}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            "Sec-WebSocket-Key: {$key}",
            'Sec-WebSocket-Version: 13',
        ];

        foreach ($extraHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $headers[] = '';
        $headers[] = '';

        $request = \implode("\r\n", $headers);
        \fwrite($this->connection->getStream(), $request);

        // Expected accept value
        $expectedAccept = \base64_encode(
            \sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC175AB2', true),
        );

        // Wait for handshake response (blocking)
        $deadline = \microtime(true) + 5;
        while (!$this->connection->isHandshakeCompleted()) {
            if (\microtime(true) > $deadline) {
                throw new RuntimeException('WebSocket handshake timed out');
            }
            $this->connection->completeClientHandshake($expectedAccept);
            \usleep(10000);
        }
    }
}
