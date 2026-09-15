<?php

/**
 * razymod/oauth — GET /<alias>/authorize?provider=<name>.
 *
 * Starts a login: PKCE verifier + signed single-use state are minted by the
 * core (S2), the browser is 302'd to the provider. 302 deliberately —
 * Controller::goto() is 301 (Controller.php:313-318) and a cached
 * authorization redirect would be a cross-account bug factory.
 */

use Razy\Controller;
use Razy\Exception\OAuthException;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — provider slug read immediately, string-cast, then matched against the registry (allow-list by construction).
    $name = isset($_GET['provider']) && \is_string($_GET['provider']) ? $_GET['provider'] : '';

    /** @var array{registry: Razy\Security\OAuth\ProviderRegistry, flow: Razy\Security\OAuth\OAuth2, configFor: callable, postLoginRedirect: string} $bundle */
    $bundle = (require __DIR__ . '/support/flow.php')(
        $this->getModuleConfig()->array(),
        Razy\Cache::getAdapter(),
    );

    try {
        if (!$bundle['registry']->has($name)) {
            throw new OAuthException('Provider "' . $name . '" is not enabled for this distributor.');
        }

        $config = ($bundle['configFor'])($name);

        if ($config === null || $config->clientId === '' || $config->redirectUri === '') {
            throw new OAuthException('Provider "' . $name . '" is misconfigured (client id / redirect_uri env missing).');
        }

        ['url' => $url] = $bundle['flow']->begin($bundle['registry']->get($name), $config);
    } catch (OAuthException $e) {
        // configuration truth, not attacker input — the message is safe and
        // useful to the operator (fail-loud law; no secrets are ever composed)
        $this->xhr()->responseCode(400)->responseAsBody(['ok' => false, 'error' => $e->getMessage()]);

        return;
    }

    \header('Location: ' . $url, true, 302);

    throw new Razy\Exception\RedirectException($url, 302);
};
