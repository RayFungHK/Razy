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
 * GitHub OAuth (dossier S3).
 *
 * Quirks honoured: S256-only support, `Accept: application/json` on the token
 * call (GitHub's DEFAULT answer is urlencoded — the core parses both, §5 G8),
 * and identity from `id` — never the email, which is just contact data.
 */
final class GithubProvider implements ProviderInterface
{
    public function __construct(private readonly string $scopes = 'read:user user:email')
    {
    }

    public function name(): string
    {
        return 'github';
    }

    public function authorizeUrl(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    public function tokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    public function authorizeParams(array $defaults): array
    {
        return $defaults;
    }

    public function fetchUser(ClientInterface $client, TokenResponse $token): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $token->accessToken,
            'Accept' => 'application/json',
            'User-Agent' => 'Razy-OAuth', // GitHub's API requires a UA on user calls
        ];

        try {
            $response = $client->send('GET', 'https://api.github.com/user', ['headers' => $headers]);
        } catch (HttpTransportException $e) {
            throw new OAuthException('GitHub user request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = $response->data();

        if ($response->status() !== 200 || !\is_array($data) || !isset($data['id'])) {
            throw new OAuthException('GitHub user request returned no profile (HTTP ' . $response->status() . ').');
        }

        $email = \is_string($data['email'] ?? null) && $data['email'] !== '' ? $data['email'] : null;

        if ($email === null) {
            $email = $this->fetchPrimaryEmail($client, $headers);
        }

        return [
            'id' => (string) $data['id'],
            'name' => \is_string($data['name'] ?? null) ? $data['name'] : (\is_string($data['login'] ?? null) ? $data['login'] : null),
            'email' => $email,
            'avatar' => \is_string($data['avatar_url'] ?? null) ? $data['avatar_url'] : null,
            'raw' => $data,
        ];
    }

    /**
     * The `user:email` scope path: verified-first, primary preferred. A
     * failure here is not fatal — email is contact data, never identity.
     */
    private function fetchPrimaryEmail(ClientInterface $client, array $headers): ?string
    {
        try {
            $response = $client->send('GET', 'https://api.github.com/user/emails', ['headers' => $headers]);
        } catch (HttpTransportException) {
            return null;
        }

        $emails = $response->data();

        if ($response->status() !== 200 || !\is_array($emails)) {
            return null;
        }

        $first = null;

        foreach ($emails as $entry) {
            if (!\is_array($entry) || !\is_string($entry['email'] ?? null)) {
                continue;
            }

            if (($entry['verified'] ?? false) === true && ($entry['primary'] ?? false) === true) {
                return $entry['email'];
            }

            $first ??= $entry['email'];
        }

        return $first;
    }
}
