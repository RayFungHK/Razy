<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Asset;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Razy\Http\StaticFile;
use UnexpectedValueException;

/**
 * Content hashes of one module's `webassets/` folder (RZ-09).
 *
 * A file's hash is the first 16 hex digits of its xxh128 digest, cached per
 * process by path, mtime and size, so worker mode hashes each file once per
 * change. Only files {@see StaticFile::resolve()} would serve are listed or
 * hashed (no dotfiles, nothing outside the folder).
 *
 * @license MIT
 */
final class AssetManifest
{
    /** @var array<string, array{0: int, 1: int, 2: string}> Real path => [mtime, size, hash] */
    private static array $hashes = [];

    /**
     * @param string $root The module's webassets folder
     */
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return string The webassets folder
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * The real path of a file in the folder, or null.
     *
     * @param string $file Path below the folder (not URL-encoded)
     *
     * @return string|null
     */
    public function path(string $file): ?string
    {
        return StaticFile::resolve($this->root, \implode('/', \array_map('rawurlencode', \explode('/', $file))));
    }

    /**
     * The content hash of a file in the folder, or null when it is missing.
     *
     * @param string $file Path below the folder (not URL-encoded)
     *
     * @return string|null
     */
    public function hash(string $file): ?string
    {
        $path = $this->path($file);

        return null === $path ? null : self::hashPath($path);
    }

    /**
     * The content hash of a file already resolved by {@see path()}.
     *
     * @param string $path The file's path
     *
     * @return string
     */
    public static function hashPath(string $path): string
    {
        \clearstatcache(true, $path);
        $mtime = (int) @\filemtime($path);
        $size = (int) @\filesize($path);
        $cached = self::$hashes[$path] ?? null;
        if (null !== $cached && $cached[0] === $mtime && $cached[1] === $size) {
            return $cached[2];
        }
        $hash = \substr((string) \hash_file('xxh128', $path), 0, 16);
        self::$hashes[$path] = [$mtime, $size, $hash];

        return $hash;
    }

    /**
     * Files in the folder with one of the given extensions, sorted.
     *
     * @param list<string> $extensions Lower-case extensions without the dot
     *
     * @return list<string> Paths below the folder, `/`-separated
     */
    public function files(array $extensions): array
    {
        if (!\is_dir($this->root)) {
            return [];
        }
        $root = \rtrim(\str_replace('\\', '/', $this->root), '/') . '/';
        $files = [];
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO),
            );
            foreach ($iterator as $info) {
                if (!$info->isFile() || !\in_array(\strtolower($info->getExtension()), $extensions, true)) {
                    continue;
                }
                $relative = \substr(\str_replace('\\', '/', $info->getPathname()), \strlen($root));
                if (null !== $this->path($relative)) {
                    $files[] = $relative;
                }
            }
        } catch (UnexpectedValueException) {
            return [];
        }
        \sort($files, SORT_STRING);

        return $files;
    }
}
