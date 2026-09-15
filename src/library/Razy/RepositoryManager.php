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
 *
 * @license MIT
 */

namespace Razy;

use Closure;
use Razy\Exception\PackageIntegrityException;
use Razy\Http\HttpClient;
use Razy\Http\HttpTransportException;
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

    /** @var string Trust state: index.sig verified against the pinned publisher key */
    public const TRUST_SIGNED = 'signed';

    /** @var string Trust state: no index.sig (or unverifiable without a pinned key) — checksum-only integrity */
    public const TRUST_UNSIGNED = 'unsigned';

    /** @var string Trust state: index.sig FAILED verification — the index was refused, never parsed */
    public const TRUST_INVALID = 'invalid';

    /** @var string Trust state: index was never fetched in this manager instance */
    public const TRUST_UNKNOWN = 'unknown';

    /**
     * Built-in default official registry (gap G1, OFFICIAL-REPO-INSTALL.md §7.1).
     * Consulted only when no project repository.inc.php is usable; a
     * hand-written registry file stays authoritative. Published 2026-07 —
     * seeded via tools/registry-seed/ (layout contract in that repo's README).
     */
    public const DEFAULT_OFFICIAL_URL = 'https://github.com/RayFungHK/Razy-Repository/';

    /** @var string Branch of the built-in default official registry (GitHub default branch) */
    public const DEFAULT_OFFICIAL_BRANCH = 'main';

    /** @var array<string, string> Registered repository URLs mapped to their branch names */
    private array $repositories = [];

    /** @var array<string, array> Cached repository index data keyed by repository URL */
    private array $indexCache = [];

    /** @var array<string, string> Publisher-trust outcome per repository (S5/G4), keyed by repository URL */
    private array $indexTrust = [];

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
            // Load the project repository list, falling back to the built-in
            // default official registry when no repository.inc.php is usable (G1).
            $repositories = self::resolveRepositories();
        }

        // resolveRepositories() always yields an array (built-in default when
        // the config file is absent), and the parameter is ?array — so this
        // point is array-typed by contract; no is_array guard needed.
        foreach ($repositories as $url => $branch) {
            $this->addRepository((string) $url, (string) $branch);
        }
    }

    /**
     * Generate index.json content from local modules.
     *
     * @param string $basePath Base path containing vendor/module folders
     *
     * @return array Index data
     */
    public static function generateIndex(string $basePath): array
    {
        $index = [];
        $basePath = \rtrim($basePath, DIRECTORY_SEPARATOR);

        // Scan top-level vendor directories
        $vendors = \glob($basePath . '/*', GLOB_ONLYDIR);
        foreach ($vendors as $vendorPath) {
            $vendor = \basename($vendorPath);

            // Scan module directories within each vendor
            $modules = \glob($vendorPath . '/*', GLOB_ONLYDIR);
            foreach ($modules as $modulePath) {
                $module = \basename($modulePath);
                $moduleCode = $vendor . '/' . $module;

                // Read and parse the module's manifest.json for metadata
                $manifestPath = $modulePath . '/manifest.json';
                if (\is_file($manifestPath)) {
                    $manifest = \json_decode(\file_get_contents($manifestPath), true);
                    if (\json_last_error() === JSON_ERROR_NONE) {
                        $index[$moduleCode] = [
                            'description' => $manifest['description'] ?? '',
                            'author' => $manifest['author'] ?? '',
                            'type' => $manifest['type'] ?? 'module',
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
     * Write index.json file.
     *
     * @param string $basePath Base path for repository
     * @param array $index Index data
     *
     * @return bool Success
     */
    public static function writeIndex(string $basePath, array $index): bool
    {
        $indexPath = \rtrim($basePath, DIRECTORY_SEPARATOR) . '/index.json';
        $json = \json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return \file_put_contents($indexPath, $json) !== false;
    }

    /**
     * The built-in default official registry map (gap G1).
     *
     * Lets `search` / `install --from-repo` / `pkg install` resolve packs
     * without the hand-written repository.inc.php blocker; a one-shot
     * `--from <url>[@branch]` override beats it for a single command.
     *
     * @return array<string, string> Repository URL → branch, never empty
     */
    public static function defaultRepositories(): array
    {
        return [\rtrim(self::DEFAULT_OFFICIAL_URL, '/') => self::DEFAULT_OFFICIAL_BRANCH];
    }

    /**
     * Resolve registry sources with documented precedence:
     * project repository.inc.php (authoritative while it returns entries)
     * → built-in default official registry.
     *
     * @param string|null $configFile Explicit config path (defaults to SYSTEM_ROOT/repository.inc.php)
     *
     * @return array<string, string> Repository URL → branch, never empty
     */
    public static function resolveRepositories(?string $configFile = null): array
    {
        $configFile ??= PathUtil::append(SYSTEM_ROOT, 'repository.inc.php');

        if (\is_file($configFile)) {
            // A hand-written registry file is authoritative when it has entries.
            $repositories = require $configFile;

            if (\is_array($repositories) && $repositories !== []) {
                return $repositories;
            }
        }

        return self::defaultRepositories();
    }

    /**
     * Add a repository source.
     *
     * @param string $url Repository base URL
     * @param string $branch Branch name (for GitHub repos)
     */
    public function addRepository(string $url, string $branch = 'main'): void
    {
        $url = \rtrim($url, '/');
        $this->repositories[$url] = $branch;
    }

    /**
     * Get all configured repositories.
     *
     * @return array
     */
    public function getRepositories(): array
    {
        return $this->repositories;
    }

    /**
     * Fetch repository index.
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
            $this->notify(self::TYPE_ERROR, ['Failed to fetch index (registry unreachable or index.json not found)', $indexUrl]);
            return null;
        }

        // ── Publisher authenticity (S5 / gap G4): verify the detached Ed25519
        // signature over the EXACT fetched bytes BEFORE json_decode trusts them.
        // Checksums (PackageVerifier) catch drift and accidents; this signature
        // is what survives a compromised registry repo. Fail-closed: an invalid
        // signature refuses the index (returns null) — untrusted JSON is never
        // parsed, so no code path can act on tampered metadata.
        $signature = $this->httpGet($this->buildRawUrl($repoUrl, $branch, 'index.sig'));
        $pinnedKey = PackageSignature::resolvePinnedPublicKey();

        if ($signature !== null) {
            if ($pinnedKey === null) {
                // Signed content we cannot verify is UNVERIFIED content — say so,
                // never silently drop to "trusted".
                $this->indexTrust[$repoUrl] = self::TRUST_UNSIGNED;
                $this->notify(self::TYPE_INFO, ['Index is signed but no pinned public key is available (asset keys/official-repo.pub or ' . PackageSignature::ENV_PUBKEY . ') — treating as UNVERIFIED', $repoUrl]);
            } else {
                try {
                    $valid = PackageSignature::verify($response, \trim($signature), $pinnedKey);
                } catch (PackageIntegrityException $e) {
                    $this->indexTrust[$repoUrl] = self::TRUST_INVALID;
                    $this->notify(self::TYPE_ERROR, ['Index signature check failed: ' . $e->getMessage(), $repoUrl]);

                    return null;
                }

                if ($valid !== true) {
                    $this->indexTrust[$repoUrl] = self::TRUST_INVALID;
                    $this->notify(self::TYPE_ERROR, ['Index signature INVALID against the pinned publisher key — tampered index? refusing to parse it', $repoUrl]);

                    return null;
                }

                $this->indexTrust[$repoUrl] = self::TRUST_SIGNED;
                $this->notify(self::TYPE_INFO, ['Index signature verified against pinned publisher key', $repoUrl]);
            }
        } else {
            $this->indexTrust[$repoUrl] = self::TRUST_UNSIGNED;
            $this->notify(self::TYPE_INFO, ['UNVERIFIED registry: no index.sig published — integrity is checksum-only', $repoUrl]);
        }

        $data = \json_decode($response, true);
        if (\json_last_error() !== JSON_ERROR_NONE) {
            $this->notify(self::TYPE_ERROR, ['Invalid index JSON', $repoUrl]);
            return null;
        }

        $this->indexCache[$repoUrl] = $data;
        return $data;
    }

    /**
     * Publisher-trust outcome for one repository after its index was fetched.
     *
     * @return string one of TRUST_SIGNED / TRUST_UNSIGNED / TRUST_INVALID / TRUST_UNKNOWN (not fetched)
     */
    public function getIndexTrustState(string $repoUrl): string
    {
        return $this->indexTrust[$repoUrl] ?? self::TRUST_UNKNOWN;
    }

    /**
     * Trust outcomes for every repository whose index this manager fetched —
     * commands print this as the "never silent" UNVERIFIED banner
     * (OFFICIAL-REPO-INSTALL.md §7: "unsigned index ⇒ hard banner").
     *
     * @return array<string, string> repository URL => TRUST_* value
     */
    public function getTrustReport(): array
    {
        return $this->indexTrust;
    }

    /**
     * Search for modules across all repositories.
     *
     * @param string $query Search query (module code or keyword)
     *
     * @return array Search results
     */
    public function search(string $query): array
    {
        $results = [];
        $query = \strtolower(\trim($query));

        // Search across all registered repositories
        foreach ($this->repositories as $repoUrl => $branch) {
            $index = $this->fetchIndex($repoUrl);
            if ($index === null) {
                continue;
            }

            foreach ($index as $moduleCode => $info) {
                // Match query against module code, description, or author (case-insensitive)
                if (
                    \str_contains(\strtolower($moduleCode), $query)
                    || \str_contains(\strtolower($info['description'] ?? ''), $query)
                    || \str_contains(\strtolower($info['author'] ?? ''), $query)
                ) {
                    $results[] = [
                        'module_code' => $moduleCode,
                        'description' => $info['description'] ?? '',
                        'author' => $info['author'] ?? '',
                        'type' => $info['type'] ?? 'module',
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
     * Get module info from repositories.
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
                    'type' => $index[$moduleCode]['type'] ?? 'module',
                    'latest' => $index[$moduleCode]['latest'] ?? '',
                    'versions' => $index[$moduleCode]['versions'] ?? [],
                    'repository' => $repoUrl,
                    'branch' => $branch,
                    // S2 integrity: pass the checksum-bearing metadata through
                    // (schema-v2 'releases.<v>.sha256' or flat 'sha256') so
                    // installers can resolve the claim via PackageVerifier
                    // without re-fetching the index. Additive keys only.
                    'releases' => $index[$moduleCode]['releases'] ?? [],
                    'sha256' => $index[$moduleCode]['sha256'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Get manifest for a specific module.
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

        $data = \json_decode($response, true);
        return \json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    /**
     * Get download URL for a module version.
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
        if (!\in_array($version, $info['versions'] ?? [])) {
            $this->notify(self::TYPE_ERROR, ['Version not found', "$moduleCode@$version"]);
            return null;
        }

        // Use GitHub Releases URL format for .phar files
        return $this->buildReleaseAssetUrl($repoUrl, $moduleCode, $version);
    }

    /**
     * Build release asset URL for GitHub repositories.
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
        $tagName = \str_replace('/', '-', $moduleCode) . '-v' . $version;
        $filename = $version . '.phar';

        // Transform GitHub web URL to Releases download URL
        if (\preg_match('#^https?://github\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = \rtrim($matches[2], '/');
            return \sprintf(
                'https://github.com/%s/%s/releases/download/%s/%s',
                $owner,
                $repo,
                $tagName,
                $filename,
            );
        }

        // Fallback to raw URL format for non-GitHub repositories
        return $this->buildRawUrl($repoUrl, 'main', $moduleCode . '/' . $version . '.phar');
    }

    /**
     * Build raw content URL for GitHub or other repositories.
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
        if (\preg_match('#^https?://github\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = \rtrim($matches[2], '/');
            return \sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                $owner,
                $repo,
                $branch,
                $path,
            );
        }

        // GitLab: transform web URL to raw file endpoint
        if (\preg_match('#^https?://gitlab\.com/([^/]+)/([^/]+)/?$#i', $repoUrl, $matches)) {
            $owner = $matches[1];
            $repo = \rtrim($matches[2], '/');
            return \sprintf(
                'https://gitlab.com/%s/%s/-/raw/%s/%s',
                $owner,
                $repo,
                $branch,
                $path,
            );
        }

        // For non-GitHub/GitLab repositories, simply append the path
        return \rtrim($repoUrl, '/') . '/' . $path;
    }

    /**
     * List all available modules across repositories.
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
                    'type' => $info['type'] ?? 'module',
                    'latest' => $info['latest'] ?? '',
                    'versions' => $info['versions'] ?? [],
                    'repository' => $repoUrl,
                ];
            }
        }

        return $results;
    }

    /**
     * HTTP GET request.
     *
     * @param string $url URL to fetch
     *
     * @return string|null Response body or null on failure
     */
    private function httpGet(string $url): ?string
    {
        // Transport policy (gap G5): index/manifest fetches honour the same
        // HTTPS-only rule as artifact downloads (ArchiveSafety::isSecureUrl);
        // insecure sources need the explicit operator opt-in.
        if (!ArchiveSafety::isSecureUrl($url, PackageVerifier::insecureTransportAllowed())) {
            $this->notify(self::TYPE_ERROR, ['Insecure repository URL rejected (HTTPS required; set RAZY_ALLOW_INSECURE_TRANSPORT=1 to override)', $url]);

            return null;
        }

        // S1 migration: the transport is the hardened client now. The gate
        // above stays (deliberate) so the operator hears RepositoryManager's
        // own message, not the client's generic one. Transport failures used
        // to be silent nulls; they now notify — failure paths are allowed to
        // get MORE informative, never less (fail-loud doctrine, Q3).
        try {
            $response = HttpClient::create()
                ->userAgent('Razy-RepositoryManager')
                ->withAccept('application/json, */*')
                ->get($url);
        } catch (HttpTransportException $e) {
            $this->notify(self::TYPE_ERROR, ['HTTP request failed', $e->getMessage()]);

            return null;
        }

        return $response->successful() ? $response->body() : null;
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
