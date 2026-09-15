<?php

/**
 * razymod/oauth — GET /<alias>/callback (OAuth 2.0 provider return leg).
 *
 * Completes the flow on core guarantees (S2): state verified BEFORE any
 * network, provider error params mapped after verification, nonce redeemed
 * single-use, redirect_uri exact-matched. The identity payload is then
 * handed to the app via `social.user_resolved` (signed Q1 — THIS module
 * creates no session and owns no users table; the app's own listener wires
 * identity persistence, typically SessionGuard at manual/07 §6).
 *
 * Post-login landing is a 302 to `post_login_redirect` (config); without
 * one, a plain success page answers — the identity is already in the event.
 */

use Razy\Controller;
use Razy\Exception\OAuthException;
use Razy\Security\OAuth\OAuth2;
use Razy\Security\OAuth\Provider\GoogleProvider;
use Razy\Security\OAuth\Provider\MicrosoftProvider;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — provider slug read immediately, string-cast, then matched against the registry (allow-list by construction).
    $name = isset($_GET['provider']) && \is_string($_GET['provider']) ? $_GET['provider'] : '';

    // the query superglobal is handed WHOLE to exchange(), which knows the
    // provider's param names and verifies state before trusting any of them.
    /** @var array{registry: Razy\Security\OAuth\ProviderRegistry, flow: OAuth2, configFor: callable, postLoginRedirect: string} $bundle */
    $bundle = (require __DIR__ . '/support/flow.php')(
        $this->getModuleConfig()->array(),
        Razy\Cache::getAdapter(),
    );

    try {
        if (!$bundle['registry']->has($name)) {
            throw new OAuthException('Provider "' . $name . '" is not enabled for this distributor.');
        }

        $config = ($bundle['configFor'])($name);

        if ($config === null || $config->clientId === '') {
            throw new OAuthException('Provider "' . $name . '" is misconfigured (client id env missing).');
        }

        $provider = $bundle['registry']->get($name);
        // lint-allow: RZ-003 — whole query to the core door; exchange() verifies state BEFORE trusting any parameter (S2 ordering).
        $tokens = $bundle['flow']->exchange($provider, $config, $_GET);
        $user = $provider->fetchUser(new Razy\Http\HttpClient(), $tokens);

        // id_token, when the provider sent one, gets the STRUCTURE check
        // (aud/exp/iss, plus the Workspace hd CLAIM when configured) — and
        // the honest label: claims are checked, the signature is NOT
        // verified (G11).
        $idClaims = null;

        if ($tokens->idToken !== null && $tokens->idToken !== '') {
            $issuerPattern = match ($name) {
                'google' => GoogleProvider::issuerPattern(),
                'microsoft' => MicrosoftProvider::issuerPattern(),
                default => null,
            };

            if ($issuerPattern !== null) {
                $requiredHostedDomain = '';

                if ($name === 'google') {
                    $pcfg = $this->getModuleConfig()->array()['providers']['google'] ?? [];
                    $requiredHostedDomain = \is_array($pcfg) ? (string) ($pcfg['hosted_domain'] ?? '') : '';
                }

                $idClaims = OAuth2::verifyIdTokenClaims(
                    $tokens->idToken,
                    $config->clientId,
                    $issuerPattern,
                    null,
                    $requiredHostedDomain !== '' ? $requiredHostedDomain : null,
                );
            }
        }

        $payload = [
            'provider' => $name,
            'user' => $user,
            'tokens' => [
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at' => $tokens->expiresAt,
            ],
            'id_token_claims' => $idClaims,
            // the Q1 line: whatever persists identity acts on THIS payload,
            // in the app — never inside framework or this module
        ];
    } catch (OAuthException $e) {
        $this->xhr()->responseCode(401)->responseAsBody(['ok' => false, 'error' => $e->getMessage()]);

        return;
    }

    $emitter = $this->trigger('social.user_resolved');
    $emitter->resolve($payload);

    $target = (string) $bundle['postLoginRedirect'];

    if ($target !== '') {
        \header('Location: ' . $target, true, 302);

        throw new Razy\Exception\RedirectException($target, 302);
    }

    $this->xhr()->responseAsBody([
        'ok' => true,
        'provider' => $name,
        'identity' => $user['id'],
        'name' => $user['name'],
    ]);
};
