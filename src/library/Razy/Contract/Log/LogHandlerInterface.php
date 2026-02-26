<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Contract\Log;

/**
 * Contract for a log handler — receives formatted log records.
 *
 * Handlers are the output destinations for log messages: files, stderr,
 * syslog, external services, etc. A LogManager dispatches messages
 * to one or more handlers based on channel configuration.
 *
 * @package Razy\Contract\Log
 */
interface LogHandlerInterface
{
    /**
     * Handle a log record.
     *
     * @param string $level PSR-3 log level
     * @param string $message Interpolated message
     * @param array $context Original context array
     * @param string $timestamp Formatted timestamp string
     * @param string $channel The channel name
     */
    public function handle(string $level, string $message, array $context, string $timestamp, string $channel): void;

    /**
     * Whether this handler handles records at the given level.
     *
     * @param string $level PSR-3 log level
     *
     * @return bool
     */
    public function isHandling(string $level): bool;
}
