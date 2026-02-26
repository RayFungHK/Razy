<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Package manager for downloading, extracting, and version-locking
 * Composer-compatible packages from any repository that follows the
 * Composer/Packagist metadata structure.
 *
 * Supported transports: HTTP/HTTPS (Packagist, Satis, private mirrors),
 * FTP/FTPS, SFTP (SSH), SMB/CIFS network shares, and local filesystem.
 * Custom transports can be added by implementing PackageTransportInterface.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Closure;
use Exception;
use ZipArchive;

use Razy\Contract\DistributorInterface;
use Razy\Contract\PackageTransportInterface;
use Razy\PackageManager\HttpTransport;
use Razy\Util\VersionUtil;
use Razy\Util\PathUtil;

/**
 * PackageManager - Download, extract, and manage Composer-compatible packages.
 *
 * Fetches package metadata via a pluggable transport layer, resolves version
 * constraints with stability preferences, downloads distribution archives,
 * extracts autoload mappings (PSR-4 / PSR-0), and maintains a version lock file.
 *
 * The transport is protocol-agnostic: any source (HTTP, FTP, SFTP, SMB, local
 * filesystem) that mirrors the standard Composer repository layout is supported.
 *
 * @class PackageManager
 */
class PackageManager
{
    /** @var int Package is not yet processed */
    public const STATUS_PENDING = 0;

    /** @var int Package metadata is being fetched from the repository */
    public const STATUS_FETCHING = 1;

    /** @var int Package metadata has been fetched and a matching version found */
    public const STATUS_READY = 2;

    /** @var int Package has been downloaded, extracted, and updated successfully */
    public const STATUS_UPDATED = 3;

    /** @var string Notification type: matching version found and ready */
    public const TYPE_READY = 'ready';

    /** @var string Notification type: a dependency or action has failed */
    public const TYPE_FAILED = 'failed';

    /** @var string Notification type: download progress update */
    public const TYPE_DOWNLOAD_PROGRESS = 'download_progress';

    /** @var string Notification type: download completed */
    public const TYPE_DOWNLOAD_FINISHED = 'download_finished';

    /** @var string Notification type: extracting namespace files */
    public const TYPE_EXTRACT = 'extract';

    /** @var string Notification type: package updated successfully */
    public const TYPE_UPDATED = 'updated';

    /** @var string Notification type: an error occurred */
    public const TYPE_ERROR = 'error';

    /** @var string Notification type: download is starting */
    public const TYPE_DOWNLOAD = 'start_download';

    /** @var array<string, array> In-memory cache of fetched package versions keyed by package name */
    private static array $cached = [];

    /** @var array|null Version lock data loaded from lock.json; null until first instantiation */
    private static ?array $versionLock = null;

    /** @var PackageTransportInterface The default transport used when none is explicitly provided */
    private static ?PackageTransportInterface $defaultTransport = null;

    /** @var DistributorInterface The distributor providing package source configuration */
    private DistributorInterface $distributor;

    /** @var string Composer package name (vendor/package) */
    private string $name;

    /** @var array Resolved package metadata from the repository */
    private array $package = [];

    /** @var int Current processing status (STATUS_* constants) */
    private int $status = self::STATUS_PENDING;

    /** @var string Version constraint string (e.g. '^1.0', '~2.0', '*') */
    private string $versionRequired;

    /** @var Closure|null Optional notification callback for progress/status reporting */
    private ?Closure $notifyClosure = null;

    /** @var PackageTransportInterface The transport used for fetching and downloading */
    private PackageTransportInterface $transport;

    /**
     * PackageManager constructor.
     *
     * @param DistributorInterface           $distributor  The distributor context for lock-file scoping
     * @param string                         $packageName  Composer package name (vendor/package)
     * @param string                         $versionRequired Version constraint (e.g. '^1.0', '~2.0', '*')
     * @param callable|null                  $notify       Optional progress/status notification callback
     * @param PackageTransportInterface|null $transport    Transport for fetching/downloading; defaults to HTTP (Packagist)
     */
    public function __construct(
        DistributorInterface $distributor,
        string $packageName,
        string $versionRequired = '*',
        ?callable $notify = null,
        ?PackageTransportInterface $transport = null,
    ) {
        $this->distributor = $distributor;
        $this->name = trim($packageName);
        $this->versionRequired = trim($versionRequired);
        $this->notifyClosure = !$notify ? null : $notify(...);
        $this->transport = $transport ?? self::getDefaultTransport();

        // Load the version lock file on first instantiation (shared across all instances)
        if (null === self::$versionLock) {
            $config = [];
            $versionLockFile = PathUtil::append(SYSTEM_ROOT, 'autoload', 'lock.json');
            if (is_file($versionLockFile)) {
                try {
                    $config = file_get_contents($versionLockFile);
                    $config = json_decode($config, true);
                    if (!is_array($config)) {
                        $config = [];
                    }
                } catch (Exception) {
                    $config = [];
                }
            }
            self::$versionLock = $config;
        }

        // Ensure a lock entry exists for this distributor's code namespace
        if (!isset(self::$versionLock[$this->distributor->getCode()])) {
            self::$versionLock[$this->distributor->getCode()] = [];
        }
    }

    /**
     * Set the default transport used when none is explicitly provided.
     *
     * Call this once at bootstrap time to configure a custom package source
     * (e.g. FTP mirror, SFTP server, SMB share, or local directory) that
     * will be used by all PackageManager instances created without an
     * explicit transport parameter.
     *
     * @param PackageTransportInterface $transport
     */
    public static function setDefaultTransport(PackageTransportInterface $transport): void
    {
        self::$defaultTransport = $transport;
    }

    /**
     * Get the default transport, creating the HTTP (Packagist) transport if none is set.
     *
     * @return PackageTransportInterface
     */
    public static function getDefaultTransport(): PackageTransportInterface
    {
        if (null === self::$defaultTransport) {
            self::$defaultTransport = new HttpTransport();
        }

        return self::$defaultTransport;
    }

    /**
     * Get the transport instance used by this PackageManager.
     *
     * @return PackageTransportInterface
     */
    public function getTransport(): PackageTransportInterface
    {
        return $this->transport;
    }

    /**
     * Update the version lock file.
     *
     * @return bool
     */
    public static function updateLock(): bool
    {
        if (null !== self::$versionLock) {
            $versionLockFile = PathUtil::append(SYSTEM_ROOT, 'autoload', 'lock.json');
            file_put_contents($versionLockFile, json_encode(self::$versionLock));

            return true;
        }

        return false;
    }

    /**
     * Get the package manager status.
     *
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Validate the package and update to the newest version.
     *
     * @return bool
     * @throws Error
     */
    public function validate(): bool
    {
        if (self::STATUS_UPDATED === $this->status) {
            return true;
        }
        if (self::STATUS_READY !== $this->status) {
            return false;
        }

        // Resolve the distribution download URL/path before proceeding
        $distUrl = $this->package['dist']['url'];

        // Compare current locked version against the resolved package version
        $currentVersion = self::$versionLock[$this->distributor->getCode()][$this->name]['version'] ?? '0.0.0.0';
        if (version_compare($currentVersion, $this->package['version_normalized'], '>=')) {
            // Current version meets or exceeds the available version; skip download
            $this->status = self::STATUS_UPDATED;
        } else {
            $this->notify(self::TYPE_DOWNLOAD, [$this->name, $distUrl]);

            // Create a temporary file to hold the downloaded archive
            $temporaryFilePath = tempnam(sys_get_temp_dir(), 'rtmp');

            // Download via the configured transport (HTTP, FTP, SFTP, SMB, local, etc.)
            $downloadSuccess = $this->transport->download(
                $distUrl,
                $temporaryFilePath,
                function (int $downloadSize, int $downloaded) {
                    $this->notify(self::TYPE_DOWNLOAD_PROGRESS, [
                        $this->name, $this->package['version'],
                        $downloadSize, $downloaded, 0, 0,
                    ]);
                },
            );

            if (!$downloadSuccess) {
                $this->notify(self::TYPE_ERROR, [$this->name, 'Download failed via ' . $this->transport->getScheme() . ' transport']);

                return false;
            }
            $this->notify(self::TYPE_DOWNLOAD_FINISHED, [$this->name]);

            // Open and extract the downloaded zip archive
            $zip = new ZipArchive();
            if (true === $zip->open($temporaryFilePath)) {
                $temporaryExtractPath = PathUtil::append(sys_get_temp_dir(), $this->name . '-' . $this->package['version']);
                mkdir($temporaryExtractPath);

                // Extract to a temporary directory before moving to the final location
                $zip->extractTo($temporaryExtractPath);
                $pathOfExtract = PathUtil::append(SYSTEM_ROOT, 'autoload', $this->distributor->getCode());
                if (!is_dir($pathOfExtract)) {
                    mkdir($pathOfExtract, 0777, true);
                }

                // GitHub/Packagist archives typically wrap contents in a single root folder
                $files = array_diff(scandir($temporaryExtractPath), ['.', '..']);
                $path = end($files);

                // Copy files for each PSR-4/PSR-0 autoload namespace mapping
                $autoload = $this->package['autoload']['psr-4'] ?? $this->package['autoload']['psr-0'] ?? [];
                foreach ($autoload as $namespace => $extract) {
                    $this->notify(self::TYPE_EXTRACT, [$this->name, $namespace, $extract ?: '/']);
                    xcopy(PathUtil::append($temporaryExtractPath, $path, $extract), PathUtil::append($pathOfExtract, $namespace));
                }
                $zip->close();

                $this->status = self::STATUS_UPDATED;
                self::$versionLock[$this->distributor->getCode()][$this->name] = [
                    'version' => $this->package['version_normalized'],
                    'timestamp' => time(),
                ];
                $this->package['updated'] = true;
                $this->notify(self::TYPE_UPDATED, [$this->name]);
            } else {
                return false;
            }
        }

        // Only check the packages, instead of PHP version or extension (Maybe?)
        // Recursively validate required sub-packages (ignore php/ext-* requirements)
        foreach ($this->package['require'] as $package => $requirement) {
            // Match only vendor/package names (skip php, ext-*, etc.)
            if (preg_match('/^[^\/]+\/(.+)/', $package)) {
                // Propagate the same transport to sub-package resolution
                $package = new PackageManager($this->distributor, $package, $requirement, null, $this->transport);
                if (!$package->fetch() || !$package->validate()) {
                    $this->notify(self::TYPE_FAILED, [$this->name, $package->getName(), $package->getVersion()]);
                }
            }
        }

        return true;
    }

    /**
     * Send notify message to the inspector.
     *
     * @param string $type
     * @param array $args
     */
    private function notify(string $type, array $args = []): void
    {
        if ($this->notifyClosure) {
            call_user_func_array($this->notifyClosure, array_merge([$type], $args));
        }
    }

    /**
     * Fetch the latest package info from the configured repository.
     *
     * Uses the transport layer to retrieve metadata, supporting any
     * source that follows the Composer repository structure.
     *
     * @return bool
     */
    public function fetch(): bool
    {
        $this->status = self::STATUS_FETCHING;

        $packageName = strtolower($this->name);

        // Use cached package data if available to avoid redundant requests
        if (empty(self::$cached[$this->name])) {
            // Fetch metadata via the configured transport (HTTP, FTP, SFTP, SMB, local, etc.)
            $packageInfo = $this->transport->fetchMetadata($this->name);
            if (null === $packageInfo || !isset($packageInfo['packages'][$packageName])) {
                $this->notify(self::TYPE_ERROR, [$this->name, 'Failed to fetch metadata via ' . $this->transport->getScheme() . ' transport']);

                return false;
            }

            $fetched = [];
            foreach ($packageInfo['packages'][$packageName] as $package) {
                $fetched[$package['version']] = $package;
            }
            self::$cached[$this->name] = $fetched;
        }

        // Parse version requirement string and extract stability flag (e.g. '^1.0@beta')
        $versionRequirement = $this->versionRequired;
        $minStability = 'stable'; // default minimum stability
        // Extract @stability suffix if present (e.g. 'dev', 'alpha', 'beta', 'RC', 'stable')
        if (preg_match('/^(.+)@(dev|alpha|beta|RC|stable)$/i', $versionRequirement, $matches)) {
            $versionRequirement = $matches[1];
            $minStability = strtolower($matches[2]);
        }

        // Sort versions by stability and version number (prefer stable over dev)
        $sortedPackages = $this->sortPackagesByStability(self::$cached[$this->name], $minStability);

        foreach ($sortedPackages as &$package) {
            $version = $package['version'];
            $versionNormalized = $package['version_normalized'];

            // Check if version matches the requirement
            if ('*' == $versionRequirement || $this->versionMatches($versionRequirement, $version, $versionNormalized, $minStability)) {
                if ($package['updated'] ?? false) {
                    $this->status = self::STATUS_UPDATED;
                } else {
                    $this->package = &$package;

                    $this->notify(self::TYPE_READY, [$this->name, $package['version']]);
                    $this->status = self::STATUS_READY;
                }

                return true;
            }
        }

        $this->notify(self::TYPE_ERROR, [$this->name, 'No version in repos is available for update.']);

        return false;
    }

    /**
     * Sort packages by stability preference and version.
     *
     * @param array $packages
     * @param string $minStability
     *
     * @return array
     */
    private function sortPackagesByStability(array $packages, string $minStability): array
    {
        // Map stability labels to numeric levels (lower = more stable)
        $stabilityOrder = ['stable' => 0, 'rc' => 1, 'beta' => 2, 'alpha' => 3, 'dev' => 4];
        $minStabilityLevel = $stabilityOrder[$minStability] ?? 0;

        // Filter out packages that don't meet the minimum stability threshold
        $filtered = [];
        foreach ($packages as $package) {
            $stability = $this->getVersionStability($package['version']);
            $stabilityLevel = $stabilityOrder[$stability] ?? 4;

            // Include if stability meets the threshold or explicitly requesting dev
            if ($stabilityLevel <= $minStabilityLevel || $minStability === 'dev') {
                $filtered[] = $package;
            }
        }

        // Sort: prefer stable versions first, then descending by version number
        usort($filtered, function ($a, $b) use ($stabilityOrder) {
            $aStability = $this->getVersionStability($a['version']);
            $bStability = $this->getVersionStability($b['version']);
            $aLevel = $stabilityOrder[$aStability] ?? 4;
            $bLevel = $stabilityOrder[$bStability] ?? 4;

            // Primary sort: prefer more stable versions (lower level number)
            if ($aLevel !== $bLevel) {
                return $aLevel <=> $bLevel;
            }

            // Secondary sort: higher version number first (descending)
            return version_compare($b['version_normalized'], $a['version_normalized']);
        });

        return $filtered;
    }

    /**
     * Get version stability.
     *
     * @param string $version
     *
     * @return string
     */
    private function getVersionStability(string $version): string
    {
        $version = strtolower($version);

        // Match dev versions (prefixed with 'dev-' or suffixed with '-dev')
        if (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) {
            return 'dev';
        }
        // Check for pre-release stability labels in order of instability
        if (str_contains($version, 'alpha')) {
            return 'alpha';
        }
        if (str_contains($version, 'beta')) {
            return 'beta';
        }
        if (str_contains($version, 'rc')) {
            return 'rc';
        }

        // No pre-release suffix means stable
        return 'stable';
    }

    /**
     * Check if version matches the requirement constraint.
     *
     * @param string $requirement
     * @param string $version
     * @param string $versionNormalized
     * @param string $minStability
     *
     * @return bool
     */
    private function versionMatches(string $requirement, string $version, string $versionNormalized, string $minStability): bool
    {
        // Handle exact dev branch references (e.g. 'dev-master')
        if (str_starts_with($requirement, 'dev-')) {
            return strtolower($version) === strtolower($requirement);
        }

        // Strip '-dev' suffix from version constraints (e.g. '1.0.x-dev' → '1.0.x')
        if (str_ends_with($requirement, '-dev')) {
            $requirement = substr($requirement, 0, -4);
        }

        // Delegate to the framework's vc() function for semver constraint matching
        return VersionUtil::vc($requirement, $versionNormalized);
    }

    /**
     * Get the package name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the package version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->package['version'] ?? '';
    }
}
