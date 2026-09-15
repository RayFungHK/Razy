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
 * Microsoft Entra ID (formerly Azure AD / "Office 365") OAuth2 — the S3
 * re-expression of what `Razy\Office365SSO` hand-rolled since v0.5 (Q3: the
 * old class survives at its name; THIS is the one to build on).
 *
 * Tenant-aware endpoints ('common' | 'organizations' | 'consumers' | GUID);
 * identity is the Graph `id` (the `oid` claim equivalently) — the
 * userPrincipalName looks like an email and IS NOT one for identity purposes
 * (it mutates on directory merges).
 */
final class MicrosoftProvider implements ProviderInterface
{
    private const GRAPH_FIELDS = 'id,displayName,givenName,surname,jobTitle,mail,userPrincipalName,officeLocation';

    public function __construct(
        private readonly string $tenantId = 'common',
        private readonly string $scopes = 'User.Read openid profile email',
        private readonly string $prompt = '',
        private readonly string $loginHint = '',
        private readonly string $domainHint = '',
    ) {
    }

    /**
     * The regex `OAuth2::verifyIdTokenClaims` should enforce for `iss`
     * (both historical issuer hosts, both with and without the v2.0 suffix).
     */
    public static function issuerPattern(): string
    {
        return '#^https://(?:login\.microsoftonline\.com|sts\.windows\.net)/[a-f0-9\-]/?(?:v2\.0)?$#i';
    }

    public function name(): string
    {
        return 'microsoft';
    }

    public function authorizeUrl(): string
    {
        return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/authorize';
    }

    public function tokenUrl(): string
    {
        return 'https://login.microsoftonline.com/' . $this->tenantId . '/oauth2/v2.0/token';
    }

    public function authorizeParams(array $defaults): array
    {
        foreach (['prompt' => $this->prompt, 'login_hint' => $this->loginHint, 'domain_hint' => $this->domainHint] as $key => $value) {
            if ($value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    public function signOutUrl(string $tenantId = '', string $idTokenHint = '', string $postLogoutRedirect = ''): string
    {
        $url = 'https://login.microsoftonline.com/' . ($tenantId !== '' ? $tenantId : $this->tenantId) . '/oauth2/v2.0/logout';

        $params = \array_filter(['id_token_hint' => $idTokenHint, 'post_logout_redirect_uri' => $postLogoutRedirect]);

        return $params === [] ? $url : $url . '?' . \http_build_query($params);
    }

    public function fetchUser(ClientInterface $client, TokenResponse $token): array
    {
        try {
            $response = $client->send('GET', 'https://graph.microsoft.com/v1.0/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token->accessToken,
                    'Accept' => 'application/json',
                ],
                'query' => ['$select' => self::GRAPH_FIELDS],
            ]);
        } catch (HttpTransportException $e) {
            throw new OAuthException('Microsoft Graph request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = $response->data();

        if ($response->status() !== 200 || !\is_array($data) || !isset($data['id'])) {
            throw new OAuthException('Microsoft Graph returned no profile (HTTP ' . $response->status() . ').');
        }

        return [
            'id' => (string) $data['id'],
            'name' => \is_string($data['displayName'] ?? null) ? $data['displayName'] : null,
            'email' => \is_string($data['mail'] ?? null) ? $data['mail']
                : (\is_string($data['userPrincipalName'] ?? null) ? $data['userPrincipalName'] : null),
            'avatar' => null, // photo is a separate Graph endpoint; not profile identity
            'raw' => $data,
        ];
    }
}
