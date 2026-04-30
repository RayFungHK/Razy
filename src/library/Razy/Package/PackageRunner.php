<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

namespace Razy\Package;

use Phar;
use Razy\Application;
use Razy\Distributor;
use Razy\Standalone;
use Razy\Util\PathUtil;
use Throwable;

/**
 * Class PackageRunner.
 *
 * Orchestrates the lifecycle of a .phar-packaged standalone application.
 *
 * Lifecycle:
 *   1. installPrerequisites()   → Composer autoload into ./runtime/autoload/<pkg>/<ver>/
 *   2. resolveDependencies()    → Wait on on_depend (complete, healthcheck, or load)
 *   3. runExec() or runServe()  → Bootstrap standalone with co-modules
 *      runDistExec()            → Use Distributor: load ALL dist modules, execute target
 *
 * PackageTrait provides PHP lifecycle events:
 *   - __onPackageStart()        → Called before main execution
 *   - __onPackageExec()         → Exec-mode entry point (return exit code)
 *   - __onPackageServe()        → Serve-mode entry point (blocking)
 *   - __onPackageStop()         → Called on shutdown
 *   - __onPackageHealthcheck()  → Called by healthcheck polling
 *
 * Execution modes:
 *   - Standalone (runExec/runServe): Single module + co-modules via Standalone runtime.
 *   - Dist (-d flag, runDistExec): Full Distributor loads ALL dist modules (no routing),
 *     then triggers PackageTrait lifecycle on the target module only.
 *
 * Dependency wait modes:
 *   - "complete"    → Run exec-mode dependency, block until it exits.
 *   - "healthcheck" → Spawn serve-mode dependency, poll until HTTP healthy.
 *   - "load"        → Extract phar, inject as co-module into the Standalone
 *                     runtime. Full API, events, routing between modules.
 *
 * The Controller + PackageTrait defines package lifecycle hooks
 * (__onPackageStart, main, __onPackageStop, __onPackageHealthcheck).
 * A Controller that `use PackageTrait` serves both web and package mode.
 *
 * @class PackageRunner
 */
class PackageRunner
{
    /** @var callable|null Logger: fn(string, bool) */
    private $logger;

    /** @var array<string, int> PID map of spawned dependency processes */
    private array $dependencyPids = [];

    /** @var array<string, string> Standalone paths for "load"-mode deps, keyed by package name */
    private array $loadedModulePaths = [];

    /** @var int|null Override healthcheck timeout (seconds) */
    private ?int $healthcheckTimeout = null;

    /**
     * @param PackageManifest $manifest The parsed razy.pkg.json
     * @param string $projectRoot Project root directory
     * @param string $runtimeDir Runtime directory (default: ./runtime)
     * @param string $pkgDir Packages directory (default: ./packages)
     * @param callable|null $logger Logging callback: fn(string $msg, bool $raw)
     */
    public function __construct(
        private readonly PackageManifest $manifest,
        private readonly string $projectRoot,
        private readonly string $runtimeDir,
        private readonly string $pkgDir,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    // ── Public Lifecycle Steps ────────────────────────────────────

    /**
     * Install Composer prerequisite packages into
     * ./runtime/autoload/<package_name>/<version>/vendor/.
     *
     * Skips installation if vendor/autoload.php already exists.
     *
     * @return bool True on success or if no prerequisites are declared
     */
    public function installPrerequisites(): bool
    {
        $prerequisites = $this->manifest->getPrerequisite();
        if (empty($prerequisites)) {
            $this->log('{@c:green}[prereq]{@reset} No prerequisites declared.');
            return true;
        }

        $name = $this->manifest->getPackageName();
        $version = $this->manifest->getVersion();
        $autoloadDir = PathUtil::append(
            $this->runtimeDir,
            'autoload',
            \str_replace('/', DIRECTORY_SEPARATOR, $name),
            $version,
        );

        $vendorAutoload = PathUtil::append($autoloadDir, 'vendor', 'autoload.php');

        // Fast path: already installed
        if (\is_file($vendorAutoload)) {
            $this->log('{@c:green}[prereq]{@reset} Already installed at ' . $autoloadDir);
            require_once $vendorAutoload;
            return true;
        }

        $this->log('{@c:cyan}[prereq]{@reset} Installing to ' . $autoloadDir . '...');

        // Create directory
        if (!\is_dir($autoloadDir) && !\mkdir($autoloadDir, 0o755, true)) {
            $this->log('{@c:red}[prereq]{@reset} Failed to create directory: ' . $autoloadDir);
            return false;
        }

        // Write temporary composer.json
        $composerData = [
            'require' => $prerequisites,
            'config' => [
                'vendor-dir' => 'vendor',
            ],
        ];
        $composerFile = PathUtil::append($autoloadDir, 'composer.json');
        \file_put_contents($composerFile, \json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Run composer install
        $composerBin = $this->findComposer();
        $cmd = $composerBin . ' install --no-dev --optimize-autoloader --working-dir=' . \escapeshellarg($autoloadDir);
        $exitCode = $this->shellExec($cmd);

        if ($exitCode !== 0) {
            $this->log('{@c:red}[prereq]{@reset} Composer install failed (exit ' . $exitCode . ').');
            return false;
        }

        if (\is_file($vendorAutoload)) {
            require_once $vendorAutoload;
            $this->log('{@c:green}[prereq]{@reset} Prerequisites installed successfully.');
        }

        return true;
    }

    /**
     * Resolve on_depend dependency declarations.
     *
     * For each dependency:
     *   - "complete":    Launch exec dependency, wait for it to finish.
     *   - "healthcheck": Spawn serve dependency in background, poll until healthy.
     *   - "load":        Extract phar, collect standalone path for co-module injection.
     *                    The actual module loading happens in runExec()/runServe().
     *
     * @param PackageRegistry $registry Package registry for dependency lookup
     *
     * @return bool True if all dependencies resolved
     */
    public function resolveDependencies(PackageRegistry $registry): bool
    {
        $depends = $this->manifest->getOnDepend();
        if (empty($depends)) {
            return true;
        }

        $this->log('{@c:cyan}[depend]{@reset} Resolving ' . \count($depends) . ' dependenc' . (\count($depends) > 1 ? 'ies' : 'y') . '...');

        foreach ($depends as $dep) {
            $depName = $dep['package'];
            $waitMode = $dep['wait'];

            $this->log("  {@c:cyan}[depend]{@reset} {$depName} (wait: {$waitMode})");

            $depManifest = $registry->find($depName);
            if (!$depManifest) {
                $this->log("  {@c:red}[depend]{@reset} Package not found: {$depName}");
                return false;
            }

            if ($waitMode === 'complete') {
                if (!$this->resolveDependComplete($depManifest, $depName)) {
                    return false;
                }
            } elseif ($waitMode === 'healthcheck') {
                if (!$this->resolveDependHealthcheck($depManifest, $depName)) {
                    return false;
                }
            } elseif ($waitMode === 'load') {
                if (!$this->resolveDependLoad($depManifest, $depName)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the collected "load"-mode dependency paths.
     *
     * These are standalone/ folder paths from extracted dependency phars,
     * ready to be injected as co-modules via Standalone::loadModule().
     *
     * @return array<string, string> Package name => standalone path
     */
    public function getLoadedModulePaths(): array
    {
        return $this->loadedModulePaths;
    }

    /**
     * Run the package in 'exec' mode.
     *
     * For .phar packages: extracts the phar, bootstraps the Standalone runtime.
     * For directory packages: uses the module folder directly, no extraction.
     *
     * Includes any "load"-mode co-modules, runs the full module lifecycle
     * (__onInit → __onLoad → __onReady), then calls the package-specific
     * hooks: packageStart() → packageExec() → packageStop().
     *
     * @param string[] $passArgs Arguments to forward to the package
     *
     * @return bool True on success
     */
    public function runExec(array $passArgs = []): bool
    {
        $name = $this->manifest->getPackageName();
        $version = $this->manifest->getVersion();

        $this->log("{@c:cyan}[exec]{@reset} Bootstrapping {$name}@{$version}...");

        try {
            // Resolve standalone path based on source type
            if ($this->manifest->isDirectory()) {
                // Directory-based: the module folder IS the standalone path
                $standalonePath = $this->manifest->getSourcePath();
                $this->log("  {@c:cyan}[exec]{@reset} Source: directory ({$standalonePath})");
            } else {
                // Phar-based: extract and find standalone/ inside
                $extractDir = $this->getExtractDir();
                $this->extractPhar($this->manifest->getSourcePath(), $extractDir);

                $standalonePath = PathUtil::append($extractDir, 'standalone');
                if (!\is_dir($standalonePath)) {
                    $this->log('{@c:red}[exec]{@reset} No standalone/ folder found in phar.');
                    return false;
                }
            }

            // Define package constants for the controller to detect
            $this->definePackageConstants($name, $version, $passArgs);

            // Create Standalone runtime and inject "load"-mode co-modules
            $standalone = new Standalone($standalonePath);
            foreach ($this->loadedModulePaths as $depCode => $depPath) {
                $standalone->loadModule($depPath, $depCode);
                $this->log("  {@c:green}[load]{@reset} Co-module: {$depCode}");
            }

            // Register autoloader for standalone module classes
            \spl_autoload_register(function (string $class) use ($standalone): void {
                $standalone->autoload($class);
            });

            // Full module lifecycle: __onInit → __onLoad → __onRequire → __onReady
            $standalone->initialize();

            // Get the main module for package lifecycle hooks
            $mainModule = $standalone->getRegistry()->get('standalone/app');
            if (!$mainModule) {
                $this->log('{@c:red}[exec]{@reset} Main module not found in registry.');
                return false;
            }

            $packageInfo = [
                'package_name' => $name,
                'version' => $version,
                'mode' => 'exec',
                'args' => $passArgs,
            ];

            // Package lifecycle: start → exec → stop
            if (!$mainModule->packageStart($packageInfo)) {
                $this->log("{@c:yellow}[exec]{@reset} {$name}: __onPackageStart returned false, aborting.");
                return false;
            }

            $exitCode = $mainModule->packageExec($packageInfo);
            $mainModule->packageStop();

            $this->log("{@c:green}[exec]{@reset} {$name} completed (exit: {$exitCode}).");
            return $exitCode === 0;
        } catch (Throwable $e) {
            $this->log("{@c:red}[exec]{@reset} {$name} failed: " . $e->getMessage());
            return false;
        } finally {
            $this->cleanupDependencyProcesses();
        }
    }

    /**
     * Run the package in 'exec' mode via a full Distributor.
     *
     * All modules in the dist are loaded through the normal Distributor
     * lifecycle (__onInit → __onLoad → __onRequire), but routing is NOT
     * triggered (matchRoute is never called). Cross-module APIs, events,
     * and handshakes are fully available.
     *
     * Only the target module's PackageTrait lifecycle is executed:
     * packageStart() → packageExec() → packageStop().
     *
     * @param string $distCode The dist code (folder name under sites/)
     * @param string $targetModuleCode The module_code of the target package module
     * @param string[] $passArgs Arguments to forward to the package
     *
     * @return bool True on success (exit code 0)
     */
    public function runDistExec(string $distCode, string $targetModuleCode, array $passArgs = []): bool
    {
        $name = $this->manifest->getPackageName();
        $version = $this->manifest->getVersion();

        $this->log("{@c:cyan}[dist-exec]{@reset} Bootstrapping dist '{$distCode}', target: {$targetModuleCode}...");

        try {
            // Define package constants for the controller to detect
            $this->definePackageConstants($name, $version, $passArgs);

            // Create and initialize the Distributor — loads ALL modules
            $distributor = new Distributor($distCode);

            // Register autoloader for dist module classes
            \spl_autoload_register(function (string $class) use ($distributor): void {
                $distributor->autoload($class);
            });

            // Full module lifecycle: __onInit → __onLoad → __onRequire
            // But NO matchRoute() — no routing, no web execution
            $distributor->initialize();

            $this->log("{@c:green}[dist-exec]{@reset} Distributor '{$distCode}' initialized — all modules loaded.");

            // Find the target module
            $targetModule = $distributor->getRegistry()->get($targetModuleCode);
            if (!$targetModule) {
                $this->log("{@c:red}[dist-exec]{@reset} Module '{$targetModuleCode}' not found in dist '{$distCode}'.");
                $this->log('  Available modules:');
                foreach ($distributor->getRegistry()->getModules() as $code => $mod) {
                    $this->log("    - {$code}");
                }
                return false;
            }

            // Verify the target has PackageTrait
            if (!$targetModule->hasPackageTrait()) {
                $this->log("{@c:red}[dist-exec]{@reset} Module '{$targetModuleCode}' does not use PackageTrait.");
                return false;
            }

            $packageInfo = [
                'package_name' => $name,
                'version' => $version,
                'mode' => 'exec',
                'dist_code' => $distCode,
                'args' => $passArgs,
            ];

            // Package lifecycle: start → exec → stop
            if (!$targetModule->packageStart($packageInfo)) {
                $this->log("{@c:yellow}[dist-exec]{@reset} {$targetModuleCode}: __onPackageStart returned false, aborting.");
                return false;
            }

            $exitCode = $targetModule->packageExec($packageInfo);
            $targetModule->packageStop();

            $this->log("{@c:green}[dist-exec]{@reset} {$name} completed (exit: {$exitCode}).");
            return $exitCode === 0;
        } catch (Throwable $e) {
            $this->log("{@c:red}[dist-exec]{@reset} {$name} failed: " . $e->getMessage());
            return false;
        } finally {
            $this->cleanupDependencyProcesses();
        }
    }

    /**
     * Run the package in 'serve' mode.
     *
     * Bootstraps the Standalone runtime (same as exec), calls packageStart,
     * then delegates to __onPackageServe() which should block until done.
     * The module itself handles serve logic (HTTP server, event loop, etc.).
     *
     * Razy's default serve infrastructure already handles static assets.
     * PackageTrait's __onPackageServe() is where the module sets up
     * application-level serve routing and listeners.
     *
     * @param string[] $passArgs Arguments forwarded to the package
     *
     * @return bool True on success
     */
    public function runServe(array $passArgs = []): bool
    {
        $name = $this->manifest->getPackageName();
        $version = $this->manifest->getVersion();

        $this->log("{@c:cyan}[serve]{@reset} Bootstrapping {$name}@{$version}...");

        try {
            // Resolve standalone path based on source type
            if ($this->manifest->isDirectory()) {
                $standalonePath = $this->manifest->getSourcePath();
                $this->log("  {@c:cyan}[serve]{@reset} Source: directory ({$standalonePath})");
            } else {
                $extractDir = $this->getExtractDir();
                $this->extractPhar($this->manifest->getSourcePath(), $extractDir);

                $standalonePath = PathUtil::append($extractDir, 'standalone');
                if (!\is_dir($standalonePath)) {
                    $this->log('{@c:red}[serve]{@reset} No standalone/ folder found in phar.');
                    return false;
                }
            }

            // Define package constants for the controller to detect
            $this->definePackageConstants($name, $version, $passArgs);

            // Create Standalone runtime and inject "load"-mode co-modules
            $standalone = new Standalone($standalonePath);
            foreach ($this->loadedModulePaths as $depCode => $depPath) {
                $standalone->loadModule($depPath, $depCode);
                $this->log("  {@c:green}[load]{@reset} Co-module: {$depCode}");
            }

            // Register autoloader
            \spl_autoload_register(function (string $class) use ($standalone): void {
                $standalone->autoload($class);
            });

            // Full module lifecycle: __onInit → __onLoad → __onRequire → __onReady
            $standalone->initialize();

            // Get the main module for package lifecycle hooks
            $mainModule = $standalone->getRegistry()->get('standalone/app');
            if (!$mainModule) {
                $this->log('{@c:red}[serve]{@reset} Main module not found in registry.');
                return false;
            }

            $packageInfo = [
                'package_name' => $name,
                'version' => $version,
                'mode' => 'serve',
                'args' => $passArgs,
            ];

            // Package lifecycle: start → serve (blocking) → stop
            if (!$mainModule->packageStart($packageInfo)) {
                $this->log("{@c:yellow}[serve]{@reset} {$name}: __onPackageStart returned false, aborting.");
                return false;
            }

            $this->log("{@c:green}[serve]{@reset} {$name} starting serve...");
            $mainModule->packageServe($packageInfo);
            $mainModule->packageStop();

            $this->log("{@c:green}[serve]{@reset} {$name} serve completed.");
            return true;
        } catch (Throwable $e) {
            $this->log("{@c:red}[serve]{@reset} {$name} failed: " . $e->getMessage());
            return false;
        } finally {
            $this->cleanupDependencyProcesses();
        }
    }

    /**
     * Run the package in 'serve' mode via a full Distributor.
     *
     * All modules in the dist are loaded through the normal Distributor
     * lifecycle, but routing is NOT triggered. The target module's
     * __onPackageServe() is called and should block until done.
     *
     * @param string $distCode The dist code (folder name under sites/)
     * @param string $targetModuleCode The module_code of the target package module
     * @param string[] $passArgs Arguments to forward to the package
     *
     * @return bool True on success
     */
    public function runDistServe(string $distCode, string $targetModuleCode, array $passArgs = []): bool
    {
        $name = $this->manifest->getPackageName();
        $version = $this->manifest->getVersion();

        $this->log("{@c:cyan}[dist-serve]{@reset} Bootstrapping dist '{$distCode}', target: {$targetModuleCode}...");

        try {
            // Define package constants
            $this->definePackageConstants($name, $version, $passArgs);

            // Create and initialize the Distributor — loads ALL modules
            $distributor = new Distributor($distCode);
            \spl_autoload_register(function (string $class) use ($distributor): void {
                $distributor->autoload($class);
            });
            $distributor->initialize();

            $this->log("{@c:green}[dist-serve]{@reset} Distributor '{$distCode}' initialized — all modules loaded.");

            // Find the target module
            $targetModule = $distributor->getRegistry()->get($targetModuleCode);
            if (!$targetModule) {
                $this->log("{@c:red}[dist-serve]{@reset} Module '{$targetModuleCode}' not found in dist '{$distCode}'.");
                $this->log('  Available modules:');
                foreach ($distributor->getRegistry()->getModules() as $code => $mod) {
                    $this->log("    - {$code}");
                }
                return false;
            }

            if (!$targetModule->hasPackageTrait()) {
                $this->log("{@c:red}[dist-serve]{@reset} Module '{$targetModuleCode}' does not use PackageTrait.");
                return false;
            }

            $packageInfo = [
                'package_name' => $name,
                'version' => $version,
                'mode' => 'serve',
                'dist_code' => $distCode,
                'args' => $passArgs,
            ];

            // Package lifecycle: start → serve (blocking) → stop
            if (!$targetModule->packageStart($packageInfo)) {
                $this->log("{@c:yellow}[dist-serve]{@reset} {$targetModuleCode}: __onPackageStart returned false, aborting.");
                return false;
            }

            $this->log("{@c:green}[dist-serve]{@reset} {$name} starting serve...");
            $targetModule->packageServe($packageInfo);
            $targetModule->packageStop();

            $this->log("{@c:green}[dist-serve]{@reset} {$name} serve completed.");
            return true;
        } catch (Throwable $e) {
            $this->log("{@c:red}[dist-serve]{@reset} {$name} failed: " . $e->getMessage());
            return false;
        } finally {
            $this->cleanupDependencyProcesses();
        }
    }

    /**
     * Set a custom healthcheck timeout (overrides razy.pkg.json).
     *
     * @param int $seconds Timeout in seconds
     */
    public function setHealthcheckTimeout(int $seconds): void
    {
        $this->healthcheckTimeout = \max(1, $seconds);
    }

    /**
     * Get the manifest associated with this runner.
     *
     * @return PackageManifest
     */
    public function getManifest(): PackageManifest
    {
        return $this->manifest;
    }

    // ── Private: Dependency Helpers ───────────────────────────────

    /**
     * Resolve a "complete" dependency — run it to completion, then continue.
     *
     * @param PackageManifest $dep
     * @param string $depName
     *
     * @return bool
     */
    private function resolveDependComplete(PackageManifest $dep, string $depName): bool
    {
        $runner = new self($dep, $this->projectRoot, $this->runtimeDir, $this->pkgDir, $this->logger);
        $runner->installPrerequisites();

        if (!$runner->runExec()) {
            $this->log("  {@c:red}[depend]{@reset} {$depName} failed.");
            return false;
        }

        $this->log("  {@c:green}[depend]{@reset} {$depName} completed.");
        return true;
    }

    /**
     * Resolve a "healthcheck" dependency — spawn it in background, poll until healthy.
     *
     * @param PackageManifest $dep
     * @param string $depName
     *
     * @return bool
     */
    private function resolveDependHealthcheck(PackageManifest $dep, string $depName): bool
    {
        $pid = $this->spawnBackground($dep);
        if ($pid === null) {
            $this->log("  {@c:red}[depend]{@reset} Failed to spawn {$depName} in background.");
            return false;
        }

        $this->dependencyPids[$depName] = $pid;
        $this->log("  {@c:cyan}[depend]{@reset} Spawned {$depName} (PID: {$pid})");

        $hc = $dep->getHealthcheck();
        if (empty($hc['url'])) {
            $this->log("  {@c:yellow}[depend]{@reset} No healthcheck URL for {$depName} — assuming ready.");
            return true;
        }

        // Allow timeout override
        if ($this->healthcheckTimeout !== null) {
            $hc['timeout'] = $this->healthcheckTimeout;
        }

        if (!$this->waitForHealthcheck($depName, $hc)) {
            $this->log("  {@c:red}[depend]{@reset} Healthcheck failed for {$depName}.");
            return false;
        }

        $this->log("  {@c:green}[depend]{@reset} {$depName} is healthy.");
        return true;
    }

    /**
     * Resolve a "load" dependency — extract its phar and collect the
     * standalone/ path for co-module injection.
     *
     * The extracted module is NOT loaded here. Instead, its path is stored
     * in $loadedModulePaths and injected into the Standalone runtime by
     * runExec() or baked into the serve entry point by generateEntryPoint().
     *
     * The dependency's prerequisites are also installed so its autoload
     * is available when the co-module initialises.
     *
     * @param PackageManifest $dep
     * @param string $depName
     *
     * @return bool
     */
    private function resolveDependLoad(PackageManifest $dep, string $depName): bool
    {
        $depVersion = $dep->getVersion();

        if ($dep->isDirectory()) {
            // Directory-based dependency: use the module folder directly
            $depStandalone = $dep->getSourcePath();
            $this->log("  {@c:cyan}[load]{@reset} {$depName}: directory source ({$depStandalone})");
        } else {
            // Phar-based dependency: extract and find standalone/
            $depPharPath = $dep->getSourcePath();
            $depExtractDir = PathUtil::append(
                $this->runtimeDir,
                'pkg',
                \str_replace('/', DIRECTORY_SEPARATOR, $depName),
                $depVersion,
            );
            $this->extractPhar($depPharPath, $depExtractDir);

            $depStandalone = PathUtil::append($depExtractDir, 'standalone');
            if (!\is_dir($depStandalone)) {
                $this->log("  {@c:red}[load]{@reset} No standalone/ folder in {$depName} phar.");
                return false;
            }
        }

        // Install dependency prerequisites
        $depPrereqs = $dep->getPrerequisite();
        if (!empty($depPrereqs)) {
            $depRunner = new self($dep, $this->projectRoot, $this->runtimeDir, $this->pkgDir, $this->logger);
            if (!$depRunner->installPrerequisites()) {
                $this->log("  {@c:red}[load]{@reset} Failed to install prerequisites for {$depName}.");
                return false;
            }
        }

        // Derive module code from module.php inside standalone/ or use package name
        $moduleCode = $depName;
        $modulePhp = PathUtil::append($depStandalone, 'module.php');
        if (\is_file($modulePhp)) {
            $config = require $modulePhp;
            if (\is_array($config) && !empty($config['module_code'])) {
                $moduleCode = $config['module_code'];
            }
        }

        $this->loadedModulePaths[$moduleCode] = $depStandalone;
        $this->log("  {@c:green}[load]{@reset} {$depName} prepared as co-module ({$moduleCode})");

        return true;
    }

    /**
     * Spawn a background server process for a dependency package.
     *
     * @param PackageManifest $dep
     *
     * @return int|null PID or null on failure
     */
    private function spawnBackground(PackageManifest $dep): ?int
    {
        $phpBinary = PHP_BINARY;
        $sourcePath = $dep->getSourcePath();

        // For directory-based packages, run the entry point script directly
        if ($dep->isDirectory()) {
            $entryPoint = PathUtil::append($sourcePath, 'index.php');
            if (!\is_file($entryPoint)) {
                return null;
            }
            $target = $entryPoint;
        } else {
            $target = $sourcePath;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B ' . \escapeshellarg($phpBinary) . ' ' . \escapeshellarg($target) . ' > NUL 2>&1';
            \pclose(\popen($cmd, 'r'));
            \usleep(500_000);

            // Use escapeshellarg for WMIC CommandLine match to prevent injection
            $safeBasename = \preg_replace('/[^a-zA-Z0-9._\-]/', '', \basename($target));
            \exec('wmic process where "CommandLine like \'%' . $safeBasename . '%\'" get ProcessId 2>NUL', $wmicOut);
            foreach ($wmicOut as $line) {
                $line = \trim($line);
                if (\is_numeric($line) && (int) $line > 0) {
                    return (int) $line;
                }
            }

            return null;
        }

        $cmd = 'nohup ' . \escapeshellarg($phpBinary) . ' ' . \escapeshellarg($target) . ' > /dev/null 2>&1 & echo $!';
        $pid = (int) \trim(\shell_exec($cmd) ?? '0');

        return $pid > 0 ? $pid : null;
    }

    /**
     * Poll a healthcheck URL until it returns HTTP 2xx.
     *
     * @param string $name Package name (for logging)
     * @param array $hc {url, interval, timeout, start_period}
     *
     * @return bool
     */
    private function waitForHealthcheck(string $name, array $hc): bool
    {
        $url = $hc['url'];
        $interval = \max(1, (int) ($hc['interval'] ?? 2));
        $timeout = \max(1, (int) ($hc['timeout'] ?? 30));
        $startPeriod = \max(0, (int) ($hc['start_period'] ?? 5));

        if ($startPeriod > 0) {
            $this->log("  {@c:cyan}[health]{@reset} Waiting {$startPeriod}s start period for {$name}...");
            \sleep($startPeriod);
        }

        $elapsed = 0;
        while ($elapsed < $timeout) {
            if ($this->httpCheck($url)) {
                return true;
            }

            $remaining = $timeout - $elapsed;
            $this->log("  {@c:yellow}[health]{@reset} {$name} not ready, retry in {$interval}s ({$remaining}s remaining)");
            \sleep($interval);
            $elapsed += $interval;
        }

        return false;
    }

    /**
     * Check if an HTTP URL returns a 2xx status code.
     *
     * @param string $url
     *
     * @return bool
     */
    private function httpCheck(string $url): bool
    {
        $ctx = \stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $body = @\file_get_contents($url, false, $ctx);
        if ($body === false) {
            return false;
        }

        if (!empty($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (\preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $m)) {
                    $code = (int) $m[1];
                    return $code >= 200 && $code < 300;
                }
            }
        }

        return false;
    }

    // ── Private: Utility ──────────────────────────────────────────

    /**
     * Get the extraction directory for this package version.
     *
     * @return string Absolute path: runtime/pkg/<vendor>/<name>/<version>/
     */
    private function getExtractDir(): string
    {
        $name = \str_replace('/', DIRECTORY_SEPARATOR, $this->manifest->getPackageName());
        $version = $this->manifest->getVersion();

        return PathUtil::append($this->runtimeDir, 'pkg', $name, $version);
    }

    /**
     * Extract a .phar to a directory (skips if already extracted).
     *
     * @param string $pharPath Absolute path to .phar
     * @param string $destDir Extraction target
     */
    private function extractPhar(string $pharPath, string $destDir): void
    {
        $marker = PathUtil::append($destDir, '.extracted');
        if (\is_dir($destDir) && \is_file($marker)) {
            return;
        }

        if (!\is_dir($destDir)) {
            \mkdir($destDir, 0o755, true);
        }

        $this->log('{@c:cyan}[extract]{@reset} Extracting ' . \basename($pharPath) . '...');

        $phar = new Phar($pharPath);
        $phar->extractTo($destDir, null, true);

        \file_put_contents($marker, \date('c'));
    }

    /**
     * Define RAZY_PACKAGE_* constants for the controller to detect.
     *
     * @param string $name Package name
     * @param string $version Package version
     * @param string[] $args Pass-through arguments
     */
    private function definePackageConstants(string $name, string $version, array $args): void
    {
        if (!\defined('RAZY_PACKAGE_MODE')) {
            \define('RAZY_PACKAGE_MODE', true);
        }
        if (!\defined('RAZY_PACKAGE_NAME')) {
            \define('RAZY_PACKAGE_NAME', $name);
        }
        if (!\defined('RAZY_PACKAGE_VERSION')) {
            \define('RAZY_PACKAGE_VERSION', $version);
        }
        if (!\defined('RAZY_PACKAGE_ARGS')) {
            \define('RAZY_PACKAGE_ARGS', $args);
        }
    }

    /**
     * Execute a shell command, returning exit code.
     *
     * @param string $command
     *
     * @return int
     */
    private function shellExec(string $command): int
    {
        $exitCode = 0;
        \passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Locate the Composer executable.
     *
     * @return string The composer command string
     */
    private function findComposer(): string
    {
        // Local composer.phar in project root
        $localPhar = PathUtil::append($this->projectRoot, 'composer.phar');
        if (\is_file($localPhar)) {
            return \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($localPhar);
        }

        // System PATH
        $which = PHP_OS_FAMILY === 'Windows' ? 'where composer 2>NUL' : 'which composer 2>/dev/null';
        $path = \trim(\shell_exec($which) ?? '');
        if ($path !== '') {
            $first = \explode("\n", $path)[0];
            if (\is_file(\trim($first))) {
                return 'composer';
            }
        }

        return 'composer';
    }

    /**
     * Kill all spawned dependency background processes.
     */
    private function cleanupDependencyProcesses(): void
    {
        foreach ($this->dependencyPids as $name => $pid) {
            $this->log("  {@c:yellow}[cleanup]{@reset} Stopping [{$name}] (PID: {$pid})...");
            if (PHP_OS_FAMILY === 'Windows') {
                \exec('taskkill /F /T /PID ' . $pid . ' 2>&1');
            } else {
                \exec('kill ' . $pid . ' 2>&1');
            }
        }
        $this->dependencyPids = [];
    }

    /**
     * Write a log message via the configured logger callback.
     *
     * @param string $message Message with optional {@c:color} markup
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, false);
        }
    }
}
