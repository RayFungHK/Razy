<?php
/**
 * Unit tests for Razy\WebSocket\Connection.
 *
 * Tests the handshake logic and frame I/O using in-memory stream pairs.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\WebSocket\Connection;
use Razy\WebSocket\Frame;

#[CoversClass(Connection::class)]
class WebSocketConnectionTest extends TestCase
{
    /**
     * Create a pair of connected stream sockets for testing.
     * Uses a TCP loopback connection for Windows compatibility
     * (stream_socket_pair with STREAM_PF_UNIX is not available on Windows).
     *
     * @return array{0: resource, 1: resource}
     */
    private function createStreamPair(): array
    {
        // Bind an ephemeral port on localhost
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "Failed to create server socket: {$errstr}");

        $serverName = stream_socket_get_name($server, false);
        $client = stream_socket_client("tcp://{$serverName}", $errno, $errstr, 2);
        $this->assertNotFalse($client, "Failed to connect client: {$errstr}");

        $accepted = stream_socket_accept($server, 2);
        $this->assertNotFalse($accepted, 'Failed to accept connection');

        fclose($server);

        stream_set_blocking($client, false);
        stream_set_blocking($accepted, false);

        return [$client, $accepted];
    }

    // ── Identity ─────────────────────────────────────────────────

    #[Test]
    public function getIdReturnsNonEmptyString(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertNotEmpty($conn->getId());
        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function twoConnectionsHaveDifferentIds(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $a = new Connection($s1);
        $b = new Connection($s2);
        $this->assertNotSame($a->getId(), $b->getId());
        fclose($s1);
        fclose($s2);
    }

    // ── isOpen ───────────────────────────────────────────────────

    #[Test]
    public function isOpenReturnsTrueOnFreshConnection(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertTrue($conn->isOpen());
        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function isOpenReturnsFalseAfterDisconnect(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $conn->disconnect();
        $this->assertFalse($conn->isOpen());
        fclose($s2);
    }

    // ── User data ────────────────────────────────────────────────

    #[Test]
    public function userDataRoundTrip(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertNull($conn->getUserData());
        $conn->setUserData(['room' => 'lobby']);
        $this->assertSame(['room' => 'lobby'], $conn->getUserData());
        fclose($s1);
        fclose($s2);
    }

    // ── Handshake ────────────────────────────────────────────────

    #[Test]
    public function handshakeNotCompletedInitially(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertFalse($conn->isHandshakeCompleted());
        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function serverHandshakeCompletesSuccessfully(): void
    {
        [$serverStream, $clientStream] = $this->createStreamPair();

        $key = base64_encode(random_bytes(16));
        $request = "GET /chat HTTP/1.1\r\n"
            . "Host: localhost:8080\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($clientStream, $request);

        $server = new Connection($serverStream);
        $result = $server->performServerHandshake();

        $this->assertTrue($result);
        $this->assertTrue($server->isHandshakeCompleted());
        $this->assertSame('/chat', $server->getRequestUri());
        $this->assertSame('websocket', $server->getHeader('upgrade'));

        // Read the response the server wrote
        $response = fread($clientStream, 4096);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept:', $response);

        fclose($serverStream);
        fclose($clientStream);
    }

    #[Test]
    public function serverHandshakeReturnsFalseOnIncomplete(): void
    {
        [$serverStream, $clientStream] = $this->createStreamPair();

        // Send an incomplete request (no double CRLF terminator)
        fwrite($clientStream, "GET /chat HTTP/1.1\r\nHost: localhost\r\n");

        $server = new Connection($serverStream);
        $this->assertFalse($server->performServerHandshake());

        fclose($serverStream);
        fclose($clientStream);
    }

    #[Test]
    public function serverHandshakeThrowsOnMissingKey(): void
    {
        [$serverStream, $clientStream] = $this->createStreamPair();

        $request = "GET / HTTP/1.1\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "\r\n";

        fwrite($clientStream, $request);

        $server = new Connection($serverStream);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing Sec-WebSocket-Key');
        $server->performServerHandshake();

        fclose($serverStream);
        fclose($clientStream);
    }

    #[Test]
    public function serverHandshakeThrowsOnBadUpgradeHeader(): void
    {
        [$serverStream, $clientStream] = $this->createStreamPair();

        $key = base64_encode(random_bytes(16));
        $request = "GET / HTTP/1.1\r\n"
            . "Upgrade: http\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "\r\n";

        fwrite($clientStream, $request);

        $server = new Connection($serverStream);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid Upgrade header');
        $server->performServerHandshake();

        fclose($serverStream);
        fclose($clientStream);
    }

    // ── Frame send / receive ─────────────────────────────────────

    #[Test]
    public function sendTextAndReadFrame(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $sender   = new Connection($s1, maskOutput: false);
        $receiver = new Connection($s2, maskOutput: false);

        // Pretend handshake is done by using reflection
        $this->markHandshakeComplete($sender);
        $this->markHandshakeComplete($receiver);

        $sender->sendText('Hello');

        // Give the data a moment to traverse the stream
        usleep(10000);

        $frame = $receiver->readFrame();
        $this->assertNotNull($frame);
        $this->assertTrue($frame->isText());
        $this->assertSame('Hello', $frame->getPayload());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function sendBinaryAndReadFrame(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $sender   = new Connection($s1, maskOutput: false);
        $receiver = new Connection($s2, maskOutput: false);
        $this->markHandshakeComplete($sender);
        $this->markHandshakeComplete($receiver);

        $data = random_bytes(128);
        $sender->sendBinary($data);
        usleep(10000);

        $frame = $receiver->readFrame();
        $this->assertNotNull($frame);
        $this->assertTrue($frame->isBinary());
        $this->assertSame($data, $frame->getPayload());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function pingIsAutoAnsweredWithPong(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $pinger   = new Connection($s1, maskOutput: false);
        $receiver = new Connection($s2, maskOutput: false);
        $this->markHandshakeComplete($pinger);
        $this->markHandshakeComplete($receiver);

        // Send a ping from s1
        $pinger->sendPing('beat');
        usleep(10000);

        // Receiver reads frame — the ping should be transparently handled
        // and a pong sent back, readFrame returns null (ping consumed)
        $frame = $receiver->readFrame();
        // readFrame auto-responds to ping and tries to read the next frame
        $this->assertNull($frame); // no more frames

        // The pinger should see a pong
        usleep(10000);
        $pong = $pinger->readFrame();
        $this->assertNotNull($pong);
        $this->assertTrue($pong->isPong());
        $this->assertSame('beat', $pong->getPayload());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function sendCloseAndReadClose(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $closer   = new Connection($s1, maskOutput: false);
        $receiver = new Connection($s2, maskOutput: false);
        $this->markHandshakeComplete($closer);
        $this->markHandshakeComplete($receiver);

        $closer->sendClose(1000, 'bye');
        usleep(10000);

        $frame = $receiver->readFrame();
        $this->assertNotNull($frame);
        $this->assertTrue($frame->isClose());
        $this->assertSame(1000, $frame->getCloseCode());
        $this->assertSame('bye', $frame->getCloseReason());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function readFrameReturnsNullOnEmptyStream(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->markHandshakeComplete($conn);

        $frame = $conn->readFrame();
        $this->assertNull($frame);

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function readFramesReturnsMultipleFrames(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $sender   = new Connection($s1, maskOutput: false);
        $receiver = new Connection($s2, maskOutput: false);
        $this->markHandshakeComplete($sender);
        $this->markHandshakeComplete($receiver);

        $sender->sendText('one');
        $sender->sendText('two');
        $sender->sendText('three');
        usleep(10000);

        $frames = $receiver->readFrames();
        $this->assertCount(3, $frames);
        $this->assertSame('one', $frames[0]->getPayload());
        $this->assertSame('two', $frames[1]->getPayload());
        $this->assertSame('three', $frames[2]->getPayload());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function maskedSendIsDecodedCorrectly(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $sender   = new Connection($s1, maskOutput: true); // client-like
        $receiver = new Connection($s2, maskOutput: false); // server-like
        $this->markHandshakeComplete($sender);
        $this->markHandshakeComplete($receiver);

        $sender->sendText('masked msg');
        usleep(10000);

        $frame = $receiver->readFrame();
        $this->assertNotNull($frame);
        $this->assertSame('masked msg', $frame->getPayload());
        $this->assertTrue($frame->isMasked());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function isCloseCompleteAfterBothSidesClose(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $a = new Connection($s1, maskOutput: false);
        $b = new Connection($s2, maskOutput: false);
        $this->markHandshakeComplete($a);
        $this->markHandshakeComplete($b);

        $this->assertFalse($a->isCloseComplete());

        $a->sendClose(1000, 'done');
        usleep(10000);

        // B reads close frame — auto-replies with close
        $frame = $b->readFrame();
        $this->assertNotNull($frame);
        $this->assertTrue($frame->isClose());
        $this->assertTrue($b->isCloseComplete()); // received + sent

        usleep(10000);

        // A reads the echoed close
        $echoClose = $a->readFrame();
        $this->assertNotNull($echoClose);
        $this->assertTrue($echoClose->isClose());
        $this->assertTrue($a->isCloseComplete());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function disconnectClosesStream(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $conn->disconnect();
        $this->assertFalse(is_resource($s1));
        fclose($s2);
    }

    #[Test]
    public function getHeaderReturnsNullForMissingHeader(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertNull($conn->getHeader('x-nonexistent'));
        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function getHeadersReturnsEmptyArrayInitially(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertSame([], $conn->getHeaders());
        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function getRequestUriReturnsNullInitially(): void
    {
        [$s1, $s2] = $this->createStreamPair();
        $conn = new Connection($s1);
        $this->assertNull($conn->getRequestUri());
        fclose($s1);
        fclose($s2);
    }

    // ── Client handshake ─────────────────────────────────────────

    #[Test]
    public function clientHandshakeCompletesSuccessfully(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $key = base64_encode(random_bytes(16));
        $expectedAccept = base64_encode(
            sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC175AB2', true)
        );

        // Simulate server response
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$expectedAccept}\r\n"
            . "\r\n";

        fwrite($s2, $response);

        $client = new Connection($s1, maskOutput: true);
        $result = $client->completeClientHandshake($expectedAccept);

        $this->assertTrue($result);
        $this->assertTrue($client->isHandshakeCompleted());

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function clientHandshakeThrowsOnNon101(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        fwrite($s2, "HTTP/1.1 400 Bad Request\r\n\r\n");

        $client = new Connection($s1, maskOutput: true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server did not return 101');
        $client->completeClientHandshake('ignored');

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function clientHandshakeThrowsOnAcceptMismatch(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: WRONG_VALUE\r\n"
            . "\r\n";

        fwrite($s2, $response);

        $client = new Connection($s1, maskOutput: true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sec-WebSocket-Accept mismatch');
        $client->completeClientHandshake('correct_value');

        fclose($s1);
        fclose($s2);
    }

    #[Test]
    public function clientHandshakeReturnsFalseOnIncomplete(): void
    {
        [$s1, $s2] = $this->createStreamPair();

        // Partial response without double CRLF
        fwrite($s2, "HTTP/1.1 101 Switching Protocols\r\n");

        $client = new Connection($s1, maskOutput: true);
        $this->assertFalse($client->completeClientHandshake('any'));

        fclose($s1);
        fclose($s2);
    }

    // ── Full server↔client round-trip ────────────────────────────

    #[Test]
    public function fullHandshakeAndMessageExchange(): void
    {
        [$serverStream, $clientStream] = $this->createStreamPair();

        // Client sends opening handshake
        $key = base64_encode(random_bytes(16));
        $request = "GET /ws HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
        fwrite($clientStream, $request);

        // Server completes handshake
        $server = new Connection($serverStream, maskOutput: false);
        $this->assertTrue($server->performServerHandshake());
        $this->assertSame('/ws', $server->getRequestUri());

        // Client reads the 101 response
        $expectedAccept = base64_encode(
            sha1($key . '258EAFA5-E914-47DA-95CA-5AB5DC175AB2', true)
        );

        $client = new Connection($clientStream, maskOutput: true);
        usleep(10000);
        $this->assertTrue($client->completeClientHandshake($expectedAccept));

        // Client sends a masked text frame
        $client->sendText('ping from client');
        usleep(10000);

        $frame = $server->readFrame();
        $this->assertNotNull($frame);
        $this->assertSame('ping from client', $frame->getPayload());

        // Server responds
        $server->sendText('pong from server');
        usleep(10000);

        $reply = $client->readFrame();
        $this->assertNotNull($reply);
        $this->assertSame('pong from server', $reply->getPayload());

        fclose($serverStream);
        fclose($clientStream);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Force-complete the handshake on a Connection for unit testing.
     */
    private function markHandshakeComplete(Connection $conn): void
    {
        $ref = new \ReflectionProperty(Connection::class, 'handshakeCompleted');
        $ref->setAccessible(true);
        $ref->setValue($conn, true);
    }
}
