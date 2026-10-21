<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Http;

use InvalidArgumentException;
use Razy\RuntimeMode;

/**
 * A complete answer to a request, sent by the framework (RZ-12).
 *
 * A request resolver (`$agent->resolveRequest()`) returns one to end the
 * request before routing: unknown host → 404, wrong host → redirect, store
 * down → 503. The dist's security headers (RZ-10) are already sent; headers
 * set here are added after them and replace a header of the same name.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /**
     * @param int $status HTTP status code (100-599)
     * @param string $body Response body, sent as is
     * @param array<string, string> $headers Header name => value
     *
     * @throws InvalidArgumentException On a bad status, header name or value
     */
    public function __construct(private readonly int $status = 200, private readonly string $body = '', array $headers = [])
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException("Response status must be 100-599, got {$status}.");
        }
        foreach ($headers as $name => $value) {
            $this->headers[self::checkName((string) $name)] = self::checkValue((string) $value);
        }
    }

    /**
     * A plain-text 404 that names nothing internal.
     */
    public static function notFound(string $body = 'Not found'): self
    {
        return new self(404, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * A redirect (302 by default) to a URL or a local path.
     *
     * @throws InvalidArgumentException On a status that is not a redirect, or CR/LF in the URL
     */
    public static function redirect(string $location, int $status = 302): self
    {
        if (!\in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("Redirect status must be 301, 302, 303, 307 or 308, got {$status}.");
        }

        return new self($status, '', ['Location' => $location]);
    }

    private static function checkName(string $name): string
    {
        if (!\preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid response header name '{$name}'.");
        }

        return $name;
    }

    private static function checkValue(string $value): string
    {
        if (\preg_match('/[\r\n\x00]/', $value)) {
            throw new InvalidArgumentException('Response header values must not contain CR, LF or NUL.');
        }

        return $value;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Send the status, headers (web requests, before output) and body.
     */
    public function send(): void
    {
        if (RuntimeMode::isWeb() && !Headers::sent()) {
            Headers::status($this->status);
            foreach ($this->headers as $name => $value) {
                Headers::send($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
