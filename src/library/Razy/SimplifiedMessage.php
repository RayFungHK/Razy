<?php

namespace Razy;

class SimplifiedMessage
{
    private string $command = '';
    private array $header = [];
    private string $body = '';

    /**
     * @param string $text
     * @return string
     */
    public static function Encode(string $text): string
    {
        return str_replace('\\', '\\\\', str_replace(':', '\\c', $text));
    }

    /**
     * @param string $text
     * @return string
     */
    public static function Decode(string $text): string
    {
        return str_replace('\\\\', '\\', str_replace('\\c', ':', $text));
    }

    /**
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
     * @param string $key
     * @return string|null
     */
    public function getHeader(string $key): ?string
    {
        return $this->header[$key] ?? null;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @param string $value
     * @return SimplifiedMessage
     */
    public function setBody(string $value): self
    {
        $this->body = $value;

        return $this;
    }

    /**
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

    /**
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
        $clips = explode("\r\n", $message);

        return new self('COMMAND');
    }
}
