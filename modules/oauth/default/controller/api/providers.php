<?php

/**
 * razymod/oauth — API command `providers` (published, read-only).
 *
 * Answers WHICH providers this distributor enabled and their login URLs —
 * never client ids, never secrets (Q5 discipline holds even for public
 * answers: an env var NAME is not a secret but belongs to config truth,
 * so it stays here too).
 */

use Razy\Controller;

return function (): array {
    /** @var Controller $this */
    /** @var array{registry: Razy\Security\OAuth\ProviderRegistry} $bundle */
    $bundle = (require __DIR__ . '/support/flow.php')(
        $this->getModuleConfig()->array(),
        Razy\Cache::getAdapter(),
    );

    $out = [];

    foreach ($bundle['registry']->names() as $name) {
        $out[] = [
            'provider' => $name,
            'login_url' => $this->getModuleURL() . '/authorize?provider=' . \rawurlencode($name),
        ];
    }

    return ['providers' => $out];
};
