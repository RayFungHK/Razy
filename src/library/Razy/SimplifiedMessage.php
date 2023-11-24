<?php

namespace Razy;

class SimplifiedMessage
{
    /**
     * The string of body
     * @var string
     */
    private string $body = '';
    /**
     * The string of command
     * @var string
     */
    private string $command = '';
    /**
     * The storage of headers
     * @var array
     */
    private array $header = [];

    /**
     * @param string $command
     * @throws Error
     */
    public function __construct(string $command)
    {
        $command = trim(strtoupper($command));
        if (!preg_match('/[a-z]\w*/i', $command)) {
            throw new Error('Invalid command format.');
        }
        $this->command = $command;
    }

    /**
     * Replace specified escape character into slash or colon
     *
     * @param string $text
     * @return string
     */
    public static function Decode(string $text): string
    {
        return str_replace('\\\\', '\\', str_replace('\\c', ':', $text));
    }

    /**
     * Replace slash and colon into an escape character
     *
     * @param string $text
     * @return string
     */
    public static function Encode(string $text): string
    {
        return str_replace('\\', '\\\\', str_replace(':', '\\c', $text));
    }

    /**
     * Convert STOMP message into a SimplifiedMessage entity
     *
     * @param string $message
     * @return static
     * @throws Error
     */
    public static function Fetch(string $message): self
    {
        // Get the command
        if (preg_match('/^([A-Z][A-Z0-9_]*)\r\n(\w+:.*\r\n)*\r\n(.*)\0\r\n$/sm', $message, $matches)) {
            $simplifiedMessage = new self($matches[1]);
            if ($matches[2]) {
                preg_match_all('/(\w+):(.*)\r\n/', $matches[2], $headers, PREG_SET_ORDER);
                foreach ($headers as $header) {
                    list(, $key, $value) = $header;
                    $simplifiedMessage->setHeader($key, $value);
                }
            }
            $simplifiedMessage->setBody($matches[3]);

            return $simplifiedMessage;
        }

        return new self('COMMAND');
    }

    /**
     * Get the body message
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set the body message
     *
     * @param string $value
     * @return $this
     */
    public function setBody(string $value): self
    {
        $this->body = $value;

        return $this;
    }

    /**
     * Get the command
     *
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Get the header's value by given key
     *
     * @param string $key
     * @return string|null
     */
    public function getHeader(string $key): ?string
    {
        return $this->header[$key] ?? null;
    }

    /**
     * Set the header's value
     *
     * @param string $key
     * @param string $value
     * @return SimplifiedMessage
     * @throws Error
     */
    public function setHeader(string $key, string $value): self
    {
        $key = trim($key);
        if (!preg_match('/\w+/i', $key)) {
            throw new Error('Invalid header key format.');
        }
        $this->header[$key] = $value;

        return $this;
    }

    /**
     * Get the STOMP message
     *
     * @return string
     */
    public function getMessage(): string
    {
        $message = $this->command . "\r\n";
        if (count($this->header) > 0) {
            foreach ($this->header as $key => $value) {
                $message .= $key . ':' . $value . "\r\n";
            }
        }
        $message .= "\r\n" . $this->body . "\0\r\n";

        return $message;
    }
}
