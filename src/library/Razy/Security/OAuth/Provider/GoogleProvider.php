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

namespace Razy\Security\OAuth\Provider;

use Razy\Exception\OAuthException;
use Razy\Http\ClientInterface;
use Razy\Http\HttpTransportException;
use Razy\Security\OAuth\ProviderInterface;
use Razy\Security\OAuth\TokenResponse;

/**
 * Google OAuth2 (dossier S3).
 *
 * The dossier's pin, honoured literally: identity is **`sub`, not email** —
 * emails are mutable and verifiable-by-ego, `sub` is the stable pairwise id
 * (`legacy_sub` is the documented escape for re-migrated clients). Google
 * answers token calls as JSON; `access_type=offline` requests a refresh
 * token; `hd` optionally restricts sign-in to a Workspace hosted domain
 * (callers that set it should also enforce the `hd` CLAIM via
 * `OAuth2::verifyIdTokenClaims` — the authorize parameter is a request, the
 * claim is the answer).
 */
final class GoogleProvider implements ProviderInterface
{
    public function __construct(
        private readonly string $scopes = 'openid profile email',
        private readonly ?string $hostedDomain = null,
        private readonly bool $promptForConsent = false,
    ) {
    }

    /**
     * The regex `OAuth2::verifyIdTokenClaims` should enforce for `iss`.
     */
    public static function issuerPattern(): string
    {
        return '#^https://accounts\.google\.com(/)?$|^https://oauth2\.googleapis\.com(/)?$#';
    }

    public function name(): string
    {
        return 'google';
    }

    public function authorizeUrl(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public function tokenUrl(): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    public function authorizeParams(array $defaults): array
    {
        $params = $defaults;
        $params['access_type'] = 'offline';

        if ($this->promptForConsent) {
            // Google only returns refresh_token with consent on the first
            // grant; this re-arms it without a new installation
            $params['prompt'] = 'consent';
        }

        if ($this->hostedDomain !== null && $this->hostedDomain !== '') {
            $params['hd'] = $this->hostedDomain;
        }

        return $params;
    }

    public function fetchUser(ClientInterface $client, TokenResponse $token): array
    {
        // The userinfo endpoint answers with the authoritative `sub`; the
        // id_token carries the same claim but stays decode-only-unverified
        // here (full JWK verification is on the dossier's Do-NOT-build list).
        try {
            $response = $client->send('GET', 'https://openidconnect.googleapis.com/v1/userinfo', [
                'headers' => ['Authorization' => 'Bearer ' . $token->accessToken],
            ]);
        } catch (HttpTransportException $e) {
            throw new OAuthException('Google userinfo request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = $response->data();

        if ($response->status() !== 200 || !\is_array($data) || !(isset($data['sub']) || isset($data['legacy_sub']))) {
            throw new OAuthException('Google userinfo request returned no identity (HTTP ' . $response->status() . ').');
        }

        $id = \is_string($data['sub'] ?? null) ? $data['sub'] : (string) $data['legacy_sub'];

        return [
            'id' => $id,
            'name' => \is_string($data['name'] ?? null) ? $data['name'] : null,
            // email is CONTACT data here; identity above is `sub` (§7)
            'email' => \is_string($data['email'] ?? null) ? $data['email'] : null,
            'avatar' => \is_string($data['picture'] ?? null) ? $data['picture'] : null,
            'raw' => $data,
        ];
    }
}
