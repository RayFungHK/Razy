<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\ConfigurationException;
use Razy\Util\PathUtil;
use Razy\Util\StringUtil;
use Throwable;

/**
 * Class Domain.
 *
 * Represents a domain configuration in the Razy routing system. Each Domain maps
 * a hostname (or alias) to one or more Distributor instances based on URL path
 * prefixes. It handles URL query matching to find the appropriate distributor
 * and delegates autoloading and lifecycle events to the matched distributor.
 *
 * @class Domain
 *
 * @package Razy
 */
class Domain
{
    /** @var Distributor|null The matched distributor instance for this request */
    private ?Distributor $distributor = null;

    /**
     * Domain constructor.
     *
     * @param Application $app The Application Instance
     * @param string $domain The string of the domain
     * @param string $alias The string of the alias
     * @param array $mapping An array of the distributor paths or the string of the distributor path
     *
     * @throws ConfigurationException
     */
    public function __construct(private readonly Application $app, private readonly string $domain, private readonly string $alias = '', private array $mapping = [])
    {
        if (empty($this->mapping)) {
            throw new ConfigurationException('No distributor is found.');
        }
        // Pre-sort mappings by path depth (deepest first) once at construction,
        // avoiding redundant sortPathLevel() on every matchQuery() call
        StringUtil::sortPathLevel($this->mapping);
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
     * Get the Application instance that owns this Domain.
     *
     * @return Application
     */
    public function getApplication(): Application
    {
        return $this->app;
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
     * @return Distributor|null
     *
     * @throws Throwable
     */
    public function matchQuery(string $urlQuery): ?Distributor
    {
        if (0 === \strlen($urlQuery)) {
            $urlQuery = '/';
        }
        $urlQuery = PathUtil::tidy($urlQuery, false, '/');
        if (!empty($this->mapping)) {
            // Mapping is already pre-sorted by path depth in the constructor
            foreach ($this->mapping as $urlPath => $distIdentifier) {
                // Parse distributor identifier into code and tag (e.g., "mysite@dev" -> code="mysite", tag="dev")
                [$distCode, $tag] = \explode('@', $distIdentifier . '@', 2);
                $urlPath = PathUtil::tidy($urlPath, true, '/');
                if (\str_starts_with($urlQuery, $urlPath)) {
                    ($this->distributor = new Distributor($distCode, $tag ?? '*', $this, $urlPath, \substr($urlQuery, \strlen($urlPath) - 1)))->initialize();
                    return $this->distributor;
                }
            }
        }

        return null;
    }

    /**
     * Call the distributor's autoloader.
     *
     * @param string $className
     *
     * @return bool
     */
    public function autoload(string $className): bool
    {
        return $this->distributor && $this->distributor->autoload($className);
    }

    /**
     * Get the matched distributor.
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
            $this->distributor->getRegistry()->dispose();
        }
        return $this;
    }
}
