<?php

/**
 * This file is part of Razy v0.5.
 *
 * Native YAML parser and dumper facade for the Razy framework. Delegates
 * parsing to YAMLParser and dumping to YAMLDumper.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Razy\Exception\FileException;
use Razy\YAML\YAMLDumper;
use Razy\YAML\YAMLParser;
use RuntimeException;

/**
 * YAML facade class providing static parse/dump methods.
 *
 * Supports YAML 1.2 subset:
 * - Mappings (key-value pairs)
 * - Sequences (lists)
 * - Scalars (strings, numbers, booleans, null)
 * - Comments
 * - Multi-line strings (literal | and folded >)
 * - Nested structures
 * - Flow collections (inline arrays/objects)
 * - Anchors and aliases
 *
 * @class YAML
 */
class YAML
{
    /**
     * Parse YAML string into PHP array.
     *
     * @param string $yaml YAML string
     *
     * @return mixed Parsed data
     *
     * @throws RuntimeException
     */
    public static function parse(string $yaml): mixed
    {
        try {
            $parser = new YAMLParser($yaml);
            return $parser->parse();
        } catch (Exception $e) {
            throw new RuntimeException('YAML parse error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Parse YAML file into PHP array.
     *
     * @param string $filename Path to YAML file
     *
     * @return mixed Parsed data
     *
     * @throws FileException|RuntimeException
     */
    public static function parseFile(string $filename): mixed
    {
        if (!\file_exists($filename)) {
            throw new FileException("YAML file not found: {$filename}");
        }

        if (!\is_readable($filename)) {
            throw new FileException("YAML file not readable: {$filename}");
        }

        // Try to load from cache (validated against file modification time)
        $realPath = \realpath($filename);
        if ($realPath !== false) {
            $cacheKey = 'yaml.' . \md5($realPath);
            $cached = Cache::getValidated($cacheKey, $realPath);
            if ($cached !== null) {
                return $cached;
            }
        }

        $content = \file_get_contents($filename);
        if ($content === false) {
            throw new FileException("Failed to read YAML file: {$filename}");
        }

        $data = self::parse($content);

        // Cache the parsed result with file mtime validation
        if ($realPath !== false) {
            Cache::setValidated($cacheKey, $realPath, $data);
        }

        return $data;
    }

    /**
     * Dump PHP data to YAML string.
     *
     * @param mixed $data Data to dump
     * @param int $indent Indentation spaces (default: 2)
     * @param int $inline Inline arrays from this level (default: 4)
     *
     * @return string YAML string
     */
    public static function dump(mixed $data, int $indent = 2, int $inline = 4): string
    {
        $dumper = new YAMLDumper($indent, $inline);
        return $dumper->dump($data);
    }

    /**
     * Dump PHP data to YAML file.
     *
     * @param string $filename Path to YAML file
     * @param mixed $data Data to dump
     * @param int $indent Indentation spaces
     * @param int $inline Inline arrays from this level
     *
     * @return bool Success
     *
     * @throws FileException
     */
    public static function dumpFile(string $filename, mixed $data, int $indent = 2, int $inline = 4): bool
    {
        $yaml = self::dump($data, $indent, $inline);

        $dir = \dirname($filename);
        if (!\is_dir($dir)) {
            if (!\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
                throw new FileException("Failed to create directory: {$dir}");
            }
        }

        $result = \file_put_contents($filename, $yaml);
        if ($result === false) {
            throw new FileException("Failed to write YAML file: {$filename}");
        }

        return true;
    }
}
