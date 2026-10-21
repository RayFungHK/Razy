<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Contract\Log;

/**
 * RZ-14: turns one log record into one line of output (no trailing newline).
 *
 * @license MIT
 */
interface LogFormatterInterface
{
    /**
     * @param string $level PSR-3 level
     * @param string $message Interpolated message
     * @param array<array-key, mixed> $context Record context (LogContext fields included)
     * @param string $timestamp `Y-m-d H:i:s.u` local time
     * @param string $channel Channel name ('' when none)
     */
    public function format(string $level, string $message, array $context, string $timestamp, string $channel): string;
}
