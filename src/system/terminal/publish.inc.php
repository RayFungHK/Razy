<?php

/**
 * Publishing helper functions for the Razy package system.
 *
 * Provides GitHub API operations (tags, releases, file management) and
 * version normalisation used by the `pkg publish` sub-command.
 *
 * These functions are loaded on demand via require_once from pkg.inc.php.
 * The standalone `php Razy.phar publish` command has been removed --
 * use `php Razy.phar pkg publish` instead.
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Normalize a version string to standard X.Y.Z format.
 *
 * @param string $version The raw version string (may include 'v' prefix)
 *
 * @return string|null Normalized version string, or null if invalid
 */
function normalizeVersion(string $version): ?string
{
    // Remove 'v' prefix if present
    $version = \ltrim($version, 'vV');

    // Match version pattern
    if (\preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([a-zA-Z0-9.]+))?$/', $version, $matches)) {
        $major = $matches[1];
        $minor = $matches[2] ?? '0';
        $patch = $matches[3] ?? '0';
        $build = isset($matches[4]) ? '.' . $matches[4] : '';
        $prerelease = isset($matches[5]) ? '-' . $matches[5] : '';

        return $major . '.' . $minor . '.' . $patch . $build . $prerelease;
    }

    return null;
}

/**
 * Retrieve all tag names from a GitHub repository.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 *
 * @return array List of tag names
 */
function githubGetTags(string $token, string $repo): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/tags';

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $tags = \json_decode($response, true);
    if (!\is_array($tags)) {
        return [];
    }

    return \array_column($tags, 'name');
}

/**
 * Create a lightweight tag on a GitHub repository branch.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Branch to tag from
 * @param string $tagName The tag name to create
 * @param string $message The commit message for the tag
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubCreateTag(string $token, string $repo, string $branch, string $tagName, string $message): array
{
    // First, get the SHA of the branch
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/git/ref/heads/' . $branch;

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Could not get branch SHA'];
    }

    $refData = \json_decode($response, true);
    $sha = $refData['object']['sha'] ?? null;

    if (!$sha) {
        return ['success' => false, 'error' => 'Branch SHA not found'];
    }

    // Create the tag reference
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/git/refs';

    $data = [
        'ref' => 'refs/tags/' . $tagName,
        'sha' => $sha,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Delete a file from a GitHub repository via the Contents API.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Branch containing the file
 * @param string $path File path within the repository
 * @param string $message Commit message for the deletion
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubDeleteFile(string $token, string $repo, string $branch, string $path, string $message): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . $path;

    // First, get the file to get its SHA
    $ch = \curl_init($apiUrl . '?ref=' . $branch);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'File not found'];
    }

    $fileData = \json_decode($response, true);
    $sha = $fileData['sha'] ?? null;

    if (!$sha) {
        return ['success' => false, 'error' => 'Could not get file SHA'];
    }

    // Delete the file
    $data = [
        'message' => $message,
        'sha' => $sha,
        'branch' => $branch,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 200) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Upload or update a file in a GitHub repository via the Contents API.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $branch Target branch
 * @param string $path File path within the repository
 * @param string $content File content to upload
 * @param string $message Commit message
 *
 * @return array Result with 'success' flag and optional 'error'
 */
function githubPutFile(string $token, string $repo, string $branch, string $path, string $content, string $message): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/contents/' . $path;

    // First, try to get the file to check if it exists (need SHA for update)
    $ch = \curl_init($apiUrl . '?ref=' . $branch);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    $sha = null;
    if ($httpCode === 200) {
        $fileData = \json_decode($response, true);
        $sha = $fileData['sha'] ?? null;
    }

    // Prepare the content
    $data = [
        'message' => $message,
        'content' => \base64_encode($content),
        'branch' => $branch,
    ];

    if ($sha) {
        $data['sha'] = $sha;
    }

    // Upload/update the file
    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        return ['success' => true];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Create a GitHub Release associated with a tag.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param string $tagName Tag to create the release from
 * @param string $name Release title
 * @param string $body Release notes / description
 * @param bool $prerelease Whether to mark as pre-release
 *
 * @return array Result with 'success', 'release_id', and 'upload_url'
 */
function githubCreateRelease(string $token, string $repo, string $tagName, string $name, string $body, bool $prerelease = false): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/releases';

    $data = [
        'tag_name' => $tagName,
        'name' => $name,
        'body' => $body,
        'draft' => false,
        'prerelease' => $prerelease,
    ];

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        $releaseData = \json_decode($response, true);
        return ['success' => true, 'release_id' => $releaseData['id'], 'upload_url' => $releaseData['upload_url']];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Upload a binary asset to a GitHub Release.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 * @param int $releaseId The Release ID to attach the asset to
 * @param string $assetPath Local filesystem path to the asset file
 * @param string $assetName Filename for the uploaded asset
 *
 * @return array Result with 'success' and optional 'download_url' or 'error'
 */
function githubUploadReleaseAsset(string $token, string $repo, int $releaseId, string $assetPath, string $assetName): array
{
    $uploadUrl = 'https://uploads.github.com/repos/' . $repo . '/releases/' . $releaseId . '/assets?name=' . \urlencode($assetName);

    $fileContent = \file_get_contents($assetPath);
    if ($fileContent === false) {
        return ['success' => false, 'error' => 'Cannot read file: ' . $assetPath];
    }

    $ch = \curl_init($uploadUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_POST, true);
    \curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
        'Content-Type: application/octet-stream',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode === 201) {
        $assetData = \json_decode($response, true);
        return ['success' => true, 'download_url' => $assetData['browser_download_url']];
    }

    $errorData = \json_decode($response, true);
    $errorMessage = $errorData['message'] ?? 'HTTP ' . $httpCode;

    return ['success' => false, 'error' => $errorMessage];
}

/**
 * Retrieve all existing releases and their assets from a GitHub repository.
 *
 * @param string $token GitHub personal access token
 * @param string $repo Repository in owner/repo format
 *
 * @return array Map of tag_name => ['id' => int, 'assets' => string[]]
 */
function githubGetReleases(string $token, string $repo): array
{
    $apiUrl = 'https://api.github.com/repos/' . $repo . '/releases';

    $ch = \curl_init($apiUrl);
    \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    \curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'User-Agent: Razy-Publisher',
        'Accept: application/vnd.github.v3+json',
    ]);

    $response = \curl_exec($ch);
    $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    \curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $releases = \json_decode($response, true);
    if (!\is_array($releases)) {
        return [];
    }

    // Return map of tag_name => release info
    $result = [];
    foreach ($releases as $release) {
        $result[$release['tag_name']] = [
            'id' => $release['id'],
            'assets' => \array_column($release['assets'], 'name'),
        ];
    }

    return $result;
}

/**
 * Pack a module into a .phar file for distribution.
 *
 * @param string $modulePath Absolute path to the module directory
 * @param string $moduleCode Module code in vendor/module format
 * @param string $version Semver version string
 * @param string $outputBasePath Base output directory for packages
 * @param array $config Module configuration from module.php
 *
 * @return array Result with 'success' and 'pharFile' or 'error'
 */
function packModule(string $modulePath, string $moduleCode, string $version, string $outputBasePath, array $config): array
{
    try {
        // Determine output path
        $outputPath = PathUtil::append($outputBasePath, $moduleCode);

        // Create output directory
        if (!\is_dir($outputPath)) {
            if (!\mkdir($outputPath, 0755, true)) {
                return ['success' => false, 'error' => 'Cannot create output directory'];
            }
        }

        // Determine source package path
        $packagePath = PathUtil::append($modulePath, 'default');
        if (!\is_dir($packagePath)) {
            return ['success' => false, 'error' => 'Default package not found'];
        }

        // Create .phar file
        $pharFilename = $version . '.phar';
        $pharPath = PathUtil::append($outputPath, $pharFilename);

        // Remove existing file
        if (\is_file($pharPath)) {
            \unlink($pharPath);
        }

        $phar = new Phar($pharPath, 0, $pharFilename);
        $phar->startBuffering();

        // Add package files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packagePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = \substr($file->getPathname(), \strlen($packagePath) + 1);
            $relativePath = \str_replace('\\', '/', $relativePath);

            // Skip publish.json so credentials are never packed into the .phar
            if ($relativePath === 'publish.json') {
                continue;
            }

            if ($file->isDir()) {
                $phar->addEmptyDir($relativePath);
            } else {
                $phar->addFile($file->getPathname(), $relativePath);
            }
        }

        // Add module.php
        $modulePhpPath = PathUtil::append($modulePath, 'module.php');
        if (\is_file($modulePhpPath)) {
            $phar->addFile($modulePhpPath, 'module.php');
        }

        // Add webassets if exists
        $webassetsPath = PathUtil::append($modulePath, 'webassets');
        if (\is_dir($webassetsPath)) {
            $webIterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($webassetsPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($webIterator as $file) {
                $relativePath = 'webassets/' . \substr($file->getPathname(), \strlen($webassetsPath) + 1);
                $relativePath = \str_replace('\\', '/', $relativePath);

                if ($file->isDir()) {
                    $phar->addEmptyDir($relativePath);
                } else {
                    $phar->addFile($file->getPathname(), $relativePath);
                }
            }
        }

        $phar->stopBuffering();

        // Compress if possible
        if (Phar::canCompress(Phar::GZ)) {
            $phar->compressFiles(Phar::GZ);
        }

        // Create/update manifest.json
        $manifestPath = PathUtil::append($outputPath, 'manifest.json');
        $manifest = [];
        if (\is_file($manifestPath)) {
            $manifest = \json_decode(\file_get_contents($manifestPath), true) ?? [];
        }

        $manifest['module_code'] = $config['module_code'] ?? $moduleCode;
        $manifest['description'] = $config['description'] ?? '';
        $manifest['author'] = $config['author'] ?? '';

        // Add version to versions list
        if (!isset($manifest['versions']) || !\is_array($manifest['versions'])) {
            $manifest['versions'] = [];
        }
        if (!\in_array($version, $manifest['versions'])) {
            $manifest['versions'][] = $version;
            \usort($manifest['versions'], 'version_compare');
            $manifest['versions'] = \array_reverse($manifest['versions']);
        }
        $manifest['latest'] = $manifest['versions'][0];

        \file_put_contents($manifestPath, \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create latest.json
        $latestInfo = [
            'version' => $version,
            'checksum' => \hash_file('sha256', $pharPath),
            'size' => \filesize($pharPath),
            'timestamp' => \date('c'),
        ];
        \file_put_contents(PathUtil::append($outputPath, 'latest.json'), \json_encode($latestInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ['success' => true, 'pharFile' => $pharFilename];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
