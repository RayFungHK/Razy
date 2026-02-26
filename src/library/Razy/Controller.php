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

use BadMethodCallException;
use Closure;
use InvalidArgumentException;
use Razy\Contract\ContainerInterface;
use Razy\Database\MigrationManager;
use Razy\Database\Statement;
use Razy\Exception\ContainerException;
use Razy\Exception\ModuleLoadException;
use Razy\Exception\RedirectException;
use Razy\ORM\Model;
use Razy\Template\Source;
use Razy\Util\PathUtil;
use Throwable;

/**
 * The Controller class is responsible for handling the lifecycle events of a module,
 * managing assets, configurations, templates, and API interactions.
 *
 * Subclass this in each module to define lifecycle hooks (__onInit, __onLoad, __onReady, etc.)
 * and route handlers. Undeclared methods are resolved via closure file autoloading.
 *
 * @class Controller
 *
 * @package Razy
 *
 * @license MIT
 */
class Controller
{
    /** @var int Bitmask flag: load all plugin types */
    public const PLUGIN_ALL = 0b1111;

    /** @var int Bitmask flag: load Template plugins */
    public const PLUGIN_TEMPLATE = 0b0001;

    /** @var int Bitmask flag: load Collection plugins */
    public const PLUGIN_COLLECTION = 0b0010;

    /** @var int Bitmask flag: load Pipeline plugins */
    public const PLUGIN_PIPELINE = 0b0100;

    /** @var int Bitmask flag: load Statement (Database) plugins */
    public const PLUGIN_STATEMENT = 0b1000;

    /** @var array<string, Closure> Dynamically loaded closure methods bound to this controller */
    private array $externalClosure = [];

    /** @var array<string, Emitter> Cached API emitters keyed by module code */
    private array $cachedAPI = [];

    /** @var array<string, class-string<Model>> Cached model FQCNs keyed by model name */
    private array $loadedModels = [];

    /**
     * Controller constructor.
     *
     * @param Module|null $module The module associated with this controller
     */
    final public function __construct(private readonly ?Module $module = null)
    {
    }

    /**
     * Controller Event __onInit, will be triggered when the module is scanned and ready to load.
     *
     * @param Agent $agent The agent responsible for module initialization
     *
     * @return bool Return true if the module is loaded, or return false to mark the module status as "Failed"
     */
    public function __onInit(Agent $agent): bool
    {
        return true;
    }

    /**
     * __onDispose event, all modules will be executed after route and script is completed.
     */
    public function __onDispose(): void
    {
    }

    /**
     * __onDispatch event, all modules will be executed before verify the module require
     * Return false to remove from the queue.
     *
     * @return bool
     */
    public function __onDispatch(): bool
    {
        return true;
    }

    /**
     * __onRouted event, will trigger when other module has matched the route.
     *
     * @param ModuleInfo $moduleInfo Information about the matched module
     */
    public function __onRouted(ModuleInfo $moduleInfo): void
    {
    }

    /**
     * __onScriptReady event, will be triggered when other module is ready to execute the script.
     *
     * @param ModuleInfo $module Information about the module ready to execute the script
     */
    public function __onScriptReady(ModuleInfo $module): void
    {
    }

    /**
     * __onLoad event, trigger after all modules are loaded in queue.
     *
     * @param Agent $agent The agent responsible for loading the module
     *
     * @return bool
     */
    public function __onLoad(Agent $agent): bool
    {
        return true;
    }

    /**
     * Controller Event __onReady, will be triggered if all modules are loaded.
     */
    public function __onReady(): void
    {
    }

    /**
     * __onEntry event, execute when route is matched and ready to execute.
     *
     * @param array $routedInfo Information about the matched route
     */
    public function __onEntry(array $routedInfo): void
    {
    }

    /**
     * Handling the error of the closure.
     *
     * @param string $path The path where the error occurred
     * @param Throwable $exception The exception that was thrown
     *
     * @throws Throwable
     */
    public function __onError(string $path, Throwable $exception): void
    {
        Error::showException($exception);
    }

    /**
     * Controller Event __onAPICall, will be executed if the module is accessed via API.
     * Return false to refuse API access.
     *
     * @param ModuleInfo $module The ModuleInfo entity that is accessed via API
     * @param string $method The command method will be called via API
     * @param string $fqdn The well-formatted FQDN string includes the domain name and distributor code
     *
     * @return bool Return false to refuse API access
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        return true;
    }

    /**
     * Controller Event __onBridgeCall, will be executed if the module is accessed via cross-distributor bridge.
     * Return false to refuse bridge access.
     *
     * @param string $sourceDistributor The identifier of the calling distributor (e.g., 'siteA@1.0.0')
     * @param string $command The bridge command being called
     *
     * @return bool Return false to refuse bridge access
     */
    public function __onBridgeCall(string $sourceDistributor, string $command): bool
    {
        return true;
    }

    /**
     * __onTouch event, handling touch request from another module.
     *
     * @param ModuleInfo $module The module sending the touch request
     * @param string $version The version of the module
     * @param string $message The message associated with the touch request
     *
     * @return bool
     */
    public function __onTouch(ModuleInfo $module, string $version, string $message = ''): bool
    {
        return true;
    }

    /**
     * __onRequire event, return false if the module is not ready.
     *
     * @return bool
     */
    public function __onRequire(): bool
    {
        return true;
    }

    /**
     * Controller method bridge.
     * When the method called which is not declared, the Controller will
     * inject the Closure from the specified path that is configured in __onInit state.
     *
     * @param string $method The string of the method name which is called
     * @param array $arguments The arguments will pass to the method
     *
     * @return mixed The return result of the method
     *
     * @throws Throwable
     */
    final public function __call(string $method, array $arguments)
    {
        // 1) Check if a closure binding exists for this method name
        if ($path = $this->module->getBinding($method)) {
            if (null !== ($closure = $this->module->getClosure($path))) {
                return \call_user_func_array($closure, $arguments);
            }
        }

        // 2) Attempt to load an external closure file: controller/{ClassName}.{method}.php
        $moduleInfo = $this->module->getModuleInfo();
        $path = PathUtil::append($moduleInfo->getPath(), 'controller', $moduleInfo->getClassName() . '.' . $method . '.php');
        if (\is_file($path)) {
            /** @var Closure $closure */
            $closure = require $path;
            if (!\is_callable($closure) && $closure instanceof Closure) {
                throw new ModuleLoadException("File '{$path}' loaded for method '{$method}' must return a Closure, got " . \gettype($closure) . '.');
            }
            // Bind the closure to this controller instance and cache it
            $this->externalClosure[$method] = $closure->bindTo($this);
        }

        // 3) Execute the cached external closure or throw if method is undefined
        $closure = $this->externalClosure[$method] ?? null;
        if (!$closure) {
            throw new BadMethodCallException('The method `' . $method . '` is not defined in `' . \get_class($this) . '`.');
        }
        return \call_user_func_array($closure, $arguments);
    }

    /**
     * Get the module's asset URL that defined in .htaccess rewrite, before running the application the rewrite must be updated in CLI first.
     *
     * @return string The asset URL
     */
    final public function getAssetPath(): string
    {
        // Build URL: {siteURL}/webassets/{moduleAlias}/{moduleVersion}/
        return PathUtil::append(
            $this->module->getSiteURL(),
            'webassets',
            $this->module->getModuleInfo()->getAlias(),
            $this->module->getModuleInfo()->getVersion(),
        ) . '/';
    }

    /**
     * Get the module's data folder of the application.
     *
     * @param string $module The module name
     *
     * @return string The data path
     */
    final public function getDataPath(string $module = ''): string
    {
        return $this->module->getDataPath($module);
    }

    /**
     * Get the Configuration entity.
     *
     * @return Configuration
     *
     * @throws ConfigurationException
     */
    final public function getModuleConfig(): Configuration
    {
        return $this->module->loadConfig();
    }

    /**
     * Get the root URL of the module.
     *
     * @return string The module URL
     */
    final public function getModuleURL(): string
    {
        return $this->module->getModuleURL();
    }

    /**
     * Redirect to a specified path in the module.
     *
     * @param string $path The path to redirect to
     * @param array $query The query parameters to append to the URL
     */
    final public function goto(string $path, array $query = []): void
    {
        $url = PathUtil::append($this->getModuleURL(), $path);
        \header('location: ' . $url, true, 301);
        throw new RedirectException($url, 301);
    }

    /**
     * Create an EventEmitter to fire an event.
     *
     * This is the preferred method for firing events from controllers.
     * Returns an EventEmitter - call resolve() to dispatch to listeners.
     *
     * Example:
     * ```php
     * $emitter = $this->trigger('user_registered');
     * $emitter->resolve($userData);
     * $responses = $emitter->getAllResponse();
     * ```
     *
     * @param string $event The name of the event
     * @param callable|null $callback Optional callback executed when resolving
     *
     * @return EventEmitter The emitter instance - call resolve() to dispatch
     */
    final public function trigger(string $event, ?callable $callback = null): EventEmitter
    {
        return $this->module->createEmitter($event, !$callback ? null : $callback(...));
    }

    /**
     * Load the template file.
     *
     * @param string $path The path to the template file
     *
     * @return Source
     *
     * @throws Throwable
     */
    final public function loadTemplate(string $path): Source
    {
        $path = $this->getTemplateFilePath($path);
        if (!\is_file($path)) {
            throw new InvalidArgumentException('The path ' . $path . ' is not a valid path.');
        }
        $template = $this->module->getGlobalTemplateEntity();
        return $template->load($path, $this->getModuleInfo());
    }

    /**
     * Get the XHR entity.
     *
     * @param bool $returnAsArray Whether to return the XHR data as an array
     *
     * @return XHR
     */
    final public function xhr(bool $returnAsArray = false): XHR
    {
        return new XHR($returnAsArray);
    }

    /**
     * Get the view file system path.
     *
     * @param string $path The path to the view file
     *
     * @return string The full file system path to the view
     */
    final public function getTemplateFilePath(string $path): string
    {
        // Resolve to the module's view/ directory
        $path = PathUtil::append($this->module->getModuleInfo()->getPath(), 'view', $path);
        $filename = \basename($path);
        // Auto-append .tpl extension if no extension is present
        if (!\preg_match('/[^.]+\..+/', $filename)) {
            $path .= '.tpl';
        }
        return $path;
    }

    /**
     * Get the ModuleInfo object.
     *
     * @return ModuleInfo
     */
    final public function getModuleInfo(): ModuleInfo
    {
        return $this->module->getModuleInfo();
    }

    /**
     * Get the distributor site root URL.
     *
     * @return string
     */
    final public function getSiteURL(): string
    {
        return $this->module->getSiteURL();
    }

    /**
     * Get the Module version.
     *
     * @return string The module version
     */
    final public function getModuleVersion(): string
    {
        return $this->module->getModuleInfo()->getVersion();
    }

    /**
     * Get the module code.
     *
     * @return string The module code
     */
    final public function getModuleCode(): string
    {
        return $this->module->getModuleInfo()->getCode();
    }

    /**
     * Get the global template entity.
     *
     * @return Template The global template entity
     */
    final public function getTemplate(): Template
    {
        return $this->module->getGlobalTemplateEntity();
    }

    /**
     * Get the DI container.
     *
     * @return ContainerInterface|null The Container instance, or null if unavailable
     */
    final public function container(): ?ContainerInterface
    {
        return $this->module?->getContainer();
    }

    /**
     * Resolve a service from the DI container with auto-wiring.
     *
     * Convenience method that delegates to Container::make(). Throws if the container
     * is not available (e.g., Controller created without a Module).
     *
     * Usage in module controllers:
     *   $service = $this->resolve(MyService::class);
     *   $service = $this->resolve(MyService::class, ['param' => $value]);
     *
     * @param string $abstract The class or interface to resolve
     * @param array $params Optional parameters to pass to the constructor
     *
     * @return mixed The resolved instance
     *
     * @throws ContainerException If the DI container is not available
     */
    final public function resolve(string $abstract, array $params = []): mixed
    {
        $container = $this->container();
        if (!$container) {
            throw new ContainerException('DI container is not available. Ensure the Controller is attached to a Module with a valid Distributor chain.');
        }
        return $container->make($abstract, $params);
    }

    /**
     * Check whether a service is registered in the DI container.
     *
     * @param string $abstract The class or interface to check
     *
     * @return bool True if the service can be resolved, false if not or container unavailable
     */
    final public function hasService(string $abstract): bool
    {
        $container = $this->container();
        return $container !== null && $container->has($abstract);
    }

    /**
     * Get the routed information.
     *
     * @return array The routed information
     */
    final public function getRoutedInfo(): array
    {
        return $this->module->getRoutedInfo();
    }

    /**
     * Get the API Emitter.
     *
     * @param string $moduleCode The module code for which to get the Emitter
     *
     * @return Emitter The Emitter instance
     */
    final public function api(string $moduleCode): Emitter
    {
        // Cache emitters to avoid re-creating them for repeated API calls
        $this->cachedAPI[$moduleCode] ??= $this->module->getEmitter($moduleCode);
        return $this->cachedAPI[$moduleCode];
    }

    /**
     * Get the parsed template content by given list of sources.
     *
     * @param array $sources The list of template sources
     *
     * @throws Throwable
     */
    final public function view(array $sources): void
    {
        echo $this->module->getGlobalTemplateEntity()->outputQueued($sources);
    }

    /**
     * Get the module system path.
     *
     * @return string The module system path
     */
    final public function getModuleSystemPath(): string
    {
        return $this->module->getModuleInfo()->getPath();
    }

    /**
     * Load a Model class from the module's `model/` directory.
     *
     * Each model file should return an anonymous class extending `Razy\ORM\Model`.
     * The returned FQCN (fully-qualified class name) can be used for static calls
     * such as `::query($db)`, `::find($db, $id)`, `::create($db, [...])`, etc.
     *
     * Model files are cached after the first load — subsequent calls with the
     * same name return the cached class name without re-including the file.
     *
     * Example model file (`model/User.php`):
     * ```php
     * <?php
     * use Razy\ORM\Model;
     *
     * return new class extends Model {
     *     protected static string $table    = 'users';
     *     protected static array  $fillable = ['name', 'email'];
     * };
     * ```
     *
     * Example usage in controller:
     * ```php
     * $User = $this->loadModel('User');
     * $user = $User::find($db, 1);
     * $users = $User::query($db)->where('active=:a', ['a' => 1])->get();
     * ```
     *
     * @param string $name Model name (file name without .php extension)
     *
     * @return class-string<Model> The fully-qualified anonymous class name
     *
     * @throws InvalidArgumentException If the model file does not exist
     * @throws ModuleLoadException If the file does not return a valid Model subclass
     */
    final public function loadModel(string $name): string
    {
        // Return cached model class if already loaded
        if (isset($this->loadedModels[$name])) {
            return $this->loadedModels[$name];
        }

        $path = PathUtil::append($this->module->getModuleInfo()->getPath(), 'model', $name . '.php');

        if (!\is_file($path)) {
            throw new InvalidArgumentException(
                "Model file not found: '{$path}'. Ensure the file exists in the module's model/ directory.",
            );
        }

        $result = require $path;

        // The file should return an anonymous class instance extending Model
        if (!$result instanceof Model) {
            throw new ModuleLoadException(
                "Model file '{$path}' must return an instance of Razy\\ORM\\Model (anonymous class), got " . (\is_object($result) ? \get_class($result) : \gettype($result)) . '.',
            );
        }

        $fqcn = \get_class($result);
        $this->loadedModels[$name] = $fqcn;

        return $fqcn;
    }

    /**
     * Create a MigrationManager pre-configured with this module's migration/ directory.
     *
     * The migration/ folder sits alongside model/, controller/, view/ inside the
     * module's versioned package directory. Migration files follow the naming
     * convention `YYYY_MM_DD_HHMMSS_DescriptionName.php` and return anonymous
     * classes extending `Razy\Database\Migration`.
     *
     * Directory layout:
     * ```
     * {vendor}/{module}/{version}/
     *   migration/
     *     2026_02_24_100000_CreateUsersTable.php
     *     2026_02_24_100001_CreatePostsTable.php
     * ```
     *
     * Example migration file (`migration/2026_02_24_100000_CreateUsersTable.php`):
     * ```php
     * <?php
     * use Razy\Database\Migration;
     * use Razy\Database\SchemaBuilder;
     *
     * return new class extends Migration {
     *     public function up(SchemaBuilder $schema): void {
     *         $schema->create('users', function ($table) {
     *             $table->integer('id')->primary()->autoIncrement();
     *             $table->string('name', 100);
     *             $table->string('email', 255)->unique();
     *         });
     *     }
     *
     *     public function down(SchemaBuilder $schema): void {
     *         $schema->dropIfExists('users');
     *     }
     *
     *     public function getDescription(): string {
     *         return 'Create the users table';
     *     }
     * };
     * ```
     *
     * Example usage in controller:
     * ```php
     * public function __onInit(Agent $agent): bool {
     *     $db = $this->resolve(Database::class);
     *     $manager = $this->getMigrationManager($db);
     *
     *     // Run pending migrations
     *     $applied = $manager->migrate();
     *
     *     // Check status
     *     $status = $manager->getStatus();
     *
     *     // Rollback last batch
     *     $rolledBack = $manager->rollback();
     *
     *     return true;
     * }
     * ```
     *
     * @param Database $database The database connection to migrate against
     *
     * @return MigrationManager A manager with the module's migration/ path registered
     *
     * @throws InvalidArgumentException If the migration/ directory does not exist
     */
    final public function getMigrationManager(Database $database): MigrationManager
    {
        $migrationPath = PathUtil::append($this->module->getModuleInfo()->getPath(), 'migration');

        if (!\is_dir($migrationPath)) {
            throw new InvalidArgumentException(
                "Migration directory not found: '{$migrationPath}'. Create a migration/ folder in the module's package directory.",
            );
        }

        $manager = new MigrationManager($database);
        $manager->addPath($migrationPath);

        return $manager;
    }

    /**
     * Register the module's plugin loader.
     *
     * @param int $flag The flag to determine which plugins to load
     *
     * @return $this
     */
    final public function registerPluginLoader(int $flag = 0): self
    {
        // Register plugin folders based on bitmask flags
        if ($flag & self::PLUGIN_TEMPLATE) {
            Template::addPluginFolder(PathUtil::append($this->getModuleSystemPath(), 'plugins', 'Template'), $this);
        }
        if ($flag & self::PLUGIN_COLLECTION) {
            Collection::addPluginFolder(PathUtil::append($this->getModuleSystemPath(), 'plugins', 'Collection'), $this);
        }
        if ($flag & self::PLUGIN_PIPELINE) {
            Pipeline::addPluginFolder(PathUtil::append($this->getModuleSystemPath(), 'plugins', 'Pipeline'), $this);
        }
        if ($flag & self::PLUGIN_STATEMENT) {
            Statement::addPluginFolder(PathUtil::append($this->getModuleSystemPath(), 'plugins', 'Statement'), $this);
        }
        return $this;
    }

    /**
     * Send a handshake to one or a list of modules, return true if the module is accessible for API.
     *
     * @param string $modules The list of modules to handshake with
     * @param string $message The optional message to send with the handshake
     *
     * @return bool
     */
    final public function handshake(string $modules, string $message = ''): bool
    {
        // Split comma-separated module codes and handshake each one
        $modules = \explode(',', $modules);
        foreach ($modules as $module) {
            $module = \trim($module);
            // If any module refuses the handshake, fail immediately
            if (!$this->module->handshake($module, $message)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get the data path URL.
     *
     * @param string $module The module name
     *
     * @return string The data path URL
     */
    final public function getDataPathURL(string $module = ''): string
    {
        return $this->module->getDataPath($module, true);
    }

    /**
     * Execute the internal function by given path, also an alternative of direct access the method.
     *
     * @param string $path The path to the internal function
     * @param array ...$args The arguments to pass to the function
     *
     * @return mixed|null The result of the function execution
     */
    final public function fork(string $path, array ...$args): mixed
    {
        $result = null;
        if ($closure = $this->module->getClosure($path, true)) {
            $result = \call_user_func_array($closure, $args);
        }
        return $result;
    }
}
