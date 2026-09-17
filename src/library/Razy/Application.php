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

use Exception;
use Razy\Contract\ContainerInterface;
use Razy\Exception\ConfigurationException;
use Razy\Util\NetworkUtil;
use Razy\Util\PathUtil;
use Razy\Util\StringUtil;
use Throwable;

/**
 * Application is the top-level entry point for the Razy framework.
 *
 * It manages multisite domain configuration, distributor resolution,
 * URL routing dispatch, rewrite rule generation, and site config persistence.
 * Only one unlocked Application instance may exist at a time.
 *
 * @class Application
 *
 * @license MIT
 */
class Application
{
    /** @var bool Whether the application is locked (prevents config changes) */
    public static bool $locked = false;

    /** @var array<string, string> Map of alias FQDN => canonical domain FQDN */
    private array $alias = [];

    /** @var array|null Loaded site configuration (domains + alias) */
    private ?array $config = null;

    /** @var array<string, array> Registered distributors keyed by identifier (code@tag) */
    private array $distributors = [];

    /** @var array<string, array> Multisite domain-to-distributor mappings */
    private array $multisite = [];

    /** @var string Unique identifier (object hash) for this Application instance */
    private string $guid = '';

    /** @var Domain|null The matched Domain instance after host() is called */
    private ?Domain $domain = null;

    /** @var array File integrity checksums for config and rewrite protection */
    private array $protection = [];

    /** @var bool Whether initialize() has been called (lazy deferred from constructor) */
    private bool $initialized = false;

    /** @var Container The dependency injection container for this request */
    private Container $container;

    /** @var Standalone|null Standalone mode runtime (bypasses Domain/multisite) */
    private ?Standalone $standaloneDistributor = null;

    /**
     * Container constructor.
     *
     * @throws Throwable
     */
    public function __construct()
    {
        if (self::$locked) {
            throw new ConfigurationException('Application is locked.');
        }

        $this->guid = \spl_object_hash($this);

        // Initialize the DI container and register core bindings
        $this->container = new Container();
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->alias(ContainerInterface::class, Container::class);

        // Register framework-level services as singletons
        $this->container->singleton(PluginManager::class, fn () => PluginManager::getInstance());
    }

    /**
     * Lock the Application to not allow update or change config.
     */
    public static function Lock(): void
    {
        self::$locked = true;
    }

    /**
     * Unlock the Application for worker mode (reset state between requests)
     * Only used in Caddy/FrankenPHP worker mode.
     */
    public static function UnlockForWorker(): void
    {
        if (\defined('WORKER_MODE') && WORKER_MODE) {
            self::$locked = false;
        }
    }

    /**
     * @param string $fqdn the well-formatted FQDN string
     *
     * @return bool
     *
     * @throws Throwable
     */
    public function host(string $fqdn): bool
    {
        $fqdn = \trim($fqdn);
        if (!empty($fqdn)) {
            $fqdn = NetworkUtil::formatFqdn($fqdn);
            if (!NetworkUtil::isFqdn($fqdn, true)) {
                throw new ConfigurationException('Invalid domain format, it should be a string in FQDN format.');
            }
        }

        // Lazy-initialize config and multisite mappings on first host() call
        $this->ensureInitialized();

        // Register the SPL autoloader for distributor-managed libraries
        \spl_autoload_register(function (string $className): void {
            // Delegate autoloading to the matched domain's distributor chain
            if ($this->domain) {
                $this->domain->autoload($className);
            }
        });

        // Match the domain by the given FQDN string
        if (($this->domain = $this->matchDomain($fqdn)) === null) {
            throw new ConfigurationException("No domain matched for '{$fqdn}'. Check sites.inc.php configuration.");
        }

        return true;
    }

    /**
     * Activate standalone/lite mode.
     *
     * Bypasses the entire Domain/multisite resolution. Creates a Standalone
     * runtime that loads one ultra-flat module directly from the standalone
     * folder, with its own DI container.
     *
     * Detection rule (in main.php): standalone/ exists AND 'multiple_site' is not
     * enabled in config.inc.php (or RAZY_MULTIPLE_SITE env var).
     *
     * @param string $standalonePath Absolute path to the standalone folder
     *
     * @return bool
     *
     * @throws Throwable
     */
    public function standalone(string $standalonePath): bool
    {
        // Register autoloader for standalone runtime
        \spl_autoload_register(function ($className) {
            return $this->standaloneDistributor?->autoload($className);
        });

        // Create a Standalone runtime with the given path and our container
        $this->standaloneDistributor = new Standalone($standalonePath, $this->container);

        return true;
    }

    /**
     * Route a URL query in standalone mode.
     *
     * Initializes the Standalone runtime (loading the single module)
     * and dispatches the URL query to the route matcher.
     *
     * @param string $urlQuery The URL query string
     *
     * @return bool True if a matching route was found and dispatched
     *
     * @throws Throwable
     */
    public function queryStandalone(string $urlQuery): bool
    {
        if (!$this->standaloneDistributor) {
            throw new ConfigurationException('Standalone mode is not activated. Call standalone() first.');
        }

        // Set the URL query on the distributor for route matching
        $this->standaloneDistributor->setUrlQuery($urlQuery);
        $this->standaloneDistributor->initialize();

        return $this->standaloneDistributor->matchRoute();
    }

    /**
     * Lightweight route dispatch for worker mode (skips full module lifecycle).
     *
     * Requires that queryStandalone() has already been called at least once
     * to set up the module graph. Subsequent requests use this fast path
     * which goes directly to route matching without re-initialising modules,
     * re-registering routes, or starting sessions.
     *
     * @param string $urlQuery The URL query to dispatch
     *
     * @return bool True if a matching route was found and dispatched
     *
     * @throws ConfigurationException If standalone mode is not activated
     * @throws Throwable
     */
    public function dispatchStandalone(string $urlQuery): bool
    {
        if (!$this->standaloneDistributor) {
            throw new ConfigurationException('Standalone mode is not activated. Call standalone() first.');
        }

        // Guard: worker-only fast path — prevents bypass of the full module
        // lifecycle (queryStandalone) which would allow route/module injection.
        if (!\defined('WORKER_MODE') || !WORKER_MODE) {
            throw new ConfigurationException(
                'dispatchStandalone() is restricted to worker mode. Use queryStandalone() for standard requests.',
            );
        }

        if (!self::$locked) {
            throw new ConfigurationException(
                'Application must be locked before worker dispatch. Complete the boot phase first.',
            );
        }

        return $this->standaloneDistributor->dispatch($urlQuery);
    }

    /**
     * Get the Standalone distributor instance.
     *
     * Used by PackageRunner to inject "load"-mode dependency modules
     * into the Standalone runtime before queryStandalone() is called.
     *
     * @return Standalone|null The Standalone instance, or null if not in standalone mode
     */
    public function getStandaloneDistributor(): ?Standalone
    {
        return $this->standaloneDistributor;
    }

    /**
     * Get the Application GUID.
     *
     * @return string The GUID of the Application Instance
     */
    public function getGUID(): string
    {
        return $this->guid;
    }

    /**
     * Get the DI container for this Application instance.
     *
     * @return ContainerInterface The Container instance
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Update the multisite config.
     *
     * @return $this
     *
     * @throws Error
     */
    public function updateSites(): static
    {
        if (!$this->config) {
            $this->loadSiteConfig();
        }

        $this->multisite = [];
        $this->alias = [];
        $this->distributors = [];

        // Load extra alias mappings and associate them with configured domains
        $aliasMapping = [];
        if (\is_array($this->config['alias'])) {
            foreach ($this->config['alias'] as $alias => $domain) {
                if (\is_string($domain)) {
                    // Normalize both domain and alias to standard FQDN format
                    $domain = NetworkUtil::formatFqdn($domain);
                    $alias = NetworkUtil::formatFqdn($alias);
                    if (NetworkUtil::isFqdn($domain, true) && NetworkUtil::isFqdn($alias, true)) {
                        $aliasMapping[$domain] ??= [];
                        $aliasMapping[$domain][] = $alias;
                        $this->alias[$alias] = $domain;
                    }
                }
            }
        }

        // Parse the domain list and validate each distributor entry
        if (\is_array($this->config['domains'] ?? null)) {
            foreach ($this->config['domains'] as $domain => $distPaths) {
                $domain = NetworkUtil::formatFqdn($domain);
                // A bare '*' is the DEFAULT-SITE key matchDomain() falls back to
                // when nothing else matches (see matchDomain's trailing
                // `isset($sites['*'])` branch). isFqdn() rejects it — its label
                // grammar needs 2+ characters — so validation must not be the
                // only gate here, or the documented default site is unreachable
                // by construction (proven live 2026-09: a worker pod with a '*'
                // entry booted to "No domain matched for ''").
                if (('*' === $domain || NetworkUtil::isFqdn($domain, true)) && \is_array($distPaths)) {
                    foreach ($distPaths as $relativePath => $distCode) {
                        // Recursively validate distributor identifiers and register them
                        ($validate = function ($distIdentifier, $urlPath = '') use (&$validate, $domain, $aliasMapping) {
                            if (\is_string($distIdentifier)) {
                                // Validate distributor identifier format: code[@tag]
                                if (\preg_match('/^[a-z0-9][\w\-]*(@(?:[a-z0-9][\w\-]*|\d+(\.\d+)*))?$/i', $distIdentifier)) {
                                    // Do not seed *.{apex} onto personal/localhost/default tenant.
                                    $distCodeName = \strtolower(\explode('@', $distIdentifier)[0]);
                                    $personalOrDefault = \in_array($distCodeName, ['default', 'personal', 'localhost'], true);
                                    if (\str_starts_with($domain, '*.') && !NetworkUtil::allowsApexWildcardSeed(\substr($domain, 2), $personalOrDefault)) {
                                        return;
                                    }
                                    if (isset($this->distributors[$distIdentifier])) {
                                        // Distributor already registered; add this domain to its list
                                        $this->distributors[$distIdentifier]['domain'][] = $domain;
                                        // Secondary domain/wildcard keys must still populate multisite[], otherwise
                                        // Application::matchDomain() never considers them ({@see matchDomain()}).
                                        $this->multisite[$domain] ??= [];
                                        $this->multisite[$domain][$urlPath] = $distIdentifier;
                                    } else {
                                        [$code, $tag] = \explode('@', $distIdentifier . '@', 2);

                                        // Verify the distributor's config file (dist.php) exists
                                        $distConfigPath = PathUtil::append(SITES_FOLDER, $code, 'dist.php');
                                        if (\is_file($distConfigPath)) {
                                            $this->multisite[$domain] ??= [];
                                            $this->multisite[$domain][$urlPath] = $distIdentifier;

                                            $this->distributors[$distIdentifier] = [
                                                'code' => $code,
                                                'tag' => $tag ?: '*',
                                                'url_path' => $urlPath,
                                                'domain' => [$domain],
                                                'alias' => $aliasMapping[$domain] ?? [],
                                                'identifier' => $distIdentifier,
                                            ];
                                        }
                                    }
                                }
                            } elseif (\is_array($distIdentifier)) {
                                // Nested distributor definitions: recurse with appended URL path
                                foreach ($distIdentifier as $subPath => $identifier) {
                                    // Load the list of distributor recursively
                                    $validate($identifier, PathUtil::append($urlPath, $subPath));
                                }
                            }
                        })($distCode, $relativePath);
                    }
                }
            }
        }

        if (\count($this->multisite)) {
            foreach ($this->multisite as $domain => &$distPaths) {
                // Sort URL paths by depth so deeper paths are matched before shallower ones
                StringUtil::sortPathLevel($distPaths);
            }
        }

        return $this;
    }

    /**
     * Load the multisites config from file.
     *
     * @return array[]
     *
     * @throws Error
     */
    public function loadSiteConfig(): array
    {
        $configFilePath = PathUtil::append(\defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

        // Load default config setting
        $this->config = [
            'domains' => [],
            'alias' => [],
        ];

        // Import the config file setting
        try {
            if (\is_file($configFilePath)) {
                $this->config = require $configFilePath;
                if (!isset($this->protection['config_file'])) {
                    $this->protection['config_file'] = [
                        'checksums' => \md5_file($configFilePath),
                        'path' => $configFilePath,
                    ];
                }

                $rewriteFilePath = PathUtil::append(SYSTEM_ROOT, '.htaccess');
                if (\is_file($rewriteFilePath)) {
                    if (!isset($this->protection['rewrite_file'])) {
                        $this->protection['rewrite_file'] = [
                            'checksums' => \md5_file($rewriteFilePath),
                            'path' => $rewriteFilePath,
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            throw new ConfigurationException('Failed to load site configuration: ' . $configFilePath, $e);
        }

        if (!\is_array($this->config['domains'] ?? null)) {
            $this->config['domains'] = [];
        }

        if (!\is_array($this->config['alias'] ?? null)) {
            $this->config['alias'] = [];
        }

        // Host-level sibling exclusions (coexistence; Routing\ExcludePaths).
        // Entry validation happens at generation time (compiler) so a typo
        // surfaces as a clear `rewrite` error instead of taking down serving.
        if (!\is_array($this->config['exclude_paths'] ?? null)) {
            $this->config['exclude_paths'] = [];
        }

        return $this->config;
    }

    /**
     * Start to query the distributor by the URL query.
     *
     * @param string $urlQuery The URL query string
     *
     * @return bool Return true if Distributor is matched
     *
     * @throws ConfigurationException
     * @throws Throwable
     */
    public function query(string $urlQuery): bool
    {
        if (!$this->domain) {
            throw new ConfigurationException('No domain was matched that no query is allowed.');
        }
        $distributor = $this->domain->matchQuery($urlQuery);
        if (null === $distributor) {
            return false;
        }

        if (!$distributor->matchRoute()) {
            Error::show404();
        }
        return true;
    }

    /**
     * Lightweight route dispatch for worker mode (multisite).
     *
     * Uses Domain::dispatchQuery() which caches fully-initialised Distributors
     * across requests. On the first request for a given URL prefix, the full
     * Distributor lifecycle runs (config load → module scan → __onInit →
     * __onLoad → __onRequire → matchRoute). Subsequent requests reuse the
     * cached Distributor and skip directly to route matching.
     *
     * Periodically checks config fingerprints (controlled by the
     * WORKER_CONFIG_CHECK_INTERVAL env var, default 100) and hot-reloads
     * when dist.php or module folder mtimes change.
     *
     * @param string $urlQuery The URL query to dispatch
     *
     * @return bool True if a matching route was found and dispatched
     *
     * @throws ConfigurationException If no domain was matched
     * @throws Throwable
     */
    public function dispatch(string $urlQuery): bool
    {
        if (!$this->domain) {
            throw new ConfigurationException('No domain was matched that no query is allowed.');
        }

        // Guard: worker-only fast path — prevents bypass of the full
        // distributor lifecycle (query) which would allow module injection.
        if (!\defined('WORKER_MODE') || !WORKER_MODE) {
            throw new ConfigurationException(
                'dispatch() is restricted to worker mode. Use query() for standard requests.',
            );
        }

        if (!self::$locked) {
            throw new ConfigurationException(
                'Application must be locked before worker dispatch. Complete the boot phase first.',
            );
        }

        return $this->domain->dispatchQuery($urlQuery);
    }

    /**
     * Update the rewrite.
     *
     * Carries the host-level `exclude_paths` sibling-exclusion config into the
     * compiler (host-level placement is deliberate — exclusions describe the
     * shared host, not one distributor; see Routing\ExcludePaths). Applies on
     * the normal `rewrite` regeneration flow; no separate command.
     *
     * @return bool
     *
     * @throws Error
     * @throws Throwable
     */
    public function updateRewriteRules(): bool
    {
        $this->ensureInitialized();
        if (!self::$locked) {
            $compiler = new Routing\RewriteRuleCompiler();
            $outputPath = PathUtil::append(\defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, '.htaccess');
            return $compiler->compile($this->multisite, $this->alias, $outputPath, $this->config['exclude_paths'] ?? []);
        }

        return true;
    }

    /**
     * Generate a Caddyfile from the current multisite configuration.
     *
     * Produces a Caddy-compatible configuration file with site blocks for each
     * domain, webasset handlers, data mapping, and FrankenPHP worker mode
     * directives. This is the Caddy equivalent of updateRewriteRules().
     *
     * Carries the host-level `exclude_paths` config through; the compiler only
     * de-claims those paths from php_server and never emits reverse_proxy
     * (decision Q3, architecture/ROUTE-COEXISTENCE.md §5).
     *
     * @param bool $workerMode Whether to enable FrankenPHP worker mode (default: true)
     * @param string $documentRoot The server document root path (default: '/app/public')
     *
     * @return bool True on success
     *
     * @throws Error
     * @throws Throwable
     */
    public function updateCaddyfile(bool $workerMode = true, string $documentRoot = '/app/public'): bool
    {
        $this->ensureInitialized();
        if (!self::$locked) {
            $compiler = new Routing\CaddyfileCompiler();
            $outputPath = PathUtil::append(\defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'Caddyfile');
            return $compiler->compile($this->multisite, $this->alias, $outputPath, $workerMode, $documentRoot, $this->config['exclude_paths'] ?? []);
        }

        return true;
    }

    /**
     * Write the multisite config file.
     *
     * @param array|null $config
     *
     * @return bool
     *
     * @throws ConfigurationException On an invalid `exclude_paths` entry
     * @throws Throwable
     */
    public function writeSiteConfig(?array $config = null): bool
    {
        if (!self::$locked) {
            $configFilePath = PathUtil::append(\defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

            // Write the config file
            $source = Template::loadFile(PHAR_PATH . '/asset/setup/sites.inc.php.tpl');
            $root = $source->getRoot();

            $config ??= $this->config;
            foreach ($config['domains'] as $domainName => $sites) {
                $domainBlock = $root->newBlock('domain')->assign('domain', $domainName);
                foreach ($sites as $path => $code) {
                    $domainBlock->newBlock('site')->assign([
                        'path' => $path,
                        'dist_code' => $code,
                    ]);
                }
            }

            foreach ($config['alias'] as $alias => $domain) {
                if (\is_string($domain)) {
                    $domain = \trim($domain);
                    if ($domain) {
                        $root->newBlock('alias')->assign([
                            'alias' => $alias,
                            'domain' => $domain,
                        ]);
                    }
                }
            }

            // Round-trip host-level exclusions so config regeneration (this
            // method and the shutdown watchdog) never drops them. Only
            // validated prefixes are written, keeping the emitted PHP literal
            // safe; invalid entries throw with a clear message instead.
            foreach (Routing\ExcludePaths::normalize($config['exclude_paths'] ?? []) as $prefix) {
                $root->newBlock('exclude_path')->assign('prefix', $prefix);
            }

            try {
                $file = \fopen($configFilePath, 'w');
                if (!$file) {
                    throw new Exception('Can\'t create lock file!');
                }

                if (\flock($file, LOCK_EX)) {
                    \ftruncate($file, 0);
                    \fwrite($file, $source->output());
                    \fflush($file);
                    \flock($file, LOCK_UN);
                }

                \fclose($file);

                $this->config = $config;
                return true;
            } catch (Exception) {
                return false;
            }
        }
        return true;
    }

    /**
     * Execute dispose event.
     *
     * @return $this
     */
    public function dispose(): static
    {
        $this->domain?->dispose();
        if ($this->standaloneDistributor) {
            $this->standaloneDistributor->getRegistry()->dispose();
            $this->standaloneDistributor = null;
        }
        self::$locked = false;
        return $this;
    }

    /**
     * Check if the distributor is existing by given distributor code.
     *
     * @param string $code
     *
     * @return bool
     */
    public function hasDistributor(string $code): bool
    {
        $this->ensureInitialized();
        return isset($this->distributors[$code]);
    }

    /**
     * Run if the module under the distributor is need to unpack the asset or install the package from composer.
     *
     * @param string $code
     * @param callable $closure
     *
     * @return bool
     *
     * @throws ConfigurationException
     * @throws Throwable
     */
    public function compose(string $code, callable $closure): bool
    {
        $code = \trim($code);
        if ($this->hasDistributor($code)) {
            $distributor = (new Distributor($code))->initialize(true);
            return $distributor->getPrerequisites()->compose($closure(...));
        }

        throw new ConfigurationException('Distributor `' . $code . '` is not found.');
    }

    /**
     * Make sure the config file and rewrite has not modified or remove in application.
     *
     * @throws Error
     * @throws Throwable
     */
    public function validation(): void
    {
        if (!self::$locked) {
            if (isset($this->protection['config_file'])) {
                if (!\is_file($this->protection['config_file']['path']) || \md5_file($this->protection['config_file']['path']) !== $this->protection['config_file']['checksums']) {
                    $this->writeSiteConfig();
                }
            }
        }

        if (isset($this->protection['rewrite_file'])) {
            if (!\is_file($this->protection['rewrite_file']['path']) || \md5_file($this->protection['rewrite_file']['path']) !== $this->protection['rewrite_file']['checksums']) {
                $this->updateRewriteRules();
            }
        }
    }

    /**
     * Get the Domain instance by given FQDN string.
     *
     * @param string $fqdn The well-formatted FQDN string used to match the domain
     *
     * @return Domain|null Return the matched Domain instance or return null if no FQDN has matched
     *
     * @throws Throwable
     */
    private function matchDomain(string $fqdn): ?Domain
    {
        [$domain] = \explode(':', $fqdn . ':', 2);

        // DNS host matching is case-insensitive. Lowercase lookup keys so
        // Console.oaao.ai cannot miss an exact console.oaao.ai binding and
        // fall through to a one-label *.apex wildcard.
        // Exact FQDN always beats one-label *.apex (order below is fixed):
        // exact fqdn, exact domain, alias, then one-label wildcard (* -> [^.]+), then bare *.
        $fqdnKey = \strtolower($fqdn);
        $domainKey = \strtolower($domain);
        $sites = \array_change_key_case($this->multisite, CASE_LOWER);
        $aliases = \array_change_key_case($this->alias, CASE_LOWER);

        // Match fqdn string
        if (\array_key_exists($fqdnKey, $sites)) {
            return new Domain($this, $fqdnKey, '', $sites[$fqdnKey]);
        }

        // Match domain name
        if (\array_key_exists($domainKey, $sites)) {
            return new Domain($this, $domainKey, '', $sites[$domainKey]);
        }

        // Match alias by fqdn string
        if (\array_key_exists($fqdnKey, $aliases) && isset($sites[$aliases[$fqdnKey]])) {
            return new Domain($this, $aliases[$fqdnKey], $fqdnKey, $sites[$aliases[$fqdnKey]]);
        }

        // Match alias by domain name
        if (\array_key_exists($domainKey, $aliases) && isset($sites[$aliases[$domainKey]])) {
            return new Domain($this, $aliases[$domainKey], $domainKey, $sites[$aliases[$domainKey]]);
        }

        // Match the domain in multisite list that with the wildcard character
        foreach ($sites as $wildcardFqdn => $path) {
            if (NetworkUtil::isFqdn($wildcardFqdn, true)) {
                // If the FQDN string contains * (wildcard)
                if ('*' !== $wildcardFqdn && \str_contains($wildcardFqdn, '*')) {
                    $wildcard = \preg_quote($wildcardFqdn, '/');
                    $wildcard = \str_replace('\*', '[^.]+', $wildcard);
                    // Match hostname only: HTTP_HOST may include an explicit port (e.g. host:80) which must
                    // not participate in multisite wildcard FQDN patterns (stored without port suffix).
                    if (\preg_match('/^' . $wildcard . '$/', $domainKey)) {
                        // Given fqdn becomes the domain's alias
                        return new Domain($this, $wildcardFqdn, $fqdnKey, $sites[$wildcardFqdn]);
                    }
                }
            }
        }

        // Default sites
        if (isset($sites['*'])) {
            // If there is a wildcard domain exists
            return new Domain($this, '*', $fqdnKey, $sites['*']);
        }

        // Return null if no domain has matched
        return null;
    }

    /**
     * Ensure the application is initialized (lazy deferred from constructor).
     * This loads config and sets up multisite mappings on first call.
     *
     * @throws Throwable
     */
    private function ensureInitialized(): void
    {
        if (!$this->initialized) {
            $this->initialize();
            $this->initialized = true;
        }
    }

    /**
     * Load the site configuration to setup the multisite setting.
     *
     * @throws Throwable
     */
    private function initialize(): void
    {
        if (!\defined('SYSTEM_ROOT')) {
            throw new ConfigurationException('SYSTEM_ROOT is not defined, initialize failed.');
        }

        // Load the site configuration file
        $this->config = $this->loadSiteConfig();

        $this->updateSites();
    }
}
