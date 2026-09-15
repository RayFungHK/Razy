<?php

/**
 * This file is part of Razy v1.1.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Security\Wizard;

use Razy\Cache\CacheInterface;
use Razy\Cache\NullAdapter;
use Razy\Exception\SetupException;
use Razy\Security\OAuth\StateSigner;

/**
 * Signed, single-use wizard bootstrap tokens (MODULE-LIFECYCLE.md Q6,
 * maintainer-approved 2026-09-17: CLI-minted one-time token, StateSigner
 * lineage, no framework user row).
 *
 * The bootstrap-auth chicken-and-egg — the wizard migrates the schema the
 * login system may itself live in — is answered WITHOUT inventing accounts:
 * an operator who already holds the shell mints a token
 * (`php Razy.phar module wizard-token <dist> <code>`), and the web runner
 * accepts exactly that token, exactly once, within the TTL window.
 *
 *   token = b64url(payload) . '.' . b64url(HMAC-SHA256(payload, secret))
 *   payload = {n: nonce, d: dist, m: module code, t: unix ts}
 *
 * Same rails as StateSigner (whose b64url helpers this reuses rather than
 * duplicates): self-protecting value, constant-time HMAC BEFORE any parse,
 * mandatory cache for the single-use nonce (no cache = no single-use = a
 * silently weaker scheme, which is the exact drift the OAuth dossier exists
 * to stop). The secret is env-held: RAZY_WIZARD_TOKEN_SECRET.
 */
final class WizardTokenSigner
{
    /** wizard tokens die after 10 minutes (Q6: 5-10 min window) */
    public const DEFAULT_TTL = 600;

    private const KEY_PREFIX = 'razys/wizard-nonce/';

    public function __construct(
        private readonly string $secret,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
        if ($secret === '') {
            throw new SetupException('WizardTokenSigner requires a non-empty signing secret (Q6 rails: hold it in env, e.g. RAZY_WIZARD_TOKEN_SECRET).');
        }

        if ($this->ttl < 1) {
            throw new SetupException('WizardTokenSigner TTL must be at least 1 second.');
        }
    }

    /**
     * Mint one token bound to a distributor + module. The bearer proves
     * nothing about identity — the shell already proved that by running the
     * CLI; this token only survives into the browser hop, once.
     */
    public function issue(string $dist, string $moduleCode): string
    {
        $nonce = \bin2hex(\random_bytes(16));

        $record = [
            'd' => $dist,
            'm' => $moduleCode,
            'exp' => \time() + $this->ttl,
        ];

        $this->requireCache()->set(self::KEY_PREFIX . $nonce, \json_encode($record, JSON_THROW_ON_ERROR), $this->ttl);

        $payload = \json_encode([
            'n' => $nonce,
            'd' => $dist,
            'm' => $moduleCode,
            't' => \time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $seg = StateSigner::b64url($payload);

        return $seg . '.' . StateSigner::b64url($this->hmac($seg));
    }

    /**
     * Verify authenticity, freshness, and dist+module binding. Side-effect
     * free — call this BEFORE consuming the nonce or touching the ledger.
     *
     * @return array{nonce: string}
     */
    public function verify(string $token, string $dist, string $moduleCode): array
    {
        $parts = \explode('.', $token);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new SetupException('Malformed wizard token (expected payload.signature).');
        }

        [$seg, $sigSeg] = $parts;

        $given = StateSigner::b64urlDecode($sigSeg);

        if (!\hash_equals($this->hmac($seg), $given)) {
            throw new SetupException('Wizard token signature mismatch (tampered, or minted with a different secret).');
        }

        $payload = \json_decode(StateSigner::b64urlDecode($seg), true);

        if (!\is_array($payload) || !isset($payload['n'], $payload['d'], $payload['m'], $payload['t']) || !\is_string($payload['n'])) {
            throw new SetupException('Wizard token payload is not a valid signed payload.');
        }

        if (\abs(\time() - (int) $payload['t']) > $this->ttl) {
            throw new SetupException('Wizard token is older than the allowed window — have the operator mint a fresh one.');
        }

        if (!\hash_equals($payload['d'], $dist)) {
            throw new SetupException('Wizard token was minted for a different distributor.');
        }

        if (!\hash_equals($payload['m'], $moduleCode)) {
            throw new SetupException('Wizard token was minted for a different module.');
        }

        return ['nonce' => $payload['n']];
    }

    /**
     * Spend the nonce. A second redeem is a replay and dies here — the
     * runner migrates at most once per minted token, always.
     */
    public function redeem(string $nonce): void
    {
        $cache = $this->requireCache();
        $key = self::KEY_PREFIX . $nonce;
        $raw = $cache->get($key);

        if (!\is_string($raw) || $raw === '') {
            throw new SetupException('Wizard token nonce is unknown, expired, or already spent (single-use enforced).');
        }

        $cache->delete($key); // consume BEFORE parse: a corrupt record is still spent

        $record = \json_decode($raw, true);

        if (!\is_array($record) || !isset($record['exp'])) {
            throw new SetupException('Wizard token nonce record is corrupt — refusing to run setup.');
        }

        if (\time() > (int) $record['exp']) {
            throw new SetupException('Wizard token nonce expired.');
        }
    }

    private function hmac(string $payloadSegment): string
    {
        return \hash_hmac('sha256', $payloadSegment, $this->secret, true);
    }

    private function requireCache(): CacheInterface
    {
        if ($this->cache === null) {
            throw new SetupException('Wizard tokens require a cache for single-use nonces (Q6 rails); inject Cache::getAdapter().');
        }

        // An unconfigured Cache hands out NullAdapter — its set() is a silent
        // no-op, so a "minted" token could NEVER be redeemed. Refusing here
        // tells the operator the truth at mint time, not at the browser.
        if ($this->cache instanceof NullAdapter) {
            throw new SetupException('Wizard tokens cannot be minted against an unconfigured cache (NullAdapter cannot enforce single-use) — configure the cache first.');
        }

        return $this->cache;
    }
}
