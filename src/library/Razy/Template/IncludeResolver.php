<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Resolves `<!-- INCLUDE BLOCK: … -->` paths (RZ-11): relative to the template
 * that is being parsed, or `@layout/…` from the dist-wide shared layout folder.
 *
 *
 * @license MIT
 */

namespace Razy\Template;

use Razy\Exception\ConfigurationException;
use Razy\Exception\TemplateException;
use Razy\Util\PathUtil;

/**
 * Turns an INCLUDE path into a template file, or fails loudly.
 *
 * - `@layout/head.tpl` resolves under the layout root (dist.php `'templates' =>
 *   ['layout_path' => …]`, default `layout/` next to dist.php). The file must stay
 *   inside that root after realpath, so `@layout/../x` and symlinks out of it fail.
 *   This is the sanctioned way for several modules to share one head/chrome/foot:
 *   the dist owns the files, no module reads another module's view folder (RZ-001).
 * - Any other path is relative to the directory of the template being parsed,
 *   exactly as before.
 * - A path that does not resolve throws a TemplateException. dist.php
 *   `'templates' => ['strict_include' => false]` restores the old silent skip with an
 *   E_USER_DEPRECATED notice; that switch goes away in the next major.
 *
 * @class IncludeResolver
 */
final class IncludeResolver
{
    /** INCLUDE prefix for the dist-wide shared layout folder. */
    public const LAYOUT_PREFIX = '@layout/';

    /** Default layout folder, relative to the folder that holds dist.php. */
    public const DEFAULT_LAYOUT_PATH = 'layout';

    /** Keys accepted under dist.php 'templates'. */
    private const KEYS = ['layout_path', 'strict_include'];

    /**
     * @param string|null $layoutRoot Absolute layout folder, or null when the app has none
     * @param bool $strict Throw on an INCLUDE that does not resolve
     */
    public function __construct(private readonly ?string $layoutRoot = null, private readonly bool $strict = true)
    {
    }

    /**
     * Build the resolver from dist.php 'templates'. Absent = layout folder `layout/`
     * next to dist.php, strict includes on.
     *
     * @param array<string, mixed> $config The dist.php config array
     * @param string $distCode The dist code, for error messages
     * @param string $folder The folder that holds dist.php
     *
     * @throws ConfigurationException On unknown keys or values of the wrong type
     */
    public static function fromConfig(array $config, string $distCode, string $folder): self
    {
        $templates = $config['templates'] ?? [];
        if (!\is_array($templates)) {
            throw new ConfigurationException("dist.php 'templates' must be an array for dist '{$distCode}'.");
        }
        if ($unknown = \array_diff(\array_keys($templates), self::KEYS)) {
            throw new ConfigurationException(
                "dist.php 'templates' has unknown key(s) for dist '{$distCode}': " . \implode(', ', $unknown)
                . ' (allowed: ' . \implode(', ', self::KEYS) . ').',
            );
        }

        $layout = $templates['layout_path'] ?? self::DEFAULT_LAYOUT_PATH;
        if (!\is_string($layout) || '' === \trim($layout) || \str_contains($layout, "\0")) {
            throw new ConfigurationException("dist.php 'templates' 'layout_path' must be a non-empty path string for dist '{$distCode}'.");
        }
        $strict = $templates['strict_include'] ?? true;
        if (!\is_bool($strict)) {
            throw new ConfigurationException("dist.php 'templates' 'strict_include' must be true or false for dist '{$distCode}'.");
        }

        return new self(self::absolute(\trim($layout), $folder), $strict);
    }

    private static function absolute(string $path, string $folder): string
    {
        $isAbsolute = \str_starts_with($path, '/') || \str_starts_with($path, '\\') || 1 === \preg_match('/^[A-Za-z]:[\\\\\/]/', $path);

        return $isAbsolute ? $path : PathUtil::append($folder, $path);
    }

    /**
     * The layout folder (absolute; it may not exist).
     */
    public function getLayoutRoot(): ?string
    {
        return $this->layoutRoot;
    }

    /**
     * Whether an unresolved INCLUDE throws.
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Resolve an INCLUDE path to a readable template file.
     *
     * @param string $spec The path written in the INCLUDE tag
     * @param string $baseDir Directory that relative paths resolve against
     *
     * @return string|null The real path, or null when skipped (non-strict mode only)
     *
     * @throws TemplateException When the path does not resolve and strict mode is on
     */
    public function resolve(string $spec, string $baseDir): ?string
    {
        $spec = \trim($spec);
        $path = \str_starts_with($spec, self::LAYOUT_PREFIX) ? $this->resolveLayout($spec) : $this->resolveRelative($spec, $baseDir);
        if (\is_string($path)) {
            return $path;
        }

        $message = 'INCLUDE BLOCK: ' . $spec . ' does not resolve: ' . $path[0];
        if ($this->strict) {
            throw new TemplateException($message);
        }
        \trigger_error($message . ". Skipped because dist.php 'templates' 'strict_include' is false; that switch is deprecated.", E_USER_DEPRECATED);

        return null;
    }

    /**
     * Resolve a template path a controller loads directly: `@layout/page` (the whole
     * page is a shared layout; `.tpl` is added when the name has no extension, as for
     * module views), or null when the path is not a layout path.
     *
     * @throws TemplateException When a layout path does not resolve (always, strict or not)
     */
    public function resolveLayoutFile(string $path): ?string
    {
        if (!\str_starts_with($path, self::LAYOUT_PREFIX)) {
            return null;
        }
        if (!\preg_match('/[^.]+\..+/', \basename($path))) {
            $path .= '.tpl';
        }
        $resolved = $this->resolveLayout($path);
        if (!\is_string($resolved)) {
            throw new TemplateException('Template ' . $path . ' does not resolve: ' . $resolved[0]);
        }

        return $resolved;
    }

    /**
     * @return string|array{0: string} The real path, or [reason]
     */
    private function resolveLayout(string $spec): string|array
    {
        if (null === $this->layoutRoot) {
            return ['no layout folder is configured'];
        }
        $root = \realpath($this->layoutRoot);
        if (false === $root || !\is_dir($root)) {
            return ['layout folder ' . $this->layoutRoot . ' does not exist'];
        }
        $relative = \substr($spec, \strlen(self::LAYOUT_PREFIX));
        if ('' === $relative || \str_contains($relative, "\0")) {
            return ['empty or invalid layout path'];
        }
        $path = \realpath(PathUtil::append($root, $relative));
        if (false === $path || !\is_file($path)) {
            return ['no such file in ' . $root];
        }
        if (!\str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return ['the file is outside the layout folder ' . $root];
        }

        return $path;
    }

    /**
     * @return string|array{0: string} The real path, or [reason]
     */
    private function resolveRelative(string $spec, string $baseDir): string|array
    {
        if ('' === $spec || \str_contains($spec, "\0")) {
            return ['empty or invalid path'];
        }
        $path = \realpath(PathUtil::append($baseDir, $spec));
        if (false === $path || !\is_file($path)) {
            return ['no such file relative to ' . $baseDir];
        }

        return $path;
    }
}
