<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Closure;
use Exception;
use ZipArchive;

class PackageManager
{
    public const STATUS_PENDING = 0;
    public const STATUS_FETCHING = 1;
    public const STATUS_READY = 2;
    public const STATUS_UPDATED = 3;

    public const TYPE_READY = 'ready';
    public const TYPE_FAILED = 'failed';
    public const TYPE_DOWNLOAD_PROGRESS = 'download_progress';
    public const TYPE_DOWNLOAD_FINISHED = 'download_finished';
    public const TYPE_EXTRACT = 'extract';
    public const TYPE_UPDATED = 'updated';
    public const TYPE_ERROR = 'error';
    public const TYPE_DOWNLOAD = 'start_download';
    private static array $cached = [];
    private static ?array $versionLock = null;
    private Distributor $distributor;
    private string $name;
    private array $package = [];
    private int $status = self::STATUS_PENDING;
    private string $versionRequired;
    private ?Closure $notifyClosure = null;

    /**
     * PackageManager constructor.
     *
     * @param Distributor $distributor
     * @param string $packageName
     * @param string $versionRequired
     * @param Closure|null $notify
     */
    public function __construct(Distributor $distributor, string $packageName, string $versionRequired = '*', ?Closure $notify = null)
    {
        $this->distributor = $distributor;
        $this->name = trim($packageName);
        $this->versionRequired = trim($versionRequired);
        $this->notifyClosure = $notify;

        if (null === self::$versionLock) {
            $config = [];
            $versionLockFile = append(SYSTEM_ROOT, 'autoload', 'lock.json');
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

        if (!isset(self::$versionLock[$this->distributor->getCode()])) {
            self::$versionLock[$this->distributor->getCode()] = [];
        }
    }

    /**
     * Update the version lock file.
     *
     * @return bool
     */
    public static function UpdateLock(): bool
    {
        if (null !== self::$versionLock) {
            $versionLockFile = append(SYSTEM_ROOT, 'autoload', 'lock.json');
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

        // Before download and extract the zip file, update the required package first
        // Save the information for further action
        $distUrl = $this->package['dist']['url'];

        $currentVersion = self::$versionLock[$this->distributor->getCode()][$this->name]['version'] ?? '0.0.0.0';
        if (version_compare($currentVersion, $this->package['version_normalized'], '>=')) {
            // If the current version is higher than or equal with the package version, no updates
            $this->status = self::STATUS_UPDATED;
        } else {
            $this->notify(self::TYPE_DOWNLOAD, [$this->name, $distUrl]);

            // Create temporary file
            $temporaryFilePath = tempnam(sys_get_temp_dir(), 'rtmp');
            $targetFile = fopen($temporaryFilePath, 'w');

            // Start download the zip file via CURL
            $ch = curl_init($distUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            // Progress update
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadSize, $downloaded, $uploadSize, $uploaded) {
                if ($downloadSize > 0) {
                    $this->notify(self::TYPE_DOWNLOAD_PROGRESS, [$this->name, $this->package['version'], $downloadSize, $downloaded, $uploadSize, $uploaded]);
                }
            });
            curl_setopt($ch, CURLOPT_FILE, $targetFile);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: Razy-Package-Manager',
                'Accept:application/vnd.github.v3.raw',
                'Accept-Encoding: gzip, deflate',
            ]);
            curl_exec($ch);
            fclose($targetFile);
            $this->notify(self::TYPE_DOWNLOAD_FINISHED, [$this->name]);

            // Unzip the file
            $zip = new ZipArchive();
            if (true === $zip->open($temporaryFilePath)) {
                $temporaryExtractPath = append(sys_get_temp_dir(), $this->name . '-' . $this->package['version']);
                mkdir($temporaryExtractPath);

                // Extract all the file into temporary directory first
                $zip->extractTo($temporaryExtractPath);
                $pathOfExtract = append(SYSTEM_ROOT, 'autoload', $this->distributor->getCode());
                if (!is_dir($pathOfExtract)) {
                    mkdir($pathOfExtract, 0777, true);
                }

                // Suppose the root of the zip file has one folder
                $files = array_diff(scandir($temporaryExtractPath), ['.', '..']);
                $path = end($files);

                // Extract all files from the `autoload mapping list`
                $autoload = $this->package['autoload']['psr-4'] ?? $this->package['autoload']['psr-0'] ?? [];
                foreach ($autoload as $namespace => $extract) {
                    $this->notify(self::TYPE_EXTRACT, [$this->name, $namespace, $extract ?: '/']);
                    xcopy(append($temporaryExtractPath, $path, $extract), append($pathOfExtract, $namespace));
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
        foreach ($this->package['require'] as $package => $requirement) {
            if (preg_match('/^[^\/]+\/(.+)/', $package)) {
                $package = new PackageManager($this->distributor, $package, $requirement);
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
     * Fetch the latest packages info from repo.
     */
    public function fetch(): bool
    {
        $this->status = self::STATUS_FETCHING;

        $packageName = strtolower($this->name);
        $url = append('https://repo.packagist.org/p2', strtolower($packageName) . '.json');

        if (!self::$cached[$this->name]) {
            $packageInfo = file_get_contents($url);
            // Ensure the repo has response
            if (200 == $this->parseResponseCode($http_response_header)) {
                try {
                    $packageInfo = json_decode($packageInfo, true);
                } catch (Exception) {
                    return false;
                }
            }
            $fetched = [];
            foreach ($packageInfo['packages'][$packageName] as $package) {
                $fetched[$package['version']] = $package;
            }
            self::$cached[$this->name] = $fetched;
        }

        foreach (self::$cached[$this->name] as &$package) {
            // Ensure the latest release version is meet the requirements
            if ('*' == $this->versionRequired || vc($this->versionRequired, $package['version_normalized'])) {
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
     * Extract the response code.
     *
     * @param array $http_response_header
     *
     * @return int
     */
    private function parseResponseCode(array $http_response_header): int
    {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/[\d.]+\s+(\d+)/', $header, $matches)) {
                return intval($matches[1]);
            }
        }

        return 0;
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
