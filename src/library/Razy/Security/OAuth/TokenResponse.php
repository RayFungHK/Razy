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

/**
 * One token-endpoint answer, parsed once, honest about time.
 *
 * `expiresAt` is an absolute timestamp (0 = the provider said nothing):
 * relative `expires_in` seconds are the classic footgun — a token fetched
 * two minutes before use would look fresher than it is.
 */
final class TokenResponse
{
    /**
     * @param array<string,mixed> $raw the verbatim endpoint answer
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $tokenType,
        public readonly ?int $expiresIn,
        public readonly int $expiresAt,
        public readonly ?string $refreshToken,
        public readonly ?string $scope,
        public readonly ?string $idToken,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiresIn = (isset($data['expires_in']) && \is_numeric($data['expires_in']))
            ? (int) $data['expires_in']
            : null;

        $stringOrNull = static fn (mixed $v): ?string => \is_string($v) && $v !== '' ? $v : null;

        return new self(
            (string) $data['access_token'],
            $stringOrNull($data['token_type'] ?? null),
            $expiresIn,
            $expiresIn !== null ? \time() + $expiresIn : 0,
            $stringOrNull($data['refresh_token'] ?? null),
            $stringOrNull($data['scope'] ?? null),
            $stringOrNull($data['id_token'] ?? null),
            $data,
        );
    }

    /**
     * Whether the access token is past its absolute expiry (a provider that
     * sent no expiry never expires 'early' — expiry is simply unknown).
     */
    public function hasExpired(): bool
    {
        return $this->expiresAt > 0 && \time() >= $this->expiresAt;
    }
}
