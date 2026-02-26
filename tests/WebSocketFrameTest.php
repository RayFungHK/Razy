<?php

/**
 * Unit tests for Razy\WebSocket\Frame.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\WebSocket\Frame;

#[CoversClass(Frame::class)]
class WebSocketFrameTest extends TestCase
{
    // ── Constructor ──────────────────────────────────────────────

    #[Test]
    public function constructorSetsFields(): void
    {
        $frame = new Frame(Frame::OPCODE_TEXT, 'hello', true, false);
        $this->assertSame(Frame::OPCODE_TEXT, $frame->getOpcode());
        $this->assertSame('hello', $frame->getPayload());
        $this->assertTrue($frame->isFin());
        $this->assertFalse($frame->isMasked());
    }

    #[Test]
    public function constructorRejectsInvalidOpcode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Frame(0x10);
    }

    #[Test]
    public function constructorAcceptsMaxOpcode(): void
    {
        $frame = new Frame(0xF);
        $this->assertSame(0xF, $frame->getOpcode());
    }

    // ── Getters ──────────────────────────────────────────────────

    #[Test]
    public function getPayloadLength(): void
    {
        $frame = Frame::text('abc');
        $this->assertSame(3, $frame->getPayloadLength());
    }

    #[Test]
    public function opcodeNameReturnsKnownLabels(): void
    {
        $this->assertSame('text', Frame::text('x')->getOpcodeName());
        $this->assertSame('binary', Frame::binary('x')->getOpcodeName());
        $this->assertSame('ping', Frame::ping()->getOpcodeName());
        $this->assertSame('pong', Frame::pong()->getOpcodeName());
        $this->assertSame('close', Frame::close()->getOpcodeName());
    }

    #[Test]
    public function opcodeNameReturnsUnknownForReserved(): void
    {
        $frame = new Frame(0x3);
        $this->assertStringContainsString('unknown', $frame->getOpcodeName());
    }

    #[Test]
    public function isControlDetectsControlFrames(): void
    {
        $this->assertTrue(Frame::ping()->isControl());
        $this->assertTrue(Frame::pong()->isControl());
        $this->assertTrue(Frame::close()->isControl());
        $this->assertFalse(Frame::text('x')->isControl());
        $this->assertFalse(Frame::binary('x')->isControl());
    }

    #[Test]
    public function isTextAndIsBinary(): void
    {
        $this->assertTrue(Frame::text('a')->isText());
        $this->assertFalse(Frame::text('a')->isBinary());
        $this->assertTrue(Frame::binary('b')->isBinary());
        $this->assertFalse(Frame::binary('b')->isText());
    }

    #[Test]
    public function isCloseIsPingIsPong(): void
    {
        $this->assertTrue(Frame::close()->isClose());
        $this->assertTrue(Frame::ping()->isPing());
        $this->assertTrue(Frame::pong()->isPong());
    }

    // ── Close frame extras ───────────────────────────────────────

    #[Test]
    public function closeFrameContainsCodeAndReason(): void
    {
        $frame = Frame::close(1001, 'Going Away');
        $this->assertSame(1001, $frame->getCloseCode());
        $this->assertSame('Going Away', $frame->getCloseReason());
    }

    #[Test]
    public function closeCodeNullForEmptyPayload(): void
    {
        $frame = new Frame(Frame::OPCODE_CLOSE, '');
        $this->assertNull($frame->getCloseCode());
        $this->assertSame('', $frame->getCloseReason());
    }

    #[Test]
    public function closeCodeOnlyPayloadReturnsCodeNoReason(): void
    {
        $frame = new Frame(Frame::OPCODE_CLOSE, \pack('n', 1000));
        $this->assertSame(1000, $frame->getCloseCode());
        $this->assertSame('', $frame->getCloseReason());
    }

    #[Test]
    public function getCloseCodeReturnsNullForNonCloseFrame(): void
    {
        $this->assertNull(Frame::text('x')->getCloseCode());
    }

    // ── Encoding / Decoding roundtrip ────────────────────────────

    #[Test]
    public function encodeDecodeUnmaskedTextFrame(): void
    {
        $original = Frame::text('Hello, WebSocket!');
        $binary = $original->encode(false);

        $result = Frame::decode($binary);
        $this->assertNotNull($result);

        [$decoded, $consumed] = $result;
        $this->assertSame(\strlen($binary), $consumed);
        $this->assertSame(Frame::OPCODE_TEXT, $decoded->getOpcode());
        $this->assertTrue($decoded->isFin());
        $this->assertSame('Hello, WebSocket!', $decoded->getPayload());
        $this->assertFalse($decoded->isMasked());
    }

    #[Test]
    public function encodeDecodeMaskedTextFrame(): void
    {
        $original = Frame::text('masked payload', true);
        $binary = $original->encode(true);

        $result = Frame::decode($binary);
        $this->assertNotNull($result);

        [$decoded, $consumed] = $result;
        $this->assertSame(\strlen($binary), $consumed);
        $this->assertSame('masked payload', $decoded->getPayload());
        $this->assertTrue($decoded->isMasked());
    }

    #[Test]
    public function encodeDecodeEmptyPayload(): void
    {
        $frame = Frame::text('');
        $binary = $frame->encode();

        $result = Frame::decode($binary);
        $this->assertNotNull($result);

        [$decoded, $consumed] = $result;
        $this->assertSame('', $decoded->getPayload());
        $this->assertSame(\strlen($binary), $consumed);
    }

    #[Test]
    public function encodeDecodeBinaryFrame(): void
    {
        $data = \random_bytes(256);
        $original = Frame::binary($data);
        $binary = $original->encode();

        $result = Frame::decode($binary);
        $this->assertNotNull($result);

        [$decoded] = $result;
        $this->assertSame(Frame::OPCODE_BINARY, $decoded->getOpcode());
        $this->assertSame($data, $decoded->getPayload());
    }

    #[Test]
    public function encodeDecodePingPong(): void
    {
        $ping = Frame::ping('heartbeat');
        [$decodedPing] = Frame::decode($ping->encode());
        $this->assertTrue($decodedPing->isPing());
        $this->assertSame('heartbeat', $decodedPing->getPayload());

        $pong = Frame::pong('heartbeat');
        [$decodedPong] = Frame::decode($pong->encode());
        $this->assertTrue($decodedPong->isPong());
        $this->assertSame('heartbeat', $decodedPong->getPayload());
    }

    #[Test]
    public function encodeDecodeCloseFrame(): void
    {
        $close = Frame::close(1000, 'Normal');
        [$decoded] = Frame::decode($close->encode());
        $this->assertTrue($decoded->isClose());
        $this->assertSame(1000, $decoded->getCloseCode());
        $this->assertSame('Normal', $decoded->getCloseReason());
    }

    // ── Medium payload (126-byte extended) ───────────────────────

    #[Test]
    public function encodeDecodeMediumPayload(): void
    {
        $data = \str_repeat('X', 300);
        $frame = Frame::text($data);
        $binary = $frame->encode();

        [$decoded, $consumed] = Frame::decode($binary);
        $this->assertSame(300, $decoded->getPayloadLength());
        $this->assertSame($data, $decoded->getPayload());
        $this->assertSame(\strlen($binary), $consumed);
    }

    // ── Large payload (64-bit extended length) ───────────────────

    #[Test]
    public function encodeDecodeLargePayload(): void
    {
        $data = \str_repeat('Y', 70000);
        $frame = Frame::text($data);
        $binary = $frame->encode();

        [$decoded, $consumed] = Frame::decode($binary);
        $this->assertSame(70000, $decoded->getPayloadLength());
        $this->assertSame($data, $decoded->getPayload());
        $this->assertSame(\strlen($binary), $consumed);
    }

    // ── Masked large payload ─────────────────────────────────────

    #[Test]
    public function encodeDecodeMaskedLargePayload(): void
    {
        $data = \str_repeat('Z', 70000);
        $frame = Frame::text($data, true);
        $binary = $frame->encode(true);

        [$decoded] = Frame::decode($binary);
        $this->assertSame($data, $decoded->getPayload());
    }

    // ── Incomplete buffer ────────────────────────────────────────

    #[Test]
    public function decodeReturnsNullOnEmptyBuffer(): void
    {
        $this->assertNull(Frame::decode(''));
    }

    #[Test]
    public function decodeReturnsNullOnSingleByte(): void
    {
        $this->assertNull(Frame::decode("\x81"));
    }

    #[Test]
    public function decodeReturnsNullOnIncompletePayload(): void
    {
        // Build a valid text frame header for 10 bytes, but only supply 5
        $frame = Frame::text(\str_repeat('A', 10));
        $binary = $frame->encode();
        $partial = \substr($binary, 0, 7); // header(2) + 5 payload bytes

        $this->assertNull(Frame::decode($partial));
    }

    #[Test]
    public function decodeReturnsNullOnIncompleteMediumHeader(): void
    {
        // 126-byte length needs 2 extra header bytes
        $frame = Frame::text(\str_repeat('B', 200));
        $binary = $frame->encode();
        $partial = \substr($binary, 0, 3); // Cut in the middle of the length field

        $this->assertNull(Frame::decode($partial));
    }

    #[Test]
    public function decodeReturnsNullOnIncompleteLargeHeader(): void
    {
        $frame = Frame::text(\str_repeat('C', 70000));
        $binary = $frame->encode();
        $partial = \substr($binary, 0, 5); // Cut in the middle of the 8-byte length field

        $this->assertNull(Frame::decode($partial));
    }

    #[Test]
    public function decodeReturnsNullOnIncompleteMaskKey(): void
    {
        // Masked frame: header (2) + mask-key (4) + payload
        $frame = Frame::text('Hi', true);
        $binary = $frame->encode(true);
        // Cut before the mask key is complete
        $partial = \substr($binary, 0, 4);

        $this->assertNull(Frame::decode($partial));
    }

    // ── Concatenated frames ──────────────────────────────────────

    #[Test]
    public function decodeTwoFramesFromConcatenatedBuffer(): void
    {
        $frame1 = Frame::text('first');
        $frame2 = Frame::text('second');
        $buffer = $frame1->encode() . $frame2->encode();

        [$decoded1, $consumed1] = Frame::decode($buffer);
        $this->assertSame('first', $decoded1->getPayload());

        $remaining = \substr($buffer, $consumed1);
        [$decoded2, $consumed2] = Frame::decode($remaining);
        $this->assertSame('second', $decoded2->getPayload());
        $this->assertSame(0, \strlen(\substr($remaining, $consumed2)));
    }

    // ── Non-FIN frames ───────────────────────────────────────────

    #[Test]
    public function encodeDecodeNonFinFrame(): void
    {
        $frame = new Frame(Frame::OPCODE_TEXT, 'partial', false, false);
        $binary = $frame->encode();

        [$decoded] = Frame::decode($binary);
        $this->assertFalse($decoded->isFin());
        $this->assertSame('partial', $decoded->getPayload());
    }

    // ── applyMask ────────────────────────────────────────────────

    #[Test]
    public function applyMaskIsSymmetric(): void
    {
        $data = 'Hello, masked world!';
        $key = \random_bytes(4);

        $masked = Frame::applyMask($data, $key);
        $unmasked = Frame::applyMask($masked, $key);
        $this->assertSame($data, $unmasked);
    }

    #[Test]
    public function applyMaskOnEmptyString(): void
    {
        $this->assertSame('', Frame::applyMask('', 'abcd'));
    }

    // ── Factory helpers ──────────────────────────────────────────

    #[Test]
    public function textFactoryCreatesTextFrame(): void
    {
        $frame = Frame::text('msg');
        $this->assertSame(Frame::OPCODE_TEXT, $frame->getOpcode());
        $this->assertTrue($frame->isFin());
        $this->assertSame('msg', $frame->getPayload());
    }

    #[Test]
    public function binaryFactoryCreatesBinaryFrame(): void
    {
        $frame = Frame::binary("\x00\x01");
        $this->assertSame(Frame::OPCODE_BINARY, $frame->getOpcode());
    }

    #[Test]
    public function pingFactoryCreatesPingFrame(): void
    {
        $frame = Frame::ping('p');
        $this->assertSame(Frame::OPCODE_PING, $frame->getOpcode());
        $this->assertSame('p', $frame->getPayload());
    }

    #[Test]
    public function pongFactoryCreatesPongFrame(): void
    {
        $frame = Frame::pong('p');
        $this->assertSame(Frame::OPCODE_PONG, $frame->getOpcode());
    }

    #[Test]
    public function closeFactoryCreatesCloseFrame(): void
    {
        $frame = Frame::close(1001, 'bye');
        $this->assertSame(Frame::OPCODE_CLOSE, $frame->getOpcode());
        $this->assertSame(1001, $frame->getCloseCode());
        $this->assertSame('bye', $frame->getCloseReason());
    }

    #[Test]
    public function closeFactoryDefaultCode(): void
    {
        $frame = Frame::close();
        $this->assertSame(1000, $frame->getCloseCode());
        $this->assertSame('', $frame->getCloseReason());
    }
}
