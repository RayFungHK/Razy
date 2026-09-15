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

use Razy\Exception\OAuthException;
use Razy\Http\HttpTransportException;

/**
 * The OAuth 2.0 authorization-code core (OAuth dossier S2).
 *
 * Flow ownership, honestly split: the MODULE owns routes and config, this
 * class owns protocol correctness —
 *   begin():     PKCE S256 always-on + signed single-use state (Q2 option C)
 *   exchange():  RFC 6749 §5.2 error mapping BEFORE any network call, nonce
 *                redemption (single-use), exact redirect_uri, form-encoded
 *                token POST with body or Basic client auth (RFC 6749 §2.3.1),
 *                Content-Type-aware token parsing (GitHub answers urlencoded)
 *   refresh():   RFC 8707 — refresh grants carry NO code_verifier
 *
 * The old `Razy\OAuth2` name survives with its internals replaced (Q3); this
 * is the replacement itself. No cURL, no globals, no env: inject a
 * `ClientInterface` + `StateSigner`, which makes the whole flow testable —
 * and every test in this repo runs without a network.
 */
final class OAuth2
{
    public function __construct(
        private readonly \Razy\Http\ClientInterface $client,
        private readonly StateSigner $signer,
    ) {
    }

    /**
     * Decode-and-bind id_token claims (dossier §7 step 9, G11).
     *
     * HONEST LABEL, law of this method: it VERIFIES STRUCTURE (audience
     * exact-match, absolute expiry, issuer regex, optional nonce/hd binds)
     * and it does NOT verify the signature — full JWK fetch/rotate/verify is
     * explicitly on the dossier's Do-NOT-build list. Callers may present
     * results as "claims checked"; they must NEVER say "signature verified".
     *
     * @return array<string,mixed> the claims
     */
    public static function verifyIdTokenClaims(string $idToken, string $clientId, string $issuerPattern, ?string $expectedNonce = null, ?string $expectedHostedDomain = null): array
    {
        $parts = \explode('.', $idToken);

        if (\count($parts) !== 3) {
            throw new OAuthException('Invalid id_token format (expected three segments).');
        }

        $claims = \json_decode(StateSigner::b64urlDecode($parts[1]), true);

        if (!\is_array($claims)) {
            throw new OAuthException('id_token payload is not JSON.');
        }

        $aud = $claims['aud'] ?? null;
        $audOk = \is_string($aud) ? \hash_equals($aud, $clientId) : (\is_array($aud) && \in_array($clientId, $aud, true));

        if (!$audOk) {
            throw new OAuthException('id_token audience does not include this client.');
        }

        if (!isset($claims['exp']) || !\is_numeric($claims['exp']) || \time() >= (int) $claims['exp']) {
            throw new OAuthException('id_token is expired or carries no exp.');
        }

        $iss = $claims['iss'] ?? null;

        if (!\is_string($iss) || \preg_match($issuerPattern, $iss) !== 1) {
            throw new OAuthException('id_token issuer is not recognized.');
        }

        if ($expectedNonce !== null && (!isset($claims['nonce']) || !\is_string($claims['nonce']) || !\hash_equals($expectedNonce, $claims['nonce']))) {
            throw new OAuthException('id_token nonce does not match the login attempt.');
        }

        if ($expectedHostedDomain !== null && (!isset($claims['hd']) || !\is_string($claims['hd']) || !\hash_equals($expectedHostedDomain, $claims['hd']))) {
            throw new OAuthException('id_token hosted domain (hd) does not match the required Workspace.');
        }

        return $claims;
    }

    /**
     * Build the authorize redirect (the caller answers 302 — never
     * Controller::goto, that one is 301; dossier §7).
     *
     * @return array{url: string, state: string}
     */
    public function begin(ProviderInterface $provider, OAuthConfig $config): array
    {
        if ($config->redirectUri === '') {
            throw new OAuthException('A registered redirect_uri is required (exact-match discipline, §7 step 5).');
        }

        ['state' => $state, 'verifier' => $verifier] = $this->signer->issue($provider->name(), $config->redirectUri);

        $params = [
            'client_id' => $config->clientId,
            'redirect_uri' => $config->redirectUri,
            'response_type' => 'code',
            'scope' => $config->scopes,
            'state' => $state,
            'code_challenge' => StateSigner::codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ];

        $params = \array_filter($provider->authorizeParams($params), static fn ($v): bool => $v !== '');

        return [
            'url' => $provider->authorizeUrl() . (\str_contains($provider->authorizeUrl(), '?') ? '&' : '?') . \http_build_query($params, '', '&'),
            'state' => $state,
        ];
    }

    /**
     * Complete the flow: verify the callback, redeem the nonce, exchange the
     * code. Order is security-relevant (§7): state verifies BEFORE anything
     * else, and nothing reaches the network until the nonce redeems.
     *
     * @param array<string,mixed> $query the callback query (provider error params included)
     */
    public function exchange(ProviderInterface $provider, OAuthConfig $config, array $query): TokenResponse
    {
        $state = $query['state'] ?? null;

        if (!\is_string($state) || $state === '') {
            throw new OAuthException('Authorization callback carries no state — refusing (state is mandatory; Q2 default).');
        }

        ['nonce' => $nonce] = $this->signer->verify($state, $provider->name(), $config->redirectUri);

        if (isset($query['error'])) {
            // RFC 6749 §5.2, mapped AFTER state verification (an unverified
            // error page is an attacker's page, not the provider's).
            $error = \is_string($query['error']) && $query['error'] !== '' ? $query['error'] : 'unspecified';
            $description = isset($query['error_description']) && \is_string($query['error_description']) ? ': ' . $query['error_description'] : '';

            throw new OAuthException('Provider returned error "' . $error . '"' . $description);
        }

        $code = $query['code'] ?? null;

        if (!\is_string($code) || $code === '') {
            throw new OAuthException('Authorization callback carries no code.');
        }

        $verifier = $this->signer->redeem($nonce); // single-use consumed at the last gate before the wire

        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config->redirectUri,
            'code_verifier' => $verifier,
        ];

        return $this->tokenRequest($provider->tokenUrl(), $params, $config);
    }

    /**
     * RFC 8707 refresh: same door, no PKCE.
     */
    public function refresh(ProviderInterface $provider, OAuthConfig $config, string $refreshToken): TokenResponse
    {
        if ($refreshToken === '') {
            throw new OAuthException('Cannot refresh without a refresh token.');
        }

        return $this->tokenRequest($provider->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], $config);
    }

    /**
     * @param array<string,string> $params form fields (secrets stay out of logs — this method never logs)
     */
    private function tokenRequest(string $url, array $params, OAuthConfig $config): TokenResponse
    {
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];

        if ($config->basicAuth) {
            // RFC 6749 §2.3.1 — secret in the Basic header, never in the body
            $headers['Authorization'] = 'Basic ' . \base64_encode($config->clientId . ':' . $config->clientSecret);
        } else {
            $params['client_id'] = $config->clientId;

            if ($config->clientSecret !== '') {
                $params['client_secret'] = $config->clientSecret;
            }
        }

        try {
            $response = $this->client->send('POST', $url, [
                'headers' => $headers,
                'raw_body' => \http_build_query($params, '', '&'),
            ]);
        } catch (HttpTransportException $e) {
            throw new OAuthException('Token endpoint unreachable: ' . $e->getMessage(), 0, $e);
        }

        $data = $response->data(); // Content-Type aware: json OR urlencoded (GitHub's default) — §5 G8

        if ($response->status() >= 400) {
            $error = \is_array($data) && isset($data['error']) && \is_string($data['error'])
                ? $data['error']
                : 'http_' . $response->status();
            $description = \is_array($data) && isset($data['error_description']) && \is_string($data['error_description'])
                ? ': ' . $data['error_description']
                : '';

            throw new OAuthException('Token endpoint error "' . $error . '"' . $description, $response->status());
        }

        if (!\is_array($data) || !isset($data['access_token']) || !\is_string($data['access_token']) || $data['access_token'] === '') {
            throw new OAuthException('Token response carries no access_token.');
        }

        return TokenResponse::fromArray($data);
    }
}
