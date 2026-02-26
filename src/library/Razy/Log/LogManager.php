<?php

/**
 * This file is part of Razy v0.5.
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

use DateTimeImmutable;
use DateTimeInterface;
use Razy\Contract\Log\InvalidArgumentException;
use Razy\Contract\Log\LoggerInterface;
use Razy\Contract\Log\LoggerTrait;
use Razy\Contract\Log\LogHandlerInterface;
use Razy\Contract\Log\LogLevel;
use Stringable;

/**
 * Multi-channel log manager.
 *
 * Manages named channels, each with its own set of handlers. Implements
 * PSR-3 LoggerInterface so it can be used as a drop-in replacement for
 * the core Logger class.
 *
 * Features:
 * - Named channels with per-channel handler stacks
 * - Default channel for convenience
 * - Stack channels broadcasting to multiple handlers
 * - In-memory buffer for programmatic access
 * - Channel-switching via channel() method
 *
 * Usage:
 * ```php
 * $logManager = new LogManager('app');
 *
 * // Add handlers to channels
 * $logManager->addHandler('app', new FileHandler('/logs'));
 * $logManager->addHandler('errors', new FileHandler('/logs/errors', LogLevel::ERROR));
 * $logManager->addHandler('errors', new StderrHandler(LogLevel::ERROR));
 *
 * // Log to default channel
 * $logManager->info('Application started');
 *
 * // Log to a specific channel
 * $logManager->channel('errors')->error('Something broke');
 *
 * // Stack channel — logs to multiple channels at once
 * $logManager->stack(['app', 'errors'])->critical('Critical failure');
 * ```
 */
class LogManager implements LoggerInterface
{
    use LoggerTrait;

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
     * @var array<string, list<LogHandlerInterface>> Channel name → handlers
     */
    private array $channels = [];

    /**
     * @var string Default channel name
     */
    private string $defaultChannel;

    /**
     * @var string|null Current channel override (set via channel())
     */
    private ?string $currentChannel = null;

    /**
     * @var list<string>|null Current stack channels override (set via stack())
     */
    private ?array $stackChannels = null;

    /**
     * @var array<int, array{timestamp: string, level: string, message: string, context: array, channel: string}> In-memory buffer
     */
    private array $buffer = [];

    /**
     * @var bool Whether to keep entries in memory
     */
    private bool $bufferEnabled;

    /**
     * Create a new LogManager.
     *
     * @param string $defaultChannel The default channel to log to
     * @param bool $bufferEnabled Keep entries in memory (default: false)
     */
    public function __construct(
        string $defaultChannel = 'default',
        bool $bufferEnabled = false,
    ) {
        $this->defaultChannel = $defaultChannel;
        $this->bufferEnabled = $bufferEnabled;
    }

    /**
     * Add a handler to a channel.
     *
     * @param string $channel Channel name
     * @param LogHandlerInterface $handler Handler instance
     *
     * @return $this
     */
    public function addHandler(string $channel, LogHandlerInterface $handler): static
    {
        $this->channels[$channel][] = $handler;

        return $this;
    }

    /**
     * Get all handlers for a channel.
     *
     * @return list<LogHandlerInterface>
     */
    public function getHandlers(string $channel): array
    {
        return $this->channels[$channel] ?? [];
    }

    /**
     * Switch to a specific channel for the next log call.
     *
     * Returns $this so you can chain: `$logManager->channel('errors')->error('msg')`
     *
     * @return $this
     */
    public function channel(string $channel): static
    {
        $this->currentChannel = $channel;
        $this->stackChannels = null;

        return $this;
    }

    /**
     * Log to multiple channels at once (stack channel).
     *
     * @param list<string> $channels Channel names
     *
     * @return $this
     */
    public function stack(array $channels): static
    {
        $this->stackChannels = $channels;
        $this->currentChannel = null;

        return $this;
    }

    /**
     * Get the default channel name.
     */
    public function getDefaultChannel(): string
    {
        return $this->defaultChannel;
    }

    /**
     * Set the default channel.
     *
     * @return $this
     */
    public function setDefaultChannel(string $channel): static
    {
        $this->defaultChannel = $channel;

        return $this;
    }

    /**
     * Get all registered channel names.
     *
     * @return list<string>
     */
    public function getChannelNames(): array
    {
        return \array_keys($this->channels);
    }

    /**
     * Check whether a channel has handlers.
     */
    public function hasChannel(string $channel): bool
    {
        return !empty($this->channels[$channel]);
    }

    /**
     * {@inheritdoc}
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level = $this->validateLevel((string) $level);
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $interpolated = $this->interpolate((string) $message, $context);

        // Determine channels to log to
        $channelNames = $this->resolveChannels();

        // Reset channel overrides after resolution
        $this->currentChannel = null;
        $this->stackChannels = null;

        foreach ($channelNames as $channelName) {
            $handlers = $this->channels[$channelName] ?? [];

            // Buffer entry
            if ($this->bufferEnabled) {
                $this->buffer[] = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'message' => $interpolated,
                    'context' => $context,
                    'channel' => $channelName,
                ];
            }

            foreach ($handlers as $handler) {
                if ($handler->isHandling($level)) {
                    $handler->handle($level, $interpolated, $context, $timestamp, $channelName);
                }
            }
        }
    }

    /**
     * Get all buffered entries.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: array, channel: string}>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Clear the buffer.
     *
     * @return $this
     */
    public function clearBuffer(): static
    {
        $this->buffer = [];

        return $this;
    }

    /**
     * Resolve which channel names to log to.
     *
     * @return list<string>
     */
    private function resolveChannels(): array
    {
        if ($this->stackChannels !== null) {
            return $this->stackChannels;
        }

        if ($this->currentChannel !== null) {
            return [$this->currentChannel];
        }

        return [$this->defaultChannel];
    }

    /**
     * Validate a PSR-3 log level string.
     */
    private function validateLevel(string $level): string
    {
        if (!isset(self::LEVEL_PRIORITY[$level])) {
            throw new InvalidArgumentException(
                "Unknown log level '{$level}'. Valid levels: " . \implode(', ', \array_keys(self::LEVEL_PRIORITY)),
            );
        }

        return $level;
    }

    /**
     * PSR-3 context interpolation.
     */
    private function interpolate(string $message, array $context): string
    {
        if (empty($context) || !\str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $token = '{' . $key . '}';
            if (!\str_contains($message, $token)) {
                continue;
            }

            if ($value instanceof Stringable || $value instanceof DateTimeInterface) {
                $replacements[$token] = (string) $value;
            } elseif (\is_scalar($value) || $value === null) {
                $replacements[$token] = (string) $value;
            }
        }

        return \strtr($message, $replacements);
    }
}
