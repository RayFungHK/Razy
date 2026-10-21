<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Log;

use Razy\Contract\Log\LogFormatterInterface;
use Razy\Contract\Log\LogHandlerInterface;
use Razy\Contract\Log\LogLevel;

/**
 * Stderr log handler — writes log messages to STDERR.
 *
 * Useful for container/cloud environments where logs are captured from
 * standard error output. RZ-14: pass a {@see JsonFormatter} for one JSON
 * object per line; the default {@see LineFormatter} keeps the text format.
 * Works under every SAPI (FPM and FrankenPHP have no STDERR constant, so the
 * handler opens `php://stderr` itself).
 */
class StderrHandler implements LogHandlerInterface
{
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

    private LogFormatterInterface $formatter;

    /** @var resource|null */
    private $stream;

    /**
     * @param string $minLevel Minimum log level to handle
     * @param LogFormatterInterface|null $formatter Line format (default: {@see LineFormatter})
     * @param resource|null $stream Where to write (default: stderr)
     */
    public function __construct(
        private string $minLevel = LogLevel::DEBUG,
        ?LogFormatterInterface $formatter = null,
        $stream = null,
    ) {
        $this->formatter = $formatter ?? new LineFormatter();
        $this->stream = \is_resource($stream) ? $stream : null;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(string $level, string $message, array $context, string $timestamp, string $channel): void
    {
        if (!$this->isHandling($level)) {
            return;
        }

        $stream = $this->stream ??= (\defined('STDERR') ? STDERR : \fopen('php://stderr', 'w'));
        if (\is_resource($stream)) {
            \fwrite($stream, $this->formatter->format($level, $message, $context, $timestamp, $channel) . PHP_EOL);
        }
    }

    /**
     * The formatter in use.
     */
    public function getFormatter(): LogFormatterInterface
    {
        return $this->formatter;
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
