<?php

/**
 * Unit tests for Razy\WebSocket\Client.
 *
 * Tests URL parsing and the connect-handshake flow using local stream pairs.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\WebSocket\Client;
use Razy\WebSocket\Connection;
use ReflectionMethod;
use RuntimeException;

#[CoversClass(Client::class)]
class WebSocketClientTest extends TestCase
{
    // ── URL parsing (via reflection) ─────────────────────────────

    #[Test]
    public function parseUrlWs(): void
    {
        $parsed = $this->callParseUrl('ws://localhost:8080/chat');
        $this->assertSame('ws', $parsed['scheme']);
        $this->assertSame('localhost', $parsed['host']);
        $this->assertSame(8080, $parsed['port']);
        $this->assertSame('/chat', $parsed['path']);
    }

    #[Test]
    public function parseUrlWss(): void
    {
        $parsed = $this->callParseUrl('wss://example.com/secure');
        $this->assertSame('wss', $parsed['scheme']);
        $this->assertSame('example.com', $parsed['host']);
        $this->assertSame(443, $parsed['port']);
        $this->assertSame('/secure', $parsed['path']);
    }

    #[Test]
    public function parseUrlDefaultPort(): void
    {
        $parsed = $this->callParseUrl('ws://host/');
        $this->assertSame(80, $parsed['port']);
    }

    #[Test]
    public function parseUrlWithQueryString(): void
    {
        $parsed = $this->callParseUrl('ws://host:9000/path?token=abc&v=2');
        $this->assertSame('/path?token=abc&v=2', $parsed['path']);
    }

    #[Test]
    public function parseUrlNoPath(): void
    {
        $parsed = $this->callParseUrl('ws://host:1234');
        $this->assertSame('/', $parsed['path']);
    }

    #[Test]
    public function parseUrlThrowsOnInvalidScheme(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported scheme');
        $this->callParseUrl('http://host/path');
    }

    #[Test]
    public function parseUrlThrowsOnInvalidUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->callParseUrl('not a url at all');
    }

    // ── Live connect against a local server ──────────────────────

    #[Test]
    public function connectAndExchangeMessages(): void
    {
        $port = $this->findFreePort();

        // Start a minimalist server in a separate process
        $serverSocket = \stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );
        $this->assertNotFalse($serverSocket, "Bind failed: {$errstr}");
        \stream_set_blocking($serverSocket, false);

        // Connect the client (this spawns the TCP connection + handshake)
        // We need to accept + handshake on the server side concurrently.
        // Since PHP is single-threaded, we'll interleave manually.

        // 1. Client initiates TCP
        $clientStream = @\stream_socket_client(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $errstr,
            2,
        );
        $this->assertNotFalse($clientStream);
        \stream_set_blocking($clientStream, false);

        // 2. Server accepts
        $acceptedStream = \stream_socket_accept($serverSocket, 2);
        $this->assertNotFalse($acceptedStream);
        \stream_set_blocking($acceptedStream, false);

        // 3. Client sends WS handshake
        $key = \base64_encode(\random_bytes(16));
        $handshake = "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
        \fwrite($clientStream, $handshake);
        \usleep(50000);

        // 4. Server performs handshake
        $serverConn = new Connection($acceptedStream, maskOutput: false);
        $this->assertTrue($serverConn->performServerHandshake());

        // 5. Client reads the 101 response
        $expectedAccept = \base64_encode(
            \sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC175AB2', true),
        );
        $clientConn = new Connection($clientStream, maskOutput: true);
        \usleep(50000);
        $this->assertTrue($clientConn->completeClientHandshake($expectedAccept));

        // 6. Client sends text
        $clientConn->sendText('hello server');
        \usleep(50000);

        $frame = $serverConn->readFrame();
        $this->assertNotNull($frame);
        $this->assertSame('hello server', $frame->getPayload());

        // 7. Server replies
        $serverConn->sendText('hello client');
        \usleep(50000);

        $reply = $clientConn->readFrame();
        $this->assertNotNull($reply);
        $this->assertSame('hello client', $reply->getPayload());

        // Cleanup
        \fclose($clientStream);
        \fclose($acceptedStream);
        \fclose($serverSocket);
    }

    #[Test]
    public function connectThrowsOnUnreachableHost(): void
    {
        $this->expectException(RuntimeException::class);
        Client::connect('ws://127.0.0.1:1', timeout: 1);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function callParseUrl(string $url): array
    {
        $method = new ReflectionMethod(Client::class, 'parseUrl');
        $method->setAccessible(true);

        return $method->invoke(null, $url);
    }

    private function findFreePort(): int
    {
        $sock = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        \socket_bind($sock, '127.0.0.1', 0);
        \socket_getsockname($sock, $addr, $port);
        \socket_close($sock);

        return $port;
    }
}
