<?php

/**
 * CLI Command: sign.
 *
 * Maintainer-side tooling for registry publisher authenticity (coexistence of
 * OFFICIAL-REPO-INSTALL.md §8 step S5, gap G4): Ed25519 keypairs, detached
 * signatures over registry metadata (index.json), and verification against
 * the pinned public key.
 *
 * Usage:
 *   php Razy.phar sign keygen [--key=<path>] [--pub=<path>]
 *   php Razy.phar sign <file> [--key=<path>] [--out=<sigpath>]
 *   php Razy.phar sign verify <file> [--sig=<sigpath>] [--pub=<path|hex>]
 *
 * Modes:
 *   keygen          generate a fresh Ed25519 pair (refuses to overwrite keys)
 *   <file>          sign exact file bytes -> hex signature sidecar ('<file>.sig' default)
 *   verify          check <file> against its signature; exit code 0 = valid
 *
 * Options:
 *   --key=<path>    secret key file (or hex); default env RAZY_REGISTRY_SIGNKEY
 *   --pub=<path>    public key file (or hex); verify default: pinned asset/env
 *   --sig=<path>    signature file for verify (default '<file>.sig')
 *   --out=<path>    signature output path (default '<file>.sig')
 *
 * Relative paths resolve against the PROJECT ROOT, not the shell CWD — the
 * Phar stream wrapper resolves bare relative paths inside the archive, so
 * cwd-relative file arguments are deterministic-by-construction here.
 *
 * The secret key NEVER enters the registry repository or this framework repo:
 * publish only the .pub into the phar asset (src/asset/keys/official-repo.pub)
 * and the registry's keys/ folder; keep the private key offline.
 *
 * @license MIT
 */

namespace Razy;

use Throwable;

return function (string $mode = '', ...$args) {
    $parseOpt = static function (string $name) use ($args): ?string {
        foreach ($args as $arg) {
            if (\is_string($arg) && \str_starts_with($arg, '--' . $name . '=')) {
                return \substr($arg, \strlen('--' . $name . '='));
            }
        }

        return null;
    };

    // Phar caveat (see header): bare relative paths resolve INSIDE the phar
    // archive via the stream wrapper, so file arguments are anchored to the
    // project root (RAZY_PATH, defined by the CLI bootstrap) unless absolute.
    $resolve = static function (?string $path): ?string {
        if ($path === null || $path === '') {
            return $path;
        }

        // Absolute (drive-letter or leading slash) or hex material passes through
        // untouched; hex detection mirrors PackageSignature::resolveHexOrFile.
        if (\preg_match('#^([a-zA-Z]:[\\\/]|[\\\/])#', $path) === 1) {
            return $path;
        }

        if (\preg_match('/^[0-9a-f]{64}$|^[0-9a-f]{128}$/i', $path) === 1) {
            return $path;
        }

        return \RAZY_PATH . '/' . \ltrim(\str_replace('\\', '/', $path), '/');
    };

    if ($mode === '') {
        $this->writeLineLogging('{@s:bu}Registry signing{@reset}', true);
        $this->writeLineLogging('Usage: php Razy.phar sign keygen | sign <file> | sign verify <file> — see header docblock.', true);
        exit(1);
    }

    try {
        if ($mode === 'keygen') {
            $keyPath = $resolve($parseOpt('key') ?? 'registry-sign.key');
            $pubPath = $resolve($parseOpt('pub') ?? 'registry-sign.pub');

            foreach ([$keyPath, $pubPath] as $path) {
                if (\is_file($path)) {
                    $this->writeLineLogging("{@c:red}[ERROR] Refusing to overwrite existing key file: {$path} (destroying a signing key orphans every published signature){@reset}", true);
                    exit(1);
                }

                // Fail typed, not with raw file_put_contents warnings, when the
                // destination directory does not exist or is unwritable.
                $dir = \dirname($path);

                if (!\is_dir($dir) || !\is_writable($dir)) {
                    $this->writeLineLogging("{@c:red}[ERROR] Key destination directory missing or unwritable: {$dir}{@reset}", true);
                    exit(1);
                }
            }

            $pair = PackageSignature::generate();
            \file_put_contents($keyPath, $pair['secret'] . "\n");
            @\chmod($keyPath, 0o600);
            \file_put_contents($pubPath, $pair['public'] . "\n");

            $this->writeLineLogging('{@c:green}✓ Ed25519 keypair generated', true);
            $this->writeLineLogging("  secret (KEEP OFFLINE): {$keyPath}", true);
            $this->writeLineLogging("  public:                {$pubPath}", true);
            $this->writeLineLogging('  public key: ' . $pair['public'], true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Next: commit the public hex as the pinned asset src/asset/keys/official-repo.pub,', true);
            $this->writeLineLogging('      mirror it in the registry repo keys/, and back the SECRET key up offline.', true);
            exit(0);
        }

        if ($mode === 'verify') {
            $file = $resolve(\is_string($args[0] ?? null) ? $args[0] : '');

            if ('' === $file || !\is_file($file)) {
                $this->writeLineLogging('{@c:red}[ERROR] verify needs an existing file argument.{@reset}', true);
                exit(1);
            }

            $sigPath = $resolve($parseOpt('sig') ?? ($file . '.sig'));

            if (!\is_file($sigPath)) {
                $this->writeLineLogging("{@c:red}[ERROR] Signature file not found: {$sigPath}{@reset}", true);
                exit(1);
            }

            $pubArg = $resolve($parseOpt('pub'));
            $public = PackageSignature::resolvePublicKey($pubArg);

            if ($public === null) {
                $this->writeLineLogging('{@c:red}[ERROR] No pinned public key available (asset keys/official-repo.pub or RAZY_REGISTRY_PUBKEY) and no --pub given.{@reset}', true);
                exit(1);
            }

            $valid = PackageSignature::verify((string) \file_get_contents($file), (string) \file_get_contents($sigPath), $public);

            if ($valid) {
                $this->writeLineLogging('{@c:green}✓ SIGNATURE VALID{@reset} — ' . $file . ' matches its signature under the pinned key.', true);
                exit(0);
            }

            $this->writeLineLogging('{@c:red}✗ SIGNATURE INVALID{@reset} — tampered file or wrong key. Refusing to trust it.', true);
            exit(1);
        }

        // default mode: sign <file>
        $file = $resolve($mode);

        if ('' === $file || !\is_file($file)) {
            $this->writeLineLogging("{@c:red}[ERROR] File to sign not found: {$file}{@reset}", true);
            exit(1);
        }

        $secret = PackageSignature::resolveSigningSecret($resolve($parseOpt('key')));

        if ($secret === null) {
            $this->writeLineLogging('{@c:red}[ERROR] No signing key: pass --key=<path> or set RAZY_REGISTRY_SIGNKEY.{@reset}', true);
            exit(1);
        }

        $signature = PackageSignature::sign((string) \file_get_contents($file), $secret);
        $outPath = $resolve($parseOpt('out') ?? ($file . '.sig'));
        \file_put_contents($outPath, $signature . "\n");

        $this->writeLineLogging('{@c:green}✓ signed{@reset} ' . $file . ' → ' . $outPath, true);
        $this->writeLineLogging('  ' . \substr($signature, 0, 32) . '…', true);
        exit(0);
    } catch (Throwable $e) {
        $this->writeLineLogging('{@c:red}[ERROR] ' . $e->getMessage() . '{@reset}', true);
        exit(1);
    }
};
