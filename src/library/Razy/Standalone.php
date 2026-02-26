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
 * @license MIT
 */

namespace Razy;

use Throwable;
use Razy\Contract\ContainerInterface;
use Razy\Contract\DistributorInterface;
use Razy\Distributor\ModuleRegistry;
use Razy\Distributor\ModuleScanner;
use Razy\Distributor\PrerequisiteResolver;
use Razy\Distributor\RouteDispatcher;
use Razy\Module\ModuleStatus;
use Razy\Util\PathUtil;

/**
 * Class Standalone
 *
 * Lightweight runtime for standalone (lite) mode. Manages a single ultra-flat
 * module directly from the standalone/ folder without Domain resolution,
 * dist.php configuration, or ModuleScanner directory traversal.
 *
 * Implements DistributorInterface so that Module, API, EventEmitter, and
 * PackageManager can work identically in both multisite and standalone mode.
 *
 * @class Standalone
 * @package Razy
 */
class Standalone implements DistributorInterface
{
    /** @var string Fixed distribution code */
    private string $code = 'standalone';

    /** @var string Filesystem path to the standalone folder */
    private string $folderPath;

    /** @var bool Strict mode (always false for standalone) */
    private bool $strict = false;

    /** @var bool Fallback routing is always enabled */
    private bool $fallback = true;

    /** @var string URL query string for route matching */
    private string $urlQuery = '/';

    /** @var Template|null Lazy-initialized global Template instance */
    private ?Template $globalTemplate = null;

    // --- Sub-components ---

    /** @var ModuleRegistry Module tracking, lookup, API registration, and lifecycle */
    private ModuleRegistry $registry;

    /** @var ModuleScanner Filesystem scanning, manifest caching, module autoloading */
    private ModuleScanner $scanner;

    /** @var RouteDispatcher Route registration, matching, and dispatching */
    private RouteDispatcher $router;

    /** @var PrerequisiteResolver Package prerequisite tracking and conflict detection */
    private PrerequisiteResolver $prerequisites;

    /**
     * Standalone constructor.
     *
     * @param string $standalonePath Absolute path to the standalone application folder
     * @param ContainerInterface|null $container Optional DI container
     */
    public function __construct(string $standalonePath, private readonly ?ContainerInterface $container = null)
    {
        $this->folderPath = $standalonePath;
        $this->initializeSubComponents();
    }

    /**
     * Create sub-component instances and register this Standalone in the DI container.
     */
    private function initializeSubComponents(): void
    {
        $this->registry = new ModuleRegistry($this, true);
        $this->scanner = new ModuleScanner($this);
        $this->router = new RouteDispatcher();
        $this->prerequisites = new PrerequisiteResolver($this->code, $this);

        if ($this->container) {
            $this->container->instance(self::class, $this);
        }
    }

    /**
     * Load the single standalone module and resolve its lifecycle.
     *
     * @param bool $initialOnly When true, only trigger __onInit (skip __onLoad/__onRequire)
     * @return $this
     * @throws Error
     * @throws Throwable
     */
    public function initialize(bool $initialOnly = false): static
    {
        $modules = &$this->registry->getModulesRef();

        // Create a single Module directly with synthesized config (no ModuleScanner)
        $moduleConfig = [
            'module_code' => 'standalone/app',
            'author' => 'standalone',
            'description' => 'Standalone application module',
        ];
        $module = new Module($this, $this->folderPath, $moduleConfig, 'default', false, true);
        $modules['standalone/app'] = $module;

        // Resolve module dependencies (recursively)
        foreach ($this->registry->getModules() as $mod) {
            $this->require($mod);
        }

        if ($initialOnly) {
            return $this;
        }

        // Preparation Stage (__onLoad)
        $this->registry->setQueue(array_filter($this->registry->getQueue(), function (Module $module) {
            return $module->prepare();
        }));

        // Validation Stage (__onRequire)
        $this->registry->setQueue(array_filter($this->registry->getQueue(), function (Module $module) {
            return $module->validate();
        }));

        return $this;
    }

    /**
     * Match the registered route and execute the matched path.
     *
     * @return bool
     * @throws Throwable
     */
    public function matchRoute(): bool
    {
        $this->setSession();

        $this->registry->processAwaits();
        $this->registry->notifyReady();

        return $this->router->matchRoute($this->urlQuery, $this->getSiteURL(), $this->registry);
    }

    /**
     * Autoload a class from the standalone module's library folder.
     *
     * @param string $className
     * @return bool
     */
    public function autoload(string $className): bool
    {
        return $this->scanner->autoload($className, $this->registry->getModules(), $this->code);
    }

    /**
     * Set the URL query string for route matching.
     *
     * @param string $urlQuery
     * @return void
     */
    public function setUrlQuery(string $urlQuery): void
    {
        $this->urlQuery = PathUtil::tidy($urlQuery, false, '/');
        if (!$this->urlQuery) {
            $this->urlQuery = '/';
        }
    }

    /**
     * Set the cookie path and start the session.
     *
     * @return $this
     */
    public function setSession(): static
    {
        if (WEB_MODE) {
            session_set_cookie_params(0, '/', HOSTNAME);
            session_name($this->code);
            session_start();
        }

        return $this;
    }

    // -----------------------------------------------------------------------
    // DistributorInterface implementation
    // -----------------------------------------------------------------------

    /** {@inheritDoc} */
    public function getCode(): string
    {
        return $this->code;
    }

    /** {@inheritDoc} */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /** {@inheritDoc} */
    public function getFallback(): bool
    {
        return $this->fallback;
    }

    /** {@inheritDoc} */
    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    /** {@inheritDoc} */
    public function getRegistry(): ModuleRegistry
    {
        return $this->registry;
    }

    /** {@inheritDoc} */
    public function getRouter(): RouteDispatcher
    {
        return $this->router;
    }

    /** {@inheritDoc} */
    public function getPrerequisites(): PrerequisiteResolver
    {
        return $this->prerequisites;
    }

    /** {@inheritDoc} */
    public function getScanner(): ModuleScanner
    {
        return $this->scanner;
    }

    /** {@inheritDoc} */
    public function getDataPath(string $module = '', bool $isURL = false): string
    {
        if ($isURL) {
            return PathUtil::append($this->getSiteURL(), 'data', $module);
        }

        return PathUtil::append(DATA_FOLDER, $this->getIdentity(), $module);
    }

    /** {@inheritDoc} */
    public function getIdentity(): string
    {
        return 'standalone-' . $this->code;
    }

    /** {@inheritDoc} */
    public function getSiteURL(): string
    {
        return (defined('RAZY_URL_ROOT')) ? RAZY_URL_ROOT : '';
    }

    /** {@inheritDoc} */
    public function getFolderPath(): string
    {
        return $this->folderPath;
    }

    /** {@inheritDoc} */
    public function getGlobalTemplateEntity(): Template
    {
        if (!$this->globalTemplate) {
            $this->globalTemplate = new Template();
        }

        return $this->globalTemplate;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Recursively resolve and initialize a module and its dependencies.
     *
     * @param Module $module
     * @return bool
     * @throws Throwable
     */
    private function require(Module $module): bool
    {
        if ($this->registry->isLoadable($module)) {
            if ($module->getStatus() === ModuleStatus::Pending) {
                $module->standby();
            }

            $requireModules = $module->getModuleInfo()->getRequire();
            foreach ($requireModules as $moduleCode => $version) {
                $reqModule = $this->registry->get($moduleCode);
                if (!$reqModule) {
                    return false;
                }

                if ($reqModule->getStatus() === ModuleStatus::Pending) {
                    if (!$this->require($reqModule)) {
                        return false;
                    }
                } elseif ($reqModule->getStatus() === ModuleStatus::Failed) {
                    return false;
                }
            }

            if ($module->getStatus() === ModuleStatus::Processing) {
                if (!$module->initialize()) {
                    $module->unload();
                    return false;
                }
                $this->registry->enqueue($module->getModuleInfo()->getCode(), $module);
            }
        }

        return ($module->getStatus() === ModuleStatus::InQueue);
    }
}
