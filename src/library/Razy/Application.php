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

use Closure;
use Exception;
use Throwable;

class Application
{
    static bool $locked = false;
    private array $alias = [];
    private ?array $config = null;
    private array $distributors = [];
    private array $multisite = [];
    private string $guid = '';
    private ?Domain $domain = null;
    private array $protection = [];

    /**
     * Container constructor.
     *
     * @throws Throwable
     */
    public function __construct()
    {
        if (self::$locked) {
            throw new Error('Application is locked.');
        }

        $this->guid = spl_object_hash($this);

        // Start loading the site config
        $this->initialize();
    }

    /**
     * @param string $fqdn the well-formatted FQDN string
     *
     * @return bool
     * @throws Throwable
     */
    public function host(string $fqdn): bool
    {
        $fqdn = trim($fqdn);
        if (!empty($fqdn)) {
            $fqdn = format_fqdn($fqdn);
            if (!is_fqdn($fqdn, true)) {
                throw new Error('Invalid domain format, it should be a string in FQDN format.');
            }
        }

        // Register the SPL autoloader
        spl_autoload_register(function ($className) {
            // Try load the library in matched distributor
            return $this->domain && $this->domain->autoload($className);
        });

        // Match the domain by given fqdn
        if (($this->domain = $this->matchDomain($fqdn)) === null) {
            throw new Error('No domain is matched.');
        }

        return true;
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
        [$domain,] = explode(':', $fqdn . ':', 2);

        // Match fqdn string
        if (array_key_exists($fqdn, $this->multisite)) {
            return new Domain($this, $fqdn, '', $this->multisite[$fqdn]);
        }

        // Match domain name
        if (array_key_exists($domain, $this->multisite)) {
            return new Domain($this, $domain, '', $this->multisite[$domain]);
        }

        // Match alias by fqdn string
        if (array_key_exists($fqdn, $this->alias) && isset($this->multisite[$this->alias[$fqdn]])) {
            return new Domain($this, $this->alias[$fqdn], $fqdn, $this->multisite[$this->alias[$fqdn]]);
        }

        // Match alias by domain name
        if (array_key_exists($domain, $this->alias) && isset($this->multisite[$this->alias[$domain]])) {
            return new Domain($this, $this->alias[$domain], $domain, $this->multisite[$this->alias[$domain]]);
        }

        // Match the domain in multisite list that with the wildcard character
        foreach ($this->multisite as $wildcardFqdn => $path) {
            if (is_fqdn($wildcardFqdn, true)) {
                // If the FQDN string contains * (wildcard)
                if ('*' !== $wildcardFqdn && str_contains($wildcardFqdn, '*')) {
                    $wildcard = preg_replace('/\\\\.(*SKIP)(*FAIL)|\*/', '[^.]+', $wildcardFqdn);
                    if (preg_match('/^' . $wildcard . '$/', $fqdn)) {
                        // Given fqdn becomes the domain's alias
                        return new Domain($this, $wildcardFqdn, $fqdn, $this->multisite[$fqdn]);
                    }
                }
            }
        }

        // Default sites
        if (isset($this->multisite['*'])) {
            // If there is a wildcard domain exists
            return new Domain($this, '*', $fqdn, $this->multisite['*']);
        }

        // Return null if no domain has matched
        return null;
    }

    /**
     * Update the multisite config.
     *
     * @return $this
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

        // Load extra alias and map to configured domain
        $aliasMapping = [];
        if (is_array($this->config['alias'])) {
            foreach ($this->config['alias'] as $alias => $domain) {
                if (is_string($domain)) {
                    // Standardize domain and alias format
                    $domain = format_fqdn($domain);
                    $alias = format_fqdn($alias);
                    if (is_fqdn($domain, true) && is_fqdn($alias, true)) {
                        $aliasMapping[$domain] = $aliasMapping[$domain] ?? [];
                        $aliasMapping[$domain][] = $alias;
                        $this->alias[$alias] = $domain;
                    }
                }
            }
        }

        // Extract the domain list
        if (is_array($this->config['domains'] ?? null)) {
            foreach ($this->config['domains'] as $domain => $distPaths) {
                // Standardize domain format
                $domain = format_fqdn($domain);
                if (is_fqdn($domain, true) && is_array($distPaths)) {
                    foreach ($distPaths as $relativePath => $distCode) {
                        // Validate the distributor directory has the configuration file
                        ($validate = function ($distIdentifier, $urlPath = '') use (&$validate, $domain, $aliasMapping) {
                            if (is_string($distIdentifier)) {
                                if (preg_match('/^[a-z0-9][\w\-]*(@(?:[a-z0-9][\w\-]*|\d+(\.\d+)*))?$/i', $distIdentifier)) {
                                    if (isset($this->distributors[$distIdentifier])) {
                                        $this->distributors[$distIdentifier]['domain'][] = $domain;
                                    } else {
                                        [$code, $tag] = explode('@', $distIdentifier . '@', 2);

                                        // Check if the distributor folder is existing
                                        $distConfigPath = append(SITES_FOLDER, $code, 'dist.php');
                                        if (is_file($distConfigPath)) {
                                            $this->multisite[$domain] = $this->multisite[$domain] ?? [];
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
                            } elseif (is_array($distIdentifier)) {
                                foreach ($distIdentifier as $subPath => $identifier) {
                                    // Load the list of distributor recursively
                                    $validate($identifier, append($urlPath, $subPath));
                                }
                            }
                        })($distCode, $relativePath);
                    }
                }
            }
        }

        if (count($this->multisite)) {
            foreach ($this->multisite as $domain => &$distPaths) {
                // Sort the url path for priority match
                sort_path_level($distPaths);
            }
        }

        return $this;
    }

    /**
     * Load the site configuration to setup the multisite setting.
     *
     * @throws Throwable
     */
    private function initialize(): void
    {
        if (!defined('SYSTEM_ROOT')) {
            throw new Error('SYSTEM_ROOT is not defined, initialize failed.');
        }

        // Load the site configuration file
        $this->config = $this->loadSiteConfig();

        $this->updateSites();
    }

    /**
     * Load the multisites config from file
     *
     * @return array[]
     * @throws Error
     */
    public function loadSiteConfig(): array
    {
        $configFilePath = append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

        // Load default config setting
        $this->config = [
            'domains' => [],
            'alias' => [],
        ];

        // Import the config file setting
        try {
            if (is_file($configFilePath)) {
                $this->config = require $configFilePath;
                if (!isset($this->protection['config_file'])) {
                    $this->protection['config_file'] = [
                        'checksums' => md5_file($configFilePath),
                        'path' => $configFilePath,
                    ];
                }

                $rewriteFilePath = append(SYSTEM_ROOT, '.htaccess');
                if (is_file($rewriteFilePath)) {
                    if (!isset($this->protection['rewrite_file'])) {
                        $this->protection['rewrite_file'] = [
                            'checksums' => md5_file($rewriteFilePath),
                            'path' => $rewriteFilePath,
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            exit;
        }

        if (!is_array($this->config['domains'] ?? null)) {
            $this->config['domains'] = [];
        }

        if (!is_array($this->config['alias'] ?? null)) {
            $this->config['alias'] = [];
        }

        return $this->config;
    }

    /**
     * Start to query the distributor by the URL query.
     *
     * @param string $urlQuery The URL query string
     * @return bool Return true if Distributor is matched
     *
     * @throws Error
     * @throws Throwable
     */
    public function query(string $urlQuery): bool
    {
        if (!$this->domain) {
            throw new Error('No domain was matched that no query is allowed.');
        }
        $distributor = $this->domain->matchQuery($urlQuery);
        if (null === $distributor) {
            return false;
        }

        if (!$distributor->matchRoute()) {
            Error::Show404();
        }

        return true;
    }

    /**
     * Update the rewrite.
     *
     * @return bool
     * @throws Error
     * @throws Throwable
     */
    public function updateRewriteRules(): bool
    {
        if (!self::$locked) {
            $source = Template::LoadFile(PHAR_PATH . '/asset/setup/htaccess.tpl');
            $rootBlock = $source->getRoot();

            foreach ($this->distributors as $info) {
                $domains = $info['domain'];
                foreach ($domains as $domain) {
                    $staticDomain = $domain;
                    $domain = preg_quote($domain);

                    try {
                        $distributor = new Distributor($info['code'], $info['tag']);
                        $distributor->initialize(true);
                        $modules = $distributor->getModules();

                        if (!preg_match('/:\d+$/', $domain)) {
                            $domain .= '(:\d+)?';
                        }
                        $domainBlock = $rootBlock->newBlock('domain', $domain)->assign([
                            'domain' => $domain,
                            'system_root' => SYSTEM_ROOT,
                        ]);

                        $dataMapping = $distributor->getDataMapping();
                        if (!count($dataMapping) || !isset($dataMapping['/'])) {
                            $domainBlock->newBlock('data_mapping')->assign([
                                'system_root' => SYSTEM_ROOT,
                                'distributor_path' => $info['code'],
                                'route_path' => ($info['url_path'] === '/') ? '' : ltrim($info['url_path'] . '/', '/'),
                                'data_path' => append('data', $staticDomain . '-' . $distributor->getCode(), '$1'),
                            ]);
                        }
                        foreach ($dataMapping as $path => $site) {
                            $domainBlock->newBlock('data_mapping')->assign([
                                'system_root' => SYSTEM_ROOT,
                                'distributor_path' => $site['dist'],
                                'route_path' => ltrim((($path === '/') ? $info['url_path'] : $info['url_path'] . '/' . $path) . '/', '/'),
                                'data_path' => append('data', $site['domain'] . '-' . $site['dist'], '$1'),
                            ]);
                        }

                        foreach ($modules as $module) {
                            $moduleInfo = $module->getModuleInfo();
                            if (is_dir(append($moduleInfo->getPath(), 'webassets'))) {
                                $domainBlock->newBlock('webassets')->assign([
                                    'system_root' => SYSTEM_ROOT,
                                    'dist_path' => ltrim(tidy(append($moduleInfo->getContainerPath(true), '$1', 'webassets', '$2'), false, '/'), '/'),
                                    'route_path' => ($info['url_path'] === '/') ? '' : ltrim($info['url_path'] . '/', '/'),
                                    'mapping' => $moduleInfo->getClassName(),
                                ]);
                            }
                        }
/*
                        $routes = $distributor->getRoutes();
                        foreach ($routes as $routePath => $config) {
                            $routePath = rtrim(append($info['url_path'], $routePath), '/');
                            if ($config['type'] === 'standard') {
                                $routePath = preg_replace_callback('/\\\\.(*SKIP)(*FAIL)|:(?:([awdWD])|(\[[^\\[\\]]+]))({\d+,?\d*})?/', function ($matches) {
                                    $regex = (strlen($matches[2] ?? '')) > 0 ? $matches[2] : (('a' === $matches[1]) ? '[^/]' : '\\' . $matches[1]);
                                    return $regex . ((0 !== strlen($matches[3] ?? '')) ? $matches[3] : $regex .= '+');
                                }, $routePath);
                            }
                            $domainBlock->newBlock('route')->assign([
                                'system_root' => SYSTEM_ROOT,
                                'route_path' => append(SYSTEM_ROOT, $routePath),
                            ]);
                        }
*/
                    } catch (Exception) {
                        return false;
                    }
                }
            }

            file_put_contents(append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, '.htaccess'), $source->output());
            return true;
        }

        return true;
    }

    /**
     * Write the multisite config file.
     *
     * @param array|null $config
     *
     * @return bool
     * @throws Throwable
     */
    public function writeSiteConfig(?array $config = null): bool
    {
        if (!self::$locked) {
            $configFilePath = append(defined('RAZY_PATH') ? RAZY_PATH : SYSTEM_ROOT, 'sites.inc.php');

            // Write the config file
            $source = Template::LoadFile(PHAR_PATH . '/asset/setup/sites.inc.php.tpl');
            $root = $source->getRoot();

            $config = $config ?? $this->config;
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
                if (is_string($domain)) {
                    $domain = trim($domain);
                    if ($domain) {
                        $root->newBlock('alias')->assign([
                            'alias' => $alias,
                            'domain' => $domain,
                        ]);
                    }
                }
            }

            try {
                $file = fopen($configFilePath, 'w');
                if (!$file) {
                    throw new Exception('Can\'t create lock file!');
                }

                if (flock($file, LOCK_EX)) {
                    ftruncate($file, 0);
                    fwrite($file, $source->output());
                    fflush($file);
                    flock($file, LOCK_UN);
                }

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
        return isset($this->distributors[$code]);
    }

    /**
     * Run if the module under the distributor is need to unpack the asset or install the package from composer
     *
     * @param string $code
     * @param callable $closure
     *
     * @return bool
     *
     * @throws Error
     * @throws Throwable
     */
    public function compose(string $code, callable $closure): bool
    {
        $code = trim($code);
        if ($this->hasDistributor($code)) {
            return (new Distributor($code))->initialize(true)->compose($closure(...));
        }

        throw new Error('Distributor `' . $code . '` is not found.');
    }

    /**
     * Lock the Application to not allow update or change config
     *
     * @return void
     */
    static public function Lock(): void
    {
        self::$locked = true;
    }

    /**
     * Make sure the config file and rewrite has not modified or remove in application
     *
     * @return void
     * @throws Error
     * @throws Throwable
     */
    public function validation(): void
    {
        if (!self::$locked) {
            if (isset($this->protection['config_file'])) {
                if (!is_file($this->protection['config_file']['path']) || md5_file($this->protection['config_file']['path']) !== $this->protection['config_file']['checksums']) {
                    $this->writeSiteConfig();
                }
            }
        }

        if (isset($this->protection['rewrite_file'])) {
            if (!is_file($this->protection['rewrite_file']['path']) || md5_file($this->protection['rewrite_file']['path']) !== $this->protection['rewrite_file']['checksums']) {
                $this->updateRewriteRules();
            }
        }
    }
}