<?php

/**
 * razymod/oauth — flow factory (own-module support, RZ-001-clean inclusion).
 *
 * The single door where config meets the framework core (S2/S3). Assembly
 * rules are the signed decisions:
 *
 * - Q5: config files carry ENV VAR NAMES (`client_id_env` /
 *   `client_secret_env`), never values; the state-signing secret lives in
 *   RAZY_OAUTH_STATE_SECRET. This file is the ONLY place the module reads
 *   env — handlers stay pure config pass-through.
 * - Q2: StateSigner is the only state scheme, the Cache is mandatory —
 *   Cache::getAdapter() supplies it; single-use nonce custody has no
 *   silent fallback (a cacheless login flow fails loud at first use).
 * - Unknown provider names in config are a TYPO, and typos die loud.
 *
 * @return array{registry: ProviderRegistry, flow: OAuth2, configFor: callable(string): ?OAuthConfig, postLoginRedirect: string}
 */

use Razy\Exception\OAuthException;
use Razy\Http\HttpClient;
use Razy\Security\OAuth\OAuth2;
use Razy\Security\OAuth\OAuthConfig;
use Razy\Security\OAuth\Provider\GithubProvider;
use Razy\Security\OAuth\Provider\GoogleProvider;
use Razy\Security\OAuth\Provider\MicrosoftProvider;
use Razy\Security\OAuth\ProviderRegistry;
use Razy\Security\OAuth\StateSigner;

return function (array $config, ?Razy\Cache\CacheInterface $cache): array {
    /** @var array<string,mixed> $providers config truth arrives untyped; per-entry is_array filters garbage */
    $providers = \is_array($config['providers'] ?? null) ? $config['providers'] : [];

    $readEnv = static function (string $name): string {
        // env() is the bootstrap helper; getenv is the honest fallback for
        // any context that reached this file without it.
        $value = \function_exists('env') ? \env($name, null) : \getenv($name);

        return \is_string($value) ? \trim($value) : '';
    };

    $registry = new ProviderRegistry();
    $enabled = [];

    foreach ($providers as $name => $pcfg) {
        if (!\is_array($pcfg) || ($pcfg['enabled'] ?? false) !== true) {
            continue;
        }

        $enabled[(string) $name] = $pcfg;

        $registry->register(match ((string) $name) {
            'github' => new GithubProvider((string) ($pcfg['scopes'] ?? 'read:user user:email')),
            'google' => new GoogleProvider(
                (string) ($pcfg['scopes'] ?? 'openid profile email'),
                (string) ($pcfg['hosted_domain'] ?? '') !== '' ? (string) $pcfg['hosted_domain'] : null,
                (bool) ($pcfg['prompt_consent'] ?? false),
            ),
            'microsoft' => new MicrosoftProvider(
                (string) ($pcfg['tenant'] ?? 'common'),
                (string) ($pcfg['scopes'] ?? 'User.Read openid profile email'),
                (string) ($pcfg['prompt'] ?? ''),
            ),
            default => throw new OAuthException('Unknown OAuth provider in config: "' . $name . '" (supported: github, google, microsoft).'),
        });
    }

    // The signing secret has NO default — Q5 says env, and an empty secret
    // is StateSigner's own fail-loud territory.
    $signer = new StateSigner($readEnv('RAZY_OAUTH_STATE_SECRET'), $cache);
    $flow = new OAuth2(new HttpClient(), $signer);

    $configFor = static function (?string $name) use ($enabled, $readEnv): ?OAuthConfig {
        if ($name === null || !isset($enabled[$name])) {
            return null;
        }

        $pcfg = $enabled[$name];

        return new OAuthConfig(
            $readEnv((string) ($pcfg['client_id_env'] ?? '')),
            $readEnv((string) ($pcfg['client_secret_env'] ?? '')),
            (string) ($pcfg['redirect_uri'] ?? ''),
            (string) ($pcfg['scopes'] ?? ''),
            (bool) ($pcfg['basic_auth'] ?? false),
        );
    };

    return [
        'registry' => $registry,
        'flow' => $flow,
        'configFor' => $configFor,
        'postLoginRedirect' => (string) ($config['post_login_redirect'] ?? ''),
    ];
};
