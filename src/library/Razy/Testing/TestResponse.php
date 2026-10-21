<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Testing;

use JsonException;

/**
 * RZ-15: what one {@see Client} request produced: status, headers, body.
 *
 * Plain accessors, no assertions, so it works with any test framework.
 *
 * @license MIT
 */
final class TestResponse
{
    /**
     * @param int $status HTTP status
     * @param list<array{0: string, 1: string}> $headers Header lines (name, value), in send order
     * @param string $body Everything the request printed
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
    ) {
    }

    /**
     * A `Max-Age <= 0` or an `Expires` in the past deletes the cookie.
     */
    private static function expired(string $attributes): bool
    {
        $maxAge = \preg_match('/(?:^|;)\s*max-age\s*=\s*(-?\d+)/i', ';' . $attributes, $m) === 1 ? (int) $m[1] : null;
        if ($maxAge !== null && $maxAge <= 0) {
            return true;
        }
        $expires = \preg_match('/(?:^|;)\s*expires\s*=\s*([^;]+)/i', ';' . $attributes, $m) === 1
            ? \strtotime(\trim($m[1]))
            : false;

        return $expires !== false && $expires <= \time();
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * Headers by lowercase name; a repeated header keeps every value.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[\strtolower($name)][] = $value;
        }

        return $out;
    }

    /**
     * The last value of a header (case-insensitive), or null.
     */
    public function header(string $name): ?string
    {
        $values = $this->headers()[\strtolower($name)] ?? [];

        return $values === [] ? null : $values[\count($values) - 1];
    }

    /**
     * The body decoded as JSON.
     *
     * @throws JsonException when the body is not JSON
     */
    public function json(): mixed
    {
        return \json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400 && $this->header('Location') !== null;
    }

    /**
     * Cookies this response set: name => value ('' when the cookie was deleted).
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        $cookies = [];
        foreach ($this->cookieChanges() as ['name' => $name, 'value' => $value]) {
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /**
     * Cookies this response told the client to drop: `Max-Age <= 0`, or an `Expires` in the past.
     *
     * @return list<string>
     */
    public function expiredCookies(): array
    {
        $expired = [];
        foreach ($this->cookieChanges() as ['name' => $name, 'delete' => $delete]) {
            if ($delete) {
                $expired[] = $name;
            }
        }

        return $expired;
    }

    /**
     * Every `Set-Cookie` line in send order, the way a cookie jar must apply it: a later
     * line for a name replaces an earlier one, and a delete only clears what came before it.
     *
     * @return list<array{name: string, value: string, delete: bool}>
     */
    public function cookieChanges(): array
    {
        $changes = [];
        foreach ($this->headers()['set-cookie'] ?? [] as $line) {
            [$pair, $attributes] = \array_pad(\explode(';', $line, 2), 2, '');
            [$name, $value] = \array_pad(\explode('=', $pair, 2), 2, '');
            $value = \rawurldecode(\trim($value));
            $changes[] = [
                'name' => \rawurldecode(\trim($name)),
                'value' => $value,
                'delete' => $value === '' || self::expired($attributes),
            ];
        }

        return $changes;
    }
}
