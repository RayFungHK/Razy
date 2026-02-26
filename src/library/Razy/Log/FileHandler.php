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

namespace Razy\Log;

use DateTimeImmutable;
use Razy\Contract\Log\LogHandlerInterface;
use Razy\Contract\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * File-based log handler.
 *
 * Writes log entries to date-based files in a specified directory.
 * Uses LOCK_EX for thread-safe writes. Mirrors the core Logger's
 * file output behaviour but as a standalone handler.
 *
 * @package Razy\Log
 */
class FileHandler implements LogHandlerInterface
{
    /**
     * PSR-3 log level severity map.
     */
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param string $directory Log file directory
     * @param string $minLevel Minimum log level to handle
     * @param string $filenamePattern Date format for filename (default: 'Y-m-d')
     */
    public function __construct(
        private readonly string $directory,
        private string $minLevel = LogLevel::DEBUG,
        private readonly string $filenamePattern = 'Y-m-d',
    ) {
        if (!\is_dir($this->directory)) {
            \mkdir($this->directory, 0o775, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handle(string $level, string $message, array $context, string $timestamp, string $channel): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $filename = (new DateTimeImmutable())->format($this->filenamePattern) . '.log';
        $filepath = $this->directory . DIRECTORY_SEPARATOR . $filename;

        $levelUpper = \strtoupper($level);
        $channelTag = $channel !== '' ? "[{$channel}] " : '';
        $line = "[{$timestamp}] {$channelTag}[{$levelUpper}] {$message}";

        // Append extra context
        $extra = $this->getExtraContext($context);
        if (!empty($extra)) {
            $line .= ' ' . \json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line .= PHP_EOL;

        \file_put_contents($filepath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(string $level): bool
    {
        return $this->getLevelPriority($level) >= $this->getLevelPriority($this->minLevel);
    }

    /**
     * Get the log directory.
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Get the minimum level.
     */
    public function getMinLevel(): string
    {
        return $this->minLevel;
    }

    /**
     * Set the minimum level.
     */
    public function setMinLevel(string $level): void
    {
        $this->minLevel = $level;
    }

    private function getLevelPriority(string $level): int
    {
        return self::LEVEL_PRIORITY[$level] ?? 0;
    }

    private function getExtraContext(array $context): array
    {
        $extra = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof Throwable) {
                $extra['exception'] = [
                    'class' => \get_class($value),
                    'message' => $value->getMessage(),
                    'code' => $value->getCode(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
                continue;
            }
            if (!\is_scalar($value) && $value !== null && !($value instanceof Stringable)) {
                $extra[$key] = $value;
            }
        }

        return $extra;
    }
}
