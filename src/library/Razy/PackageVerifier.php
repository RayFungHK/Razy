<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Artifact integrity + transport-policy gate for registry installs
 * (OFFICIAL-REPO-INSTALL.md §8 step S2, gaps G3/G5).
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\PackageIntegrityException;

/**
 * PackageVerifier — checksum & URL-policy gate for every remote pack/phar fetch.
 *
 * The producer side (`pack`) already records SHA-256 per release into
 * `manifest.json`/`latest.json` (`src/system/terminal/pack.inc.php`), but the
 * consumer side verified it nowhere (dossier §2.5: checksums are decorative on
 * the install path). This class closes that gap for the phar channels
 * (`install --from-repo`, `sync`, `pkg install`) with two guarantees:
 *
 *  1. resolveExpectedChecksum() implements the fail-closed claim semantics:
 *     - index publishes `releases.<version>.sha256` (or a flat `sha256`)
 *       => verification is MANDATORY;
 *     - entry declares a release block (or sha256 key) without a usable
 *       checksum => claimed-but-missing, abort (fail closed);
 *     - index predates checksums entirely => proceed with a loud warning
 *       (honest: cannot verify what was never published).
 *  2. assertSecureUrl() applies the same HTTPS-only transport policy the ZIP
 *     installer already enforces (`RepoInstaller::downloadArchive` via
 *     `ArchiveSafety::isSecureUrl`, 2026 audit §S2), so the pack/phar paths are
 *     no longer the unguarded ones. Phar *signature* integrity stays with
 *     PHP itself (`new Phar(...)` rejects broken signatures) — not re-checked here.
 *
 * All methods are pure/static — unit-tested in tests/PackageVerifierTest.php
 * and tests/RegistryFetchPolicyTest.php without network: the hash check and the
 * claim/url decision logic are directly testable; download-loop wiring in the
 * terminal commands is pinned by source-ordering tests.
 */
final class PackageVerifier
{
    /**
     * Decide the checksum policy for one release from raw index-entry metadata.
     *
     * Reads the schema-v2 fields promoted by the dossier (§6.2) —
     * `releases.<version>.sha256` first, then a flat `sha256` key
     * (single-release registries) — without touching the network. Callers act
     * on the result before extraction:
     *
     *  required=true,  sha256=string  → hash the artifact, abort on mismatch
     *  required=true,  sha256=null    → a checksum was claimed but is missing: abort
     *  required=false                 → no checksum was ever published: warn, proceed
     *
     * Accepts both raw `index.json` entries and the
     * `RepositoryManager::getModuleInfo()` projection (which passes the
     * `releases` / `sha256` keys through).
     *
     * @param array $indexEntry Raw index entry for the pack (may carry 'releases' and/or 'sha256')
     * @param string $version Resolved release version being installed
     *
     * @return array{required: bool, sha256: string|null, reason: string}
     */
    public static function resolveExpectedChecksum(array $indexEntry, string $version): array
    {
        $releases = $indexEntry['releases'] ?? null;

        if (\is_array($releases) && \array_key_exists($version, $releases)) {
            $release = $releases[$version];

            if (!\is_array($release)) {
                return self::claimedChecksum(null, 'release metadata for "' . $version . '" is malformed (expected an object)');
            }

            $sha = $release['sha256'] ?? null;

            if (\is_string($sha) && \trim($sha) !== '') {
                return self::claimedChecksum(\strtolower(\trim($sha)), 'index publishes sha256 for ' . $version);
            }

            return self::claimedChecksum(null, 'release "' . $version . '" is published with checksums but its sha256 is missing');
        }

        // Flat single-release style claim (v2 shorthand; v1 entries carry neither key).
        if (isset($indexEntry['sha256'])) {
            $flat = $indexEntry['sha256'];

            if (\is_string($flat) && \trim($flat) !== '') {
                return self::claimedChecksum(\strtolower(\trim($flat)), 'index entry publishes a flat sha256 checksum');
            }

            return self::claimedChecksum(null, 'index entry carries a sha256 key but the value is missing or empty');
        }

        return [
            'required' => false,
            'sha256' => null,
            'reason' => 'index entry carries no sha256 (index predates checksum publishing)',
        ];
    }

    /**
     * Hash a downloaded artifact with hash_file() and compare against the
     * expected SHA-256 digest.
     *
     * Fails loudly (PackageIntegrityException) on a malformed expectation, an
     * unreadable artifact, or any digest mismatch — callers must treat the
     * throw as "abort before extractTo and remove the partial download".
     */
    public static function verifyFile(string $path, string $expectedSha256): void
    {
        $expected = self::normalizeDigest($expectedSha256);

        // '@' keeps the open-failure warning off the wire: the typed exception
        // below IS the diagnostic (missing/unreadable files are expected inputs here).
        $actual = @\hash_file('sha256', $path);

        // hash_file() returns false on missing/unreadable files (its success
        // digest is always a 64-char hex string, so no separate '' check).
        if (!\is_string($actual)) {
            throw new PackageIntegrityException('Cannot hash downloaded artifact at "' . $path . '" (missing or unreadable) — refusing to proceed with an unverifiable file.');
        }

        self::assertSameDigest($actual, $expected, $path);
    }

    /**
     * Verify in-memory artifact bytes (e.g. a cURL body kept before saving,
     * as `pkg install` does) against the expected SHA-256 digest.
     *
     * Same fail-closed contract as verifyFile().
     */
    public static function verifyString(string $bytes, string $expectedSha256): void
    {
        $expected = self::normalizeDigest($expectedSha256);

        self::assertSameDigest(\hash('sha256', $bytes), $expected, 'downloaded payload (' . \strlen($bytes) . ' bytes)');
    }

    /**
     * Enforce the distribution-URL policy on a fetch before any bytes move.
     *
     * Delegates to ArchiveSafety::isSecureUrl — the same primitive the ZIP
     * installer already used (2026 audit §S2): HTTPS only; plain HTTP requires
     * the explicit operator opt-in (PackageManager::$allowInsecureTransport or
     * RAZY_ALLOW_INSECURE_TRANSPORT=1); scheme-less local paths and file://
     * pass as deliberately-configured local dev / mirror sources (existing
     * policy, kept as-is).
     *
     * @param string $url Repository or artifact URL about to be fetched
     * @param bool|null $allowInsecure Explicit override; null = read operator opt-in
     */
    public static function assertSecureUrl(string $url, ?bool $allowInsecure = null): void
    {
        $allow = $allowInsecure ?? self::insecureTransportAllowed();

        if (!ArchiveSafety::isSecureUrl($url, $allow)) {
            throw new PackageIntegrityException('Insecure artifact URL rejected (HTTPS required): ' . $url . ' — set RAZY_ALLOW_INSECURE_TRANSPORT=1 only for a trusted local mirror.');
        }
    }

    /**
     * Read the operator opt-in for plain-HTTP transport. Trusted settings only
     * (static flag or environment) — never derived from package metadata.
     */
    public static function insecureTransportAllowed(): bool
    {
        if (PackageManager::$allowInsecureTransport) {
            return true;
        }

        // env() is a bootstrap helper; tests may run without it loaded.
        return \function_exists('env') && (bool) \env('RAZY_ALLOW_INSECURE_TRANSPORT', false);
    }

    /**
     * @return array{required: bool, sha256: string|null, reason: string}
     */
    private static function claimedChecksum(?string $sha256, string $reason): array
    {
        return [
            'required' => true,
            'sha256' => $sha256,
            'reason' => $reason,
        ];
    }

    /**
     * Validate + normalize a claimed digest before any comparison.
     */
    private static function normalizeDigest(string $expectedSha256): string
    {
        $expected = \strtolower(\trim($expectedSha256));

        if (\preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            throw new PackageIntegrityException('Malformed sha256 checksum claim "' . \substr($expectedSha256, 0, 24) . '" (expected 64 hex characters) — refusing to trust an unusable claim.');
        }

        return $expected;
    }

    /**
     * Constant-time digest comparison with an operator-facing mismatch report.
     */
    private static function assertSameDigest(string $actual, string $expected, string $subject): void
    {
        $actual = \strtolower(\trim($actual));

        if (!\hash_equals($expected, $actual)) {
            throw new PackageIntegrityException('Checksum mismatch for ' . $subject . ': expected ' . \substr($expected, 0, 12) . '..., got ' . \substr($actual, 0, 12) . '... — the downloaded artifact does not match the published checksum. Refusing to install it.');
        }
    }
}
