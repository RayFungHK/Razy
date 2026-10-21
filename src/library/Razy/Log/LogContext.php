<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Log;

/**
 * RZ-14: request-scoped fields added to every log record.
 *
 * `Razy\Logger` and `Razy\Log\LogManager` merge these fields into each
 * record's context (fields passed to the log call win). The framework sets
 * `request_id` at the start of every web request ({@see \Razy\Http\RequestId::begin()})
 * and clears the bag between worker requests, so a field set by one request
 * never shows up in the next. Apps add their own (`tenant`, `user_id`) with
 * {@see set()} in a request resolver or middleware.
 *
 * @license MIT
 */
final class LogContext
{
    /** @var array<string, scalar|null> */
    private static array $fields = [];

    /**
     * Set a field for the rest of this request.
     *
     * @param string $key Field name
     * @param scalar|null $value Field value (scalars only, so every handler can print it)
     */
    public static function set(string $key, string|int|float|bool|null $value): void
    {
        self::$fields[$key] = $value;
    }

    /**
     * Get a field, or null when unset.
     */
    public static function get(string $key): string|int|float|bool|null
    {
        return self::$fields[$key] ?? null;
    }

    /**
     * Remove a field.
     */
    public static function remove(string $key): void
    {
        unset(self::$fields[$key]);
    }

    /**
     * All fields.
     *
     * @return array<string, scalar|null>
     */
    public static function all(): array
    {
        return self::$fields;
    }

    /**
     * Merge the fields under a record's own context (the record's keys win).
     *
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    public static function apply(array $context): array
    {
        return self::$fields === [] ? $context : $context + self::$fields;
    }

    /**
     * Clear every field (start of each request, tests).
     */
    public static function reset(): void
    {
        self::$fields = [];
    }
}
