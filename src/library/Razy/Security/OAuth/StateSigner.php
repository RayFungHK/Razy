<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Security\OAuth;

use Razy\Cache\CacheInterface;
use Razy\Exception\OAuthException;

/**
 * Signed, single-use OAuth `state` + PKCE verifier custody (dossier Q2, option C).
 *
 * The framework's session driver has no cookie (documented trap, §7), so the
 * CSRF/redirect-binding state CANNOT live in a session by default. Instead:
 *
 *   state = b64url(payload) . '.' . b64url(HMAC-SHA256(payload, secret))
 *   payload = {n: nonce, p: provider, r: sha256(redirect_uri)[0..16], t: unix ts}
 *
 * — the `state` the browser round-trips is SELF-PROTECTING (RFC 9700 §4.7.1
 * sanctions signing state values): tamper or provider/redirect swaps fail the
 * constant-time HMAC before a single byte reaches the network. The nonce then
 * resolves a server-side cache record holding the PKCE code_verifier — so the
 * verifier NEVER travels through the browser (only its S256 challenge does,
 * per RFC 7636), and redeeming deletes the record: a replayed callback dies
 * on single-use, ten minutes after issue the whole thing is void anyway.
 *
 * Precedents this deliberately mirrors: `BridgeSignature` (env-held secret,
 * hash_equals, HMAC) and the queue-admin double-submit lesson (modules cannot
 * assume cookie plumbing). The cache is mandatory BY DESIGN, not inertia:
 * without it there is no single-use and no verifier custody, and a silently
 * weaker state scheme is the exact drift this dossier exists to stop.
 */
final class StateSigner
{
    /** consent round-trips die after 10 minutes */
    public const DEFAULT_TTL = 600;

    private const KEY_PREFIX = 'razymod/oauth/state-nonce/';

    public function __construct(
        private readonly string $secret,
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = self::DEFAULT_TTL,
    ) {
        if ($secret === '') {
            throw new OAuthException('StateSigner requires a non-empty signing secret (dossier Q5: hold it in env, e.g. RAZY_OAUTH_STATE_SECRET).');
        }

        if ($this->ttl < 1) {
            throw new OAuthException('StateSigner TTL must be at least 1 second.');
        }
    }

    /**
     * RFC 7636 §4.1 code_verifier: 32 random bytes, base64url → 43 chars,
     * all in the unreserved set. S256 is the ONLY method this codebase offers
     * (`plain` is never generated and never accepted).
     */
    public static function generateVerifier(): string
    {
        return self::b64url(\random_bytes(32));
    }

    /**
     * RFC 7636 §4.2 S256 challenge.
     */
    public static function codeChallenge(string $verifier): string
    {
        return self::b64url(\hash('sha256', $verifier, true));
    }

    public static function b64url(string $bytes): string
    {
        return \rtrim(\strtr(\base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $encoded): string
    {
        $decoded = \base64_decode(\strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new OAuthException('State contains invalid base64url.');
        }

        return $decoded;
    }

    private static function redirectHash(string $redirectUri): string
    {
        return \substr(\hash('sha256', $redirectUri), 0, 16);
    }

    /**
     * Start one login attempt: mint nonce + PKCE verifier, custody the
     * verifier server-side, return the signed state to send.
     *
     * @return array{state: string, verifier: string}
     */
    public function issue(string $provider, string $redirectUri): array
    {
        $nonce = \bin2hex(\random_bytes(16));
        $verifier = self::generateVerifier();

        $record = [
            'verifier' => $verifier,
            'p' => $provider,
            'r' => self::redirectHash($redirectUri),
            'exp' => \time() + $this->ttl,
        ];

        $this->requireCache()->set(self::KEY_PREFIX . $nonce, \json_encode($record, JSON_THROW_ON_ERROR), $this->ttl);

        $payload = \json_encode([
            'n' => $nonce,
            'p' => $provider,
            'r' => $record['r'],
            't' => \time(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $seg = self::b64url($payload);

        return ['state' => $seg . '.' . self::b64url($this->hmac($seg)), 'verifier' => $verifier];
    }

    /**
     * Verify a returned state's authenticity, freshness, provider and
     * redirect binding. Network-free and side-effect-free — call this BEFORE
     * touching the token endpoint (dossier §7 step 4).
     *
     * @return array{nonce: string}
     */
    public function verify(string $state, string $provider, string $redirectUri): array
    {
        $parts = \explode('.', $state);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new OAuthException('Malformed state value (expected payload.signature).');
        }

        [$seg, $sigSeg] = $parts;

        $given = self::b64urlDecode($sigSeg);

        if (!\hash_equals($this->hmac($seg), $given)) {
            throw new OAuthException('State signature mismatch (tampered or issued with a different secret).');
        }

        $payload = \json_decode(self::b64urlDecode($seg), true);

        if (!\is_array($payload) || !isset($payload['n'], $payload['p'], $payload['r'], $payload['t']) || !\is_string($payload['n'])) {
            throw new OAuthException('State payload is not a valid signed payload.');
        }

        if (\abs(\time() - (int) $payload['t']) > $this->ttl) {
            throw new OAuthException('State is older than the allowed window — restart the login.');
        }

        if (!\hash_equals($payload['p'], $provider)) {
            throw new OAuthException('State was issued for a different provider.');
        }

        if (!\hash_equals($payload['r'], self::redirectHash($redirectUri))) {
            throw new OAuthException('State is bound to a different redirect_uri.');
        }

        return ['nonce' => $payload['n']];
    }

    /**
     * Consume the nonce: returns the PKCE verifier ONCE, then it is gone.
     * A second redeem is a replay and dies here.
     */
    public function redeem(string $nonce): string
    {
        $cache = $this->requireCache();
        $key = self::KEY_PREFIX . $nonce;
        $raw = $cache->get($key);

        if (!\is_string($raw) || $raw === '') {
            throw new OAuthException('State nonce is unknown, expired, or already used (single-use enforced).');
        }

        $cache->delete($key); // consume BEFORE parse: a corrupt record is still spent

        $record = \json_decode($raw, true);

        if (!\is_array($record) || !isset($record['verifier']) || !\is_string($record['verifier'])) {
            throw new OAuthException('State nonce record is corrupt — refusing to issue a verifier.');
        }

        if (isset($record['exp']) && \time() > (int) $record['exp']) {
            throw new OAuthException('State nonce expired.');
        }

        return $record['verifier'];
    }

    private function hmac(string $payloadSegment): string
    {
        return \hash_hmac('sha256', $payloadSegment, $this->secret, true);
    }

    private function requireCache(): CacheInterface
    {
        if ($this->cache === null) {
            throw new OAuthException('State custody requires a cache for single-use nonces and PKCE verifiers (dossier Q2); inject Cache::getAdapter().');
        }

        return $this->cache;
    }
}
