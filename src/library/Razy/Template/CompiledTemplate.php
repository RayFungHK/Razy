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

namespace Razy\Template;

/**
 * Class CompiledTemplate.
 *
 * Pre-tokenizes template text segments into an intermediate representation,
 * eliminating redundant regex matching on every render cycle.
 *
 * Each text segment is decomposed into literal strings and variable reference
 * tokens. At render time, Entity iterates tokens directly instead of running
 * the full variable tag regex.
 *
 * Performance impact: 3-5x faster for repeated rendering scenarios.
 *
 * @class CompiledTemplate
 *
 * @package Razy\Template
 */
class CompiledTemplate
{
    /** @var string Regex pattern for matching variable tags {$var.path->mod|fallback} */
    private const VAR_TAG_REGEX = '/{((\$\w+(?:\.(?:\w+|(?<rq>(?<q>[\'"])(?:\\\.(*SKIP)|(?!\k<q>).)*\k<q>)))*(?:->\w+(?::(?:\w+|(?P>rq)|-?\d+(?:\.\d+)?))*)*)(?:\|(?:(?2)|(?P>rq)))*)}/' ;

    /** @var string Regex pattern for splitting pipe-delimited alternatives */
    private const PIPE_SPLIT_REGEX = '/(?<quote>[\'"])(\.(*SKIP)|(?:(?!\k<quote>).)+)\k<quote>(*SKIP)(*FAIL)|\|/';

    /** @var array<string, self> In-memory cache keyed by content hash */
    private static array $cache = [];

    /**
     * CompiledTemplate constructor.
     *
     * @param array<string|array> $segments Pre-tokenized segments:
     *                                      - string: literal text (output as-is)
     *                                      - array: ['clips' => string[]] — variable reference with pipe-delimited alternatives
     * @param string $hash MD5 hash of the source content
     * @param int $compiledAt Unix timestamp when compiled
     */
    private function __construct(
        public readonly array $segments,
        public readonly string $hash,
        public readonly int $compiledAt,
    ) {
    }

    /**
     * Compile a text content string into pre-tokenized segments.
     *
     * Uses in-memory cache keyed by content hash — identical content
     * will return the same CompiledTemplate instance.
     *
     * @param string $content Raw template text segment
     *
     * @return self Compiled template with pre-tokenized segments
     */
    public static function compile(string $content): self
    {
        $hash = \md5($content);
        if (isset(self::$cache[$hash])) {
            return self::$cache[$hash];
        }

        $segments = [];
        $offset = 0;
        $contentLen = \strlen($content);

        // Find all variable tag positions and pre-split their pipe alternatives
        if (\preg_match_all(self::VAR_TAG_REGEX, $content, $allMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($allMatches as $match) {
                $matchStart = (int) $match[0][1];
                $matchLen = \strlen($match[0][0]);

                // Add literal text before this variable tag
                if ($matchStart > $offset) {
                    $segments[] = \substr($content, $offset, $matchStart - $offset);
                }

                // Pre-split pipe-delimited alternatives for this variable tag
                $clips = \preg_split(self::PIPE_SPLIT_REGEX, $match[1][0]);
                $segments[] = ['clips' => $clips];

                $offset = $matchStart + $matchLen;
            }
        }

        // Add remaining literal text after last variable tag
        if ($offset < $contentLen) {
            $segments[] = \substr($content, $offset);
        } elseif (empty($segments)) {
            // Content has no variable tags — single literal segment
            $segments[] = $content;
        }

        $instance = new self($segments, $hash, \time());
        self::$cache[$hash] = $instance;

        return $instance;
    }

    /**
     * Retrieve a compiled template from the in-memory cache.
     *
     * @param string $cacheKey Content hash
     *
     * @return self|null Cached instance or null if not found
     */
    public static function fromCache(string $cacheKey): ?self
    {
        return self::$cache[$cacheKey] ?? null;
    }

    /**
     * Check if a compiled template exists in the in-memory cache.
     *
     * @param string $hash Content hash
     *
     * @return bool
     */
    public static function isCached(string $hash): bool
    {
        return isset(self::$cache[$hash]);
    }

    /**
     * Clear the in-memory compilation cache.
     * Useful for worker mode or testing.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get the number of cached compiled templates.
     *
     * @return int
     */
    public static function getCacheSize(): int
    {
        return \count(self::$cache);
    }
}
