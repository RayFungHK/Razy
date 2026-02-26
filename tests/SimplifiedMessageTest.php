<?php
/**
 * Unit tests for Razy\SimplifiedMessage.
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Razy\SimplifiedMessage;

#[CoversClass(SimplifiedMessage::class)]
class SimplifiedMessageTest extends TestCase
{
    // ?�?� Constructor ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function constructorAcceptsValidCommand(): void
    {
        $msg = new SimplifiedMessage('connect');
        $this->assertSame('CONNECT', $msg->getCommand());
    }

    #[Test]
    public function constructorNormalisesToUpperCase(): void
    {
        $msg = new SimplifiedMessage('hello');
        $this->assertSame('HELLO', $msg->getCommand());
    }

    #[Test]
    public function constructorAcceptsCommandWithDigitsAndUnderscores(): void
    {
        $msg = new SimplifiedMessage('cmd_2');
        $this->assertSame('CMD_2', $msg->getCommand());
    }

    #[Test]
    public function constructorTrimsWhitespace(): void
    {
        $msg = new SimplifiedMessage('  send  ');
        $this->assertSame('SEND', $msg->getCommand());
    }

    #[Test]
    public function constructorThrowsOnEmptyCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SimplifiedMessage('   ');
    }

    #[Test]
    public function constructorThrowsOnNumericOnlyCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SimplifiedMessage('1234');
    }

    // ?�?� Setters / Getters ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function setBodyReturnsFluentInterface(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $result = $msg->setBody('payload');
        $this->assertSame($msg, $result);
    }

    #[Test]
    public function getBodyReturnsSetBody(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $msg->setBody('hello world');
        $this->assertSame('hello world', $msg->getBody());
    }

    #[Test]
    public function bodyDefaultsToEmpty(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $this->assertSame('', $msg->getBody());
    }

    #[Test]
    public function setHeaderReturnsFluentInterface(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $result = $msg->setHeader('key', 'value');
        $this->assertSame($msg, $result);
    }

    #[Test]
    public function getHeaderReturnsSetValue(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $msg->setHeader('content_type', 'text/plain');
        $this->assertSame('text/plain', $msg->getHeader('content_type'));
    }

    #[Test]
    public function getHeaderReturnsNullForMissingKey(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $this->assertNull($msg->getHeader('nonexistent'));
    }

    #[Test]
    public function setHeaderOverwritesPreviousValue(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $msg->setHeader('key', 'old');
        $msg->setHeader('key', 'new');
        $this->assertSame('new', $msg->getHeader('key'));
    }

    #[Test]
    public function setHeaderThrowsOnEmptyKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $msg = new SimplifiedMessage('CMD');
        $msg->setHeader('   ', 'value');
    }

    #[Test]
    public function setHeaderThrowsOnInvalidKeyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $msg = new SimplifiedMessage('CMD');
        $msg->setHeader('!@#', 'value');
    }

    // ?�?� getMessage ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function getMessageWithCommandOnly(): void
    {
        $msg = new SimplifiedMessage('PING');
        $expected = "PING\r\n\r\n\0\r\n";
        $this->assertSame($expected, $msg->getMessage());
    }

    #[Test]
    public function getMessageWithBody(): void
    {
        $msg = new SimplifiedMessage('SEND');
        $msg->setBody('Hello');
        $expected = "SEND\r\n\r\nHello\0\r\n";
        $this->assertSame($expected, $msg->getMessage());
    }

    #[Test]
    public function getMessageWithSingleHeader(): void
    {
        $msg = new SimplifiedMessage('SEND');
        $msg->setHeader('destination', '/queue/test');
        $msg->setBody('body');
        $expected = "SEND\r\ndestination:/queue/test\r\n\r\nbody\0\r\n";
        $this->assertSame($expected, $msg->getMessage());
    }

    #[Test]
    public function getMessageWithMultipleHeaders(): void
    {
        $msg = new SimplifiedMessage('MESSAGE');
        $msg->setHeader('id', '42');
        $msg->setHeader('type', 'text');
        $msg->setBody('payload');

        $result = $msg->getMessage();

        $this->assertStringStartsWith("MESSAGE\r\n", $result);
        $this->assertStringContainsString("id:42\r\n", $result);
        $this->assertStringContainsString("type:text\r\n", $result);
        $this->assertStringEndsWith("\r\npayload\0\r\n", $result);
    }

    // ?�?� Encode / Decode ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function encodeEscapesColons(): void
    {
        // Encode replaces : ??\c then \ ??\\
        // So 'key:value' ??'key\cvalue' ??'key\\cvalue'
        $this->assertSame("key\\\\cvalue", SimplifiedMessage::encode('key:value'));
    }

    #[Test]
    public function encodeEscapesBackslashes(): void
    {
        $this->assertSame('path\\\\file', SimplifiedMessage::encode('path\\file'));
    }

    #[Test]
    public function encodeEscapesBothColonAndBackslash(): void
    {
        // Input literal: a:\b
        // Step 1 (: ??\c): a\c\b
        // Step 2 (\ ??\\): a\\c\\b
        $this->assertSame('a\\\\c\\\\b', SimplifiedMessage::encode('a:\\b'));
    }

    #[Test]
    public function decodeReversesColonEscape(): void
    {
        $this->assertSame('key:value', SimplifiedMessage::decode('key\\cvalue'));
    }

    #[Test]
    public function decodeReversesBackslashEscape(): void
    {
        $this->assertSame('path\\file', SimplifiedMessage::decode('path\\\\file'));
    }

    #[Test]
    public function encodeDecodeRoundTripBackslashOnly(): void
    {
        // Round-trip works for backslash-only content (no colons)
        $original = 'path\\file';
        $this->assertSame($original, SimplifiedMessage::decode(SimplifiedMessage::encode($original)));
    }

    #[Test]
    public function encodeDecodeRoundTripPlainText(): void
    {
        $original = 'plain text 123';
        $this->assertSame($original, SimplifiedMessage::decode(SimplifiedMessage::encode($original)));
    }

    #[Test]
    public function encodePreservesPlainText(): void
    {
        $this->assertSame('hello world', SimplifiedMessage::encode('hello world'));
    }

    #[Test]
    public function decodePreservesPlainText(): void
    {
        $this->assertSame('hello world', SimplifiedMessage::decode('hello world'));
    }

    // ?�?� Fetch (parsing) ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function fetchParsesFullMessage(): void
    {
        $raw = "SEND\r\ndestination:/queue/a\r\n\r\nHello\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('SEND', $msg->getCommand());
        $this->assertSame('/queue/a', $msg->getHeader('destination'));
        $this->assertSame('Hello', $msg->getBody());
    }

    #[Test]
    public function fetchParsesMessageWithMultipleHeaders(): void
    {
        $raw = "MESSAGE\r\nid:42\r\ntype:text\r\n\r\nbody\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('MESSAGE', $msg->getCommand());
        $this->assertSame('42', $msg->getHeader('id'));
        $this->assertSame('text', $msg->getHeader('type'));
        $this->assertSame('body', $msg->getBody());
    }

    #[Test]
    public function fetchParsesMessageWithNoHeaders(): void
    {
        $raw = "PING\r\n\r\n\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('PING', $msg->getCommand());
        $this->assertSame('', $msg->getBody());
    }

    #[Test]
    public function fetchParsesMessageWithEmptyBody(): void
    {
        $raw = "ACK\r\nid:1\r\n\r\n\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('ACK', $msg->getCommand());
        $this->assertSame('1', $msg->getHeader('id'));
        $this->assertSame('', $msg->getBody());
    }

    #[Test]
    public function fetchParsesMultilineBody(): void
    {
        $body = "line1\r\nline2\r\nline3";
        $raw = "DATA\r\n\r\n{$body}\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('DATA', $msg->getCommand());
        $this->assertSame($body, $msg->getBody());
    }

    #[Test]
    public function fetchReturnsDefaultCommandForInvalidInput(): void
    {
        $msg = SimplifiedMessage::fetch('garbage data');
        $this->assertSame('COMMAND', $msg->getCommand());
    }

    #[Test]
    public function fetchReturnsDefaultCommandForEmptyString(): void
    {
        $msg = SimplifiedMessage::fetch('');
        $this->assertSame('COMMAND', $msg->getCommand());
    }

    // ?�?� Round-trip: build ??getMessage ??Fetch ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function roundTripPreservesFullMessage(): void
    {
        $original = new SimplifiedMessage('SUBSCRIBE');
        $original->setHeader('id', '0');
        $original->setHeader('destination', '/topic/test');
        $original->setBody('optional body');

        $parsed = SimplifiedMessage::fetch($original->getMessage());

        $this->assertSame($original->getCommand(), $parsed->getCommand());
        $this->assertSame($original->getHeader('id'), $parsed->getHeader('id'));
        $this->assertSame($original->getHeader('destination'), $parsed->getHeader('destination'));
        $this->assertSame($original->getBody(), $parsed->getBody());
    }

    #[Test]
    public function roundTripCommandOnlyMessage(): void
    {
        $original = new SimplifiedMessage('HEARTBEAT');
        $parsed = SimplifiedMessage::fetch($original->getMessage());

        $this->assertSame('HEARTBEAT', $parsed->getCommand());
        $this->assertSame('', $parsed->getBody());
    }

    // ?�?� Edge cases ?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�?�

    #[Test]
    public function headerValueCanContainSpecialCharacters(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $msg->setHeader('path', '/a/b/c?x=1&y=2');
        $this->assertSame('/a/b/c?x=1&y=2', $msg->getHeader('path'));
    }

    #[Test]
    public function bodyCanContainNullCharacterAfterBuild(): void
    {
        $msg = new SimplifiedMessage('CMD');
        $msg->setBody("before\0after");
        // Body stores the raw content; getMessage appends the terminator
        $this->assertSame("before\0after", $msg->getBody());
    }

    #[Test]
    public function commandWithUnderscore(): void
    {
        $msg = new SimplifiedMessage('MY_CMD');
        $this->assertSame('MY_CMD', $msg->getCommand());
    }

    #[Test]
    public function fluentChainingWorks(): void
    {
        $msg = (new SimplifiedMessage('CMD'))
            ->setHeader('a', '1')
            ->setHeader('b', '2')
            ->setBody('body');

        $this->assertSame('1', $msg->getHeader('a'));
        $this->assertSame('2', $msg->getHeader('b'));
        $this->assertSame('body', $msg->getBody());
    }

    #[Test]
    public function fetchWithCommandContainingDigits(): void
    {
        $raw = "CMD2\r\nkey:val\r\n\r\ndata\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('CMD2', $msg->getCommand());
        $this->assertSame('val', $msg->getHeader('key'));
        $this->assertSame('data', $msg->getBody());
    }

    #[Test]
    public function fetchWithCommandContainingUnderscore(): void
    {
        $raw = "MY_CMD\r\n\r\ndata\0\r\n";
        $msg = SimplifiedMessage::fetch($raw);

        $this->assertSame('MY_CMD', $msg->getCommand());
        $this->assertSame('data', $msg->getBody());
    }
}
