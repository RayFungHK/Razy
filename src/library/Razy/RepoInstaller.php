<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Downloads and installs modules from GitHub repositories or custom
 * ZIP archive URLs, with support for versioned releases, branches, and tags.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use Exception;
use ZipArchive;

/**
 * RepoInstaller - Download and install modules from repository sources.
 *
 * Supports GitHub repositories (full URL or short owner/repo format),
 * custom ZIP download endpoints, version branches (latest, stable, specific tags),
 * and authenticated access for private repositories.
 *
 * @class RepoInstaller
 */
class RepoInstaller
{
    /** @var string Notification type: progress percentage update */
    public const TYPE_PROGRESS = 'progress';

    /** @var string Notification type: download has started */
    public const TYPE_DOWNLOAD_START = 'download_start';

    /** @var string Notification type: download completed */
    public const TYPE_DOWNLOAD_COMPLETE = 'download_complete';

    /** @var string Notification type: archive extraction started */
    public const TYPE_EXTRACT_START = 'extract_start';

    /** @var string Notification type: archive extraction completed */
    public const TYPE_EXTRACT_COMPLETE = 'extract_complete';

    /** @var string Notification type: installation completed successfully */
    public const TYPE_INSTALL_COMPLETE = 'install_complete';

    /** @var string Notification type: an error occurred */
    public const TYPE_ERROR = 'error';

    /** @var string Notification type: informational message */
    public const TYPE_INFO = 'info';

    /** @var string Source type for GitHub repositories */
    public const SOURCE_GITHUB = 'github';

    /** @var string Source type for custom/non-GitHub URLs */
    public const SOURCE_CUSTOM = 'custom';

    /** @var string Version identifier for the latest release */
    public const VERSION_LATEST = 'latest';

    /** @var string Version identifier for the latest stable (non-prerelease) release */
    public const VERSION_STABLE = 'stable';

    /** @var string Detected source type (SOURCE_GITHUB or SOURCE_CUSTOM) */
    private string $source = self::SOURCE_GITHUB;

    /** @var string GitHub repository owner (empty for custom sources) */
    private string $owner = '';

    /** @var string Repository name */
    private string $repo = '';

    /** @var string Branch or tag name to download from */
    private string $branch = 'main';

    /** @var string Version identifier ('latest', 'stable', or empty) */
    private string $version = '';

    /** @var string Full URL for custom (non-GitHub) download sources */
    private string $customUrl = '';

    /** @var string Target filesystem path for installation */
    private string $targetPath;

    /** @var Closure|null Progress/status notification callback */
    private ?Closure $notifyClosure = null;

    /** @var string|null Authentication token for private repository access */
    private ?string $authToken = null;

    /**
     * RepoInstaller constructor.
     *
     * @param string $repository Repository in format:
     *                           - GitHub: 'owner/repo' or 'https://github.com/owner/repo'
     *                           - Custom: 'https://example.com/path/to/repo.zip'
     * @param string $targetPath Target installation path
     * @param callable|null $notify Callback for progress notifications
     * @param string|null $version Version to install: 'latest', 'stable', branch name, or tag (default: main)
     * @param string|null $authToken Optional authentication token for private repos
     *
     * @throws Exception
     */
    public function __construct(
        string $repository,
        string $targetPath,
        ?callable $notify = null,
        ?string $version = null,
        ?string $authToken = null,
    ) {
        $this->parseRepository($repository);
        $this->targetPath = \rtrim($targetPath, DIRECTORY_SEPARATOR);
        $this->notifyClosure = $notify ? $notify(...) : null;
        $this->authToken = $authToken;

        if ($version !== null) {
            $this->setVersion($version);
        }
    }

    /**
     * Set the version/branch to download.
     *
     * @param string $version Version identifier: 'latest', 'stable', branch name, or tag
     */
    public function setVersion(string $version): void
    {
        $version = \trim($version);

        // Handle well-known version aliases
        if ($version === self::VERSION_LATEST || $version === self::VERSION_STABLE) {
            $this->version = $version;
            $this->branch = '';
        } elseif (\str_starts_with($version, '@')) {
            // '@tag' syntax: use the tag/branch name after the '@' prefix
            $this->branch = \substr($version, 1);
            $this->version = '';
        } else {
            // Direct branch/tag name
            $this->branch = $version;
            $this->version = '';
        }
    }

    /**
     * Get repository information from GitHub API.
     *
     * @return array|null Repository information or null on failure
     */
    public function getRepositoryInfo(): ?array
    {
        if ($this->source === self::SOURCE_CUSTOM) {
            // For custom URLs, validate accessibility rather than fetching metadata
            return $this->validateCustomUrl() ? ['name' => $this->repo, 'source' => 'custom'] : null;
        }

        // Query the GitHub REST API for repository metadata
        $apiUrl = \sprintf('https://api.github.com/repos/%s/%s', $this->owner, $this->repo);

        $headers = [
            'User-Agent: Razy-Repo-Installer',
            'Accept: application/vnd.github.v3+json',
        ];

        if ($this->authToken) {
            $headers[] = 'Authorization: token ' . $this->authToken;
        }

        $ch = \curl_init($apiUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = \json_decode($response, true);
            if (\json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Get the latest release information from GitHub.
     *
     * @return array|null Release information or null if no releases
     */
    public function getLatestRelease(): ?array
    {
        if ($this->source !== self::SOURCE_GITHUB) {
            return null;
        }

        $apiUrl = \sprintf('https://api.github.com/repos/%s/%s/releases/latest', $this->owner, $this->repo);

        $headers = [
            'User-Agent: Razy-Repo-Installer',
            'Accept: application/vnd.github.v3+json',
        ];

        if ($this->authToken) {
            $headers[] = 'Authorization: token ' . $this->authToken;
        }

        $ch = \curl_init($apiUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = \json_decode($response, true);
            if (\json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Get stable release (latest non-prerelease).
     *
     * @return array|null Release information or null if no stable releases
     */
    public function getStableRelease(): ?array
    {
        if ($this->source !== self::SOURCE_GITHUB) {
            return null;
        }

        $apiUrl = \sprintf('https://api.github.com/repos/%s/%s/releases', $this->owner, $this->repo);

        $headers = [
            'User-Agent: Razy-Repo-Installer',
            'Accept: application/vnd.github.v3+json',
        ];

        if ($this->authToken) {
            $headers[] = 'Authorization: token ' . $this->authToken;
        }

        $ch = \curl_init($apiUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode === 200 && $response) {
            $releases = \json_decode($response, true);
            if (\json_last_error() === JSON_ERROR_NONE && \is_array($releases)) {
                // Iterate releases to find the first non-prerelease, non-draft entry
                foreach ($releases as $release) {
                    if (empty($release['prerelease']) && empty($release['draft'])) {
                        return $release;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get all available tags/versions.
     *
     * @return array List of available tags
     */
    public function getAvailableTags(): array
    {
        if ($this->source !== self::SOURCE_GITHUB) {
            return [];
        }

        $apiUrl = \sprintf('https://api.github.com/repos/%s/%s/tags', $this->owner, $this->repo);

        $headers = [
            'User-Agent: Razy-Repo-Installer',
            'Accept: application/vnd.github.v3+json',
        ];

        if ($this->authToken) {
            $headers[] = 'Authorization: token ' . $this->authToken;
        }

        $ch = \curl_init($apiUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode === 200 && $response) {
            $tags = \json_decode($response, true);
            if (\json_last_error() === JSON_ERROR_NONE && \is_array($tags)) {
                return \array_column($tags, 'name');
            }
        }

        return [];
    }

    /**
     * Download and install the module.
     *
     * @return bool True on success, false on failure
     *
     * @throws Exception
     */
    public function install(): bool
    {
        $displayName = $this->source === self::SOURCE_GITHUB
            ? \sprintf('%s/%s', $this->owner, $this->repo)
            : $this->repo;

        $this->notify(self::TYPE_INFO, ['Starting installation', $displayName]);

        // Resolve the final download URL based on version/branch/tag settings
        $downloadUrl = $this->resolveDownloadUrl();

        if (!$downloadUrl) {
            $this->notify(self::TYPE_ERROR, ['Failed to resolve download URL', 'Check repository and version']);
            return false;
        }

        // Download the archive
        if (!$this->downloadArchive($downloadUrl)) {
            return false;
        }

        $this->notify(self::TYPE_INSTALL_COMPLETE, [$displayName, $this->targetPath]);

        return true;
    }

    /**
     * Get source type.
     *
     * @return string SOURCE_GITHUB or SOURCE_CUSTOM
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Get repository owner (GitHub only).
     *
     * @return string
     */
    public function getOwner(): string
    {
        return $this->owner;
    }

    /**
     * Get repository name.
     *
     * @return string
     */
    public function getRepo(): string
    {
        return $this->repo;
    }

    /**
     * Get branch name.
     *
     * @return string
     */
    public function getBranch(): string
    {
        return $this->branch;
    }

    /**
     * Get version identifier.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get custom URL (custom source only).
     *
     * @return string
     */
    public function getCustomUrl(): string
    {
        return $this->customUrl;
    }

    /**
     * Get target installation path.
     *
     * @return string
     */
    public function getTargetPath(): string
    {
        return $this->targetPath;
    }

    /**
     * Validate repository exists and is accessible.
     *
     * @return bool True if repository is valid
     */
    public function validate(): bool
    {
        $info = $this->getRepositoryInfo();
        return $info !== null;
    }

    /**
     * Parse repository string to extract source type, owner, and repo name.
     *
     * @param string $repository Repository identifier
     *
     * @throws Exception
     */
    private function parseRepository(string $repository): void
    {
        $repository = \trim($repository);

        // Detect URL-based repository references
        if (\preg_match('#^https?://#i', $repository)) {
            // Check if it's a GitHub URL (with optional .git suffix and @branch)
            if (\preg_match('#^https?://github\.com/([^/]+)/([^/@]+?)(?:\.git)?(?:@(.+))?$#i', $repository, $matches)) {
                $this->source = self::SOURCE_GITHUB;
                $this->owner = $matches[1];
                $this->repo = $matches[2];
                if (isset($matches[3])) {
                    $this->setVersion($matches[3]);
                }
            } else {
                // Non-GitHub URL: treat as a direct download endpoint
                $this->source = self::SOURCE_CUSTOM;
                $this->customUrl = $repository;

                // Extract a human-readable name from the URL path for display
                if (\preg_match('#/([^/]+?)(?:\.zip)?$#i', $repository, $matches)) {
                    $this->repo = $matches[1];
                } else {
                    $this->repo = 'custom-module';
                }
            }
        }
        // Parse compact format: owner/repo or owner/repo@version
        elseif (\preg_match('#^([^/]+)/([^/@]+)(?:@(.+))?$#', $repository, $matches)) {
            $this->source = self::SOURCE_GITHUB;
            $this->owner = $matches[1];
            $this->repo = $matches[2];
            if (isset($matches[3])) {
                $this->setVersion($matches[3]);
            }
        } else {
            throw new Exception('Invalid repository format. Use "owner/repo", "owner/repo@version", or a full URL');
        }
    }

    /**
     * Validate custom URL is accessible.
     *
     * @return bool True if URL is valid
     */
    private function validateCustomUrl(): bool
    {
        $headers = ['User-Agent: Razy-Repo-Installer'];
        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        $ch = \curl_init($this->customUrl);
        \curl_setopt($ch, CURLOPT_NOBODY, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Resolve the download URL based on version settings.
     *
     * @return string|null Download URL or null on failure
     */
    private function resolveDownloadUrl(): ?string
    {
        // Custom sources already have a direct download URL
        if ($this->source === self::SOURCE_CUSTOM) {
            $this->notify(self::TYPE_INFO, ['Using custom URL', $this->customUrl]);
            return $this->customUrl;
        }

        // For GitHub: resolve version alias to a release URL
        if ($this->version === self::VERSION_LATEST) {
            $release = $this->getLatestRelease();
            if ($release) {
                $this->notify(self::TYPE_INFO, ['Using latest release', $release['tag_name']]);
                return $release['zipball_url'];
            }
            $this->notify(self::TYPE_ERROR, ['No releases found', 'Falling back to main branch']);
            $this->branch = 'main';
        } elseif ($this->version === self::VERSION_STABLE) {
            $release = $this->getStableRelease();
            if ($release) {
                $this->notify(self::TYPE_INFO, ['Using stable release', $release['tag_name']]);
                return $release['zipball_url'];
            }
            $this->notify(self::TYPE_ERROR, ['No stable releases found', 'Falling back to main branch']);
            $this->branch = 'main';
        }

        // Download from branch/tag (no release URL resolved)
        $branch = $this->branch ?: 'main';

        // First attempt: treat as a Git tag (refs/tags/)
        $tagUrl = \sprintf(
            'https://github.com/%s/%s/archive/refs/tags/%s.zip',
            $this->owner,
            $this->repo,
            $branch,
        );

        if ($this->urlExists($tagUrl)) {
            $this->notify(self::TYPE_INFO, ['Using tag', $branch]);
            return $tagUrl;
        }

        // Fallback: treat as a Git branch (refs/heads/)
        $branchUrl = \sprintf(
            'https://github.com/%s/%s/archive/refs/heads/%s.zip',
            $this->owner,
            $this->repo,
            $branch,
        );

        $this->notify(self::TYPE_INFO, ['Using branch', $branch]);
        return $branchUrl;
    }

    /**
     * Check if URL exists.
     *
     * @param string $url URL to check
     *
     * @return bool True if URL exists
     */
    private function urlExists(string $url): bool
    {
        $headers = ['User-Agent: Razy-Repo-Installer'];
        if ($this->authToken) {
            $headers[] = 'Authorization: token ' . $this->authToken;
        }

        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_NOBODY, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Download and extract archive.
     *
     * @param string $url Download URL
     *
     * @return bool True on success, false on failure
     */
    private function downloadArchive(string $url): bool
    {
        $this->notify(self::TYPE_DOWNLOAD_START, [$url]);

        // Write downloaded content to a temporary file
        $tempFile = \tempnam(\sys_get_temp_dir(), 'razy_repo_');

        $fp = \fopen($tempFile, 'w+');
        if (!$fp) {
            $this->notify(self::TYPE_ERROR, ['Cannot create temporary file', $tempFile]);
            return false;
        }

        // Build authorization headers based on the source type
        $headers = ['User-Agent: Razy-Repo-Installer'];

        if ($this->authToken) {
            // GitHub uses 'token' scheme; other providers use 'Bearer'
            if ($this->source === self::SOURCE_GITHUB) {
                $headers[] = 'Authorization: token ' . $this->authToken;
            } else {
                $headers[] = 'Authorization: Bearer ' . $this->authToken;
            }
        }

        // Configure cURL for streaming download with progress reporting
        $ch = \curl_init($url);
        \curl_setopt($ch, CURLOPT_FILE, $fp);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        \curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        // Report download progress percentage to the notification callback
        \curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $downloadSize, $downloaded) {
            if ($downloadSize > 0) {
                $percentage = \round(($downloaded / $downloadSize) * 100, 2);
                $this->notify(self::TYPE_PROGRESS, [$downloadSize, $downloaded, $percentage]);
            }
        });

        $result = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);
        \fclose($fp);

        if (!$result || $httpCode !== 200) {
            $this->notify(self::TYPE_ERROR, ['Download failed', \sprintf('HTTP %d', $httpCode)]);
            \unlink($tempFile);
            return false;
        }

        $this->notify(self::TYPE_DOWNLOAD_COMPLETE, [\filesize($tempFile)]);

        // Extract the archive
        if (!$this->extractArchive($tempFile)) {
            \unlink($tempFile);
            return false;
        }

        \unlink($tempFile);
        return true;
    }

    /**
     * Extract ZIP archive to target directory.
     *
     * @param string $archivePath Path to ZIP file
     *
     * @return bool True on success, false on failure
     */
    private function extractArchive(string $archivePath): bool
    {
        $this->notify(self::TYPE_EXTRACT_START, [$archivePath]);

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            $this->notify(self::TYPE_ERROR, ['Cannot open archive', $archivePath]);
            return false;
        }

        // Use a unique temporary directory for extraction
        $tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_extract_' . \uniqid();
        if (!\mkdir($tempDir, 0o755, true)) {
            $this->notify(self::TYPE_ERROR, ['Cannot create temp directory', $tempDir]);
            $zip->close();
            return false;
        }

        // Extract all files
        if (!$zip->extractTo($tempDir)) {
            $this->notify(self::TYPE_ERROR, ['Extraction failed', $tempDir]);
            $zip->close();
            $this->removeDirectory($tempDir);
            return false;
        }

        $zip->close();

        // GitHub/Git archives wrap content in a single root folder; detect and unwrap
        $files = \array_diff(\scandir($tempDir), ['.', '..']);
        if (\count($files) === 1) {
            $rootFolder = $tempDir . DIRECTORY_SEPARATOR . \reset($files);
            if (\is_dir($rootFolder)) {
                $tempDir = $rootFolder;
            }
        }

        // Create target directory if it doesn't exist
        if (!\is_dir($this->targetPath)) {
            if (!\mkdir($this->targetPath, 0o755, true)) {
                $this->notify(self::TYPE_ERROR, ['Cannot create target directory', $this->targetPath]);
                $this->removeDirectory($tempDir);
                return false;
            }
        }

        // Copy files to target
        if (!$this->copyDirectory($tempDir, $this->targetPath)) {
            $this->notify(self::TYPE_ERROR, ['Cannot copy files', $this->targetPath]);
            $this->removeDirectory($tempDir);
            return false;
        }

        // Clean up
        $this->removeDirectory($tempDir);

        $this->notify(self::TYPE_EXTRACT_COMPLETE, [$this->targetPath]);

        return true;
    }

    /**
     * Recursively copy directory contents.
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     *
     * @return bool True on success
     */
    private function copyDirectory(string $source, string $destination): bool
    {
        if (!\is_dir($destination)) {
            \mkdir($destination, 0o755, true);
        }

        $dir = \opendir($source);
        if (!$dir) {
            return false;
        }

        while (($file = \readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $source . DIRECTORY_SEPARATOR . $file;
            $dstPath = $destination . DIRECTORY_SEPARATOR . $file;

            if (\is_dir($srcPath)) {
                if (!$this->copyDirectory($srcPath, $dstPath)) {
                    \closedir($dir);
                    return false;
                }
            } else {
                if (!\copy($srcPath, $dstPath)) {
                    \closedir($dir);
                    return false;
                }
            }
        }

        \closedir($dir);
        return true;
    }

    /**
     * Recursively remove directory.
     *
     * @param string $directory Directory to remove
     *
     * @return bool True on success
     */
    private function removeDirectory(string $directory): bool
    {
        if (!\is_dir($directory)) {
            return false;
        }

        $files = \array_diff(\scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                \unlink($path);
            }
        }

        return \rmdir($directory);
    }

    /**
     * Send notification to callback.
     *
     * @param string $type Notification type
     * @param array $data Notification data
     */
    private function notify(string $type, array $data = []): void
    {
        if ($this->notifyClosure) {
            ($this->notifyClosure)($type, ...$data);
        }
    }
}

// Backward compatibility alias
\class_alias(RepoInstaller::class, 'Razy\GitHubInstaller');
