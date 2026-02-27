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
 */
class Domain
{
    /** @var Distributor|null The matched distributor instance for this request */
    private ?Distributor $distributor = null;

    /**
     * @var array<string, array{distributor: Distributor, fingerprint: string, urlPath: string}>
     * Cached distributors keyed by "distCode@tag". Each entry stores the fully
     * initialised Distributor, its config fingerprint at construction time, and
     * the URL path prefix it was mounted on.
     *
     * Worker mode reuses cached distributors across requests to avoid
     * re-reading dist.php, re-scanning modules, and re-running the full
     * module lifecycle (__onInit → __onLoad → __onRequire) on every request.
     */
    private array $distributorCache = [];

    /**
     * @var int How often (in dispatch calls) to check whether the on-disk
     * configuration has changed. 0 = check every request (safest),
     * configurable via WORKER_CONFIG_CHECK_INTERVAL env var.
     */
    private int $configCheckInterval;

    /** @var int Running counter of dispatchQuery() calls for periodic check gating */
    private int $dispatchCount = 0;

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

        // Config change detection interval (0 = every request, 100 = every 100th dispatch)
        $this->configCheckInterval = (int) (\getenv('WORKER_CONFIG_CHECK_INTERVAL') ?: 100);
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
                    ($this->distributor = new Distributor($distCode, $tag ?: '*', $this, $urlPath, \substr($urlQuery, \strlen($urlPath) - 1)))->initialize();
                    return $this->distributor;
                }
            }
        }

        return null;
    }

    /**
     * Lightweight route dispatch for worker mode with distributor caching.
     *
     * On the first request for a given distCode@tag, builds and caches the
     * full Distributor (config load + module scan + lifecycle). Subsequent
     * requests reuse the cached Distributor and go straight to route matching
     * via Distributor::dispatch().
     *
     * Periodically checks config fingerprints to detect on-disk changes:
     * - **Low-impact changes** (dist.php config edits that don't affect the
     *   module set): hot-reloaded by rebuilding the Distributor in-place.
     * - **High-impact changes** (module folder structure changes): also
     *   hot-reloaded, since the full initialize() re-scans and re-resolves.
     *
     * The check interval is controlled by WORKER_CONFIG_CHECK_INTERVAL env var
     * (default: 100 = check every 100th request per distributor).
     *
     * @param string $urlQuery The URL query to dispatch
     *
     * @return bool True if a matching route was found and dispatched
     *
     * @throws Throwable
     */
    public function dispatchQuery(string $urlQuery): bool
    {
        // Guard: worker-only fast path — prevents bypassing the full
        // distributor lifecycle (matchQuery + initialize + matchRoute)
        // which wires up module dependencies, routes, and middleware.
        if (!\defined('WORKER_MODE') || !WORKER_MODE) {
            throw new ConfigurationException(
                'Domain::dispatchQuery() is restricted to worker mode. Use matchQuery() for standard requests.'
            );
        }

        if (!Application::$locked) {
            throw new ConfigurationException(
                'Application must be locked before worker dispatch. Complete the boot phase first.'
            );
        }

        if (0 === \strlen($urlQuery)) {
            $urlQuery = '/';
        }
        $urlQuery = PathUtil::tidy($urlQuery, false, '/');

        if (empty($this->mapping)) {
            return false;
        }

        ++$this->dispatchCount;

        // Find which URL path prefix matches, same logic as matchQuery()
        foreach ($this->mapping as $urlPath => $distIdentifier) {
            [$distCode, $tag] = \explode('@', $distIdentifier . '@', 2);
            $tag = $tag ?: '*';
            $urlPath = PathUtil::tidy($urlPath, true, '/');

            if (!\str_starts_with($urlQuery, $urlPath)) {
                continue;
            }

            $cacheKey = $distCode . '@' . $tag;
            $remainingQuery = \substr($urlQuery, \strlen($urlPath) - 1);

            // Check if we have a cached distributor
            if (isset($this->distributorCache[$cacheKey])) {
                $cached = $this->distributorCache[$cacheKey];
                $this->distributor = $cached['distributor'];

                // Periodic config change detection
                $shouldCheck = ($this->configCheckInterval <= 0)
                    || ($this->dispatchCount % $this->configCheckInterval === 0);

                if ($shouldCheck) {
                    $currentFingerprint = $this->distributor->getConfigFingerprint();
                    if ($currentFingerprint !== $cached['fingerprint']) {
                        // Config or modules changed on disk — rebuild distributor
                        unset($this->distributorCache[$cacheKey]);
                        return $this->buildAndCacheDistributor(
                            $distCode, $tag, $urlPath, $remainingQuery, $cacheKey
                        );
                    }
                }

                // Fast dispatch: reuse cached distributor, only update URL + dispatch
                return $this->distributor->dispatch($remainingQuery);
            }

            // First time seeing this distCode@tag — full build + cache
            return $this->buildAndCacheDistributor(
                $distCode, $tag, $urlPath, $remainingQuery, $cacheKey
            );
        }

        return false;
    }

    /**
     * Build a new Distributor, run full lifecycle, cache it, and dispatch.
     *
     * @param string $distCode  Distributor code
     * @param string $tag       Distributor tag
     * @param string $urlPath   URL path prefix
     * @param string $urlQuery  Remaining URL query after prefix
     * @param string $cacheKey  Cache key (distCode@tag)
     *
     * @return bool True if route was matched and dispatched
     *
     * @throws Throwable
     */
    private function buildAndCacheDistributor(
        string $distCode,
        string $tag,
        string $urlPath,
        string $urlQuery,
        string $cacheKey
    ): bool {
        $this->distributor = new Distributor($distCode, $tag, $this, $urlPath, $urlQuery);
        $this->distributor->initialize();

        // Cache with fingerprint for future change detection
        $this->distributorCache[$cacheKey] = [
            'distributor' => $this->distributor,
            'fingerprint' => $this->distributor->getConfigFingerprint(),
            'urlPath' => $urlPath,
        ];

        // First request for this distributor uses full matchRoute() (with session, awaits, etc.)
        if (!$this->distributor->matchRoute()) {
            Error::show404();
        }
        return true;
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
