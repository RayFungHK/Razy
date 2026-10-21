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

use Razy\Contract\Log\LogFormatterInterface;

/**
 * RZ-14: the classic text line, `[timestamp] [channel] [LEVEL] message`, plus
 * ` rid=<id>` when the record carries a request id. Newlines in the message
 * are flattened (no log injection).
 *
 * @license MIT
 */
final class LineFormatter implements LogFormatterInterface
{
    /**
     * ` rid=<id>` when the context carries a request id, else ''.
     *
     * @param array<array-key, mixed> $context
     */
    public static function requestIdSuffix(array $context): string
    {
        $id = $context['request_id'] ?? null;

        return \is_string($id) && $id !== '' ? ' rid=' . \preg_replace('/[^A-Za-z0-9._:\-]/', '', $id) : '';
    }

    public function format(string $level, string $message, array $context, string $timestamp, string $channel): string
    {
        $channelTag = $channel !== '' ? "[{$channel}] " : '';
        $line = '[' . $timestamp . '] ' . $channelTag . '[' . \strtoupper($level) . '] '
            . \str_replace(["\r\n", "\r", "\n"], ' ', $message);

        return $line . self::requestIdSuffix($context);
    }
}
