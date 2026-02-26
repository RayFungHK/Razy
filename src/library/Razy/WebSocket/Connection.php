<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Represents a single WebSocket connection wrapping a stream resource.
 * Handles the RFC 6455 handshake, frame-level read/write, ping/pong,
 * and graceful close.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\WebSocket;

use RuntimeException;

/**
 * A bidirectional WebSocket connection over a PHP stream.
 *
 * Wraps a raw socket resource and provides high-level methods for
 * sending/receiving text, binary, ping, pong, and close frames.
 *
 * The connection can be used on both the server side (accepted socket)
 * and the client side (after the opening handshake).
 *
 * @class Connection
 *
 * @package Razy\WebSocket
 */
class Connection
{
    /** @var string Accumulated unprocessed bytes from the network */
    private string $buffer = '';

    /** @var bool Whether this connection has completed its opening handshake */
    private bool $handshakeCompleted = false;

    /** @var bool Whether a close frame has been sent */
    private bool $closeSent = false;

    /** @var bool Whether a close frame has been received */
    private bool $closeReceived = false;

    /** @var string|null Request URI from the client handshake */
    private ?string $requestUri = null;

    /** @var array<string, string> HTTP headers from the opening handshake */
    private array $headers = [];

    /** @var mixed Custom user data attached to this connection */
    private mixed $userData = null;

    /** @var string Unique connection identifier */
    private string $id;

    /**
     * @param resource $stream The underlying socket stream
     * @param bool $maskOutput Whether outgoing frames should be masked
     *                         (true for client connections per RFC 6455 §5.3)
     */
    public function __construct(
        private mixed $stream,
        private bool  $maskOutput = false,
    ) {
        $this->id = \spl_object_hash($this);
    }

    // ── Identity ─────────────────────────────────────────────────

    /**
     * Unique connection ID (spl_object_hash of this instance).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the underlying stream resource.
     *
     * @return resource
     */
    public function getStream(): mixed
    {
        return $this->stream;
    }

    /**
     * Whether the opening handshake has been completed.
     */
    public function isHandshakeCompleted(): bool
    {
        return $this->handshakeCompleted;
    }

    /**
     * Whether the connection is still open.
     */
    public function isOpen(): bool
    {
        return \is_resource($this->stream) && !\feof($this->stream) && !$this->closeSent;
    }

    // ── User data ────────────────────────────────────────────────

    public function setUserData(mixed $data): void
    {
        $this->userData = $data;
    }

    public function getUserData(): mixed
    {
        return $this->userData;
    }

    // ── Request metadata ─────────────────────────────────────────

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[\strtolower($name)] ?? null;
    }

    // ── Server-side handshake ────────────────────────────────────

    /**
     * Attempt to complete the server-side opening handshake.
     *
     * Reads from the buffer looking for a complete HTTP Upgrade request.
     * If found, sends the 101 Switching Protocols response.
     *
     * @return bool true if handshake was completed on this call
     *
     * @throws RuntimeException on protocol violation
     */
    public function performServerHandshake(): bool
    {
        if ($this->handshakeCompleted) {
            return true;
        }

        // Read available data into buffer
        $this->readIntoBuffer();

        // We need at least the double CRLF that terminates HTTP headers
        $headerEnd = \strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }

        $headerBlock = \substr($this->buffer, 0, $headerEnd);
        $this->buffer = \substr($this->buffer, $headerEnd + 4);

        $lines = \explode("\r\n", $headerBlock);
        $requestLine = \array_shift($lines);

        // Parse "GET /path HTTP/1.1"
        if (!\preg_match('#^GET\s+(\S+)\s+HTTP/1\.1$#i', $requestLine, $m)) {
            throw new RuntimeException('Invalid WebSocket HTTP request line: ' . $requestLine);
        }
        $this->requestUri = $m[1];

        // Parse headers
        foreach ($lines as $line) {
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $this->headers[\strtolower(\trim($name))] = \trim($value);
            }
        }

        // Validate required WebSocket headers
        $key = $this->headers['sec-websocket-key'] ?? null;
        if ($key === null) {
            throw new RuntimeException('Missing Sec-WebSocket-Key header');
        }

        $upgrade = \strtolower($this->headers['upgrade'] ?? '');
        if ($upgrade !== 'websocket') {
            throw new RuntimeException('Invalid Upgrade header: ' . $upgrade);
        }

        // Build accept key per RFC 6455 §4.2.2
        $acceptKey = \base64_encode(
            \sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC175AB2', true),
        );

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
            . "\r\n";

        \fwrite($this->stream, $response);
        $this->handshakeCompleted = true;

        return true;
    }

    /**
     * Complete the client-side opening handshake.
     *
     * Called after the client has sent its Upgrade request and needs
     * to read and validate the server's 101 response.
     *
     * @param string $expectedAccept The expected Sec-WebSocket-Accept value
     *
     * @return bool true when handshake is completed
     *
     * @throws RuntimeException on protocol violation
     */
    public function completeClientHandshake(string $expectedAccept): bool
    {
        if ($this->handshakeCompleted) {
            return true;
        }

        $this->readIntoBuffer();

        $headerEnd = \strpos($this->buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return false;
        }

        $headerBlock = \substr($this->buffer, 0, $headerEnd);
        $this->buffer = \substr($this->buffer, $headerEnd + 4);

        $lines = \explode("\r\n", $headerBlock);
        $statusLine = \array_shift($lines);

        if (!\preg_match('#^HTTP/1\.1\s+101\s+#i', $statusLine)) {
            throw new RuntimeException('Server did not return 101: ' . $statusLine);
        }

        foreach ($lines as $line) {
            if (\str_contains($line, ':')) {
                [$name, $value] = \explode(':', $line, 2);
                $this->headers[\strtolower(\trim($name))] = \trim($value);
            }
        }

        $accept = $this->headers['sec-websocket-accept'] ?? '';
        if ($accept !== $expectedAccept) {
            throw new RuntimeException('Sec-WebSocket-Accept mismatch');
        }

        $this->handshakeCompleted = true;

        return true;
    }

    // ── Frame I/O ────────────────────────────────────────────────

    /**
     * Send a text message.
     */
    public function sendText(string $text): void
    {
        $this->sendFrame(Frame::text($text, $this->maskOutput));
    }

    /**
     * Send a binary message.
     */
    public function sendBinary(string $data): void
    {
        $this->sendFrame(Frame::binary($data, $this->maskOutput));
    }

    /**
     * Send a ping frame.
     */
    public function sendPing(string $payload = ''): void
    {
        $this->sendFrame(Frame::ping($payload));
    }

    /**
     * Send a pong frame.
     */
    public function sendPong(string $payload = ''): void
    {
        $this->sendFrame(Frame::pong($payload));
    }

    /**
     * Send a close frame and mark the connection as closing.
     *
     * @param int $code Status code (default: 1000 normal)
     * @param string $reason Human-readable reason
     */
    public function sendClose(int $code = 1000, string $reason = ''): void
    {
        if ($this->closeSent) {
            return;
        }
        $frame = Frame::close($code, $reason);
        $this->sendFrame($frame);
        $this->closeSent = true;
    }

    /**
     * Send a raw Frame on the wire.
     */
    public function sendFrame(Frame $frame): void
    {
        if (!\is_resource($this->stream)) {
            throw new RuntimeException('Cannot write to closed stream');
        }
        $data = $frame->encode($this->maskOutput);
        $written = @\fwrite($this->stream, $data);
        if ($written === false) {
            throw new RuntimeException('fwrite failed');
        }
    }

    /**
     * Read and return the next complete frame, or null if none is available.
     *
     * This method is non-blocking when the stream is set to non-blocking mode.
     * It handles control frames (ping/pong) transparently and reassembles
     * fragmented messages.
     *
     * @return Frame|null A complete data or close frame, or null
     */
    public function readFrame(): ?Frame
    {
        $this->readIntoBuffer();

        if ($this->buffer === '') {
            return null;
        }

        $result = Frame::decode($this->buffer);
        if ($result === null) {
            return null;
        }

        [$frame, $consumed] = $result;
        $this->buffer = \substr($this->buffer, $consumed);

        // Transparently handle ping → pong
        if ($frame->isPing()) {
            $this->sendPong($frame->getPayload());

            return $this->readFrame(); // Try next frame
        }

        if ($frame->isClose()) {
            $this->closeReceived = true;
            if (!$this->closeSent) {
                // Echo the close frame back
                $this->sendClose(
                    $frame->getCloseCode() ?? 1000,
                    $frame->getCloseReason(),
                );
            }
        }

        return $frame;
    }

    /**
     * Read all available frames from the buffer.
     *
     * @return Frame[]
     */
    public function readFrames(): array
    {
        $frames = [];
        while (($frame = $this->readFrame()) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Disconnect and close the underlying stream.
     */
    public function disconnect(): void
    {
        if (\is_resource($this->stream)) {
            @\fclose($this->stream);
        }
    }

    /**
     * Check whether the close handshake is complete (both sides sent close).
     */
    public function isCloseComplete(): bool
    {
        return $this->closeSent && $this->closeReceived;
    }

    // ── Internal ─────────────────────────────────────────────────

    /**
     * Read bytes from the stream into the internal buffer.
     */
    private function readIntoBuffer(): void
    {
        if (!\is_resource($this->stream) || \feof($this->stream)) {
            return;
        }

        $data = @\fread($this->stream, 65536);
        if ($data !== false && $data !== '') {
            $this->buffer .= $data;
        }
    }
}
