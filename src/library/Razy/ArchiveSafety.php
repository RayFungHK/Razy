<?php

namespace Razy;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Archive & package-source safety primitives.
 *
 * Extracted from the 2026 security audit findings (RAZY-ANALYSIS-REPORT.md §S2,
 * "Zip-slip double-dip"): two independent `ZipArchive::extractTo()` call sites
 * (PackageManager, RepoInstaller) trusted attacker-controlled entry names, built
 * extraction directories from raw package names, and accepted plain-HTTP
 * distribution URLs. This class centralizes the four defenses so both installers
 * share one tested implementation:
 *
 *  1. validateEntryName()  — traversal / absolute-path / wrapper rejection
 *  2. validateArchive()    — enumerate a ZipArchive's entries BEFORE extracting
 *  3. rejectSymlinks()     — post-extraction scan (zip symlink entries would let
 *                            a later write hop out through an extracted link)
 *  4. isSecureUrl()        — plain-HTTP rejection with explicit opt-in
 *
 * All methods are pure/static — unit-tested in tests/ArchiveSafetyTest.php.
 */
class ArchiveSafety
{
    /**
     * Validate a single archive entry name against path-traversal attacks.
     *
     * Rejects: absolute paths ("/x", "C:\x", "\\server\x"), any ".." segment
     * (in "/" or "\" form), NUL bytes, control characters, stream wrappers
     * ("phar://", "zip://", "file://..."), and empty/`.` segments.
     *
     * @param string $name raw entry name from a ZIP archive
     *
     * @return bool true when the name is safe to extract under a base directory
     */
    public static function validateEntryName(string $name): bool
    {
        if ($name === '' || \str_contains($name, "\0")) {
            return false;
        }

        // Control characters anywhere (e.g. "\n\r" smuggling) are never legitimate.
        if (\preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return false;
        }

        // Absolute or drive-qualified paths.
        if (\str_starts_with($name, '/') || \str_starts_with($name, '\\') || \preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        // Stream-wrapper style names ("phar://...", "zip://...", "file://...").
        if (\preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $name) === 1) {
            return false;
        }

        // Normalize separators, then reject any traversal or current-dir segment.
        // Directory entries legitimately end with a single "/" — tolerate exactly
        // one trailing separator, but nothing else that yields empty segments.
        $trimmed = \rtrim($name, '/\\');
        $segments = \preg_split('#[/\\\]#', $trimmed) ?: [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Enumerate every entry in a (opened) ZipArchive and return the names that
     * fail validateEntryName(). An empty list means the archive is safe to extract.
     *
     * @return list<string> offending entry names
     */
    public static function validateArchive(ZipArchive $zip): array
    {
        $bad = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            // getNameIndex() is this build's index→name API (ext-zip 1.21.x ships
            // no getName()); statName() is name-indexed only — verified 8.3.1.
            $name = $zip->getNameIndex($i);

            if (!\is_string($name)) {
                $bad[] = "#{$i} (unreadable entry)";

                continue;
            }

            if (!self::validateEntryName($name)) {
                $bad[] = $name;
            }
        }

        return $bad;
    }

    /**
     * Post-extraction scan for symlinks (Unix and Windows junctions surface as
     * links too). Returns the offending paths so callers can purge the directory.
     *
     * @return list<string> symlink paths found under $dir
     */
    public static function findSymlinks(string $dir): array
    {
        $links = [];

        if (!\is_dir($dir)) {
            return $links;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $path) {
            if (\is_link((string) $path)) {
                $links[] = (string) $path;
            }
        }

        return $links;
    }

    /**
     * Sanitize a composer-style package name ("vendor/pkg-name") for safe
     * interpolation into filesystem paths. Returns null when the name is hostile.
     */
    public static function sanitizePackageName(string $name): ?string
    {
        // Composer names: [a-z0-9._-]+/[a-z0-9._-]+ — allow a single "/" separator.
        if (\preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)?$#', $name) !== 1) {
            return null;
        }

        foreach (\explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $name;
    }

    /**
     * Distribution-URL policy: HTTPS only by default. Scheme-less values are
     * treated as local paths (LocalTransport) and pass. Plain HTTP requires an
     * explicit $allowInsecure opt-in (operator config), never a silent default.
     */
    public static function isSecureUrl(string $url, bool $allowInsecure = false): bool
    {
        $scheme = \strtolower((string) (\parse_url($url, \PHP_URL_SCHEME) ?: ''));

        if ($scheme === '') {
            return true; // local file path — no network exposure
        }

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme === 'http') {
            return $allowInsecure;
        }

        // ftp://, sftp://, smb:// etc. — transports the operator configured
        // deliberately; the URL came from their own repositories.json.
        return \in_array($scheme, ['ftp', 'sftp', 'smb', 'file'], true);
    }

    /**
     * Best-effort recursive delete used to purge rejected extractions.
     * Unlinks symlinks (never follows them) before descending.
     */
    public static function purgeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            @\unlink($dir);

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @\unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @\rmdir($entry->getPathname());
            }
        }

        @\rmdir($dir);
    }
}
