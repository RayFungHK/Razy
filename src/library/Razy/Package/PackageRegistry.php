<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

namespace Razy\Package;

use Razy\Util\PathUtil;

/**
 * Class PackageRegistry.
 *
 * Scans for packages from the packages directory (.phar archives and
 * directory packages with razy.pkg.json).
 * Use the `-d` flag with `pkg` command to run modules from a dist instead.
 *
 * Expected directory structure:
 *   packages/
 *     my-app.phar          → razy.pkg.json inside with package_name = "my-app"
 *     migrate.phar         → package_name = "migrate"
 *     dashboard/            → directory package with razy.pkg.json
 *       razy.pkg.json
 *       default/controller/
 *
 * Alternative nested .phar structure (for vendor-scoped dist packages):
 *   packages/
 *     vendor/
 *       app.phar            → package_name from razy.pkg.json
 *       tool.phar           → package_name from razy.pkg.json
 *
 * The registry is read-only and stateless — call scan() or find() as needed.
 *
 * @class PackageRegistry
 */
class PackageRegistry
{
    /** @var string Absolute path to the packages directory */
    private string $pkgDir;

    /**
     * @param string $pkgDir Absolute path to the packages directory
     */
    public function __construct(string $pkgDir)
    {
        $this->pkgDir = $pkgDir;
    }

    /**
     * Scan all packages in the packages directory.
     *
     * Searches .phar files recursively up to 2 levels deep and
     * directory packages (folders containing razy.pkg.json) at level 0.
     *
     * @return PackageManifest[] Indexed by package_name
     */
    public function scan(): array
    {
        $manifests = [];

        if (!\is_dir($this->pkgDir)) {
            return $manifests;
        }

        // ── Source 1: .phar files in pkgDir ───────────────────────
        // Level 0: direct .phar files in pkgDir
        foreach (\glob(PathUtil::append($this->pkgDir, '*.phar')) ?: [] as $pharFile) {
            $manifest = PackageManifest::fromPhar($pharFile);
            if ($manifest) {
                $manifests[$manifest->getPackageName()] = $manifest;
            }
        }

        // Level 1: vendor/name.phar
        $dirs = @\scandir($this->pkgDir);
        if (\is_array($dirs)) {
            foreach ($dirs as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $subDir = PathUtil::append($this->pkgDir, $entry);
                if (!\is_dir($subDir)) {
                    continue;
                }

                foreach (\glob(PathUtil::append($subDir, '*.phar')) ?: [] as $pharFile) {
                    $manifest = PackageManifest::fromPhar($pharFile);
                    if ($manifest) {
                        $manifests[$manifest->getPackageName()] = $manifest;
                    }
                }
            }
        }

        // ── Source 2: directory packages (razy.pkg.json) ──────────
        // Level 0: direct directories in pkgDir containing razy.pkg.json
        if (\is_array($dirs)) {
            foreach ($dirs as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $subDir = PathUtil::append($this->pkgDir, $entry);
                if (!\is_dir($subDir)) {
                    continue;
                }

                $jsonPath = PathUtil::append($subDir, 'razy.pkg.json');
                if (\is_file($jsonPath)) {
                    $manifest = PackageManifest::fromDirectory($subDir);
                    if ($manifest && !isset($manifests[$manifest->getPackageName()])) {
                        $manifests[$manifest->getPackageName()] = $manifest;
                    }
                }
            }
        }

        return $manifests;
    }

    /**
     * Find a specific package by name.
     *
     * Attempts multiple resolution strategies:
     * 1. Flat .phar: packages/<vendor>__<name>.phar
     * 2. Vendor dir: packages/<vendor>/<name>.phar
     * 3. Versioned: packages/<vendor>/<name>/<version>.phar
     * 4. Full scan fallback
     *
     * @param string $packageName Package name (e.g., "vendor/app")
     *
     * @return PackageManifest|null
     */
    public function find(string $packageName): ?PackageManifest
    {
        if (!\is_dir($this->pkgDir)) {
            return null;
        }

        // Validate package name to prevent directory traversal
        if (!\preg_match('#^[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)*$#', $packageName)) {
            return null;
        }

        // ── Strategy 0: Directory package (pkgDir/name/razy.pkg.json) ─
        $dirPath = PathUtil::append($this->pkgDir, $packageName);
        if (\is_dir($dirPath) && \is_file(PathUtil::append($dirPath, 'razy.pkg.json'))) {
            $manifest = PackageManifest::fromDirectory($dirPath);
            if ($manifest && $manifest->getPackageName() === $packageName) {
                return $manifest;
            }
        }

        // ── Strategy 1: Flat .phar layout (vendor__app.phar) ──────
        $flatName = \str_replace('/', '__', $packageName);
        $flatPath = PathUtil::append($this->pkgDir, $flatName . '.phar');
        if (\is_file($flatPath)) {
            $manifest = PackageManifest::fromPhar($flatPath);
            if ($manifest && $manifest->getPackageName() === $packageName) {
                return $manifest;
            }
        }

        // ── Strategy 2: Vendor directory layout (vendor/app.phar) ─
        if (\str_contains($packageName, '/')) {
            [$vendor, $name] = \explode('/', $packageName, 2);
            $vendorPath = PathUtil::append($this->pkgDir, $vendor, $name . '.phar');
            if (\is_file($vendorPath)) {
                $manifest = PackageManifest::fromPhar($vendorPath);
                if ($manifest && $manifest->getPackageName() === $packageName) {
                    return $manifest;
                }
            }
        }

        // ── Strategy 3: Versioned phar (vendor/name/1.0.0.phar) ──
        if (\str_contains($packageName, '/')) {
            [$vendor, $name] = \explode('/', $packageName, 2);
            $pkgPath = PathUtil::append($this->pkgDir, $vendor, $name);
            if (\is_dir($pkgPath)) {
                $pharFiles = \glob(PathUtil::append($pkgPath, '*.phar'));
                if (!empty($pharFiles)) {
                    \usort($pharFiles, function (string $a, string $b) {
                        $va = \basename($a, '.phar');
                        $vb = \basename($b, '.phar');

                        return \version_compare($vb, $va);
                    });

                    foreach ($pharFiles as $pharFile) {
                        $manifest = PackageManifest::fromPhar($pharFile);
                        if ($manifest && $manifest->getPackageName() === $packageName) {
                            return $manifest;
                        }
                    }
                }
            }
        }

        // ── Strategy 4: Full scan fallback ────────────────────────
        $all = $this->scan();

        return $all[$packageName] ?? null;
    }

    /**
     * Get the packages directory path.
     *
     * @return string
     */
    public function getDirectory(): string
    {
        return $this->pkgDir;
    }
}
