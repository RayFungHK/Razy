<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Worker;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Detects file changes in module directories and classifies the change type.
 *
 * Maintains a snapshot of file hashes per module. On each check, compares
 * current filesystem state against the snapshot to determine:
 *   - ChangeType::None       → no files changed
 *   - ChangeType::Config     → only package.php / templates / assets changed (hot-swap safe)
 *   - ChangeType::Rebindable → PHP files changed but only anonymous classes/closures (rebind safe)
 *   - ChangeType::ClassFile  → PHP files with named class definitions changed (restart required)
 *
 * PHP cannot unload named class definitions, but anonymous classes produce
 * unique internal names on each include, making them safe to re-include
 * and rebind via the Container.
 */
class ModuleChangeDetector
{
    /**
     * File extensions that are considered non-class (config/template/asset).
     * All .php files except package.php are treated as class files.
     */
    private const CONFIG_EXTENSIONS = [
        'tpl', 'html', 'css', 'js', 'json', 'xml', 'yaml', 'yml',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'txt', 'md', 'csv',
    ];

    /**
     * PHP files that are NOT class definitions (pure config).
     */
    private const CONFIG_PHP_FILES = [
        'package.php',
    ];

    /**
     * File hash snapshots per module.
     * Structure: [moduleCode => [relativePath => md5Hash]].
     *
     * @var array<string, array<string, string>>
     */
    private array $snapshots = [];

    /**
     * Map of module code => base directory path.
     *
     * @var array<string, string>
     */
    private array $modulePaths = [];

    /**
     * Modules that had changes on last detect() call.
     * Structure: [moduleCode => ChangeType].
     *
     * @var array<string, ChangeType>
     */
    private array $lastChanges = [];

    /**
     * Take a snapshot of all files in a module directory.
     * Call this after initial module loading to establish a baseline.
     *
     * @param string $moduleCode Unique module identifier
     * @param string $modulePath Absolute path to the module directory
     */
    public function snapshot(string $moduleCode, string $modulePath): void
    {
        $modulePath = \rtrim($modulePath, '/\\');
        $this->modulePaths[$moduleCode] = $modulePath;
        $this->snapshots[$moduleCode] = $this->hashDirectory($modulePath);
    }

    /**
     * Detect changes for a specific module against its snapshot.
     *
     * Classification uses severity-based precedence:
     *   ClassFile > Rebindable > Config > None
     *
     * For each changed .php file (except package.php), the file content is
     * scanned for named class/interface/trait/enum declarations. If any are
     * found, the change is ClassFile (restart required). If only anonymous
     * classes or closures are present, the change is Rebindable.
     *
     * @param string $moduleCode The module to check
     *
     * @return ChangeType The type of change detected
     */
    public function detect(string $moduleCode): ChangeType
    {
        if (!isset($this->snapshots[$moduleCode]) || !isset($this->modulePaths[$moduleCode])) {
            // New module (no snapshot) — treat as class change to be safe
            return ChangeType::ClassFile;
        }

        $currentHashes = $this->hashDirectory($this->modulePaths[$moduleCode]);
        $oldHashes = $this->snapshots[$moduleCode];

        $changedFiles = $this->diffHashes($oldHashes, $currentHashes);

        if (empty($changedFiles)) {
            return ChangeType::None;
        }

        // Track the highest severity change found
        $maxSeverity = ChangeType::None;

        foreach ($changedFiles as $relativePath) {
            $fileChange = $this->classifyFile($relativePath, $this->modulePaths[$moduleCode]);

            if ($fileChange->severity() > $maxSeverity->severity()) {
                $maxSeverity = $fileChange;
            }

            // Short-circuit: ClassFile is the highest severity
            if ($maxSeverity === ChangeType::ClassFile) {
                return ChangeType::ClassFile;
            }
        }

        return $maxSeverity;
    }

    /**
     * Detect changes across ALL registered modules.
     *
     * @return array<string, ChangeType> Map of moduleCode => ChangeType (only changed modules included)
     */
    public function detectAll(): array
    {
        $this->lastChanges = [];

        foreach ($this->modulePaths as $moduleCode => $_) {
            $changeType = $this->detect($moduleCode);
            if ($changeType !== ChangeType::None) {
                $this->lastChanges[$moduleCode] = $changeType;
            }
        }

        return $this->lastChanges;
    }

    /**
     * Get the overall change type across all modules.
     * Returns the most severe change type using severity ordering:
     *   ClassFile > Rebindable > Config > None.
     *
     * @return ChangeType The most severe change type
     */
    public function detectOverall(): ChangeType
    {
        $changes = $this->detectAll();

        if (empty($changes)) {
            return ChangeType::None;
        }

        $maxSeverity = ChangeType::None;
        foreach ($changes as $changeType) {
            if ($changeType->severity() > $maxSeverity->severity()) {
                $maxSeverity = $changeType;
            }
            // Short-circuit on ClassFile (highest severity)
            if ($maxSeverity === ChangeType::ClassFile) {
                return ChangeType::ClassFile;
            }
        }

        return $maxSeverity;
    }

    /**
     * Get modules that changed on the last detectAll() call.
     *
     * @return array<string, ChangeType>
     */
    public function getChangedModules(): array
    {
        return $this->lastChanges;
    }

    /**
     * Get modules that only had config changes (hot-swap candidates).
     *
     * @return string[] Module codes
     */
    public function getHotSwappableModules(): array
    {
        return \array_keys(\array_filter(
            $this->lastChanges,
            fn (ChangeType $ct) => $ct === ChangeType::Config,
        ));
    }

    /**
     * Get modules that require restart (class file changes).
     *
     * @return string[] Module codes
     */
    public function getRestartRequiredModules(): array
    {
        return \array_keys(\array_filter(
            $this->lastChanges,
            fn (ChangeType $ct) => $ct === ChangeType::ClassFile,
        ));
    }

    /**
     * Get modules that can be rebound (anonymous PHP changes).
     *
     * @return string[] Module codes
     */
    public function getRebindableModules(): array
    {
        return \array_keys(\array_filter(
            $this->lastChanges,
            fn (ChangeType $ct) => $ct === ChangeType::Rebindable,
        ));
    }

    /**
     * Refresh snapshot for a module (call after successful hot-swap).
     *
     * @param string $moduleCode The module to refresh
     */
    public function refreshSnapshot(string $moduleCode): void
    {
        if (isset($this->modulePaths[$moduleCode])) {
            $this->snapshots[$moduleCode] = $this->hashDirectory($this->modulePaths[$moduleCode]);
        }
    }

    /**
     * Refresh snapshots for all registered modules.
     */
    public function refreshAll(): void
    {
        foreach ($this->modulePaths as $moduleCode => $path) {
            $this->snapshots[$moduleCode] = $this->hashDirectory($path);
        }
    }

    /**
     * Check if a module is registered.
     *
     * @param string $moduleCode
     *
     * @return bool
     */
    public function hasModule(string $moduleCode): bool
    {
        return isset($this->modulePaths[$moduleCode]);
    }

    /**
     * Get all registered module codes.
     *
     * @return string[]
     */
    public function getRegisteredModules(): array
    {
        return \array_keys($this->modulePaths);
    }

    /**
     * Determine if a file path represents a PHP class definition.
     *
     * Rules:
     * - Non-.php files → NOT a class file
     * - package.php → NOT a class file (it returns a config array)
     * - All other .php files → IS a class file (controller, library, model)
     *
     * @param string $relativePath Relative path within the module directory
     *
     * @return bool
     */
    public function isClassFile(string $relativePath): bool
    {
        $extension = \strtolower(\pathinfo($relativePath, PATHINFO_EXTENSION));

        // Non-PHP files are never class files
        if ($extension !== 'php') {
            return false;
        }

        // Check if this PHP file is a known config file
        $basename = \basename($relativePath);
        return !\in_array($basename, self::CONFIG_PHP_FILES, true);
    }

    /**
     * Classify a changed file by examining its content.
     *
     * For non-PHP files or config PHP files (package.php), returns Config.
     * For other PHP files, scans the source to distinguish:
     *   - Named class/interface/trait/enum declarations → ClassFile
     *   - Only anonymous classes / closures → Rebindable
     *
     * If the file was deleted (no longer exists), it is treated as Rebindable
     * since the old class definition is already loaded and won't conflict.
     *
     * @param string $relativePath Relative path within the module directory
     * @param string $modulePath Absolute path to the module directory
     *
     * @return ChangeType
     */
    public function classifyFile(string $relativePath, string $modulePath): ChangeType
    {
        $extension = \strtolower(\pathinfo($relativePath, PATHINFO_EXTENSION));

        // Non-PHP files are always config
        if ($extension !== 'php') {
            return ChangeType::Config;
        }

        // Known config PHP files
        $basename = \basename($relativePath);
        if (\in_array($basename, self::CONFIG_PHP_FILES, true)) {
            return ChangeType::Config;
        }

        // Build absolute path
        $absolutePath = \rtrim($modulePath, '/\\') . '/' . \ltrim(\str_replace('\\', '/', $relativePath), '/');

        // Deleted PHP files — the class was already loaded, so no conflict
        if (!\is_file($absolutePath)) {
            return ChangeType::Rebindable;
        }

        // Scan file content for named declarations
        return $this->classifyPhpFile($absolutePath);
    }

    /**
     * Scan a PHP file to determine if it declares named classes.
     *
     * Uses token-based analysis to detect named class, interface, trait,
     * and enum declarations. Files that only contain anonymous classes
     * (return new class { ... }) or closures are classified as Rebindable.
     *
     * @param string $absolutePath Absolute filesystem path to the PHP file
     *
     * @return ChangeType ClassFile if named declarations found, Rebindable otherwise
     */
    public function classifyPhpFile(string $absolutePath): ChangeType
    {
        if (!\is_file($absolutePath)) {
            return ChangeType::Rebindable;
        }

        $content = \file_get_contents($absolutePath);
        if ($content === false) {
            // Cannot read — be safe, treat as ClassFile
            return ChangeType::ClassFile;
        }

        // Use PHP tokenizer for accurate detection
        $tokens = \token_get_all($content);
        $tokenCount = \count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            if (!\is_array($tokens[$i])) {
                continue;
            }

            $tokenId = $tokens[$i][0];

            // Look for class, interface, trait, enum keywords
            if ($tokenId === T_CLASS || $tokenId === T_INTERFACE || $tokenId === T_TRAIT || $tokenId === T_ENUM) {
                // Check if this is "new class" (anonymous) — look backwards for T_NEW
                if ($tokenId === T_CLASS) {
                    // Scan backwards over whitespace/comments to find T_NEW
                    $isAnonymous = false;
                    for ($j = $i - 1; $j >= 0; $j--) {
                        if (\is_array($tokens[$j])) {
                            if ($tokens[$j][0] === T_NEW) {
                                $isAnonymous = true;
                                break;
                            }
                            if ($tokens[$j][0] === T_WHITESPACE || $tokens[$j][0] === T_COMMENT || $tokens[$j][0] === T_DOC_COMMENT) {
                                continue;
                            }
                        }
                        break; // Non-whitespace, non-new token — not anonymous
                    }
                    if ($isAnonymous) {
                        continue; // Skip anonymous class
                    }
                }

                // Look forward for a T_STRING (the class/interface/trait/enum name)
                for ($k = $i + 1; $k < $tokenCount; $k++) {
                    if (\is_array($tokens[$k])) {
                        if ($tokens[$k][0] === T_WHITESPACE) {
                            continue;
                        }
                        if ($tokens[$k][0] === T_STRING) {
                            // Found a named declaration
                            return ChangeType::ClassFile;
                        }
                    }
                    break;
                }
            }
        }

        // No named declarations — safe to rebind
        return ChangeType::Rebindable;
    }

    /**
     * Recursively hash all files in a directory.
     *
     * @param string $directory Absolute path
     *
     * @return array<string, string> Map of relative path => md5 hash
     */
    private function hashDirectory(string $directory): array
    {
        $hashes = [];

        if (!\is_dir($directory)) {
            return $hashes;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            $relativePath = $this->getRelativePath($directory, $file->getPathname());
            $hashes[$relativePath] = \md5_file($file->getPathname());
        }

        \ksort($hashes);
        return $hashes;
    }

    /**
     * Compare two hash arrays and return the list of changed files.
     * Includes new files, removed files, and files with different hashes.
     *
     * @param array<string, string> $old Old hashes
     * @param array<string, string> $new Current hashes
     *
     * @return string[] List of changed file relative paths
     */
    private function diffHashes(array $old, array $new): array
    {
        $changed = [];

        // Files modified or removed
        foreach ($old as $path => $hash) {
            if (!isset($new[$path]) || $new[$path] !== $hash) {
                $changed[] = $path;
            }
        }

        // New files
        foreach ($new as $path => $_) {
            if (!isset($old[$path])) {
                $changed[] = $path;
            }
        }

        return \array_unique($changed);
    }

    /**
     * Get relative path from a base directory.
     *
     * @param string $base Base directory
     * @param string $path Full file path
     *
     * @return string Relative path with forward slashes
     */
    private function getRelativePath(string $base, string $path): string
    {
        $base = \str_replace('\\', '/', \rtrim($base, '/\\')) . '/';
        $path = \str_replace('\\', '/', $path);

        if (\str_starts_with($path, $base)) {
            return \substr($path, \strlen($base));
        }

        return $path;
    }
}
