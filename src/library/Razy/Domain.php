<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */


namespace Razy;

use Throwable;

class Domain
{
    private ?Distributor $distributor = null;

    /**
     * Domain constructor.
     *
     * @param Application $app The Application Instance
     * @param string $domain The string of the domain
     * @param string $alias The string of the alias
     * @param array $mapping An array of the distributor paths or the string of the distributor path
     * @throws Error
     */
    public function __construct(private readonly Application $app, private readonly string $domain, private readonly string $alias = '', private array $mapping = [])
    {
        if (empty($this->mapping)) {
            throw new Error('No distributor is found.');
        }
    }

    /**
     * Get the domain alias.
     *
     * @return string
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Get the string of the domain name.
     *
     * @return string
     */
    public function getDomainName(): string
    {
        return $this->domain;
    }

    /**
     * Match the distributor location by the URL_QUERY.
     *
     * @param string $urlQuery The URL Query
     *
     * @return null|Distributor
     * @throws Throwable
     *
     */
    public function matchQuery(string $urlQuery): ?Distributor
    {
        if (0 === strlen($urlQuery)) {
            $urlQuery = '/';
        }

        $urlQuery = tidy($urlQuery, false, '/');
        if (!empty($this->mapping)) {
            sort_path_level($this->mapping);
            foreach ($this->mapping as $urlPath => $distIdentifier) {
                [$distCode, $tag] = explode('@', $distIdentifier . '@', 2);
                $urlPath = tidy($urlPath, true, '/');
                if (str_starts_with($urlQuery, $urlPath)) {
                    ($this->distributor = new Distributor($distCode, $tag ?? '*', $this, $urlPath, substr($urlQuery, strlen($urlPath) - 1)))->initialize();

                    return $this->distributor;
                }
            }
        }

        return null;
    }

    /**
     * Call the distributor's autoloader
     *
     * @param string $className
     * @return bool
     */
    public function autoload(string $className): bool
    {
        return $this->distributor && $this->distributor->autoload($className);
    }


    /**
     * Get the matched distributor
     *
     * @return Distributor|null
     */
    public function getDistributor(): ?Distributor
    {
        return $this->distributor;
    }

    /**
     * Execute dispose event.
     *
     * @return $this
     */
    public function dispose(): static
    {
        if ($this->distributor) {
            $this->distributor->dispose();
        }
        return $this;
    }
}