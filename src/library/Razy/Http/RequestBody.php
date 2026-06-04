<?php

declare(strict_types=1);

namespace Razy\Http;

/**
 * Read and decode the current HTTP request body (typically JSON POST).
 */
final class RequestBody
{
    /**
     * @return array<string, mixed>
     */
    public static function jsonArray(?string $raw = null): array
    {
        if ($raw === null) {
            $raw = \file_get_contents('php://input');
        }
        if (!\is_string($raw) || \trim($raw) === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
