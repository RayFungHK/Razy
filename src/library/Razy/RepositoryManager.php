<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Manages module repositories for searching, listing, and downloading
 * Razy modules from GitHub or custom repository servers.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy;

use Closure;
use Razy\Util\PathUtil;
/**
 * RepositoryManager - Manage module repositories for searching and downloading.
 *
 * Aggregates one or more repository sources (GitHub or custom servers) and
 * provides a unified interface for index fetching, module search, manifest
 * retrieval, download URL resolution, and static index generation.
 *
 * @class RepositoryManager
 */
class RepositoryManager
{
    /** @var string Notification type: informational message */
    public const TYPE_INFO = 'info';

    /** @var string Notification type: an error occurred */
    public const TYPE_ERROR = 'error';

    /** @var string Notification type: progress update */
    public const TYPE_PROGRESS = 'progress';

    /** @var string Notification type: search result found */
    public const TYPE_SEARCH_RESULT = 'search_result';

    /** @var array<string, string> Registered repository URLs mapped to their branch names */
    private array $repositories = [];

    /** @var array<string, array> Cached repository index data keyed by repository URL */
    private array $indexCache = [];

    /** @var Closure|null Progress/status notification callback */
    private ?Closure $notifyClosure = null;

    /**
     * RepositoryManager constructor.
     *
     * @param array|null $repositories Array of repository URLs with branches
     * @param callable|null $notify Callback for notifications
     */
    public function __construct(?array $repositories = null, ?callable $notify = null)
    {
        $this->notifyClosure = $notify ? $notify(...) : null;

        if ($repositories === null) {
            // Load default repository list from the system-level configuration file
            $repoFile = PathUtil::append(SYSTEM_ROOT, 'repository.inc.php');
            if (is_file($repoFile)) {
                $repositories = require $repoFile;
            }
        }

        if (is_array($repositories)) {
            foreach ($repositories as $url => $branch) {
                $this->addRepository($url, $branch);
            }
        }
    }

    /**
     * Add a repository source
     *
     * @param string $url Repository base URL
     * @param string $branch Branch name (for GitHub repos)
     */
    public function addRepository(string $url, string $branch = 'main'): void
    {
        $url = rtrim($url, '/');
        $this->repositories[$url] = $branch;
    }

    /**
     * Get all configured repositories
     *
     * @return array
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * Fetch repository index
     *
     * @param string $repoUrl Repository URL
     *
     * @return array|null Index data or null on failure
     */
    public function fetchIndex(string $repoUrl): ?array
    {
        // Return cached index if already fetched in this session
        if (isset($this->indexCache[$repoUrl])) {
            return $this->indexCache[$repoUrl];
        }

        // Build the raw content URL for the index.json file
        $branch = $this->repositories[$repoUrl] ?? 'main';
        $indexUrl = $this->buildRawUrl($repoUrl, $branch, 'index.json');

        $this->notify(self::TYPE_INFO, ['Fetching index', $repoUrl]);

        $response = $this->httpGet($indexUrl);
        if ($response === null) {
            $this->notify(self::TYPE_ERROR, ['Failed to fetch index', $indexUrl]);
            return null;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->notify(self::TYPE_ERROR, ['Invalid index JSON', $repoUrl]);
            return null;
        }

        $this->indexCache[$repoUrl] = $data;
        return $data;
    }

    /**
     * Search for modules across all repositories
     *
     * @param string $query Search query (module code or keyword)
     *
     * @return array Search results
     */
    public function search(string $query): array
    {
        $results = [];
        $query = strtolower(trim($query));

        // Search across all registered repositories
        foreach ($this->repositories as $repoUrl => $branch) {
            $index = $this->fetchIndex($repoUrl);
            if ($index === null) {
                continue;
            }

            foreach ($index as $moduleCode => $info) {
                // Match query against module code, description, or author (case-insensitive)
                if (
                    str_contains(strtolower($moduleCode), $query) ||
                    str_contains(strtolower($info['description'] ?? ''), $query) ||
                    str_contains(strtolower($info['author'] ?? ''), $query)
                ) {
                    $results[] = [
                        'module_code' => $moduleCode,
                        'description' => $info['description'] ?? '',
                        'author' => $info['author'] ?? '',
                        'latest' => $info['latest'] ?? '',
                        'versions' => $info['versions'] ?? [],
                        'repository' => $repoUrl,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Get module info from repositories
     *
     * @param string $moduleCode Module code (vendor/module)
     *
     * @return array|null Module info or null if not found
     */
    public function getModuleInfo(string $moduleCode): ?array
    {
        foreach ($this->repositories as $repoUrl => $branch) {
            $index = $this->fetchIndex($repoUrl);
            if ($index === null) {
                continue;
            }

            if (isset($index[$moduleCode])) {
                return [
                    'module_code' => $moduleCode,
                    'description' => $index[$moduleCode]['description'] ?? '',
                    'author' => $index[$moduleCode]['author'] ?? '',
                    'latest' => $index[$moduleCode]['latest'] ?? '',
                    'versions' => $index[$moduleCode]['versions'] ?? [],
                    'repository' => $repoUrl,
                    'branch' => $branch,
                ];
            }
        }

        return null;
    }

    /**
     * Get manifest for a specific module
     *
     * @param string $moduleCode Module code (vendor/module)
     * @param string|null $repoUrl Specific repository URL (auto-detect if null)
     *
     * @return array|null Manifest data or null on failure
     */
    public function getManifest(string $moduleCode, ?string $repoUrl = null): ?array
    {
        if ($repoUrl === null) {
            $info = $this->getModuleInfo($moduleCode);
            if ($info === null) {
                return null;
            }
            $repoUrl = $info['repository'];
        }

        $branch = $this->repositories[$repoUrl] ?? 'main';
        $manifestUrl = $this->buildRawUrl($repoUrl, $branch, $moduleCode . '/manifest.json');

        $response = $this->httpGet($manifestUrl);
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Get download URL for a module version
     *
     * @param string $moduleCode Module code (vendor/module)
     * @param string $version Version string or 'latest'/'stable'
     *
     * @return string|null Download URL or null if not found
     */
    public function getDownloadUrl(string $moduleCode, string $version = 'latest'): ?string
    {
        $info = $this->getModuleInfo($moduleCode);
        if ($info === null) {
            $this->notify(self::TYPE_ERROR, ['Module not found', $moduleCode]);
            return null;
        }

        $repoUrl = $info['repository'];
        $branch = $info['branch'];

        // Resolve version
        if ($version === 'latest' || $version === 'stable') {
            $version = $info['latest'] ?? '';
            if (empty($version)) {
                $this->notify(self::TYPE_ERROR, ['No latest version available', $moduleCode]);
                return null;
            }
        }

        // Check if version exists
        if (!in_array($version, $info['versions'] ?? [])) {
            $this->notify(self::TYPE_ERROR, ['Version not found', "$moduleCode@$version"]);
            return null;
        }

        // Use GitHub Releases URL format for .phar files
        return $this->buildReleaseAssetUrl($repoUrl, $moduleCode, $version);
    }

    /**
     * Build release asset URL for GitHub repositories
     *
     * Uses GitHub Releases to download .phar files
     * URL format: https://github.com/{owner}/{repo}/releases/download/{tag}/{filename}
     *
     * @param string $repoUrl Repository URL
     * @param string $moduleCode Module code (vendor/module)
     * @param string $version Version string
     *
     * @return string Release asset URL
     */
    public function buildReleaseAssetUrl(string $repoUrl, string $moduleCode, string $version): string
    {
        // Construct a GitHub Releases tag name: vendor-module-vX.Y.Z
        $tagName = str_replace('/', '-', $moduleCode) . '-v' . $version;
        $filename = $version . '.phar';

        // Transform GitHub web URL to Releases download URL
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '/');
            return sprintf(
                'https://github.com/%s/%s/releases/download/%s/%s',
                $owner,
                $repo,
                $tagName,
                $filename
            );
        }

        // Fallback to raw URL format for non-GitHub repositories
        return $this->buildRawUrl($repoUrl, 'main', $moduleCode . '/' . $version . '.phar');
    }

    /**
     * Build raw content URL for GitHub or other repositories
     *
     * @param string $repoUrl Repository URL
     * @param string $branch Branch name
     * @param string $path File path within repository (optional)
     *
     * @return string Raw content URL
     */
    public function buildRawUrl(string $repoUrl, string $branch, string $path = ''): string
    {
        // GitHub: transform web URL to raw.githubusercontent.com for direct file access
        if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '/');
            return sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                $owner,
                $repo,
                $branch,
                $path
            );
        }

        // GitLab: transform web URL to raw file endpoint
        if (preg_match('#^https?://gitlab\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = rtrim($matches[2], '/');
            return sprintf(
                'https://gitlab.com/%s/%s/-/raw/%s/%s',
                $owner,
                $repo,
                $branch,
                $path
            );
        }

        // For non-GitHub/GitLab repositories, simply append the path
        return rtrim($repoUrl, '/') . '/' . $path;
    }

    /**
     * HTTP GET request
     *
     * @param string $url URL to fetch
     *
     * @return string|null Response body or null on failure
     */
    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Razy-RepositoryManager',
            'Accept: application/json, */*',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300) ? $response : null;
    }

    /**
     * Send notification to callback
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

    /**
     * List all available modules across repositories
     *
     * @return array All modules
     */
    public function listAll(): array
    {
        $results = [];

        foreach ($this->repositories as $repoUrl => $branch) {
            $index = $this->fetchIndex($repoUrl);
            if ($index === null) {
                continue;
            }

            foreach ($index as $moduleCode => $info) {
                $results[] = [
                    'module_code' => $moduleCode,
                    'description' => $info['description'] ?? '',
                    'author' => $info['author'] ?? '',
                    'latest' => $info['latest'] ?? '',
                    'versions' => $info['versions'] ?? [],
                    'repository' => $repoUrl,
                ];
            }
        }

        return $results;
    }

    /**
     * Generate index.json content from local modules
     *
     * @param string $basePath Base path containing vendor/module folders
     *
     * @return array Index data
     */
    public static function generateIndex(string $basePath): array
    {
        $index = [];
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR);

        // Scan top-level vendor directories
        $vendors = glob($basePath . '/*', GLOB_ONLYDIR);
        foreach ($vendors as $vendorPath) {
            $vendor = basename($vendorPath);

            // Scan module directories within each vendor
            $modules = glob($vendorPath . '/*', GLOB_ONLYDIR);
            foreach ($modules as $modulePath) {
                $module = basename($modulePath);
                $moduleCode = $vendor . '/' . $module;

                // Read and parse the module's manifest.json for metadata
                $manifestPath = $modulePath . '/manifest.json';
                if (is_file($manifestPath)) {
                    $manifest = json_decode(file_get_contents($manifestPath), true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $index[$moduleCode] = [
                            'description' => $manifest['description'] ?? '',
                            'author' => $manifest['author'] ?? '',
                            'latest' => $manifest['latest'] ?? '',
                            'versions' => $manifest['versions'] ?? [],
                        ];
                    }
                }
            }
        }

        return $index;
    }

    /**
     * Write index.json file
     *
     * @param string $basePath Base path for repository
     * @param array $index Index data
     *
     * @return bool Success
     */
    public static function writeIndex(string $basePath, array $index): bool
    {
        $indexPath = rtrim($basePath, DIRECTORY_SEPARATOR) . '/index.json';
        $json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($indexPath, $json) !== false;
    }
}
