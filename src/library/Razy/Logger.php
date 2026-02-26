<?php
/**
 * This file is part of Razy v0.5.
 *
 * PSR-3 compliant logger for the Razy framework. Supports file-based
 * logging with configurable log level threshold, PSR-3 message
 * interpolation, and thread-safe file writes.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use DateTimeImmutable;
use Razy\Contract\Log\InvalidArgumentException;
use Razy\Contract\Log\LoggerInterface;
use Razy\Contract\Log\LoggerTrait;
use Razy\Contract\Log\LogLevel;
use Stringable;

/**
 * A concrete PSR-3 logger implementation for the Razy framework.
 *
 * Features:
 * - File-based logging with LOCK_EX for thread-safe writes
 * - Configurable minimum log level threshold
 * - PSR-3 context interpolation ({placeholder} replacement)
 * - Timestamped log entries
 * - In-memory log buffer for programmatic access
 *
 * Usage:
 * ```php
 * // File logger
 * $logger = new Logger('/path/to/logs');
 * $logger->info('User {user} logged in', ['user' => 'john']);
 *
 * // Logger with custom threshold (only WARNING and above)
 * $logger = new Logger('/path/to/logs', LogLevel::WARNING);
 *
 * // Null logger (discards all messages)
 * $logger = new Logger();
 * ```
 *
 * @class Logger
 * @package Razy
 */
class Logger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * PSR-3 log level severity map (higher = more severe).
     */
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
     * @var string|null Directory path for log files, or null for null-logger mode
     */
    private ?string $logDirectory;

    /**
     * @var string Minimum log level threshold
     */
    private string $minLevel;

    /**
     * @var string Log filename pattern (date-based by default)
     */
    private string $filenamePattern;

    /**
     * @var array<int, array{timestamp: string, level: string, message: string, context: array}> In-memory log buffer
     */
    private array $buffer = [];

    /**
     * @var bool Whether to keep log entries in the in-memory buffer
     */
    private bool $bufferEnabled;

    /**
     * Create a new Logger instance.
     *
     * @param string|null $logDirectory   Directory for log files. Null disables file output (null-logger mode).
     * @param string      $minLevel       Minimum PSR-3 log level to record (default: DEBUG, i.e., log everything)
     * @param string      $filenamePattern Date format for the log filename (default: 'Y-m-d', producing files like 2026-02-23.log)
     * @param bool        $bufferEnabled  Whether to keep log entries in memory (default: false)
     */
    public function __construct(
        ?string $logDirectory = null,
        string $minLevel = LogLevel::DEBUG,
        string $filenamePattern = 'Y-m-d',
        bool $bufferEnabled = false,
    ) {
        $this->logDirectory = $logDirectory !== null ? rtrim($logDirectory, '/\\') : null;
        $this->minLevel = $this->validateLevel($minLevel);
        $this->filenamePattern = $filenamePattern;
        $this->bufferEnabled = $bufferEnabled;

        // Ensure log directory exists if specified
        if ($this->logDirectory !== null && !is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0775, true);
        }
    }

    /**
     * Logs with an arbitrary level.
     *
     * PSR-3 requires this method. The LoggerTrait convenience methods
     * (debug, info, notice, warning, error, critical, alert, emergency)
     * all delegate to this method.
     *
     * @param mixed              $level   A PSR-3 LogLevel constant
     * @param string|Stringable  $message The log message, may contain {placeholder} tokens
     * @param array              $context Replacement values for placeholders and additional data
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level = $this->validateLevel((string) $level);

        // Check threshold — skip if below minimum level
        if ($this->getLevelPriority($level) < $this->getLevelPriority($this->minLevel)) {
            return;
        }

        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $interpolated = $this->interpolate((string) $message, $context);

        // Store in memory buffer if enabled
        if ($this->bufferEnabled) {
            $this->buffer[] = [
                'timestamp' => $timestamp,
                'level'     => $level,
                'message'   => $interpolated,
                'context'   => $context,
            ];
        }

        // Write to file if log directory is configured
        if ($this->logDirectory !== null) {
            $this->writeToFile($timestamp, $level, $interpolated, $context);
        }
    }

    /**
     * Get all buffered log entries.
     *
     * @return array<int, array{timestamp: string, level: string, message: string, context: array}>
     */
    public function getBuffer(): array
    {
        return $this->buffer;
    }

    /**
     * Clear the in-memory log buffer.
     *
     * @return $this
     */
    public function clearBuffer(): static
    {
        $this->buffer = [];

        return $this;
    }

    /**
     * Get the current minimum log level threshold.
     *
     * @return string A PSR-3 LogLevel constant
     */
    public function getMinLevel(): string
    {
        return $this->minLevel;
    }

    /**
     * Set the minimum log level threshold at runtime.
     *
     * @param string $level A PSR-3 LogLevel constant
     * @return $this
     */
    public function setMinLevel(string $level): static
    {
        $this->minLevel = $this->validateLevel($level);

        return $this;
    }

    /**
     * Get the log directory path.
     *
     * @return string|null
     */
    public function getLogDirectory(): ?string
    {
        return $this->logDirectory;
    }

    /**
     * Write a formatted log entry to the daily log file.
     *
     * Uses LOCK_EX for thread-safe writes.
     *
     * @param string $timestamp  Formatted timestamp
     * @param string $level      Log level
     * @param string $message    Interpolated message
     * @param array  $context    Original context (for JSON encoding extra data)
     */
    private function writeToFile(string $timestamp, string $level, string $message, array $context): void
    {
        $filename = (new DateTimeImmutable())->format($this->filenamePattern) . '.log';
        $filepath = $this->logDirectory . DIRECTORY_SEPARATOR . $filename;

        $levelUpper = strtoupper($level);
        $line = "[{$timestamp}] [{$levelUpper}] {$message}";

        // Append context data if present (excluding interpolated keys)
        $extra = $this->getExtraContext($context);
        if (!empty($extra)) {
            $encoded = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line .= ' ' . $encoded;
        }

        $line .= PHP_EOL;

        file_put_contents($filepath, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Interpolate PSR-3 message placeholders with context values.
     *
     * Replaces {key} tokens in the message with corresponding context values.
     * Context values that implement __toString() or are scalars are used;
     * other types are skipped.
     *
     * @param string $message The raw message with {placeholder} tokens
     * @param array  $context The context array
     * @return string The interpolated message
     */
    private function interpolate(string $message, array $context): string
    {
        if (empty($context) || !str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $token = '{' . $key . '}';
            if (!str_contains($message, $token)) {
                continue;
            }

            if ($value instanceof Stringable || $value instanceof \DateTimeInterface) {
                $replacements[$token] = (string) $value;
            } elseif (is_scalar($value) || $value === null) {
                $replacements[$token] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * Get context entries that were NOT used for message interpolation.
     *
     * The 'exception' key is handled specially per PSR-3: if present and
     * it's a Throwable, its message and trace are included.
     *
     * @param array $context The full context array
     * @return array Extra context data for appending to log line
     */
    private function getExtraContext(array $context): array
    {
        $extra = [];

        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $extra['exception'] = [
                    'class'   => get_class($value),
                    'message' => $value->getMessage(),
                    'code'    => $value->getCode(),
                    'file'    => $value->getFile(),
                    'line'    => $value->getLine(),
                ];
                continue;
            }

            // Include non-scalar values that couldn't be interpolated
            if (!is_scalar($value) && $value !== null && !($value instanceof Stringable)) {
                $extra[$key] = $value;
            }
        }

        return $extra;
    }

    /**
     * Validate that a level string is a known PSR-3 log level.
     *
     * @param string $level The level to validate
     * @return string The validated level
     *
     * @throws InvalidArgumentException If the level is not recognized
     */
    private function validateLevel(string $level): string
    {
        if (!isset(self::LEVEL_PRIORITY[$level])) {
            throw new InvalidArgumentException(
                "Unknown log level '{$level}'. Valid levels: " . implode(', ', array_keys(self::LEVEL_PRIORITY))
            );
        }

        return $level;
    }

    /**
     * Get the numeric priority for a log level.
     *
     * @param string $level A PSR-3 LogLevel constant
     * @return int Priority (0 = DEBUG through 7 = EMERGENCY)
     */
    private function getLevelPriority(string $level): int
    {
        return self::LEVEL_PRIORITY[$level];
    }
}
