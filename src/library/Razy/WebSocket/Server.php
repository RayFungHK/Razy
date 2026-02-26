<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Event-driven WebSocket server using PHP stream sockets.
 * Accepts connections, performs the RFC 6455 handshake, and dispatches
 * events for open, message, close, and error.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\WebSocket;

use Closure;
use RuntimeException;
use Throwable;

/**
 * A single-process, event-driven WebSocket server.
 *
 * Uses stream_socket_server + stream_select for non-blocking I/O.
 *
 * Usage:
 * ```php
 * $server = new Server('0.0.0.0', 8080);
 * $server->onOpen(fn(Connection $conn) => echo "Connected: {$conn->getId()}\n");
 * $server->onMessage(fn(Connection $conn, Frame $frame) => $conn->sendText('echo: ' . $frame->getPayload()));
 * $server->onClose(fn(Connection $conn) => echo "Disconnected\n");
 * $server->start();
 * ```
 *
 * @class Server
 * @package Razy\WebSocket
 */
class Server
{
    /** @var resource|null The listening server socket */
    private mixed $socket = null;

    /** @var array<string, Connection> Active connections keyed by ID */
    private array $connections = [];

    /** @var bool Whether the event loop is running */
    private bool $running = false;

    // ── Event callbacks ──────────────────────────────────────────

    /** @var Closure|null fn(Connection): void */
    private ?Closure $onOpen = null;

    /** @var Closure|null fn(Connection, Frame): void */
    private ?Closure $onMessage = null;

    /** @var Closure|null fn(Connection, int, string): void */
    private ?Closure $onClose = null;

    /** @var Closure|null fn(Connection, Throwable): void */
    private ?Closure $onError = null;

    /** @var Closure|null fn(Server): void — called once per tick */
    private ?Closure $onTick = null;

    /**
     * @param string $host    Listen address (e.g. '0.0.0.0')
     * @param int    $port    Listen port
     * @param int    $timeout stream_select timeout in microseconds (default 200 ms)
     */
    public function __construct(
        private string $host = '0.0.0.0',
        private int    $port = 8080,
        private int    $timeout = 200000,
    ) {
    }

    // ── Event registration ───────────────────────────────────────

    /**
     * Called when a new client completes the WebSocket handshake.
     */
    public function onOpen(Closure $callback): static
    {
        $this->onOpen = $callback;

        return $this;
    }

    /**
     * Called when a data frame (text or binary) is received.
     */
    public function onMessage(Closure $callback): static
    {
        $this->onMessage = $callback;

        return $this;
    }

    /**
     * Called when a connection is closed.
     */
    public function onClose(Closure $callback): static
    {
        $this->onClose = $callback;

        return $this;
    }

    /**
     * Called when an exception occurs on a connection.
     */
    public function onError(Closure $callback): static
    {
        $this->onError = $callback;

        return $this;
    }

    /**
     * Called once per event-loop iteration, useful for periodic tasks.
     */
    public function onTick(Closure $callback): static
    {
        $this->onTick = $callback;

        return $this;
    }

    // ── Server lifecycle ─────────────────────────────────────────

    /**
     * Bind, listen, and enter the event loop.
     *
     * @throws RuntimeException if the socket cannot be created
     */
    public function start(): void
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $context = stream_context_create();

        $this->socket = @stream_socket_server(
            $address,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if (!$this->socket) {
            throw new RuntimeException("Failed to bind WebSocket server on {$address}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($this->socket, false);
        $this->running = true;

        $this->eventLoop();
    }

    /**
     * Signal the event loop to stop after the current iteration.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get all currently connected clients.
     *
     * @return array<string, Connection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Broadcast a text message to all connected clients.
     *
     * @param string          $text    Message payload
     * @param Connection|null $exclude Optionally exclude one connection
     */
    public function broadcast(string $text, ?Connection $exclude = null): void
    {
        foreach ($this->connections as $id => $conn) {
            if ($exclude !== null && $id === $exclude->getId()) {
                continue;
            }
            if ($conn->isOpen() && $conn->isHandshakeCompleted()) {
                $conn->sendText($text);
            }
        }
    }

    // ── Event loop ───────────────────────────────────────────────

    /**
     * Main event loop using stream_select.
     */
    private function eventLoop(): void
    {
        while ($this->running) {
            $readStreams = [$this->socket];
            foreach ($this->connections as $conn) {
                $readStreams[] = $conn->getStream();
            }

            $write = null;
            $except = null;
            $changed = @stream_select($readStreams, $write, $except, 0, $this->timeout);

            if ($changed === false) {
                continue; // Interrupted by signal
            }

            foreach ($readStreams as $stream) {
                if ($stream === $this->socket) {
                    $this->acceptNewConnection();
                } else {
                    $this->handleClientData($stream);
                }
            }

            // Tick callback
            if ($this->onTick !== null) {
                ($this->onTick)($this);
            }
        }

        // Teardown
        $this->shutdown();
    }

    /**
     * Accept a new TCP connection and register it.
     */
    private function acceptNewConnection(): void
    {
        $clientStream = @stream_socket_accept($this->socket, 0);
        if (!$clientStream) {
            return;
        }

        stream_set_blocking($clientStream, false);

        $conn = new Connection($clientStream, maskOutput: false);
        $this->connections[$conn->getId()] = $conn;
    }

    /**
     * Process incoming data on a client stream.
     *
     * @param resource $stream
     */
    private function handleClientData(mixed $stream): void
    {
        // Find the connection for this stream
        $conn = null;
        foreach ($this->connections as $c) {
            if ($c->getStream() === $stream) {
                $conn = $c;
                break;
            }
        }

        if ($conn === null) {
            return;
        }

        try {
            // If handshake not complete, attempt it
            if (!$conn->isHandshakeCompleted()) {
                if ($conn->performServerHandshake()) {
                    if ($this->onOpen !== null) {
                        ($this->onOpen)($conn);
                    }
                }

                return;
            }

            // Read frames
            if (!$conn->isOpen()) {
                $this->removeConnection($conn, 1006, 'Connection lost');

                return;
            }

            $frames = $conn->readFrames();
            foreach ($frames as $frame) {
                if ($frame->isClose()) {
                    $this->removeConnection(
                        $conn,
                        $frame->getCloseCode() ?? 1000,
                        $frame->getCloseReason(),
                    );

                    return;
                }

                if ($frame->isText() || $frame->isBinary()) {
                    if ($this->onMessage !== null) {
                        ($this->onMessage)($conn, $frame);
                    }
                }
            }

            // Check for EOF (remote disconnect without close frame)
            if (feof($stream)) {
                $this->removeConnection($conn, 1006, 'Connection lost');
            }
        } catch (Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($conn, $e);
            }
            $this->removeConnection($conn, 1011, 'Internal error');
        }
    }

    /**
     * Remove a connection and fire the onClose event.
     */
    private function removeConnection(Connection $conn, int $code, string $reason): void
    {
        $id = $conn->getId();
        if (!isset($this->connections[$id])) {
            return;
        }

        unset($this->connections[$id]);

        if ($this->onClose !== null) {
            ($this->onClose)($conn, $code, $reason);
        }

        $conn->disconnect();
    }

    /**
     * Gracefully shut down all connections and the server socket.
     */
    private function shutdown(): void
    {
        foreach ($this->connections as $conn) {
            try {
                $conn->sendClose(1001, 'Server shutting down');
            } catch (Throwable) {
                // Best-effort
            }
            $conn->disconnect();
        }
        $this->connections = [];

        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
        }
    }
}
