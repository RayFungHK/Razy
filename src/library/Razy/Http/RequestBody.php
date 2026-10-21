<?php

declare(strict_types=1);

namespace Razy\Http;

/**
 * Read and decode the current HTTP request body (typically JSON POST).
 */
final class RequestBody
{
    /** RZ-15: body supplied by the in-process test client instead of php://input */
    private static ?string $override = null;

    /**
     * RZ-15: the raw request body (`php://input`, or the test client's body).
     */
    public static function raw(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $raw = \file_get_contents('php://input');

        return \is_string($raw) ? $raw : '';
    }

    /**
     * RZ-15: make raw() return $body (null = read php://input again). For the test client.
     *
     * @internal
     */
    public static function override(?string $body): void
    {
        self::$override = $body;
    }

    /**
     * @return array<string, mixed>
     */
    public static function jsonArray(?string $raw = null): array
    {
        if ($raw === null) {
            $raw = self::raw();
        }
        if (\trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
