<?php
/**
 * Unit tests for Razy\WebSocket\Server (non-network unit tests).
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\WebSocket\Server;
use Razy\WebSocket\Connection;

#[CoversClass(Server::class)]
class WebSocketServerTest extends TestCase
{
    #[Test]
    public function callbacksAreChainable(): void
    {
        $server = new Server('127.0.0.1', 19876);

        $result = $server
            ->onOpen(fn(Connection $c) => null)
            ->onMessage(fn(Connection $c, $f) => null)
            ->onClose(fn(Connection $c, int $code, string $reason) => null)
            ->onError(fn(Connection $c, \Throwable $e) => null)
            ->onTick(fn(Server $s) => null);

        $this->assertSame($server, $result);
    }

    #[Test]
    public function getConnectionsReturnsEmptyArrayInitially(): void
    {
        $server = new Server();
        $this->assertSame([], $server->getConnections());
    }

    #[Test]
    public function stopIsCallableBeforeStart(): void
    {
        $server = new Server();
        $server->stop(); // Should not throw
        $this->assertTrue(true);
    }

    #[Test]
    public function broadcastDoesNothingWithNoConnections(): void
    {
        $server = new Server();
        // Should not throw even with no connections
        $server->broadcast('hello world');
        $this->assertSame([], $server->getConnections());
    }

    #[Test]
    public function startThrowsOnInvalidBindAddress(): void
    {
        // Bind to an address that will fail
        $server = new Server('999.999.999.999', 0);
        $this->expectException(\RuntimeException::class);
        $server->start();
    }

    #[Test]
    public function serverCanBindAndStopImmediately(): void
    {
        $server = new Server('127.0.0.1', 0);
        $server->onTick(function (Server $s) {
            $s->stop(); // Stop after first tick
        });

        // Use a random available port to avoid conflicts
        $port = $this->findFreePort();
        $server = new Server('127.0.0.1', $port);
        $server->onTick(fn(Server $s) => $s->stop());

        // This should not hang — the server starts and immediately stops
        $server->start();
        $this->assertTrue(true); // Reached here = success
    }

    /**
     * Find a free TCP port on localhost.
     */
    private function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        return $port;
    }
}
