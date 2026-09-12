<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Publisher authenticity for registry indexes (OFFICIAL-REPO-INSTALL.md §8
 * step S5, gap G4): detached Ed25519 signatures, pinned verification key.
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\PackageIntegrityException;

/**
 * PackageSignature — Ed25519 detached-signature verify/sign for registry metadata.
 *
 * Closes gap G4 ("publisher authenticity = whoever can push to the repo"):
 * `RepositoryManager::fetchIndex()` verifies `index.sig` over the EXACT fetched
 * bytes of `index.json` BEFORE `json_decode()` trusts them (dossier §8 S5).
 * Checksums (PackageVerifier, S2) kill drift and accidents; the signature is
 * what survives a compromised registry repo — an attacker with branch-write
 * access cannot forge `index.sig` without the signing key.
 *
 * Design decisions (deliberate evolution of the dossier proposal):
 *
 * - **Bytes, not canonicalized JSON.** The dossier suggested "canonical JSON
 *   sort"; we instead sign the exact bytes published and verify against the
 *   exact bytes fetched — `fetchIndex` already verifies *before* parsing, so
 *   there is no reconstruct-the-payload step where a canonicalization bug
 *   could live. Simpler and strictly equal in trust: what you verify is
 *   literally what was signed.
 * - **Algorithm: Ed25519 via ext-sodium** (`sodium_crypto_sign_*`), the
 *   modern default named in the dossier. No `openssl_verify` fallback is
 *   shipped: dual implementations of a security check are dual attack
 *   surfaces; ext-sodium is bundled with PHP ≥ 7.2 by default, and its
 *   absence on a registry consumer is a loud, actionable configuration bug.
 * - **Key pinning order:** env `RAZY_REGISTRY_PUBKEY` (64-hex literal or a
 *   path to a .pub file) → phar asset `asset/keys/official-repo.pub` → none.
 *   Same env-gate spirit as `BridgeSignature` (`RAZY_BRIDGE_SECRET`).
 *   `RAZY_REGISTRY_SIGNKEY` is the mirror for the signing side (secret hex),
 *   for the `sign` command and future publish automation — never consulted by
 *   verification code.
 * - **On-disk encoding: lowercase hex text files** — Contents-API-pushable,
 *   diffable, and no MIME mangling across mirrors.
 *
 * Prior art honored (dossier line S5): constant-time comparison lives inside
 * libsodium (`verify_detached` is the constant-time primitive); malformed
 * input fails closed with `PackageIntegrityException`, never a silent false.
 */
final class PackageSignature
{
    /** @var string Env override for the pinned PUBLIC key (hex literal or .pub path) */
    public const ENV_PUBKEY = 'RAZY_REGISTRY_PUBKEY';

    /** @var string Env override for the maintainer SECRET key (hex literal or key path) — signing side only */
    public const ENV_SIGNKEY = 'RAZY_REGISTRY_SIGNKEY';

    /** @var string Phar-asset path of the pinned official publisher key */
    public const PINNED_KEY_ASSET = 'asset/keys/official-repo.pub';

    /**
     * Generate a fresh Ed25519 keypair.
     *
     * @return array{public: string, secret: string} lowercase hex strings (64 / 128 chars)
     *
     * @throws PackageIntegrityException when ext-sodium is unavailable
     */
    public static function generate(): array
    {
        self::requireSodium();

        $pair = \sodium_crypto_sign_keypair();

        return [
            'public' => \bin2hex(\sodium_crypto_sign_publickey($pair)),
            'secret' => \bin2hex(\sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Produce a detached Ed25519 signature over exact payload bytes.
     *
     * @param string $payload exact bytes to sign (e.g. index.json content as published)
     * @param string $secretKey hex secret key (file contents are trimmed)
     *
     * @return string hex signature (128 chars), safe to publish as `index.sig`
     *
     * @throws PackageIntegrityException on unavailable sodium or malformed secret key
     */
    public static function sign(string $payload, string $secretKey): string
    {
        self::requireSodium();

        $secret = self::decodeKey($secretKey, 128, 'secret');

        return \bin2hex(\sodium_crypto_sign_detached($payload, $secret));
    }

    /**
     * Verify a detached signature over exact payload bytes.
     *
     * @param string $payload exact bytes as fetched
     * @param string $signature hex signature (e.g. trimmed index.sig body)
     * @param string $publicKey hex public key
     *
     * @return bool true only when the signature is valid for this exact payload+key
     *
     * @throws PackageIntegrityException on malformed key/signature input (fail closed)
     */
    public static function verify(string $payload, string $signature, string $publicKey): bool
    {
        self::requireSodium();

        $public = self::decodeKey($publicKey, 64, 'public');
        $sig = self::decodeKey($signature, 128, 'signature');

        return \sodium_crypto_sign_verify_detached($sig, $payload, $public);
    }

    /**
     * Resolve the registry verification key. Order: explicit CLI argument (hex
     * or path) → env override (hex literal or path) → phar asset → null
     * (= verification impossible; callers treat signed content as UNVERIFIED,
     * never silently trusted).
     *
     * @param string|null $explicit hex literal or .pub path from a CLI argument
     */
    public static function resolvePublicKey(?string $explicit = null): ?string
    {
        if ($explicit !== null && \trim($explicit) !== '') {
            return self::resolveHexOrFile($explicit, 'public key argument');
        }

        $env = self::envValue(self::ENV_PUBKEY);

        if ($env !== null && $env !== '') {
            return self::resolveHexOrFile($env, self::ENV_PUBKEY);
        }

        if (\defined('PHAR_PATH')) {
            // Deliberately NO realpath(): it returns false for phar:// URLs
            // while is_file/file_get_contents handle them fine (verified).
            $asset = \PHAR_PATH . '/' . self::PINNED_KEY_ASSET;

            if (\is_file($asset)) {
                $content = \file_get_contents($asset);

                if (\is_string($content) && \preg_match('/^[0-9a-f]{64}$/', \trim($content)) === 1) {
                    return \trim($content);
                }
            }
        }

        return null;
    }

    /**
     * Pinned-key lookup without an explicit argument (library callers).
     */
    public static function resolvePinnedPublicKey(): ?string
    {
        return self::resolvePublicKey();
    }

    /**
     * Resolve a signing secret key for the CLI/automation side (never used by
     * verification): --key path argument wins; env hex-or-path is the fallback.
     *
     * @param string|null $keyPath explicit path/hex from a CLI argument
     */
    public static function resolveSigningSecret(?string $keyPath = null): ?string
    {
        if ($keyPath !== null && \trim($keyPath) !== '') {
            return self::resolveHexOrFile($keyPath, 'signing key argument');
        }

        $env = self::envValue(self::ENV_SIGNKEY);

        if ($env !== null && $env !== '') {
            return self::resolveHexOrFile($env, self::ENV_SIGNKEY);
        }

        return null;
    }

    /**
     * Accept either a literal hex string or a path whose (trimmed) content is hex.
     */
    private static function resolveHexOrFile(string $value, string $label): string
    {
        $value = \trim($value);

        if (\preg_match('/^[0-9a-f]+$/i', $value) === 1) {
            return \strtolower($value);
        }

        $path = \realpath($value);

        if ($path === false || !\is_file($path)) {
            throw new PackageIntegrityException('Cannot read ' . $label . ' "' . $value . '" (neither hex nor a readable file).');
        }

        $content = \file_get_contents($path);

        if (!\is_string($content)) {
            throw new PackageIntegrityException('Cannot read ' . $label . ' file "' . $path . '".');
        }

        return self::normalizeHex($content, $label);
    }

    /**
     * Decode an expected-length hex key, failing closed with an operator-readable reason.
     */
    private static function decodeKey(string $hex, int $length, string $label): string
    {
        $hex = self::normalizeHex($hex, $label);

        if (\strlen($hex) !== $length || \preg_match('/^[0-9a-f]{' . $length . '}$/', $hex) !== 1) {
            throw new PackageIntegrityException('Malformed ' . $label . ' key/signature: expected ' . $length . ' hex characters, got ' . \strlen($hex) . '.');
        }

        return \sodium_hex2bin($hex);
    }

    private static function normalizeHex(string $value, string $label): string
    {
        $value = \strtolower(\trim($value));

        if (\preg_match('/^[0-9a-f]*$/', $value) !== 1) {
            throw new PackageIntegrityException('Malformed ' . $label . ': contains non-hex characters.');
        }

        return $value;
    }

    private static function requireSodium(): void
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            throw new PackageIntegrityException('Registry signature support requires ext-sodium (bundled with PHP since 7.2) — enable it or install without signature verification.');
        }
    }

    /**
     * Env read compatible with CLI and web contexts (bootstrap `env()` helper
     * may be absent, e.g. under PHPUnit).
     */
    private static function envValue(string $name): ?string
    {
        if (\function_exists('env')) {
            $value = \env($name);

            return \is_string($value) ? $value : null;
        }

        $raw = \getenv($name);

        return \is_string($raw) ? $raw : null;
    }
}
