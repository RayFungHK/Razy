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

use Razy\Exception\OAuthException;

/**
 * Named provider lookup (dossier S2: "provider contract + registry").
 *
 * Deliberately NOT a static bag (RZ-008): the module builds one per dist
 * from its own config and hands it around.
 */
final class ProviderRegistry
{
    /**
     * @var array<string, ProviderInterface>
     */
    private array $providers = [];

    public function register(ProviderInterface $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): ProviderInterface
    {
        return $this->providers[$name]
            ?? throw new OAuthException('Unknown OAuth provider "' . $name . '".');
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return \array_keys($this->providers);
    }
}
