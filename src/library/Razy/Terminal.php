<?php

/**
 * This file is part of Razy v0.5.
 *
 * Terminal CLI interface for the Razy framework. Provides styled text output,
 * user input reading, logging, and ANSI color/formatting support for
 * command-line applications.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use DateTime;
use Exception;
use InvalidArgumentException;
use Razy\Util\PathUtil;

/**
 * CLI terminal handler for the Razy framework.
 *
 * Manages terminal output with ANSI color codes, text formatting,
 * user input reading, command execution, and message logging.
 * Supports hierarchical terminal instances via parent linking.
 *
 * @class Terminal
 */
class Terminal
{
    /**
     * ANSI escape codes for foreground (font) colors.
     */
    public const COLOR_DEFAULT = "\033[39m";

    public const COLOR_BLACK = "\033[30m";

    public const COLOR_RED = "\033[31m";

    public const COLOR_GREEN = "\033[32m";

    public const COLOR_YELLOW = "\033[33m";

    public const COLOR_BLUE = "\033[34m";

    public const COLOR_MAGENTA = "\033[35m";

    public const COLOR_CYAN = "\033[36m";

    public const COLOR_LIGHTGRAY = "\033[37m";

    public const COLOR_DARKGRAY = "\033[90m";

    public const COLOR_LIGHTRED = "\033[91m";

    public const COLOR_LIGHTGREEN = "\033[92m";

    public const COLOR_LIGHTYELLOW = "\033[93m";

    public const COLOR_LIGHTBLUE = "\033[94m";

    public const COLOR_LIGHTMAGENTA = "\033[95m";

    public const COLOR_LIGHTCYAN = "\033[96m";

    public const COLOR_WHITE = "\033[97m";

    public const RESET_STLYE = "\033[0m";

    /**
     * ANSI escape codes for background colors.
     */
    public const BACKGROUND_BLACK = "\033[40m";

    public const BACKGROUND_RED = "\033[41m";

    public const BACKGROUND_GREEN = "\033[42m";

    public const BACKGROUND_YELLOW = "\033[43m";

    public const BACKGROUND_BLUE = "\033[44m";

    public const BACKGROUND_MAGENTA = "\033[45m";

    public const BACKGROUND_CYAN = "\033[46m";

    public const BACKGROUND_LIGHTGRAYE = "\033[47m";

    /** @var string ANSI escape to clear the current line */
    public const CLEAR_LINE = "\033[0G\033[2K";

    /** @var string Newline character */
    public const NEWLINE = "\n";

    /** @var string ANSI escape for blinking text */
    public const TEXT_BLINK = "\033[5m";

    /** @var bool Whether logging is enabled */
    private bool $logging = false;

    /** @var array<int, array{0: string, 1: string}> Log entries as [timestamp, message] */
    private array $logs = [];

    /** @var array Navigation stack for subcommand routing */
    private array $navigation = [];

    /** @var array<string, mixed> Runtime parameters passed to command callbacks */
    private array $parameters = [];

    /**
     * Terminal constructor.
     *
     * @param string $code
     * @param Terminal|null $parent
     *
     * @throws InvalidArgumentException
     */
    public function __construct(private string $code, private readonly ?self $parent = null)
    {
        $this->code = \trim($this->code);
        if (!$this->code) {
            throw new InvalidArgumentException('The terminal code is required.');
        }
    }

    /**
     * Read the input.
     *
     * @return string
     */
    public static function read(): string
    {
        $response = \trim(\fgets(STDIN));
        // Remove ANSI arrow-key escape sequences to prevent character overlap in input
        if (\preg_match('/\\033\[[ABCD]/', $response)) {
            return \preg_replace('/(?:\\033\[[ABCD])+/', '', $response);
        }

        return $response;
    }

    /**
     * Format the CLI text by using CLI styling tag.
     *
     * @param string $message
     *
     * @return string
     */
    public static function Format(string $message): string
    {
        // Match CLI styling tags: {@code|code...} for reset/clear/nl, or {@c:color,b:bg,s:style}
        return \preg_replace_callback('/{@(?:((?<code>clear|reset|nl)(?:\|(?&code))*)|((?<config>[cbsk]:\w+)(?:,(?&config))*))}/', function ($matches) {
            $styleString = '';
            if ($matches[3] ?? '') {
                // Parse comma-separated style configurations: c:color, b:background, s:style
                $clips = \explode(',', $matches[3]);
                foreach ($clips as $clip) {
                    [$style, $value] = \explode(':', $clip, 2);
                    if ('c' == $style || 'b' === $style) {
                        // Resolve font color (c:) or background color (b:) from class constants
                        $value = \strtoupper($value);
                        $constant = __CLASS__ . '::' . (('c' === $style) ? 'COLOR' : 'BACKGROUND') . '_' . $value;
                        if (\defined($constant)) {
                            $styleString .= \constant($constant);
                        }
                    } elseif ($style == 's') {
                        // Text decoration: b=bold, i=italic, u=underline, s=strikethrough, k=blink
                        $values = \array_keys(\array_flip(\str_split($value)));
                        foreach ($values as $styleCode) {
                            switch ($styleCode) {
                                case 'b':
                                    $styleString .= "\e[1m";
                                    break;
                                case 'i':
                                    $styleString .= "\e[3m";
                                    break;
                                case 'u':
                                    $styleString .= "\e[4m";
                                    break;
                                case 's':
                                    $styleString .= "\e[9m";
                                    break;
                                case 'k':
                                    $styleString .= "\e[5m";
                                    break;
                            }
                        }
                    }
                }
            } else {
                // Handle control codes: reset, clear line, newline
                $clips = \array_keys(\array_flip(\explode('|', $matches[1])));
                foreach ($clips as $clip) {
                    switch ($clip) {
                        case 'reset':
                            $styleString .= self::RESET_STLYE;
                            break;
                        case 'clear':
                            $styleString .= self::CLEAR_LINE;
                            break;
                        case 'nl':
                            $styleString .= PHP_EOL;
                            break;
                    }
                }
            }

            return $styleString;
        }, $message) ?? '';
    }

    /**
     * @param string $message
     * @param bool $resetStyle
     * @param string $format
     */
    public static function WriteLine(string $message, bool $resetStyle = false, string $format = ''): void
    {
        $message = \str_replace("\t", '    ', $message);
        $format = \trim($format);
        $message = ($format) ? \sprintf($format, $message) : $message;
        echo self::Format($message) . ($resetStyle ? self::RESET_STLYE : '') . PHP_EOL;
    }

    /**
     * Display the header.
     *
     * @param string $message
     * @param int $length
     *
     * @return $this
     */
    public function displayHeader(string $message, int $length = 26): self
    {
        $message = \trim($message);
        $length = \max(4, $length);
        $border = \max(16, $length) + 4;
        if ($message) {
            if (\strlen($message) > $length) {
                $clips = \str_split($message, $length);
            }
        }

        return $this;
    }

    /**
     * Get the Terminal code.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Get the parameters.
     *
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get the parent.
     *
     * @return Terminal|null
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Get the console screen width.
     *
     * @return int
     */
    public function getScreenWidth(): int
    {
        // Windows: parse the 'mode' command output to extract column count
        if ('WIN' === \strtoupper(\substr(PHP_OS, 0, 3))) {
            $setting = \shell_exec('mode');
            // Split by separator lines and take the last block (active console info)
            $clips = \preg_split('/-+/', $setting, -1, PREG_SPLIT_NO_EMPTY);
            $terminalInfo = \explode("\n", \trim(\end($clips)));
            if (\count($terminalInfo) >= 2) {
                // Second line contains "Columns: <number>"
                [, $value] = \explode(':', \trim($terminalInfo[1]), 2);

                return (int) $value;
            }

            return 0;
        }

        // Unix/Linux: use tput to get terminal width
        return (int) \shell_exec('tput cols');
    }

    /**
     * Get the parsed text length.
     *
     * @param string $text
     * @param int|null $escaped
     *
     * @return int
     */
    public function length(string $text, ?int &$escaped = 0): int
    {
        $escaped = 0;
        // Count the total length of ANSI escape sequences so they can be excluded
        if (\preg_match_all("/\e\\[(?:\\d+m|[ABCD])/", $text, $matches)) {
            \array_walk($matches[0], function (&$value) use (&$lengthOfEscape, &$escaped) {
                $escaped += \strlen($value);
            });
        }

        // Visible length = total length minus escape sequence bytes
        return \strlen($text) - $escaped;
    }

    /**
     * Enable or disable logging.
     *
     * @param bool $enable
     *
     * @return $this
     */
    public function logging(bool $enable): self
    {
        $this->logging = $enable;

        return $this;
    }

    /**
     * Execute the command and pass the arguments and parameters into closure.
     *
     * @param callable $callback
     * @param array $args
     * @param array $parameters
     *
     * @return $this
     */
    public function run(callable $callback, array $args = [], array $parameters = []): self
    {
        $this->parameters = $parameters;
        \call_user_func_array($callback(...)->bindTo($this), $args);

        return $this;
    }

    /**
     * Save the log into file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function saveLog(string $path): bool
    {
        $length = 20;
        $content = '';
        $path = PathUtil::fixPath($path);
        // Format each log entry as a timestamped line
        foreach ($this->logs as $log) {
            $content .= \sprintf('%-22s%s', '[' . $log[0] . ']', $log[1]) . PHP_EOL;
        }

        $realPath = \realpath($path);
        if ($realPath) {
            // If the path is a valid file or directory
            if (\is_dir($realPath)) {
                $path = PathUtil::append($path, (new DateTime())->format('Y_m_d_H_i_s') . '_' . $this->code . '.txt');
            }
        } else {
            // If the path is not exists, extract the directory and the file name
            // If no file name is provided, use default file name
            $fileName = (new DateTime())->format('Y_m_d_H_i_s') . '_' . $this->code . '.txt';
            if (!PathUtil::isDirPath($path)) {
                $fileName = \basename($path);
                $path = \dirname($path);
            }

            try {
                // Create directory
                \mkdir($path, 0o777, true);
            } catch (Exception $e) {
                return false;
            }
            $path = PathUtil::append($path, $fileName);
        }

        try {
            \file_put_contents($path, $content);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Output a line of message.
     *
     * @param string $message
     * @param bool $resetStyle
     * @param string $format
     *
     * @return $this
     */
    public function writeLineLogging(string $message, bool $resetStyle = false, string $format = ''): self
    {
        self::WriteLine($message, $resetStyle, $format);
        if ($this->logging) {
            $this->addLog($message);
        }

        return $this;
    }

    /**
     * Add a new log message.
     *
     * @param string $message
     *
     * @return $this
     */
    public function addLog(string $message): self
    {
        $message = \trim($message);
        $this->logs[] = [(new DateTime())->format('Y-m-d H:i:s'), $message];

        return $this;
    }
}
