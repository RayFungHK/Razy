<?php

namespace Razy\WebSocket;

use Closure;
use function Razy\append;
use function Razy\tidy;

class Server
{
    const NEWLINE                          = "\r\n";
    private ?Closure $onConnectCallback    = null;
    private ?Closure $onDisconnectCallback = null;
    private ?Closure $onReceiveCallback    = null;
    private $socket;
    private array $peer      = [];
    private string $hostname = '';
    private string $path     = '';
    private int $port        = 443;

    public function __construct(string $hostname, int $port = 443, string $path = '')
    {
        $this->hostname = $hostname;
        $this->port     = $port;
        $this->path     = $path;
    }

    public function start()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($this->socket, 0, $this->port);
        socket_listen($this->socket);

        $this->peer = [$this->socket];

        while (true) {
            $peerList = $this->peer;
            socket_select($peerList, $null, $null, 0, 10);

            if (in_array($this->socket, $peerList)) {
                $client       = socket_accept($this->socket);
                $this->peer[] = $client;

                $header = socket_read($client, 1024);
                $this->doHandshake($header, $client);

                socket_getpeername($client, $clientIP);

                if ($this->onConnectCallback) {
                    $this->onConnectCallback->call($this, $clientIP, $client);
                }

                $index = array_search($this->socket, $peerList);
                unset($peerList[$index]);
            }

            foreach ($peerList as $peer) {
                while (socket_recv($peer, $socketData, 1024, 0) >= 1) {
                    $socketMessage = $this->unpack($socketData);

                    echo $socketMessage;
                    if ($this->onReceiveCallback) {
                        $this->onReceiveCallback->call($this, $socketMessage, $peer);
                    }
                    break 2;
                }

                $socketData = @socket_read($peer, 1024, PHP_NORMAL_READ);
                if ($socketData === false) {
                    socket_getpeername($peer, $clientIP);

                    if ($this->onDisconnectCallback) {
                        $this->onDisconnectCallback->call($this, $clientIP, $peer);
                    }

                    $index = array_search($peer, $this->peer);
                    unset($this->peer[$index]);
                }
            }
        }
    }

    public function onConnect(Closure $callback): Server
    {
        $this->onConnectCallback = $callback;

        return $this;
    }

    public function onDisconnect(Closure $callback): Server
    {
        $this->onDisconnectCallback = $callback;

        return $this;
    }

    public function onReceive(Closure $callback): Server
    {
        $this->onReceiveCallback = $callback;

        return $this;
    }

    private function pack(string $data): string
    {
        $b1     = 0x80 | (0x1 & 0x0f);
        $length = strlen($data);
        $header = '';

        if ($length <= 125) {
            $header = pack('CC', $b1, $length);
        } elseif ($length > 125 && $length < 65536) {
            $header = pack('CCn', $b1, 126, $length);
        } elseif ($length >= 65536) {
            $header = pack('CCNN', $b1, 127, $length);
        }
        return $header . $data;
    }

    private function unpack(string $data): string
    {
        $length = ord($data[1]) & 127;
        if ($length == 126) {
            $masks   = substr($data, 4, 4);
            $content = substr($data, 8);
        } elseif ($length == 127) {
            $masks   = substr($data, 10, 4);
            $content = substr($data, 14);
        } else {
            $masks   = substr($data, 2, 4);
            $content = substr($data, 6);
        }

        $response = '';
        for ($i = 0; $i < strlen($content); ++$i) {
            $response .= $content[$i] ^ $masks[$i % 4];
        }

        return $response;
    }

    private function doHandshake(string $header, $client): void
    {
        $headers = [];
        $lines   = preg_split("/\r\n/", $header);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey    = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $buffer    = implode(self::NEWLINE, [
                'HTTP/1.1 101 Web Socket Protocol Handshake',
                'Upgrade: websocket',
                'Connection: Upgrade',
                'WebSocket-Origin: ' . $this->hostname,
                'WebSocket-Location: ws://' . tidy(append($this->hostname . ':' . $this->port, $this->path), false, '\\'),
                'Sec-WebSocket-Accept: ' . $secAccept,
            ]) . self::NEWLINE . self::NEWLINE;

        socket_write($client, $buffer, strlen($buffer));
    }

    public function send(string $message, $client = null): Server
    {
        $message = $this->pack($message);
        $length  = strlen($message);
        if ($client) {
            @socket_write($client, $message, $length);
            var_dump($client);
        } else {
            foreach ($this->peer as $client) {
                @socket_write($client, $message, $length);
            }
        }

        return $this;
    }
}