<?php

/**
 * This file is part of Razy v0.4.
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
    /**
     * The domain's alias
     * @var string
     */
    private string $alias;
    /**
     * The Application entity
     * @var Application
     */
    private Application $app;
    /**
     * The matched Distributor entity
     * @var null|Distributor
     */
    private ?Distributor $distributor = null;
    /**
     * The FQDN format string
     * @var string
     */
    private string $domain;
    /**
     * The storage of the distributor's path
     * @var string[]
     */
    private array $path = [];

    /**
     * Domain constructor.
     *
     * @param Application $app    The Application Instance
     * @param string      $domain The string of the domain
     * @param string      $alias  The string of the alias
     * @param array       $paths  An array of the distributor paths or the string of the distributor path
     *
     * @throws Throwable
     */
    public function __construct(Application $app, string $domain, string $alias = '', array $paths = [])
    {
        $this->domain = $domain;
        $this->alias  = $alias;
        $this->app    = $app;

        if (empty($paths)) {
            throw new Error('The path of the distributor is not valid.');
        }

        if (is_array($paths)) {
            foreach ($paths as $urlQuery => $path) {
                $this->path[$urlQuery] = $path;
            }
        } else {
            throw new Error('The object of the path `' . gettype($paths) . '` is not supported.');
        }
    }

    /**
     * @param string $fqdn The well-formatted FQDN string
     *
     * @throws Throwable
     *
     * @return null|API
     */
    public function connect(string $fqdn): ?API
    {
        return $this->app->connect($fqdn);
    }

    /**
     * Get the API instance.
     *
     * @return null|API
     */
    public function getAPI(): ?API
    {
        return ($this->distributor) ? $this->distributor->createAPI() : null;
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
     * @return null|Application
     */
    public function getPeer(): ?Application
    {
        return $this->app->getPeer();
    }

    /**
     * Match the distributor location by the URL_QUERY.
     *
     * @param string $urlQuery The URL Query
     * @param bool   $exactly  Set ture to match the Distributor path exactly
     *
     * @throws Throwable
     *
     * @return null|Distributor
     */
    public function matchQuery(string $urlQuery, bool $exactly = false): ?Distributor
    {
        if (0 === strlen($urlQuery)) {
            $urlQuery = '/';
        }

        $urlQuery = tidy($urlQuery, false, '/');
        if (!empty($this->path)) {
            sort_path_level($this->path);
            foreach ($this->path as $urlPath => $folderPath) {
                [$distCode, $alias] = explode('@', $folderPath);
                $urlPath = tidy($urlPath, true, '/');
                if (($exactly && $urlPath === $urlQuery) || str_starts_with($urlQuery, $urlPath)) {
                    return $this->distributor = new Distributor(tidy($distCode, true), $alias ?? '*', $this, $urlPath, substr($urlQuery, strlen($urlPath) - 1));
                }
            }
        }

        return null;
    }
}
