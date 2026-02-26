<?php

/**
 * This file is part of Razy v0.5.
 *
 * YAML dumper implementation for the Razy framework.
 * Serializes PHP data structures into YAML format.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\YAML;

/**
 * Internal YAML dumper implementation.
 *
 * Serializes PHP data structures into YAML format with configurable
 * indentation and inline array depth threshold.
 *
 * @internal Used by \Razy\YAML::dump()
 */
class YAMLDumper
{
    /**
     * YAMLDumper constructor.
     *
     * @param int $indent Number of spaces per indentation level
     * @param int $inline Depth at which arrays are rendered inline
     */
    public function __construct(
        private readonly int $indent = 2,
        private readonly int $inline = 4,
    ) {
    }

    /**
     * Dump data to YAML string.
     *
     * @param mixed $data The data to serialize
     *
     * @return string YAML output
     */
    public function dump(mixed $data): string
    {
        return $this->dumpLevel($data, 0, false);
    }

    /**
     * Recursively dump data at a specific nesting level.
     *
     * Decides whether to render inline or block-style based on depth
     * and data complexity.
     *
     * @param mixed $data The data to dump
     * @param int $level Current nesting depth
     * @param bool $inlineMode Whether inline rendering was requested
     *
     * @return string YAML fragment
     */
    private function dumpLevel(mixed $data, int $level, bool $inlineMode): string
    {
        if ($data === null) {
            return 'null';
        }

        if (\is_bool($data)) {
            return $data ? 'true' : 'false';
        }

        if (\is_scalar($data)) {
            return $this->dumpScalar($data);
        }

        if (!\is_array($data)) {
            return $this->dumpScalar((string) $data);
        }

        // Check if data should be rendered inline (exceeds depth threshold or is simple)
        if ($level >= $this->inline || $this->isSimpleArray($data)) {
            return $this->dumpInline($data);
        }

        // Determine if sequential (list) or associative (mapping) array
        $isList = \array_keys($data) === \range(0, \count($data) - 1);

        if ($isList) {
            return $this->dumpSequence($data, $level);
        }

        return $this->dumpMapping($data, $level);
    }

    /**
     * Dump a sequential array as a YAML sequence (block style).
     *
     * @param array $data The list data
     * @param int $level Current nesting depth
     *
     * @return string YAML sequence output
     */
    private function dumpSequence(array $data, int $level): string
    {
        if (empty($data)) {
            return '[]';
        }

        $output = "\n";
        $indent = \str_repeat(' ', $this->indent * $level);

        foreach ($data as $value) {
            if (\is_array($value) && !$this->isSimpleArray($value)) {
                $nested = $this->dumpLevel($value, $level + 1, false);
                if (\str_starts_with($nested, "\n")) {
                    $output .= $indent . '-' . $nested;
                } else {
                    $output .= $indent . '- ' . $nested . "\n";
                }
            } else {
                $output .= $indent . '- ' . $this->dumpLevel($value, $level + 1, true) . "\n";
            }
        }

        return $output;
    }

    /**
     * Dump an associative array as a YAML mapping (block style).
     *
     * @param array $data The mapping data
     * @param int $level Current nesting depth
     *
     * @return string YAML mapping output
     */
    private function dumpMapping(array $data, int $level): string
    {
        if (empty($data)) {
            return '{}';
        }

        $output = $level === 0 ? '' : "\n";
        $indent = \str_repeat(' ', $this->indent * $level);

        foreach ($data as $key => $value) {
            $output .= $indent . $this->dumpScalar($key) . ':';

            if (\is_array($value) && !$this->isSimpleArray($value)) {
                $nested = $this->dumpLevel($value, $level + 1, false);
                if (\str_starts_with($nested, "\n")) {
                    $output .= $nested;
                } else {
                    $output .= ' ' . $nested . "\n";
                }
            } else {
                $output .= ' ' . $this->dumpLevel($value, $level + 1, true) . "\n";
            }
        }

        return $output;
    }

    /**
     * Dump an array in YAML flow (inline) format: [a, b] or {k: v}.
     *
     * @param array $data The data to render inline
     *
     * @return string Flow-style YAML string
     */
    private function dumpInline(array $data): string
    {
        // Determine if list ([]) or mapping ({})
        $isList = \array_keys($data) === \range(0, \count($data) - 1);

        if ($isList) {
            $items = \array_map(fn ($v) => $this->dumpLevel($v, 999, true), $data);
            return '[' . \implode(', ', $items) . ']';
        }

        $items = [];
        foreach ($data as $key => $value) {
            $items[] = $this->dumpScalar($key) . ': ' . $this->dumpLevel($value, 999, true);
        }
        return '{' . \implode(', ', $items) . '}';
    }

    /**
     * Dump a scalar value to its YAML string representation.
     *
     * Handles null, booleans, numbers, and strings (with quoting when needed).
     *
     * @param mixed $value The scalar to format
     *
     * @return string YAML scalar representation
     */
    private function dumpScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        // Check if needs quoting
        if ($this->needsQuoting($value)) {
            // Escape special characters
            $escaped = \str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\"', '\n', '\t'], $value);
            return '"' . $escaped . '"';
        }

        return $value;
    }

    /**
     * Check whether a string value requires quoting in YAML.
     *
     * Values that look like reserved words, numbers, or contain special
     * YAML characters must be quoted to avoid ambiguity.
     *
     * @param string $value The string to check
     *
     * @return bool True if quoting is required
     */
    private function needsQuoting(string $value): bool
    {
        // Empty string
        if ($value === '') {
            return true;
        }

        // Reserved words
        if (\in_array(\strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true)) {
            return true;
        }

        // Numeric strings
        if (\is_numeric($value)) {
            return true;
        }

        // Contains special YAML characters
        if (\preg_match('/[:\[\]{},&*#?|\-<>=!%@`"]/', $value)) {
            return true;
        }

        // Starts with special characters
        if (\preg_match('/^[\s@`]/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Check if an array is "simple" (no nested arrays, 5 items or fewer).
     *
     * Simple arrays are rendered inline for compact output.
     *
     * @param array $data The array to inspect
     *
     * @return bool True if the array qualifies for inline rendering
     */
    private function isSimpleArray(array $data): bool
    {
        foreach ($data as $value) {
            if (\is_array($value)) {
                return false;
            }
        }
        return \count($data) <= 5;
    }
}
