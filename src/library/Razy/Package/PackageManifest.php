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

/**
 * Class PackageManifest.
 *
 * Represents a parsed razy.pkg.json manifest.
 * Works with both .phar packaged apps and dist/module directories.
 * Provides typed access to all manifest fields with sensible defaults.
 *
 * razy.pkg.json Schema:
 * {
 *   "package_name": "my-app",           // Required. Unique package identifier.
 *   "version": "1.0.0",                  // Required. SemVer version string.
 *   "description": "...",                // Optional. Human-readable description.
 *   "mode": "serve|exec",               // Required. Execution mode.
 *   "strict": false,                     // Optional. When true, serve binds to localhost only.
 *   "on_depend": [                       // Optional. 0-N dependency declarations.
 *     {
 *       "package": "db-cache",           //   Package name to depend on.
 *       "wait": "complete|healthcheck|load" // "complete"    = run exec dependency, wait to finish.
 *     }                                  //   "healthcheck" = spawn serve dep, poll until healthy.
 *   ],                                   //   "load"        = load as co-module (API/events enabled).
 *   "healthcheck": {                     // Optional. Health endpoint for serve-mode packages.
 *     "url": "http://localhost:8080/health",
 *     "interval": 2,                     //   Seconds between checks (default: 2).
 *     "timeout": 30,                     //   Max seconds to wait for healthy (default: 30).
 *     "start_period": 5                  //   Seconds to wait before first check (default: 5).
 *   },
 *   "prerequisite": {                    // Optional. Composer packages to auto-install.
 *     "monolog/monolog": "^3.0"          //   Installed to ./runtime/autoload/<name>/<version>/
 *   },
 *   "serve": {                           // Optional. Serve-mode configuration.
 *     "host": "localhost",               //   Bind host (overridden by strict=true → localhost).
 *     "port": 8080                       //   Bind port.
 *   }
 * }
 *
 * @class PackageManifest
 */
class PackageManifest
{
    /** @var string Source type: 'phar' for .phar archives, 'directory' for dist/module folders */
    private string $sourceType;

    /** @var string Absolute path to the .phar file or module directory */
    private string $sourcePath;

    /** @var array<string, mixed> Raw parsed razy.pkg.json data */
    private array $data;

    /**
     * PackageManifest constructor.
     *
     * @param string $sourcePath Absolute path to the .phar archive or module directory
     * @param array<string,mixed> $data Parsed razy.pkg.json contents
     * @param string $sourceType 'phar' or 'directory'
     */
    public function __construct(string $sourcePath, array $data, string $sourceType = 'phar')
    {
        $this->sourcePath = $sourcePath;
        $this->data = $data;
        $this->sourceType = $sourceType;
    }

    /**
     * Load a PackageManifest from a .phar file.
     *
     * Reads 'razy.pkg.json' from the phar root. Returns null if the file
     * does not exist or contains invalid JSON.
     *
     * @param string $pharPath Absolute path to the .phar archive
     *
     * @return self|null
     */
    public static function fromPhar(string $pharPath): ?self
    {
        if (!\is_file($pharPath)) {
            return null;
        }

        $jsonPath = 'phar://' . \str_replace('\\', '/', $pharPath) . '/razy.pkg.json';
        if (!\is_file($jsonPath)) {
            return null;
        }

        $content = @\file_get_contents($jsonPath);
        if ($content === false) {
            return null;
        }

        $data = \json_decode($content, true);
        if (!\is_array($data) || \json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Validate required fields
        if (empty($data['package_name']) || empty($data['version']) || empty($data['mode'])) {
            return null;
        }

        if (!\in_array($data['mode'], ['serve', 'exec'], true)) {
            return null;
        }

        return new self($pharPath, $data, 'phar');
    }

    /**
     * Load a PackageManifest from a dist/module directory.
     *
     * Reads 'razy.pkg.json' from the module folder root. Returns null if
     * the file does not exist or contains invalid JSON.
     *
     * @param string $dirPath Absolute path to the module directory
     *
     * @return self|null
     */
    public static function fromDirectory(string $dirPath): ?self
    {
        if (!\is_dir($dirPath)) {
            return null;
        }

        $jsonPath = \rtrim($dirPath, '/\\') . \DIRECTORY_SEPARATOR . 'razy.pkg.json';
        if (!\is_file($jsonPath)) {
            return null;
        }

        $content = @\file_get_contents($jsonPath);
        if ($content === false) {
            return null;
        }

        $data = \json_decode($content, true);
        if (!\is_array($data) || \json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // Validate required fields
        if (empty($data['package_name']) || empty($data['version']) || empty($data['mode'])) {
            return null;
        }

        if (!\in_array($data['mode'], ['serve', 'exec'], true)) {
            return null;
        }

        return new self($dirPath, $data, 'directory');
    }

    /**
     * Create a manifest from a raw array (for testing or inline construction).
     *
     * @param string $sourcePath Path to .phar or directory
     * @param array<string,mixed> $data
     * @param string $sourceType 'phar' or 'directory'
     *
     * @return self
     */
    public static function fromArray(string $sourcePath, array $data, string $sourceType = 'phar'): self
    {
        return new self($sourcePath, $data, $sourceType);
    }

    // ── Accessors ─────────────────────────────────────────────────

    /**
     * Get the source path (.phar file or module directory).
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    /**
     * Get the source type: 'phar' or 'directory'.
     */
    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    /**
     * Whether this manifest was loaded from a directory (dist/module).
     */
    public function isDirectory(): bool
    {
        return $this->sourceType === 'directory';
    }

    /**
     * @deprecated Use getSourcePath() instead
     */
    public function getPharPath(): string
    {
        return $this->sourcePath;
    }

    public function getPackageName(): string
    {
        return $this->data['package_name'] ?? '';
    }

    public function getVersion(): string
    {
        return $this->data['version'] ?? '0.0.0';
    }

    public function getDescription(): string
    {
        return $this->data['description'] ?? '';
    }

    /**
     * Execution mode: 'serve' or 'exec'.
     */
    public function getMode(): string
    {
        return $this->data['mode'] ?? 'exec';
    }

    /**
     * When true, serve mode binds to localhost only.
     */
    public function isStrict(): bool
    {
        return !empty($this->data['strict']);
    }

    /**
     * Dependency declarations.
     *
     * Each entry: ['package' => 'vendor/name', 'wait' => 'complete|healthcheck|load']
     *
     * @return array<int, array{package: string, wait: string}>
     */
    public function getOnDepend(): array
    {
        $raw = $this->data['on_depend'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $dep) {
            if (!\is_array($dep) || empty($dep['package'])) {
                continue;
            }
            $wait = ($dep['wait'] ?? 'complete');
            if (!\in_array($wait, ['complete', 'healthcheck', 'load'], true)) {
                $wait = 'complete';
            }
            $result[] = [
                'package' => (string) $dep['package'],
                'wait' => $wait,
            ];
        }

        return $result;
    }

    /**
     * Healthcheck configuration for serve-mode packages.
     *
     * @return array{url?: string, interval?: int, timeout?: int, start_period?: int}
     */
    public function getHealthcheck(): array
    {
        $hc = $this->data['healthcheck'] ?? [];
        if (!\is_array($hc)) {
            return [];
        }

        return [
            'url' => (string) ($hc['url'] ?? ''),
            'interval' => (int) ($hc['interval'] ?? 2),
            'timeout' => (int) ($hc['timeout'] ?? 30),
            'start_period' => (int) ($hc['start_period'] ?? 5),
        ];
    }

    /**
     * Composer prerequisite packages.
     *
     * @return array<string, string> Package name => version constraint
     */
    public function getPrerequisite(): array
    {
        $raw = $this->data['prerequisite'] ?? [];
        if (!\is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $pkg => $ver) {
            if (\is_string($pkg) && \is_string($ver)) {
                $result[$pkg] = $ver;
            }
        }

        return $result;
    }

    /**
     * Serve-mode configuration (host/port).
     *
     * @return array{host: string, port: int}
     */
    public function getServeConfig(): array
    {
        $serve = $this->data['serve'] ?? [];
        if (!\is_array($serve)) {
            $serve = [];
        }

        $host = (string) ($serve['host'] ?? 'localhost');
        $port = (int) ($serve['port'] ?? 8080);

        // Enforce strict mode
        if ($this->isStrict()) {
            $host = 'localhost';
        }

        return [
            'host' => $host,
            'port' => \max(1, \min(65535, $port)),
        ];
    }

    /**
     * Get the full raw data array.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->data;
    }
}
