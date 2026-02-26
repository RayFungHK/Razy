<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * RFC 6455 WebSocket frame encoder and decoder.
 *
 *
 * @license MIT
 */

namespace Razy\WebSocket;

use InvalidArgumentException;

/**
 * Represents a single WebSocket frame per RFC 6455 §5.2.
 *
 * Handles encoding PHP payloads into binary wire frames and decoding
 * binary data back into structured Frame objects.
 *
 * Supported opcodes: Continuation (0x0), Text (0x1), Binary (0x2),
 * Close (0x8), Ping (0x9), Pong (0xA).
 *
 * @class Frame
 */
class Frame
{
    // ── Opcodes ──────────────────────────────────────────────────

    /** @var int Continuation frame */
    public const OPCODE_CONTINUATION = 0x0;

    /** @var int Text frame (UTF-8) */
    public const OPCODE_TEXT = 0x1;

    /** @var int Binary frame */
    public const OPCODE_BINARY = 0x2;

    /** @var int Connection close frame */
    public const OPCODE_CLOSE = 0x8;

    /** @var int Ping frame */
    public const OPCODE_PING = 0x9;

    /** @var int Pong frame */
    public const OPCODE_PONG = 0xA;

    /** @var array<int, string> Opcode label map for debugging */
    private const OPCODE_NAMES = [
        self::OPCODE_CONTINUATION => 'continuation',
        self::OPCODE_TEXT => 'text',
        self::OPCODE_BINARY => 'binary',
        self::OPCODE_CLOSE => 'close',
        self::OPCODE_PING => 'ping',
        self::OPCODE_PONG => 'pong',
    ];

    /**
     * @param int $opcode Frame opcode (0x0–0xF)
     * @param string $payload Frame payload data
     * @param bool $fin FIN bit — true for final frame in a message
     * @param bool $masked Whether the payload is (or should be) masked
     */
    public function __construct(
        private int    $opcode,
        private string $payload = '',
        private bool   $fin = true,
        private bool   $masked = false,
    ) {
        if ($opcode < 0 || $opcode > 0xF) {
            throw new InvalidArgumentException('Opcode must be 0x0–0xF, got 0x' . \dechex($opcode));
        }
    }

    // ── Decoding ─────────────────────────────────────────────────

    /**
     * Decode a single frame from a binary buffer.
     *
     * Returns a tuple [Frame, bytesConsumed] on success, or null if the
     * buffer does not yet contain a complete frame.
     *
     * @param string $buffer Binary data buffer
     *
     * @return array{0: self, 1: int}|null
     */
    public static function decode(string $buffer): ?array
    {
        $bufLen = \strlen($buffer);
        if ($bufLen < 2) {
            return null;
        }

        $offset = 0;

        // Byte 1
        $byte1 = \ord($buffer[$offset++]);
        $fin = (bool) ($byte1 & 0x80);
        $opcode = $byte1 & 0x0F;

        // Byte 2
        $byte2 = \ord($buffer[$offset++]);
        $masked = (bool) ($byte2 & 0x80);
        $len = $byte2 & 0x7F;

        if ($len === 126) {
            if ($bufLen < $offset + 2) {
                return null;
            }
            $len = \unpack('n', \substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($len === 127) {
            if ($bufLen < $offset + 8) {
                return null;
            }
            $len = self::unpackUint64(\substr($buffer, $offset, 8));
            $offset += 8;
        }

        $maskKey = '';
        if ($masked) {
            if ($bufLen < $offset + 4) {
                return null;
            }
            $maskKey = \substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($bufLen < $offset + $len) {
            return null; // Incomplete payload
        }

        $payload = \substr($buffer, $offset, $len);
        if ($masked) {
            $payload = self::applyMask($payload, $maskKey);
        }

        $frame = new self($opcode, $payload, $fin, $masked);

        return [$frame, $offset + $len];
    }

    // ── Factory helpers ──────────────────────────────────────────

    /**
     * Create a text frame.
     */
    public static function text(string $payload, bool $mask = false): self
    {
        return new self(self::OPCODE_TEXT, $payload, true, $mask);
    }

    /**
     * Create a binary frame.
     */
    public static function binary(string $payload, bool $mask = false): self
    {
        return new self(self::OPCODE_BINARY, $payload, true, $mask);
    }

    /**
     * Create a ping frame.
     */
    public static function ping(string $payload = ''): self
    {
        return new self(self::OPCODE_PING, $payload);
    }

    /**
     * Create a pong frame.
     */
    public static function pong(string $payload = ''): self
    {
        return new self(self::OPCODE_PONG, $payload);
    }

    /**
     * Create a close frame.
     *
     * @param int $code Status code (default 1000 = normal closure)
     * @param string $reason Human-readable reason
     */
    public static function close(int $code = 1000, string $reason = ''): self
    {
        $payload = \pack('n', $code) . $reason;

        return new self(self::OPCODE_CLOSE, $payload);
    }

    // ── Internal helpers ─────────────────────────────────────────

    /**
     * XOR-mask a payload with a 4-byte key (RFC 6455 §5.3).
     */
    public static function applyMask(string $data, string $maskKey): string
    {
        $len = \strlen($data);
        $result = '';
        for ($i = 0; $i < $len; $i++) {
            $result .= $data[$i] ^ $maskKey[$i % 4];
        }

        return $result;
    }

    /**
     * Pack an integer into a big-endian 64-bit string.
     */
    private static function packUint64(int $value): string
    {
        return \pack('NN', ($value >> 32) & 0xFFFFFFFF, $value & 0xFFFFFFFF);
    }

    /**
     * Unpack a big-endian 64-bit string into an integer.
     */
    private static function unpackUint64(string $data): int
    {
        $parts = \unpack('N2', $data);

        return ($parts[1] << 32) | $parts[2];
    }

    // ── Getters ──────────────────────────────────────────────────

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    public function getOpcodeName(): string
    {
        return self::OPCODE_NAMES[$this->opcode] ?? 'unknown(0x' . \dechex($this->opcode) . ')';
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    public function getPayloadLength(): int
    {
        return \strlen($this->payload);
    }

    public function isFin(): bool
    {
        return $this->fin;
    }

    public function isMasked(): bool
    {
        return $this->masked;
    }

    public function isControl(): bool
    {
        return ($this->opcode & 0x8) !== 0;
    }

    public function isText(): bool
    {
        return $this->opcode === self::OPCODE_TEXT;
    }

    public function isBinary(): bool
    {
        return $this->opcode === self::OPCODE_BINARY;
    }

    public function isClose(): bool
    {
        return $this->opcode === self::OPCODE_CLOSE;
    }

    public function isPing(): bool
    {
        return $this->opcode === self::OPCODE_PING;
    }

    public function isPong(): bool
    {
        return $this->opcode === self::OPCODE_PONG;
    }

    /**
     * Extract the close status code from a close frame payload.
     *
     * @return int|null The 2-byte status code, or null if payload is empty
     */
    public function getCloseCode(): ?int
    {
        if ($this->opcode !== self::OPCODE_CLOSE || \strlen($this->payload) < 2) {
            return null;
        }

        return \unpack('n', $this->payload)[1];
    }

    /**
     * Extract the close reason from a close frame payload.
     *
     * @return string Close reason text (may be empty)
     */
    public function getCloseReason(): string
    {
        if ($this->opcode !== self::OPCODE_CLOSE || \strlen($this->payload) <= 2) {
            return '';
        }

        return \substr($this->payload, 2);
    }

    // ── Encoding ─────────────────────────────────────────────────

    /**
     * Encode this frame into a binary wire-format string.
     *
     * @param bool $mask Whether to apply masking (required for client → server)
     *
     * @return string Binary frame data
     */
    public function encode(bool $mask = false): string
    {
        $payload = $this->payload;
        $length = \strlen($payload);

        // Byte 1: FIN + RSV(0,0,0) + opcode
        $byte1 = ($this->fin ? 0x80 : 0x00) | ($this->opcode & 0x0F);

        // Byte 2: MASK + payload length
        $maskBit = $mask ? 0x80 : 0x00;

        if ($length <= 125) {
            $header = \pack('CC', $byte1, $maskBit | $length);
        } elseif ($length <= 65535) {
            $header = \pack('CCn', $byte1, $maskBit | 126, $length);
        } else {
            $header = \pack('CC', $byte1, $maskBit | 127) . self::packUint64($length);
        }

        if ($mask) {
            $maskKey = \random_bytes(4);
            $header .= $maskKey;
            $payload = self::applyMask($payload, $maskKey);
        }

        return $header . $payload;
    }
}
