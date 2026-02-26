<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Lightweight STOMP-like message protocol for structured inter-process or
 * WebSocket communication with command, headers, and body.
 *
 *
 * @license MIT
 */

namespace Razy;

use InvalidArgumentException;

/**
 * Simplified STOMP-like message builder and parser.
 *
 * Provides encoding/decoding of messages in a simplified STOMP format:
 * COMMAND\r\n[header:value\r\n]*\r\nbody\0\r\n
 * Supports escape sequences for special characters within header and body content.
 *
 * @class SimplifiedMessage
 */
class SimplifiedMessage
{
    /** @var string The message body content */
    private string $body = '';

    /** @var array<string, string> Key-value header pairs */
    private array $header = [];

    /**
     * SimplifiedMessage Constructor.
     *
     * @param string $command
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private string $command)
    {
        // Normalize command to uppercase and validate alphanumeric word format
        $this->command = \trim(\strtoupper($this->command));
        if (!\preg_match('/[a-z]\w*/i', $this->command)) {
            throw new InvalidArgumentException('Invalid command format.');
        }
    }

    /**
     * Replace specified escape character into slash or colon.
     *
     * @param string $text
     *
     * @return string
     */
    public static function decode(string $text): string
    {
        // Reverse the encoding: \c → colon, \\ → backslash
        return \str_replace('\\\\', '\\', \str_replace('\c', ':', $text));
    }

    /**
     * Replace slash and colon into an escape character.
     *
     * @param string $text
     *
     * @return string
     */
    public static function encode(string $text): string
    {
        // Escape colons and backslashes to avoid conflicts with the message format
        return \str_replace('\\', '\\\\', \str_replace(':', '\c', $text));
    }

    /**
     * Convert STOMP message into a SimplifiedMessage entity.
     *
     * @param string $message
     *
     * @return static
     *
     * @throws InvalidArgumentException
     */
    public static function fetch(string $message): static
    {
        // Match the STOMP-like frame: COMMAND\r\n[headers]\r\nbody\0\r\n
        // Group 1: command name, Group 2: header lines, Group 3: body content
        if (\preg_match('/^([A-Z][A-Z0-9_]*)\r\n(\w+:.*\r\n)*\r\n(.*)\0\r\n$/sm', $message, $matches)) {
            $simplifiedMessage = new static($matches[1]);
            if ($matches[2]) {
                // Parse individual header lines formatted as 'key:value\r\n'
                \preg_match_all('/(\w+):(.*)\r\n/', $matches[2], $headers, PREG_SET_ORDER);
                foreach ($headers as $header) {
                    [, $key, $value] = $header;
                    $simplifiedMessage->setHeader($key, $value);
                }
            }
            $simplifiedMessage->setBody($matches[3]);

            return $simplifiedMessage;
        }

        // Return a default COMMAND message if the input doesn't match the expected format
        return new static('COMMAND');
    }

    /**
     * Get the body message.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set the body message.
     *
     * @param string $value
     *
     * @return $this
     */
    public function setBody(string $value): self
    {
        $this->body = $value;

        return $this;
    }

    /**
     * Get the command.
     *
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Get the header's value by given key.
     *
     * @param string $key
     *
     * @return string|null
     */
    public function getHeader(string $key): ?string
    {
        return $this->header[$key] ?? null;
    }

    /**
     * Set the header's value.
     *
     * @param string $key
     * @param string $value
     *
     * @return SimplifiedMessage
     *
     * @throws InvalidArgumentException
     */
    public function setHeader(string $key, string $value): self
    {
        $key = \trim($key);
        if (!\preg_match('/\w+/i', $key)) {
            throw new InvalidArgumentException('Invalid header key format.');
        }
        $this->header[$key] = $value;

        return $this;
    }

    /**
     * Get the STOMP message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        // Build the STOMP-like frame: COMMAND + headers + blank line + body + NULL terminator
        $message = $this->command . "\r\n";
        if (\count($this->header) > 0) {
            foreach ($this->header as $key => $value) {
                $message .= $key . ':' . $value . "\r\n";
            }
        }
        // Body is terminated by a NULL character (\0) followed by CRLF
        $message .= "\r\n" . $this->body . "\0\r\n";

        return $message;
    }
}
