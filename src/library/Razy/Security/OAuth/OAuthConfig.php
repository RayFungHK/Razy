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
 * Per-conversation client parameters (dossier Q5: the caller assembles this
 * from module config + env — this class never reads env itself, keeping the
 * core honestly injectable and testable).
 */
final class OAuthConfig
{
    /**
     * @param string $clientId public client id
     * @param string $clientSecret secret; keep it in env, put a REFERENCE in config (Q5)
     * @param string $redirectUri the ONE registered URI — exact-match, never derived from the request (§7 step 5)
     * @param string $scopes provider-formatted scope string (space or comma separated, per provider)
     * @param bool $basicAuth true = RFC 6749 §2.3.1 Basic client auth; false = body params (GitHub/FB default)
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret = '',
        public readonly string $redirectUri = '',
        public readonly string $scopes = '',
        public readonly bool $basicAuth = false,
    ) {
    }
}
