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
 * @license MIT
 */

namespace Razy\Log;

use Razy\Contract\Log\LogHandlerInterface;
use Razy\Contract\Log\LogLevel;

/**
 * Stderr log handler — writes log messages to STDERR.
 *
 * Useful for container/cloud environments where logs are captured from
 * standard error output.
 *
 * @package Razy\Log
 */
class StderrHandler implements LogHandlerInterface
{
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param string $minLevel Minimum log level to handle
     */
    public function __construct(
        private string $minLevel = LogLevel::DEBUG,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function handle(string $level, string $message, array $context, string $timestamp, string $channel): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $levelUpper = strtoupper($level);
        $channelTag = $channel !== '' ? "[{$channel}] " : '';
        $line = "[{$timestamp}] {$channelTag}[{$levelUpper}] {$message}" . PHP_EOL;

        fwrite(STDERR, $line);
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(string $level): bool
    {
        return $this->getLevelPriority($level) >= $this->getLevelPriority($this->minLevel);
    }

    /**
     * Get the minimum level.
     */
    public function getMinLevel(): string
    {
        return $this->minLevel;
    }

    private function getLevelPriority(string $level): int
    {
        return self::LEVEL_PRIORITY[$level] ?? 0;
    }
}
