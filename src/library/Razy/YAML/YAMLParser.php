<?php
/**
 * This file is part of Razy v0.5.
 *
 * YAML parser implementation for the Razy framework.
 * Converts YAML strings into PHP data structures.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\YAML;

/**
 * Internal YAML parser implementation.
 *
 * Processes a YAML string line-by-line, supporting mappings, sequences,
 * flow collections, multi-line strings (literal/folded), anchors, and aliases.
 *
 * @internal Used by \Razy\YAML::parse()
 */
class YAMLParser
{
    /** @var string[] Lines of the YAML input */
    private array $lines = [];

    /** @var int Total number of lines */
    private int $lineCount = 0;

    /** @var int Current line index being parsed */
    private int $currentLine = 0;

    /** @var array<string, mixed> Stored anchor values for alias resolution */
    private array $anchors = [];

    /**
     * YAMLParser constructor.
     *
     * @param string $yaml The raw YAML string to parse
     */
    public function __construct(private readonly string $yaml)
    {
        // Split into lines and count for iteration
        $this->lines = explode("\n", $this->yaml);
        $this->lineCount = count($this->lines);
    }

    /**
     * Parse the YAML content and return the result.
     *
     * @return mixed Parsed PHP value (array, scalar, or null)
     */
    public function parse(): mixed
    {
        $this->currentLine = 0;
        $result = $this->parseLevel(0);
        
        // If result is array with single key at root, return it directly
        if (is_array($result) && count($result) === 1) {
            $keys = array_keys($result);
            if (is_string($keys[0])) {
                return $result;
            }
        }
        
        return $result;
    }

    /**
     * Recursively parse lines at a given indentation level.
     *
     * Handles mappings (key: value), sequences (- item), multi-line strings
     * (| and >), flow collections, anchors (&name), and aliases (*name).
     *
     * @param int $baseIndent The expected indentation level for this scope
     *
     * @return mixed Parsed result for this indentation level
     */
    private function parseLevel(int $baseIndent): mixed
    {
        $result = [];
        $isList = null;
        $multilineMode = null;
        $multilineKey = null;
        $multilineIndent = 0;
        $multilineContent = [];
        $multilineChomp = false;

        while ($this->currentLine < $this->lineCount) {
            $line = $this->lines[$this->currentLine];
            $trimmed = ltrim($line);

            // Skip empty lines and comments
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $this->currentLine++;
                continue;
            }

            // Calculate indentation by comparing raw vs trimmed line length
            $indent = strlen($line) - strlen($trimmed);

            // If dedented, return to parent
            if ($indent < $baseIndent) {
                break;
            }

            // Skip if not at our level
            if ($indent > $baseIndent && $multilineMode === null) {
                break;
            }

            // Handle multi-line string continuation (literal | or folded >)
            if ($multilineMode !== null) {
                if ($this->handleMultilineContinuation($line, $indent, $multilineIndent, $trimmed, $multilineContent)) {
                    continue;
                }
                // End multi-line mode
                $this->finalizeMultilineBlock($multilineMode, $multilineKey, $multilineContent, $multilineChomp, $result);
                $multilineMode = null;
                $multilineContent = [];
                continue;
            }

            $this->currentLine++;

            // Handle flow collections (inline)
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                return $this->parseFlow($trimmed);
            }

            // Handle list items
            if (preg_match('/^-\s+(.*)$/', $trimmed, $matches)) {
                $isList = true;
                $this->parseListItem(trim($matches[1]), $indent, $result);
                continue;
            }

            // Handle key-value pairs
            if (preg_match('/^([^:]+):\s*(.*)$/', $trimmed, $matches)) {
                $isList = false;
                $this->parseKeyValue(
                    trim($matches[1]),
                    trim($matches[2]),
                    $indent,
                    $result,
                    $multilineMode,
                    $multilineKey,
                    $multilineIndent,
                    $multilineChomp,
                    $multilineContent
                );
                continue;
            }

            // Plain scalar at root level
            if ($baseIndent === 0) {
                return $this->parseValue($trimmed);
            }
        }

        // Finalize any pending multi-line content
        if ($multilineMode !== null && !empty($multilineContent)) {
            $this->finalizeMultilineBlock($multilineMode, $multilineKey, $multilineContent, $multilineChomp, $result);
        }

        // Return list or map
        if ($isList === true) {
            return $result;
        }

        return $result ?: null;
    }

    /**
     * Finalize a multi-line block by joining accumulated content lines.
     *
     * For literal blocks (|), lines are joined with newlines.
     * For folded blocks (>), lines are joined with spaces after trimming.
     *
     * @param string $mode    The multi-line mode ('|' for literal, '>' for folded)
     * @param string $key     The key to assign the result to
     * @param array  $content The accumulated content lines
     * @param bool   $chomp   Whether to strip the trailing newline (reserved for future use)
     * @param array  &$result The result array to assign into
     */
    private function finalizeMultilineBlock(string $mode, string $key, array $content, bool $chomp, array &$result): void
    {
        if ($mode === '|') {
            $result[$key] = implode("\n", $content);
        } else {
            $result[$key] = implode(' ', array_map('trim', $content));
        }
    }

    /**
     * Check if the current line continues a multi-line block and accumulate content.
     *
     * @param string $line            The raw line
     * @param int    $indent          The line's indentation level
     * @param int    $multilineIndent The expected indentation for multi-line content
     * @param string $trimmed         The left-trimmed line
     * @param array  &$content        The accumulated content lines (modified by reference)
     *
     * @return bool True if the line was consumed as multi-line content
     */
    private function handleMultilineContinuation(string $line, int $indent, int $multilineIndent, string $trimmed, array &$content): bool
    {
        if ($indent >= $multilineIndent || $trimmed === '') {
            // Content line: strip base indentation and accumulate
            $contentLine = ($indent >= $multilineIndent) ? substr($line, $multilineIndent) : '';
            $content[] = $contentLine;
            $this->currentLine++;
            return true;
        }
        return false;
    }

    /**
     * Handle a list item (- value) including nested structures and inline mappings.
     *
     * @param string $value  The trimmed value after the list marker
     * @param int    $indent The indentation level of the list marker
     * @param array  &$result The result array to append to
     */
    private function parseListItem(string $value, int $indent, array &$result): void
    {
        if ($value === '') {
            // Nested structure
            $result[] = $this->parseLevel($indent + 2);
        } elseif (str_contains($value, ':')) {
            // Inline mapping in list
            $result[] = $this->parseLine($value);
        } else {
            $result[] = $this->parseValue($value);
        }
    }

    /**
     * Handle a key-value pair including anchors, aliases, nested recursion,
     * and multi-line indicators.
     *
     * @param string  $key              The parsed key
     * @param string  $value            The parsed value portion
     * @param int     $indent           The indentation level
     * @param array   &$result          The result array to assign into
     * @param ?string &$multilineMode   Set if a multi-line block is started
     * @param ?string &$multilineKey    Set to the key for multi-line content
     * @param ?int    &$multilineIndent Set to the expected indentation for multi-line content
     * @param ?bool   &$multilineChomp  Set to true if trailing newline should be stripped
     * @param array   &$multilineContent Reset when multi-line mode is started
     */
    private function parseKeyValue(
        string $key,
        string $value,
        int $indent,
        array &$result,
        ?string &$multilineMode,
        ?string &$multilineKey,
        ?int &$multilineIndent,
        ?bool &$multilineChomp,
        array &$multilineContent
    ): void {
        // Handle anchors
        if (preg_match('/^&(\w+)\s+(.+)$/', $key, $anchorMatch)) {
            $anchorName = $anchorMatch[1];
            $key = trim($anchorMatch[2]);
        }

        // Handle aliases
        if (str_starts_with($value, '*')) {
            $aliasName = substr($value, 1);
            $result[$key] = $this->anchors[$aliasName] ?? null;
            return;
        }

        if ($value === '') {
            // Could be nested structure or empty value
            $nextIndent = $this->peekNextIndent();
            if ($nextIndent > $indent) {
                $nested = $this->parseLevel($nextIndent);
                $result[$key] = $nested;

                // Store anchor if defined
                if (isset($anchorName)) {
                    $this->anchors[$anchorName] = $nested;
                }
            } else {
                $result[$key] = null;
            }
        } elseif ($value === '|' || $value === '>') {
            // Multi-line string
            $multilineMode = $value;
            $multilineKey = $key;
            $multilineIndent = $this->peekNextIndent();
            $multilineChomp = false;
            $multilineContent = [];
        } elseif ($value === '|-' || $value === '>-') {
            // Multi-line string without trailing newline
            $multilineMode = $value[0];
            $multilineKey = $key;
            $multilineIndent = $this->peekNextIndent();
            $multilineChomp = true;
            $multilineContent = [];
        } else {
            $parsedValue = $this->parseValue($value);
            $result[$key] = $parsedValue;

            // Store anchor if defined
            if (isset($anchorName)) {
                $this->anchors[$anchorName] = $parsedValue;
            }
        }

        unset($anchorName);
    }

    /**
     * Parse a single inline mapping line (e.g., "key1: val1, key2: val2").
     *
     * @param string $line The inline mapping text
     *
     * @return mixed Parsed key-value array
     */
    private function parseLine(string $line): mixed
    {
        $result = [];
        
        // Split by commas that aren't inside brackets/braces
        $pairs = preg_split('/,\s*(?![^{}\[\]]*[\}\]])/', $line);
        
        foreach ($pairs as $pair) {
            if (preg_match('/^([^:]+):\s*(.*)$/', trim($pair), $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                $result[$key] = $this->parseValue($value);
            }
        }
        
        return $result;
    }

    /**
     * Parse a YAML flow collection (inline array or object).
     *
     * @param string $text Flow collection text starting with [ or {
     *
     * @return mixed Parsed array or associative array
     */
    private function parseFlow(string $text): mixed
    {
        $text = trim($text);
        
        if (str_starts_with($text, '[')) {
            // Flow sequence
            $content = substr($text, 1, -1);
            $items = $this->splitFlow($content);
            $result = [];
            
            foreach ($items as $item) {
                $result[] = $this->parseValue(trim($item));
            }
            
            return $result;
        }
        
        if (str_starts_with($text, '{')) {
            // Flow mapping
            $content = substr($text, 1, -1);
            $items = $this->splitFlow($content);
            $result = [];
            
            foreach ($items as $item) {
                if (preg_match('/^([^:]+):\s*(.*)$/', trim($item), $matches)) {
                    $key = trim($matches[1], '"\'');
                    $value = trim($matches[2]);
                    $result[$key] = $this->parseValue($value);
                }
            }
            
            return $result;
        }
        
        return null;
    }

    /**
     * Split a flow collection's content by commas, respecting nesting and quotes.
     *
     * Tracks bracket depth and quote state so commas inside nested
     * collections or quoted strings are not used as delimiters.
     *
     * @param string $content The inner content of a flow collection (without outer brackets)
     *
     * @return array List of raw item strings
     */
    private function splitFlow(string $content): array
    {
        $items = [];
        $current = '';
        $depth = 0;
        $inQuote = false;
        $quoteChar = null;
        
        for ($i = 0; $i < strlen($content); $i++) {
            $char = $content[$i];
            
            if (($char === '"' || $char === "'") && ($i === 0 || $content[$i - 1] !== '\\')) {
                if (!$inQuote) {
                    $inQuote = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuote = false;
                    $quoteChar = null;
                }
            }
            
            if (!$inQuote) {
                if ($char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ']' || $char === '}') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $items[] = $current;
                    $current = '';
                    continue;
                }
            }
            
            $current .= $char;
        }
        
        if ($current !== '') {
            $items[] = $current;
        }
        
        return $items;
    }

    /**
     * Parse a scalar YAML value into a PHP type.
     *
     * Handles null, booleans, numbers, flow collections, quoted strings,
     * and plain strings.
     *
     * @param string $value The raw scalar text
     *
     * @return mixed The parsed PHP value
     */
    private function parseValue(string $value): mixed
    {
        $value = trim($value);

        // Null
        if ($value === '' || $value === 'null' || $value === '~') {
            return null;
        }

        // Boolean
        if (in_array(strtolower($value), ['true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array(strtolower($value), ['false', 'no', 'off'], true)) {
            return false;
        }

        // Number
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }

        // Flow collection
        if (str_starts_with($value, '[') || str_starts_with($value, '{')) {
            return $this->parseFlow($value);
        }

        // Quoted string
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $unquoted = substr($value, 1, -1);
            // Unescape
            return str_replace(['\\n', '\\t', '\\\\', '\\"'], ["\n", "\t", '\\', '"'], $unquoted);
        }

        // Plain string
        return $value;
    }

    /**
     * Peek ahead to find the indentation level of the next non-empty, non-comment line.
     *
     * Does not advance the current line pointer.
     *
     * @return int Indentation level of the next content line, or 0 if none found
     */
    private function peekNextIndent(): int
    {
        for ($i = $this->currentLine; $i < $this->lineCount; $i++) {
            $line = $this->lines[$i];
            $trimmed = ltrim($line);
            
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            
            return strlen($line) - strlen($trimmed);
        }
        
        return 0;
    }
}
