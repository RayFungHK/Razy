<?php

/**
 * This file is part of Razy v0.5.
 *
 * Environment variable loader and manager.
 *
 * Parses `.env` files and populates `$_ENV`, `$_SERVER`, and `putenv()` with
 * the key-value pairs found. Supports:
 *  - Comments (`#`)
 *  - Quoted values (single and double)
 *  - Escape sequences in double-quoted values (`\n`, `\t`, `\\`, `\"`)
 *  - Variable interpolation in double-quoted values (`${VAR}` or `$VAR`)
 *  - `export` prefix (ignored, for shell compatibility)
 *  - Multiline double-quoted values
 *  - Empty values
 *  - Casting helpers: `true`/`false`/`null`/`(empty)` literals
 *
 * @package Razy
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy;

use InvalidArgumentException;
use RuntimeException;

class Env
{
    /**
     * All loaded environment variables (name => raw string value).
     *
     * @var array<string, string>
     */
    private static array $loaded = [];

    /**
     * Whether an .env file has been loaded in this process.
     */
    private static bool $initialized = false;

    /**
     * Load an `.env` file and populate the environment.
     *
     * @param string $path Full path to the `.env` file.
     * @param bool $overwrite Whether to overwrite existing env vars. Default false.
     *
     * @throws RuntimeException If the file cannot be read.
     * @throws InvalidArgumentException If a line has invalid syntax.
     */
    public static function load(string $path, bool $overwrite = false): void
    {
        if (!\is_file($path) || !\is_readable($path)) {
            throw new RuntimeException("Environment file not found or not readable: {$path}");
        }

        $content = \file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read environment file: {$path}");
        }

        $pairs = self::parse($content);

        foreach ($pairs as $name => $value) {
            self::setVariable($name, $value, $overwrite);
        }

        self::$initialized = true;
    }

    /**
     * Load an `.env` file if it exists. Does nothing when the file is absent.
     *
     * @param string $path Full path to the `.env` file.
     * @param bool $overwrite Whether to overwrite existing env vars.
     *
     * @return bool True if the file was loaded, false if it was missing.
     */
    public static function loadIfExists(string $path, bool $overwrite = false): bool
    {
        if (!\is_file($path)) {
            return false;
        }

        self::load($path, $overwrite);

        return true;
    }

    /**
     * Parse the contents of an `.env` file into key-value pairs.
     *
     * @param string $content Raw file content.
     *
     * @return array<string, string> Parsed variables.
     *
     * @throws InvalidArgumentException On invalid syntax.
     */
    public static function parse(string $content): array
    {
        $result = [];
        $lines = \explode("\n", \str_replace(["\r\n", "\r"], "\n", $content));
        $lineCount = \count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];

            // Skip empty lines and comments
            $trimmed = \trim($line);
            if ($trimmed === '' || \str_starts_with($trimmed, '#')) {
                continue;
            }

            // Strip optional `export ` prefix
            if (\str_starts_with($trimmed, 'export ')) {
                $trimmed = \substr($trimmed, 7);
            }

            // Split on first `=`
            $eqPos = \strpos($trimmed, '=');
            if ($eqPos === false) {
                throw new InvalidArgumentException("Invalid .env syntax (missing '=' on line " . ($i + 1) . "): {$trimmed}");
            }

            $name = \trim(\substr($trimmed, 0, $eqPos));
            $value = \substr($trimmed, $eqPos + 1);

            // Validate the variable name
            if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException('Invalid environment variable name on line ' . ($i + 1) . ": {$name}");
            }

            // Parse the value
            $value = self::parseValue($value, $lines, $i, $result);

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * Get an environment variable with an optional default.
     *
     * Casts `"true"`, `"false"`, `"null"`, and `"(empty)"` string literals
     * to their PHP equivalents when `$cast` is true.
     *
     * @param string $key Variable name.
     * @param mixed $default Default value if not set.
     * @param bool $cast Whether to cast special string values.
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null, bool $cast = true): mixed
    {
        // Check our loaded cache first, then fall back to actual environment
        $value = self::$loaded[$key]
                ?? $_ENV[$key]
                ?? $_SERVER[$key]
                ?? null;

        if ($value === null) {
            $envVal = \getenv($key);
            $value = $envVal !== false ? $envVal : null;
        }

        if ($value === null) {
            return $default;
        }

        if ($cast) {
            return self::castValue((string) $value);
        }

        return $value;
    }

    /**
     * Check if an environment variable is defined.
     *
     * @param string $key Variable name.
     *
     * @return bool
     */
    public static function has(string $key): bool
    {
        return isset(self::$loaded[$key])
            || isset($_ENV[$key])
            || isset($_SERVER[$key])
            || \getenv($key) !== false;
    }

    /**
     * Manually set an environment variable.
     *
     * @param string $name Variable name.
     * @param string $value Variable value.
     */
    public static function set(string $name, string $value): void
    {
        self::setVariable($name, $value, true);
    }

    /**
     * Get all loaded environment variables.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$loaded;
    }

    /**
     * Whether an `.env` file has been loaded.
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Get the required environment variable or throw.
     *
     * @param string $key Variable name.
     * @param bool $cast Whether to cast special string values.
     *
     * @return mixed
     *
     * @throws RuntimeException If the variable is not defined.
     */
    public static function getRequired(string $key, bool $cast = true): mixed
    {
        if (!self::has($key)) {
            throw new RuntimeException("Required environment variable '{$key}' is not defined.");
        }

        return self::get($key, null, $cast);
    }

    /**
     * Reset the Env state (primarily for testing).
     *
     * Clears the loaded variables cache and the initialized flag.
     * Does NOT unset variables from `$_ENV`, `$_SERVER`, or `putenv()`.
     */
    public static function reset(): void
    {
        self::$loaded = [];
        self::$initialized = false;
    }

    // ──────────────────────────────────────────────────────────
    //  Internal helpers
    // ──────────────────────────────────────────────────────────

    /**
     * Parse the raw value portion of a `.env` line.
     *
     * Handles:
     *  - Unquoted values (trimmed, inline comments stripped)
     *  - Single-quoted values (literal, no escape processing)
     *  - Double-quoted values (escape sequences, variable interpolation, multiline)
     *
     * @param string $raw Raw value text after `=`.
     * @param array<int, string> $lines All lines in the file (for multiline).
     * @param int &$lineIdx Current line index (may advance for multiline).
     * @param array<string, string> $context Already-parsed variables for interpolation.
     *
     * @return string Resolved value.
     */
    private static function parseValue(string $raw, array $lines, int &$lineIdx, array $context): string
    {
        $raw = \ltrim($raw);

        // Empty value
        if ($raw === '') {
            return '';
        }

        // Single-quoted: literal, no interpolation, no escapes
        if (\str_starts_with($raw, "'")) {
            $end = \strrpos($raw, "'", 1);
            if ($end === false) {
                throw new InvalidArgumentException(
                    'Unterminated single-quoted value on line ' . ($lineIdx + 1),
                );
            }

            return \substr($raw, 1, $end - 1);
        }

        // Double-quoted: escapes + interpolation + multiline
        if (\str_starts_with($raw, '"')) {
            return self::parseDoubleQuoted($raw, $lines, $lineIdx, $context);
        }

        // Unquoted: strip inline comment, trim
        $commentPos = \strpos($raw, ' #');
        if ($commentPos !== false) {
            $raw = \substr($raw, 0, $commentPos);
        }

        $value = \rtrim($raw);

        // Interpolate $VAR / ${VAR} in unquoted values too
        return self::interpolate($value, $context);
    }

    /**
     * Parse a double-quoted value, handling escapes, interpolation, and multiline.
     *
     * @param string $raw Raw value text starting with `"`.
     * @param array<int, string> $lines All file lines.
     * @param int &$lineIdx Current line index.
     * @param array<string, string> $context Already-parsed variables.
     *
     * @return string Resolved value.
     */
    private static function parseDoubleQuoted(string $raw, array $lines, int &$lineIdx, array $context): string
    {
        // Remove the opening quote
        $buffer = \substr($raw, 1);

        // Try to find closing quote on this line
        $closingPos = self::findClosingQuote($buffer);

        // Multiline handling
        while ($closingPos === false) {
            $lineIdx++;
            if ($lineIdx >= \count($lines)) {
                throw new InvalidArgumentException(
                    'Unterminated double-quoted value (reached end of file)',
                );
            }
            $buffer .= "\n" . $lines[$lineIdx];
            $closingPos = self::findClosingQuote($buffer);
        }

        // Extract the content between quotes
        $content = \substr($buffer, 0, $closingPos);

        // Process escape sequences
        $content = self::processEscapes($content);

        // Interpolate variables
        return self::interpolate($content, $context);
    }

    /**
     * Find the position of the closing `"` that is not preceded by a backslash.
     *
     * @param string $str The string content (without the opening quote).
     *
     * @return int|false Position of the closing quote, or false if not found.
     */
    private static function findClosingQuote(string $str): int|false
    {
        $len = \strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] === '\\') {
                $i++; // Skip the escaped character
                continue;
            }
            if ($str[$i] === '"') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Process escape sequences in a double-quoted string.
     *
     * Supported: `\\`, `\"`, `\n`, `\r`, `\t`, `\$`.
     *
     * @param string $value The string content.
     *
     * @return string Processed string.
     */
    private static function processEscapes(string $value): string
    {
        return \strtr($value, [
            '\\\\' => '\\',
            '\\"' => '"',
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\$' => '$',
        ]);
    }

    /**
     * Perform variable interpolation on a string.
     *
     * Replaces `${VAR_NAME}` and `$VAR_NAME` with the value from the context
     * (already-parsed variables), the loaded cache, or the system environment.
     *
     * @param string $value The string to interpolate.
     * @param array<string, string> $context Already-parsed variables.
     *
     * @return string Interpolated string.
     */
    private static function interpolate(string $value, array $context): string
    {
        // Replace ${VAR} syntax
        $value = \preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', function ($m) use ($context) {
            return self::resolveVariable($m[1], $context);
        }, $value);

        // Replace $VAR syntax (word boundary after variable name)
        $value = \preg_replace_callback('/\$([A-Za-z_][A-Za-z0-9_]*)/', function ($m) use ($context) {
            return self::resolveVariable($m[1], $context);
        }, $value);

        return $value;
    }

    /**
     * Resolve a variable name to its value.
     *
     * Search order: context → loaded → $_ENV → $_SERVER → getenv().
     *
     * @param string $name Variable name.
     * @param array<string, string> $context Already-parsed variables.
     *
     * @return string Resolved value, or empty string if not found.
     */
    private static function resolveVariable(string $name, array $context): string
    {
        if (isset($context[$name])) {
            return $context[$name];
        }

        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }

        if (isset($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        $env = \getenv($name);
        if ($env !== false) {
            return $env;
        }

        return '';
    }

    /**
     * Set a variable in all three stores (loaded, $_ENV, $_SERVER via putenv).
     *
     * @param string $name Variable name.
     * @param string $value Variable value.
     * @param bool $overwrite Whether to overwrite existing variables.
     */
    private static function setVariable(string $name, string $value, bool $overwrite): void
    {
        if (!$overwrite && self::has($name)) {
            return;
        }

        self::$loaded[$name] = $value;
        $_ENV[$name] = $value;
        \putenv("{$name}={$value}");
    }

    /**
     * Cast a string value to its PHP equivalent for common literals.
     *
     * @param string $value Raw string value.
     *
     * @return mixed Casted value.
     */
    private static function castValue(string $value): mixed
    {
        return match (\strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
