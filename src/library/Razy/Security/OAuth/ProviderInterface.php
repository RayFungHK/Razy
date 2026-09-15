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

use Razy\Http\ClientInterface;

/**
 * The provider contract (OAuth dossier S2/S3, Socialite's four-method shape).
 *
 * Deliberately protocol facts only — endpoints, quirks, user mapping. Per-dist
 * client credentials are NOT here (Q5/RZ-006: they belong to module config +
 * env); the same provider object serves every distributor.
 *
 * (Five methods rather than Socialite's four: `authorizeParams` exists
 * because Google/FB quirks attach to the AUTHORIZE request, and core builds
 * that request uniformly.)
 */
interface ProviderInterface
{
    /**
     * Provider slug ('github', 'google', …); also the state-binding key.
     */
    public function name(): string;

    /**
     * Authorize endpoint (HTTPS; the shared HTTP gate enforces it at send).
     */
    public function authorizeUrl(): string;

    /**
     * Token endpoint.
     */
    public function tokenUrl(): string;

    /**
     * Opportunity to append provider quirks (access_type, prompt, hd …).
     *
     * @param array<string,string> $defaults the standard OAuth params core built
     *
     * @return array<string,string> params to actually send (defaults first)
     */
    public function authorizeParams(array $defaults): array;

    /**
     * Fetch + normalize the resource owner.
     *
     * Implementations MUST use the injected client (one door, RZ-015-clean)
     * and return at least `id`, plus `name`/`email`/`avatar` when the
     * provider knows them, and the verbatim payload under `raw`. Identity
     * claims: trust `sub`-style ids, never emails (dossier §7 — the Google
     * mapping pins this).
     *
     * @return array{id:string, name:?string, email:?string, avatar:?string, raw:array<string,mixed>}
     */
    public function fetchUser(ClientInterface $client, TokenResponse $token): array;
}
