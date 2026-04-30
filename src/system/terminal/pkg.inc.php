<?php

/**
 * CLI Command: pkg.
 *
 * Runs a packaged standalone application (a "package"). Packages are
 * self-contained .phar archives in the packages/ directory. The manifest
 * (razy.pkg.json) describes the package's name, version, execution mode,
 * dependency orchestration, and Composer prerequisites.
 *
 * For running dist modules, use the -d flag which leverages the full
 * Distributor to load ALL dist modules.
 *
 * Architecture:
 *   - Standalone mode: package is a .phar loaded from the packages/ folder.
 *   - Dist mode (-d): use a full Distributor to load ALL dist modules, then
 *     execute the target module's PackageTrait lifecycle. All cross-module
 *     APIs, events, and handshakes are available.
 *   - Two execution modes: "serve" (long-running, blocking) and "exec" (run-to-completion).
 *   - Dependency orchestration via on_depend: wait for another package to
 *     finish (mode=exec) or wait for its healthcheck to pass (mode=serve).
 *   - Composer prerequisites are auto-installed into ./runtime/autoload/<name>/<version>/.
 *   - Lifecycle: resolve on_depend → run package (PackageTrait events handle start/stop).
 *
 * Usage:
 *   php Razy.phar pkg <package> [args...]         Run a package (standalone mode)
 *   php Razy.phar pkg -d <dist/module> [args...]   Run via Distributor (dist mode)
 *   php Razy.phar pkg list                        List installed packages
 *   php Razy.phar pkg info <package>              Show package details
 *   php Razy.phar pkg stop <package>              Stop a running serve-mode package
 *   php Razy.phar pkg stop --all                  Stop all running packages
 *
 * Options:
 *   -f <path>           Project root (default: current directory)
 *   -d <dist/module>    Run via Distributor (dist mode)
 *   --daemon            Run serve-mode package in background (don't block terminal)
 *   --silent            Alias for --daemon
 *   --pkg-dir <path>    Package directory (default: ./packages/)
 *   --runtime <path>    Runtime directory (default: ./runtime/)
 *   --timeout <sec>     Healthcheck timeout in seconds (default: from razy.pkg.json)
 *   --no-depend         Skip dependency resolution
 *   --dry-run           Show what would be executed without running
 *
 * razy.pkg.json Schema:
 *   {
 *     "package_name": "my-app",
 *     "version": "1.0.0",
 *     "description": "My standalone package",
 *     "mode": "serve|exec",
 *     "strict": false,
 *     "on_depend": [
 *       { "package": "db-cache", "wait": "healthcheck" },
 *       { "package": "migrate", "wait": "complete" }
 *     ],
 *     "healthcheck": {
 *       "url": "http://localhost:8080/health",
 *       "interval": 2,
 *       "timeout": 30,
 *       "start_period": 5
 *     },
 *     "prerequisite": {
 *       "monolog/monolog": "^3.0",
 *       "guzzlehttp/guzzle": "^7.0"
 *     }
 *   }
 *
 * @license MIT
 */

namespace Razy;

use Exception;
use Phar;
use Razy\Package\PackageManifest;
use Razy\Package\PackageRegistry;
use Razy\Package\PackageRunner;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

return function (string ...$args) use (&$parameters) {
    // ── Help display ──────────────────────────────────────────────
    if (empty($args) || isset($parameters['help']) || isset($parameters['-help']) || isset($parameters['h'])) {
        $this->writeLineLogging('{@s:bu}Razy Package Runner', true);
        $this->writeLineLogging('Run packaged standalone applications (.phar or directory)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Usage:{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg <package> [args...]         Run a package (standalone)', true);
        $this->writeLineLogging('  php Razy.phar pkg -d <dist/module> [args...]   Run via Distributor (dist mode)', true);
        $this->writeLineLogging('  php Razy.phar pkg list                        List installed packages', true);
        $this->writeLineLogging('  php Razy.phar pkg info <package>              Show package details', true);
        $this->writeLineLogging('  php Razy.phar pkg install <package>           Install package from repository', true);
        $this->writeLineLogging('  php Razy.phar pkg publish [options]            Publish packages to repository', true);
        $this->writeLineLogging('  php Razy.phar pkg stop <package>              Stop a running serve-mode daemon', true);
        $this->writeLineLogging('  php Razy.phar pkg stop --all                  Stop all running package daemons', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Options:{@reset}', true);
        $this->writeLineLogging('  {@c:green}-f <path>{@reset}           Project root (default: current dir)', true);
        $this->writeLineLogging('  {@c:green}-d <dist/module>{@reset}     Run in dist mode: load all dist modules, exec target', true);
        $this->writeLineLogging('  {@c:green}--daemon{@reset}            Run serve-mode in background', true);
        $this->writeLineLogging('  {@c:green}--silent{@reset}            Alias for --daemon', true);
        $this->writeLineLogging('  {@c:green}--pkg-dir <path>{@reset}    Package directory (default: ./packages/)', true);
        $this->writeLineLogging('  {@c:green}--runtime <path>{@reset}    Runtime directory (default: ./runtime/)', true);
        $this->writeLineLogging('  {@c:green}--timeout <sec>{@reset}     Healthcheck timeout override', true);
        $this->writeLineLogging('  {@c:green}--no-depend{@reset}         Skip dependency resolution', true);
        $this->writeLineLogging('  {@c:green}--dry-run{@reset}           Show execution plan without running', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}Examples:{@reset}', true);
        $this->writeLineLogging('  {@c:cyan}# Run an exec-mode package{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg migrate -- --fresh', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Run a serve-mode package as daemon{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg my-api --daemon', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Run a dist/module package by path{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg ./dist/modules/my-app', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Run via Distributor (all dist modules loaded, target executed){@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg -d mysite/vendor/my-module', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Stop a daemon{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg stop my-api', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Install a package from repository{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg install vendor/dashboard', true);
        $this->writeLineLogging('  php Razy.phar pkg install vendor/migrate@1.0.0', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}# Publish packages to repository{@reset}', true);
        $this->writeLineLogging('  php Razy.phar pkg publish --scan', true);
        $this->writeLineLogging('  php Razy.phar pkg publish --push', true);
        $this->writeLineLogging('  php Razy.phar pkg publish --scan --push', true);

        return;
    }

    // ── Resolve project root ──────────────────────────────────────
    $projectRoot = \defined('RAZY_PATH') ? RAZY_PATH : (\defined('SYSTEM_ROOT') ? SYSTEM_ROOT : \getcwd());
    foreach ($args as $i => $arg) {
        if ('-f' === $arg && isset($args[$i + 1])) {
            $candidate = $args[$i + 1];
            if (!\preg_match('#^([a-z]:)?[/\\\]#i', $candidate)) {
                $candidate = PathUtil::append($projectRoot, $candidate);
            }
            if (\is_dir($candidate)) {
                $projectRoot = \realpath($candidate);
            }
            break;
        }
    }

    // ── Parse flags ───────────────────────────────────────────────
    $daemonMode = isset($parameters['daemon']) || isset($parameters['-daemon'])
                 || isset($parameters['silent']) || isset($parameters['-silent']);
    $noDepend = isset($parameters['no-depend']) || isset($parameters['-no-depend']);
    $dryRun = isset($parameters['dry-run']) || isset($parameters['-dry-run']);
    $stopAll = isset($parameters['all']) || isset($parameters['-all']);

    // Parse --pkg-dir <path>
    $pkgDir = PathUtil::append($projectRoot, 'packages');
    foreach ($args as $i => $arg) {
        if (('--pkg-dir' === $arg || '-pkg-dir' === $arg) && isset($args[$i + 1])) {
            $pkgDir = $args[$i + 1];
            if (!\preg_match('#^([a-z]:)?[/\\\]#i', $pkgDir)) {
                $pkgDir = PathUtil::append($projectRoot, $pkgDir);
            }
            break;
        }
    }

    // Parse --runtime <path>
    $runtimeDir = PathUtil::append($projectRoot, 'runtime');
    foreach ($args as $i => $arg) {
        if (('--runtime' === $arg || '-runtime' === $arg) && isset($args[$i + 1])) {
            $runtimeDir = $args[$i + 1];
            if (!\preg_match('#^([a-z]:)?[/\\\]#i', $runtimeDir)) {
                $runtimeDir = PathUtil::append($projectRoot, $runtimeDir);
            }
            break;
        }
    }

    // Parse --timeout <sec>
    $timeoutOverride = null;
    foreach ($args as $i => $arg) {
        if (('--timeout' === $arg || '-timeout' === $arg) && isset($args[$i + 1])) {
            $timeoutOverride = (int) $args[$i + 1];
            break;
        }
    }

    // Parse -d <dist_code/module_code> (dist mode)
    $distTarget = null;
    foreach ($args as $i => $arg) {
        if ('-d' === $arg && isset($args[$i + 1])) {
            $distTarget = $args[$i + 1];
            break;
        }
    }

    // ── Extract sub-command ───────────────────────────────────────
    // Filter out flag arguments to get positional args
    $positional = [];
    $skipNext = false;
    foreach ($args as $i => $arg) {
        if ($skipNext) {
            $skipNext = false;
            continue;
        }
        // Skip flag-value pairs
        if (\in_array($arg, ['-f', '-d', '--pkg-dir', '-pkg-dir', '--runtime', '-runtime', '--timeout', '-timeout'], true)) {
            $skipNext = true;
            continue;
        }
        // Skip lone flags
        if (\str_starts_with($arg, '-')) {
            continue;
        }
        $positional[] = $arg;
    }

    if (empty($positional)) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} No package name or sub-command specified.', true);
        $this->writeLineLogging('Run {@c:cyan}php Razy.phar pkg --help{@reset} for usage.', true);

        return false;
    }

    $subCommand = $positional[0];

    // ── Sub-command: list ─────────────────────────────────────────
    if ('list' === $subCommand) {
        $this->writeLineLogging('{@s:bu}Installed Packages', true);
        $this->writeLineLogging('', true);

        if (!\is_dir($pkgDir)) {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Package directory not found: ' . $pkgDir, true);

            return false;
        }

        $registry = new PackageRegistry($pkgDir);
        $packages = $registry->scan();

        if (empty($packages)) {
            $this->writeLineLogging('  (no packages found)', true);

            return true;
        }

        $this->writeLineLogging(\sprintf('  {@c:cyan}%-30s %-12s %-8s %-6s %s{@reset}', 'PACKAGE', 'VERSION', 'MODE', 'SOURCE', 'DESCRIPTION'), true);
        $this->writeLineLogging(\str_repeat('─', 90), true);

        foreach ($packages as $manifest) {
            $this->writeLineLogging(\sprintf(
                '  {@c:green}%-30s{@reset} %-12s %-8s %-6s %s',
                $manifest->getPackageName(),
                $manifest->getVersion(),
                $manifest->getMode(),
                $manifest->getSourceType(),
                \mb_strimwidth($manifest->getDescription(), 0, 30, '…'),
            ), true);
        }

        // Show running daemons
        $pidDir = PathUtil::append($runtimeDir, 'pid');
        if (\is_dir($pidDir)) {
            $pidFiles = \glob(PathUtil::append($pidDir, '*.pid'));
            if (!empty($pidFiles)) {
                $this->writeLineLogging('', true);
                $this->writeLineLogging('{@c:cyan}Running Daemons:{@reset}', true);
                foreach ($pidFiles as $pidFile) {
                    $name = \basename($pidFile, '.pid');
                    $pid = (int) \trim(\file_get_contents($pidFile));
                    $alive = isProcessRunning($pid);
                    $status = $alive ? '{@c:green}running{@reset}' : '{@c:red}dead{@reset}';
                    $this->writeLineLogging(\sprintf('  %-30s PID %-8d %s', \str_replace('__', '/', $name), $pid, $status), true);

                    // Clean up dead PID files
                    if (!$alive) {
                        @\unlink($pidFile);
                    }
                }
            }
        }

        return true;
    }

    // ── Sub-command: info ─────────────────────────────────────────
    if ('info' === $subCommand) {
        if (!isset($positional[1])) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package name required: pkg info <package>', true);

            return false;
        }

        $packageName = $positional[1];

        // Direct path detection: if argument is a directory with razy.pkg.json
        $manifest = null;
        $resolvedInfoPath = $packageName;
        if (!\preg_match('#^([a-z]:)?[/\\\]#i', $resolvedInfoPath)) {
            $resolvedInfoPath = PathUtil::append($projectRoot, $resolvedInfoPath);
        }
        if (\is_dir($resolvedInfoPath)) {
            $manifest = PackageManifest::fromDirectory(\realpath($resolvedInfoPath));
        }

        // Fall back to registry lookup
        if (!$manifest) {
            $registry = new PackageRegistry($pkgDir);
            $manifest = $registry->find($packageName);
        }

        if (!$manifest) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package not found: ' . $packageName, true);

            return false;
        }

        $this->writeLineLogging('{@s:bu}Package Information', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}Name:{@reset}         ' . $manifest->getPackageName(), true);
        $this->writeLineLogging('  {@c:cyan}Version:{@reset}      ' . $manifest->getVersion(), true);
        $this->writeLineLogging('  {@c:cyan}Mode:{@reset}         ' . $manifest->getMode(), true);
        $this->writeLineLogging('  {@c:cyan}Strict:{@reset}       ' . ($manifest->isStrict() ? 'yes (localhost only)' : 'no'), true);
        $this->writeLineLogging('  {@c:cyan}Description:{@reset}  ' . $manifest->getDescription(), true);
        $this->writeLineLogging('  {@c:cyan}Source:{@reset}       ' . $manifest->getSourceType() . ' (' . $manifest->getSourcePath() . ')', true);

        $depends = $manifest->getOnDepend();
        if (!empty($depends)) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('  {@c:cyan}Dependencies:{@reset}', true);
            foreach ($depends as $dep) {
                $this->writeLineLogging(\sprintf('    %-30s wait: %s', $dep['package'], $dep['wait']), true);
            }
        }

        $prereqs = $manifest->getPrerequisite();
        if (!empty($prereqs)) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('  {@c:cyan}Prerequisites (Composer):{@reset}', true);
            foreach ($prereqs as $pkg => $ver) {
                $this->writeLineLogging(\sprintf('    %-30s %s', $pkg, $ver), true);
            }
        }

        $healthcheck = $manifest->getHealthcheck();
        if (!empty($healthcheck)) {
            $this->writeLineLogging('', true);
            $this->writeLineLogging('  {@c:cyan}Healthcheck:{@reset}', true);
            $this->writeLineLogging('    URL:          ' . ($healthcheck['url'] ?? '-'), true);
            $this->writeLineLogging('    Interval:     ' . ($healthcheck['interval'] ?? 2) . 's', true);
            $this->writeLineLogging('    Timeout:      ' . ($healthcheck['timeout'] ?? 30) . 's', true);
            $this->writeLineLogging('    Start Period: ' . ($healthcheck['start_period'] ?? 5) . 's', true);
        }

        return true;
    }

    // ── Sub-command: stop ─────────────────────────────────────────
    if ('stop' === $subCommand) {
        $pidDir = PathUtil::append($runtimeDir, 'pid');

        if ($stopAll) {
            // Stop all daemons
            if (!\is_dir($pidDir)) {
                $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No running daemons found.', true);

                return true;
            }

            $pidFiles = \glob(PathUtil::append($pidDir, '*.pid'));
            if (empty($pidFiles)) {
                $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No running daemons found.', true);

                return true;
            }

            $this->writeLineLogging('{@c:cyan}Stopping all package daemons...{@reset}', true);
            foreach ($pidFiles as $pidFile) {
                $name = \str_replace('__', '/', \basename($pidFile, '.pid'));
                $pid = (int) \trim(\file_get_contents($pidFile));
                stopProcess($pid, $name);
                @\unlink($pidFile);
            }

            return true;
        }

        if (!isset($positional[1])) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package name required: pkg stop <package>', true);

            return false;
        }

        $packageName = $positional[1];

        // Sanitize package name: normalize separators and reject traversal sequences
        $packageName = \str_replace('\\', '/', $packageName);
        if (\str_contains($packageName, '..') || \preg_match('#[\x00-\x1f]#', $packageName)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid package name', true);

            return false;
        }

        $pidFile = PathUtil::append($pidDir, \str_replace('/', '__', $packageName) . '.pid');

        if (!\is_file($pidFile)) {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No daemon PID file for: ' . $packageName, true);

            return false;
        }

        $pid = (int) \trim(\file_get_contents($pidFile));
        stopProcess($pid, $packageName);
        @\unlink($pidFile);

        return true;
    }

    // ── Sub-command: install ──────────────────────────────────────
    if ('install' === $subCommand) {
        $this->writeLineLogging('{@s:bu}Package Installer', true);
        $this->writeLineLogging('Install packages from configured repositories', true);
        $this->writeLineLogging('', true);

        if (!isset($positional[1])) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package name required.', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:yellow}Usage:{@reset}', true);
            $this->writeLineLogging('  php Razy.phar pkg install <package> [options]', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:yellow}Arguments:{@reset}', true);
            $this->writeLineLogging('  {@c:green}package{@reset}    Package code from repository (e.g., vendor/package)', true);
            $this->writeLineLogging('                 Append @version for a specific version: vendor/package@1.0.0', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:yellow}Options:{@reset}', true);
            $this->writeLineLogging('  {@c:green}-y, --yes{@reset}         Auto-confirm installation prompt', true);
            $this->writeLineLogging('  {@c:green}--force{@reset}           Overwrite existing package', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:yellow}Examples:{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar pkg install vendor/dashboard{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar pkg install vendor/migrate@2.0.0{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar pkg install vendor/dashboard -y{@reset}', true);

            return false;
        }

        $installTarget = $positional[1];
        $autoConfirm = isset($parameters['yes']) || isset($parameters['-yes']) || isset($parameters['y']);
        $forceInstall = isset($parameters['force']) || isset($parameters['-force']);

        // Parse package code and optional version from vendor/package@version
        $pkgCode = $installTarget;
        $requestedVersion = null;

        if (\str_contains($installTarget, '@')) {
            [$pkgCode, $requestedVersion] = \explode('@', $installTarget, 2);
        }

        // Load repository configuration
        $repositoryConfig = (\defined('SYSTEM_ROOT') ? SYSTEM_ROOT : $projectRoot) . '/repository.inc.php';
        if (!\is_file($repositoryConfig)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} No repository.inc.php found.', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Create repository.inc.php in your project root:', true);
            $this->writeLineLogging('  {@c:cyan}<?php{@reset}', true);
            $this->writeLineLogging('  {@c:cyan}return [{@reset}', true);
            $this->writeLineLogging("  {@c:cyan}    'https://github.com/username/repo/' => 'main',{@reset}", true);
            $this->writeLineLogging('  {@c:cyan}];{@reset}', true);

            return false;
        }

        $repositories = include $repositoryConfig;
        if (!\is_array($repositories) || empty($repositories)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} No repositories configured in repository.inc.php.', true);

            return false;
        }

        // Look up the target in configured repositories
        $this->writeLineLogging('[{@c:yellow}SEARCH{@reset}] Looking for: {@c:cyan}' . $pkgCode . '{@reset}', true);

        $repoManager = new RepositoryManager($repositories);
        $pkgInfo = $repoManager->getModuleInfo($pkgCode);

        if (!$pkgInfo) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package "' . $pkgCode . '" not found in configured repositories.', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('Use search command to find available packages:', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar search ' . $pkgCode . '{@reset}', true);

            return false;
        }

        // Verify it is a package (type = "package"), not a regular module
        $targetType = $pkgInfo['type'] ?? 'module';
        if ($targetType !== 'package') {
            $this->writeLineLogging('{@c:red}[Error]{@reset} "' . $pkgCode . '" is a {@c:yellow}module{@reset}, not a package.', true);
            $this->writeLineLogging('', true);
            $this->writeLineLogging('To install modules, use:', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar install ' . $pkgCode . ' --from-repo{@reset}', true);

            return false;
        }

        $this->writeLineLogging('[{@c:green}✓{@reset}] Found package: {@c:cyan}' . $pkgCode . '{@reset}', true);

        if (!empty($pkgInfo['description'])) {
            $this->writeLineLogging('    Description: ' . $pkgInfo['description'], true);
        }
        if (!empty($pkgInfo['author'])) {
            $this->writeLineLogging('    Author: {@c:blue}' . $pkgInfo['author'] . '{@reset}', true);
        }

        // Determine version to download
        if (!$requestedVersion) {
            $requestedVersion = $pkgInfo['latest'] ?? null;
        }

        if (!$requestedVersion) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} No version available for package.', true);

            return false;
        }

        // Validate version exists
        $availableVersions = $pkgInfo['versions'] ?? [];
        if (!empty($availableVersions) && !\in_array($requestedVersion, $availableVersions, true)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Version "' . $requestedVersion . '" not found.', true);
            $this->writeLineLogging('  Available: ' . \implode(', ', $availableVersions), true);

            return false;
        }

        $this->writeLineLogging('    Versions: ' . \implode(', ', \array_slice($availableVersions, 0, 5)) . (\count($availableVersions) > 5 ? '...' : ''), true);
        $this->writeLineLogging('[{@c:green}✓{@reset}] Selected version: {@c:cyan}' . $requestedVersion . '{@reset}', true);
        $this->writeLineLogging('', true);

        // Check if package already exists locally
        $localPharPath = PathUtil::append($pkgDir, $pkgCode, $requestedVersion . '.phar');
        $localDirPath = PathUtil::append($pkgDir, $pkgCode);
        $alreadyExists = \is_file($localPharPath);

        // Also check for directory package with matching version
        if (!$alreadyExists && \is_dir($localDirPath)) {
            $localPkgJson = PathUtil::append($localDirPath, 'razy.pkg.json');
            if (\is_file($localPkgJson)) {
                $localManifest = \json_decode(\file_get_contents($localPkgJson), true);
                if (\is_array($localManifest) && ($localManifest['version'] ?? '') === $requestedVersion) {
                    $alreadyExists = true;
                }
            }
        }

        if ($alreadyExists && !$forceInstall) {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} Package {@c:cyan}' . $pkgCode . '@' . $requestedVersion . '{@reset} is already installed.', true);
            $this->writeLineLogging('  Use {@c:green}--force{@reset} to reinstall.', true);

            return true;
        }

        // Confirm installation
        if (!$autoConfirm) {
            $this->writeLineLogging('Install {@c:cyan}' . $pkgCode . '@' . $requestedVersion . '{@reset} to packages/? (y/N): ', false);
            $handle = \fopen('php://stdin', 'r');
            $response = \strtolower(\trim(\fgets($handle)));
            \fclose($handle);

            if ($response !== 'y' && $response !== 'yes') {
                $this->writeLineLogging('{@c:yellow}Installation cancelled.{@reset}', true);

                return false;
            }
            $this->writeLineLogging('', true);
        }

        // Resolve download URL
        $downloadUrl = $repoManager->getDownloadUrl($pkgCode, $requestedVersion);
        if (!$downloadUrl) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Could not determine download URL for ' . $pkgCode . '@' . $requestedVersion, true);

            return false;
        }

        $this->writeLineLogging('[{@c:yellow}DOWNLOAD{@reset}] ' . $downloadUrl, true);

        // Download the .phar file
        $ch = \curl_init($downloadUrl);
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        \curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        \curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        \curl_setopt($ch, CURLOPT_USERAGENT, 'Razy-PackageInstaller');

        $pharContent = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $downloadSize = \curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        \curl_close($ch);

        if ($httpCode !== 200 || $pharContent === false) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Download failed (HTTP ' . $httpCode . ')', true);

            return false;
        }

        $sizeKB = \round($downloadSize / 1024, 2);
        $this->writeLineLogging('[{@c:green}✓{@reset}] Downloaded ({@c:green}' . $sizeKB . ' KB{@reset})', true);

        // Save the .phar to packages/<vendor>/<module>/<version>.phar
        $targetDir = PathUtil::append($pkgDir, $pkgCode);
        if (!\is_dir($targetDir)) {
            \mkdir($targetDir, 0755, true);
        }

        $targetPharPath = PathUtil::append($targetDir, $requestedVersion . '.phar');
        \file_put_contents($targetPharPath, $pharContent);

        $this->writeLineLogging('[{@c:green}✓{@reset}] Saved to: {@c:cyan}' . $targetPharPath . '{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:green}[SUCCESS] Package installed!{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  Package:  {@c:cyan}' . $pkgCode . '@' . $requestedVersion . '{@reset}', true);
        $this->writeLineLogging('  Location: {@c:cyan}' . $targetPharPath . '{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Run with:', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar pkg ' . $pkgCode . '{@reset}', true);

        return true;
    }

    // ── Sub-command: publish ──────────────────────────────────────
    if ('publish' === $subCommand) {
        $this->writeLineLogging('{@s:bu}Package Publisher', true);
        $this->writeLineLogging('Publish standalone packages to repository', true);
        $this->writeLineLogging('', true);

        // Parse publish-specific flags (check both -flag and --flag variants)
        $verbose = isset($parameters['verbose']) || isset($parameters['-verbose']) || isset($parameters['v']);
        $pushGithub = isset($parameters['push']) || isset($parameters['-push']);
        $scanPkg = isset($parameters['scan']) || isset($parameters['-scan']);
        $forcePush = isset($parameters['force']) || isset($parameters['-force']);
        $cleanupOld = isset($parameters['cleanup']) || isset($parameters['-cleanup']);
        $pubBranch = $parameters['branch'] ?? $parameters['-branch'] ?? 'main';

        // Show usage hint when run with no flags
        if (!$pushGithub && !$scanPkg && !$dryRun) {
            // Default: just generate index (no packing, no push)
        }

        // Ensure helper functions from publish.inc.php are available
        if (!\function_exists('Razy\normalizeVersion')) {
            require_once __DIR__ . '/publish.inc.php';
        }

        if (!\is_dir($pkgDir)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Package directory not found: ' . $pkgDir, true);

            return false;
        }

        // Load GitHub credentials when --push is requested
        $ghToken = null;
        $ghRepo = null;

        if ($pushGithub) {
            $pubConfigPath = PathUtil::append($pkgDir, 'publish.json');
            if (!\is_file($pubConfigPath)) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} publish.json not found in packages directory.', true);
                $this->writeLineLogging('', true);
                $this->writeLineLogging('Create {@c:cyan}' . $pubConfigPath . '{@reset}:', true);
                $this->writeLineLogging('  {@c:cyan}{{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}    "repo": "owner/repo",{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}    "token": "ghp_your_token"{@reset}', true);
                $this->writeLineLogging('  {@c:cyan}}{@reset}', true);

                return false;
            }

            $pubConfig = \json_decode(\file_get_contents($pubConfigPath), true);
            if (!\is_array($pubConfig)) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} publish.json must contain a valid JSON object.', true);

                return false;
            }

            $ghToken = $pubConfig['token'] ?? null;
            $ghRepo = $pubConfig['repo'] ?? null;

            if (!$ghToken || !$ghRepo) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} Missing \'token\' or \'repo\' in publish.json.', true);

                return false;
            }

            $this->writeLineLogging('GitHub: {@c:cyan}' . $ghRepo . '{@reset} (branch: {@c:cyan}' . $pubBranch . '{@reset})', true);
            $this->writeLineLogging('', true);
        }

        // ── --scan: Pack directory packages into .phar ─────────
        if ($scanPkg) {
            if (\ini_get('phar.readonly') == 1) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} phar.readonly is enabled. Use -d phar.readonly=0', true);

                return false;
            }

            $this->writeLineLogging('{@c:cyan}Scanning package directories...{@reset}', true);
            $this->writeLineLogging('', true);
            $packed = 0;

            $topEntries = @\scandir($pkgDir) ?: [];
            foreach ($topEntries as $de) {
                if ($de === '.' || $de === '..') {
                    continue;
                }
                $dp = PathUtil::append($pkgDir, $de);
                if (!\is_dir($dp)) {
                    continue;
                }

                $pkgJsonFile = PathUtil::append($dp, 'razy.pkg.json');
                $modPhpFile = PathUtil::append($dp, 'module.php');

                // Only standalone packages: has razy.pkg.json, no module.php
                if (!\is_file($pkgJsonFile) || \is_file($modPhpFile)) {
                    continue;
                }

                $pkgMeta = \json_decode(\file_get_contents($pkgJsonFile), true);
                if (!\is_array($pkgMeta) || empty($pkgMeta['version'])) {
                    if ($verbose) {
                        $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . $de . ' (invalid razy.pkg.json)', true);
                    }
                    continue;
                }

                $ver = $pkgMeta['version'];
                $pharOut = PathUtil::append($dp, $ver . '.phar');

                if (\is_file($pharOut) && !$forcePush) {
                    if ($verbose) {
                        $this->writeLineLogging('  [{@c:blue}EXISTS{@reset}] ' . $de . ' v' . $ver, true);
                    }
                    continue;
                }

                $this->writeLineLogging('  [{@c:yellow}PACK{@reset}] ' . $de . ' v' . $ver, true);

                if (!$dryRun) {
                    try {
                        @\unlink($pharOut);
                        $pharName = $ver . '.phar';
                        $phar = new Phar($pharOut, 0, $pharName);
                        $phar->startBuffering();

                        $iter = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($dp, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );

                        foreach ($iter as $fi) {
                            $rel = \str_replace('\\', '/', \substr($fi->getPathname(), \strlen($dp) + 1));
                            // Skip generated files, existing .phar archives, and publish.json (credentials)
                            if (\preg_match('#^(manifest\.json|publish\.json|changelog/|.*\.phar)#', $rel)) {
                                continue;
                            }

                            if ($fi->isDir()) {
                                $phar->addEmptyDir($rel);
                            } else {
                                $phar->addFile($fi->getPathname(), $rel);
                            }
                        }

                        $phar->stopBuffering();

                        if (Phar::canCompress(Phar::GZ)) {
                            $phar->compressFiles(Phar::GZ);
                        }

                        $this->writeLineLogging('    [{@c:green}OK{@reset}] ' . $pharName, true);
                        $packed++;
                    } catch (Exception $e) {
                        $this->writeLineLogging('    [{@c:red}FAIL{@reset}] ' . $e->getMessage(), true);
                    }
                } else {
                    $packed++;
                }
            }

            $this->writeLineLogging('', true);
            $this->writeLineLogging('Packed: {@c:green}' . $packed . '{@reset} package(s)', true);
            $this->writeLineLogging('', true);
        }

        // ── Build manifest.json + index for packages ──────────
        $this->writeLineLogging('Scanning: {@c:cyan}' . $pkgDir . '{@reset}', true);
        if ($dryRun) {
            $this->writeLineLogging('{@c:yellow}[DRY RUN] No files will be modified{@reset}', true);
        }
        $this->writeLineLogging('', true);

        // Read existing index (preserve module entries)
        $idxPath = PathUtil::append($pkgDir, 'index.json');
        $index = [];
        if (\is_file($idxPath)) {
            $index = \json_decode(\file_get_contents($idxPath), true) ?? [];
        }

        $pkgCount = 0;
        $verCount = 0;
        $newReleases = [];

        // Fetch existing GitHub tags if pushing
        $remoteTags = [];
        if ($pushGithub && !$dryRun) {
            $this->writeLineLogging('[{@c:yellow}CHECK{@reset}] Fetching existing tags...', true);
            $remoteTags = \Razy\githubGetTags($ghToken, $ghRepo);
            $this->writeLineLogging('', true);
        }

        // Collect candidate directories (level-0 and level-1)
        $candidates = [];
        $topEntries = @\scandir($pkgDir) ?: [];
        foreach ($topEntries as $de) {
            if ($de === '.' || $de === '..') {
                continue;
            }
            $dp = PathUtil::append($pkgDir, $de);
            if (!\is_dir($dp)) {
                continue;
            }

            // Level 0: packages/<name>/
            if (!empty(\glob(PathUtil::append($dp, '*.phar')))) {
                $candidates[] = ['path' => $dp, 'code' => $de];
            }

            // Level 1: packages/<vendor>/<name>/
            $subEntries = @\scandir($dp) ?: [];
            foreach ($subEntries as $se) {
                if ($se === '.' || $se === '..') {
                    continue;
                }
                $sp = PathUtil::append($dp, $se);
                if (!\is_dir($sp)) {
                    continue;
                }
                if (!empty(\glob(PathUtil::append($sp, '*.phar')))) {
                    $candidates[] = ['path' => $sp, 'code' => $de . '/' . $se];
                }
            }
        }

        // Process each candidate: verify package type, build manifest
        $processedCodes = [];
        foreach ($candidates as $cand) {
            $candPath = $cand['path'];
            $candCode = $cand['code'];

            // Read or create manifest.json
            $mfPath = PathUtil::append($candPath, 'manifest.json');
            $manifest = [];
            if (\is_file($mfPath)) {
                $manifest = \json_decode(\file_get_contents($mfPath), true) ?? [];
            }

            // Collect valid .phar versions (packages only)
            $pharFiles = \glob(PathUtil::append($candPath, '*.phar'));
            $validVersions = [];

            foreach ($pharFiles as $pf) {
                $fn = \basename($pf, '.phar');
                $nv = \normalizeVersion($fn);
                if (!$nv) {
                    continue;
                }

                // Verify this .phar is a standalone package
                $pharPrefix = 'phar://' . \str_replace('\\', '/', $pf) . '/';
                $hasPkgJson = \is_file($pharPrefix . 'razy.pkg.json');
                $hasModulePhp = \is_file($pharPrefix . 'module.php');

                if (!$hasPkgJson || $hasModulePhp) {
                    if ($verbose) {
                        $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . $fn . '.phar (not a standalone package)', true);
                    }
                    continue;
                }

                $validVersions[] = $nv;

                // Track new versions for GitHub releases
                if ($pushGithub) {
                    $tagName = \str_replace('/', '-', $candCode) . '-v' . $nv;
                    if (!\in_array($tagName, $remoteTags) || $forcePush) {
                        $clPath = PathUtil::append($candPath, 'changelog', $nv . '.txt');
                        $commitMsg = 'Release ' . $candCode . ' v' . $nv;
                        if (\is_file($clPath)) {
                            $commitMsg = \trim(\file_get_contents($clPath));
                        }
                        $newReleases[] = [
                            'module' => $candCode,
                            'version' => $nv,
                            'tag' => $tagName,
                            'message' => $commitMsg,
                            'pharPath' => \basename($pf),
                            'localPath' => $pf,
                        ];
                    }
                }
            }

            if (empty($validVersions)) {
                if ($verbose) {
                    $this->writeLineLogging('  [{@c:yellow}SKIP{@reset}] ' . $candCode . ' (no package .phar files)', true);
                }
                continue;
            }

            // Sort versions (newest first)
            \usort($validVersions, 'version_compare');
            $validVersions = \array_reverse($validVersions);

            // Read metadata from the latest .phar's razy.pkg.json
            $latestPhar = PathUtil::append($candPath, $validVersions[0] . '.phar');
            $pkgMeta = [];
            $metaPath = 'phar://' . \str_replace('\\', '/', $latestPhar) . '/razy.pkg.json';
            if (\is_file($metaPath)) {
                $pkgMeta = \json_decode(\file_get_contents($metaPath), true) ?? [];
            }

            // Update manifest
            $manifest['package_name'] = $pkgMeta['package_name'] ?? $candCode;
            $manifest['description'] = $manifest['description'] ?? $pkgMeta['description'] ?? '';
            $manifest['author'] = $manifest['author'] ?? '';
            $manifest['type'] = 'package';
            $manifest['versions'] = $validVersions;
            $manifest['latest'] = $validVersions[0];

            if (!$dryRun) {
                \file_put_contents($mfPath, \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            // Update index entry
            $index[$candCode] = [
                'description' => $manifest['description'],
                'author' => $manifest['author'],
                'type' => 'package',
                'latest' => $manifest['latest'],
                'versions' => $manifest['versions'],
            ];

            $processedCodes[] = $candCode;
            $pkgCount++;
            $verCount += \count($validVersions);

            $this->writeLineLogging('[{@c:green}✓{@reset}] ' . $candCode . ' ({@c:cyan}' . \count($validVersions) . ' version(s){@reset})', true);
            if ($verbose) {
                $this->writeLineLogging('    Latest: {@c:green}' . $manifest['latest'] . '{@reset}', true);
                $this->writeLineLogging('    Versions: ' . \implode(', ', $validVersions), true);
            }
        }

        $this->writeLineLogging('', true);

        if ($pkgCount === 0) {
            $this->writeLineLogging('{@c:yellow}[WARN]{@reset} No package entries found.', true);
            $this->writeLineLogging('Use {@c:cyan}pkg publish --scan{@reset} to pack directory packages first.', true);

            return true;
        }

        // Write updated index.json
        if (!$dryRun) {
            \file_put_contents($idxPath, \json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->writeLineLogging('[{@c:green}✓{@reset}] Generated: index.json', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:green}[SUCCESS] Package index published!{@reset}', true);
        $this->writeLineLogging('  Packages: {@c:cyan}' . $pkgCount . '{@reset}', true);
        $this->writeLineLogging('  Versions: {@c:cyan}' . $verCount . '{@reset}', true);
        $this->writeLineLogging('', true);

        // ── --push: Upload to GitHub ──────────────────────────
        if ($pushGithub && !$dryRun) {
            $this->writeLineLogging('{@c:yellow}[PUSH]{@reset} Uploading to GitHub: ' . $ghRepo, true);
            $this->writeLineLogging('', true);

            // Upload index.json
            $putResult = \githubPutFile(
                $ghToken,
                $ghRepo,
                $pubBranch,
                'index.json',
                \json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                'Update repository index (packages)'
            );
            $this->writeLineLogging(($putResult['success'] ? '[{@c:green}✓{@reset}]' : '[{@c:red}✗{@reset}]') . ' index.json', true);

            // Upload manifest.json for each processed package
            foreach ($processedCodes as $code) {
                // Handle both level-0 (name) and level-1 (vendor/name) paths
                $mfLocalPath = PathUtil::append($pkgDir, $code, 'manifest.json');
                if (\is_file($mfLocalPath)) {
                    $mfResult = \githubPutFile(
                        $ghToken,
                        $ghRepo,
                        $pubBranch,
                        $code . '/manifest.json',
                        \file_get_contents($mfLocalPath),
                        'Update ' . $code . ' manifest'
                    );
                    $this->writeLineLogging(($mfResult['success'] ? '[{@c:green}✓{@reset}]' : '[{@c:red}✗{@reset}]') . ' ' . $code . '/manifest.json', true);
                }
            }

            // Create releases for new versions
            if (!empty($newReleases)) {
                $this->writeLineLogging('', true);
                $this->writeLineLogging('[{@c:yellow}RELEASES{@reset}] Creating GitHub Releases...', true);

                $existingReleases = \githubGetReleases($ghToken, $ghRepo);

                foreach ($newReleases as $rel) {
                    $tagName = $rel['tag'];

                    // Create tag
                    $tagResult = \githubCreateTag($ghToken, $ghRepo, $pubBranch, $tagName, $rel['message']);
                    if ($tagResult['success']) {
                        $this->writeLineLogging('[{@c:green}✓{@reset}] Tag: ' . $tagName, true);
                    }

                    // Create or find release
                    $releaseId = null;
                    if (isset($existingReleases[$tagName])) {
                        $releaseId = $existingReleases[$tagName]['id'];
                        if (\in_array($rel['pharPath'], $existingReleases[$tagName]['assets'])) {
                            $this->writeLineLogging('[{@c:cyan}SKIP{@reset}] Release: ' . $tagName . ' (asset exists)', true);
                            continue;
                        }
                    } else {
                        $relName = $rel['module'] . ' v' . $rel['version'];
                        $relResult = \githubCreateRelease($ghToken, $ghRepo, $tagName, $relName, $rel['message']);
                        if ($relResult['success']) {
                            $releaseId = $relResult['release_id'];
                            $this->writeLineLogging('[{@c:green}✓{@reset}] Release: ' . $tagName, true);
                        } else {
                            $this->writeLineLogging('[{@c:red}✗{@reset}] Release: ' . $tagName . ' - ' . ($relResult['error'] ?? ''), true);
                            continue;
                        }
                    }

                    // Upload .phar asset
                    if ($releaseId && \is_file($rel['localPath'])) {
                        $assetResult = \githubUploadReleaseAsset($ghToken, $ghRepo, $releaseId, $rel['localPath'], $rel['pharPath']);
                        $this->writeLineLogging(($assetResult['success'] ? '[{@c:green}✓{@reset}]' : '[{@c:red}✗{@reset}]') . ' Asset: ' . $rel['pharPath'], true);
                    }
                }
            }

            // Cleanup old .phar files from repo if --cleanup
            if ($cleanupOld) {
                $this->writeLineLogging('', true);
                $this->writeLineLogging('[{@c:yellow}CLEANUP{@reset}] Removing old .phar files from repository...', true);

                foreach ($processedCodes as $code) {
                    $codeInfo = $index[$code] ?? null;
                    if (!$codeInfo) {
                        continue;
                    }
                    foreach ($codeInfo['versions'] as $v) {
                        $pharPath = $code . '/' . $v . '.phar';
                        $delResult = \githubDeleteFile($ghToken, $ghRepo, $pubBranch, $pharPath, 'Remove old .phar (moved to Releases)');
                        if ($delResult['success']) {
                            $this->writeLineLogging('[{@c:green}✓{@reset}] Deleted: ' . $pharPath, true);
                        } elseif ($verbose) {
                            $this->writeLineLogging('[{@c:cyan}SKIP{@reset}] ' . $pharPath . ' (not found)', true);
                        }
                    }
                }
            }

            $this->writeLineLogging('', true);
            $this->writeLineLogging('{@c:green}[SUCCESS] Packages published to GitHub!{@reset}', true);
            $this->writeLineLogging('Repository: {@c:cyan}https://github.com/' . $ghRepo . '{@reset}', true);
        } elseif (!$pushGithub) {
            $this->writeLineLogging('To push to GitHub:', true);
            $this->writeLineLogging('  {@c:cyan}php Razy.phar pkg publish --push{@reset}', true);
        }

        return true;
    }

    // ── Run a package ─────────────────────────────────────────────
    // ── Dist mode: -d dist_code/module_code ──────────────────
    if ($distTarget !== null) {
        // Parse dist_code/module_code — first segment is dist, rest is module code
        $slashPos = \strpos($distTarget, '/');
        if ($slashPos === false) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Invalid -d format. Expected: -d dist_code/vendor/module', true);
            $this->writeLineLogging('  Example: -d mysite/vendor/my-module', true);

            return false;
        }

        $distCode = \substr($distTarget, 0, $slashPos);
        $targetModuleCode = \substr($distTarget, $slashPos + 1);

        if (empty($distCode) || empty($targetModuleCode)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Both dist code and module code are required.', true);
            $this->writeLineLogging('  Format: -d dist_code/vendor/module', true);

            return false;
        }

        // Verify the dist folder exists
        $distFolder = PathUtil::append(\defined('SITES_FOLDER') ? SITES_FOLDER : PathUtil::append($projectRoot, 'sites'), $distCode);
        if (!\is_dir($distFolder)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Dist not found: ' . $distCode, true);
            $this->writeLineLogging('  Expected at: ' . $distFolder, true);

            return false;
        }

        // Collect pass-through args
        $passArgs = [];
        $dashDashIdx = \array_search('--', $args, true);
        if ($dashDashIdx !== false) {
            $passArgs = \array_slice($args, $dashDashIdx + 1);
        } else {
            // Positional args after the -d value
            for ($i = 0; $i < \count($positional); $i++) {
                $passArgs[] = $positional[$i];
            }
        }

        $this->writeLineLogging('{@s:bu}Razy Package Runner {@c:cyan}(dist mode){@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}Dist:{@reset}     ' . $distCode, true);
        $this->writeLineLogging('  {@c:cyan}Target:{@reset}   ' . $targetModuleCode, true);

        // We need a manifest for the target module. We'll find it after
        // the Distributor loads to get the module path — but we need
        // the manifest for prerequisites. Try to locate razy.pkg.json
        // from the dist's module source path first.
        $distModulePath = PathUtil::append($distFolder, 'modules');
        $distConfigFile = PathUtil::append($distFolder, 'dist.php');
        if (\is_file($distConfigFile)) {
            $distConfig = require $distConfigFile;
            if (\is_array($distConfig) && !empty($distConfig['module_path'])) {
                $candidatePath = PathUtil::fixPath($distConfig['module_path']);
                if (\is_dir($candidatePath)) {
                    $distModulePath = $candidatePath;
                }
            }
        }

        // Scan the dist's module source for the target's razy.pkg.json
        $manifest = null;
        if (\is_dir($distModulePath)) {
            $entries = @\scandir($distModulePath);
            if (\is_array($entries)) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $candidateDir = PathUtil::append($distModulePath, $entry);
                    if (!\is_dir($candidateDir)) {
                        continue;
                    }
                    $candidateManifest = PackageManifest::fromDirectory($candidateDir);
                    if ($candidateManifest && $candidateManifest->getPackageName() === $targetModuleCode) {
                        $manifest = $candidateManifest;
                        break;
                    }
                    // Also check module.php to match by module_code
                    $modulePhp = PathUtil::append($candidateDir, 'module.php');
                    if (\is_file($modulePhp)) {
                        $modConfig = require $modulePhp;
                        if (\is_array($modConfig) && ($modConfig['module_code'] ?? '') === $targetModuleCode) {
                            $candidateManifest = PackageManifest::fromDirectory($candidateDir);
                            if ($candidateManifest) {
                                $manifest = $candidateManifest;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Create runner (manifest may be null if not found — runner handles prerequisites)
        $runner = new PackageRunner(
            manifest: $manifest ?? PackageManifest::fromArray('', [
                'package_name' => $targetModuleCode,
                'version' => '0.0.0',
                'mode' => 'exec',
            ]),
            projectRoot: $projectRoot,
            runtimeDir: $runtimeDir,
            pkgDir: $pkgDir,
            logger: function (string $msg, bool $raw = false) {
                $this->writeLineLogging($msg, !$raw);
            },
        );

        if ($timeoutOverride !== null) {
            $runner->setHealthcheckTimeout($timeoutOverride);
        }

        // Install prerequisites (if manifest was found)
        if ($manifest) {
            $this->writeLineLogging('  {@c:cyan}Package:{@reset}  ' . $manifest->getPackageName(), true);
            $this->writeLineLogging('  {@c:cyan}Version:{@reset}  ' . $manifest->getVersion(), true);
            $this->writeLineLogging('', true);

            if (!$runner->installPrerequisites()) {
                $this->writeLineLogging('{@c:red}[Error]{@reset} Failed to install prerequisites.', true);

                return false;
            }
        }

        // Run via Distributor (exec or serve based on manifest mode)
        $mode = $manifest ? $manifest->getMode() : 'exec';
        if ($mode === 'serve') {
            $result = $runner->runDistServe($distCode, $targetModuleCode, $passArgs);
        } else {
            $result = $runner->runDistExec($distCode, $targetModuleCode, $passArgs);
        }

        return $result;
    }

    // ── Standalone mode: package name (phar/directory) ─────────
    $packageName = $positional[0] ?? null;

    if ($packageName === null || $packageName === '') {
        $this->writeLineLogging('{@c:red}[Error]{@reset} No package name specified.', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage: {@c:cyan}php Razy.phar pkg <package_name> [args...]{@reset}', true);
        $this->writeLineLogging('Run {@c:cyan}php Razy.phar pkg list{@reset} to see available packages.', true);

        return false;
    }

    // Collect pass-through args (everything after the package name or after --)
    $passArgs = [];
    $foundSep = false;
    for ($i = 1; $i < \count($positional); $i++) {
        $passArgs[] = $positional[$i];
    }
    // Also check for -- separator in original args
    $dashDashIdx = \array_search('--', $args, true);
    if ($dashDashIdx !== false) {
        $passArgs = \array_slice($args, $dashDashIdx + 1);
    }

    // ── Discover the package ──────────────────────────────────────
    // If the package argument is a directory path containing razy.pkg.json,
    // load it directly without registry lookup.
    $manifest = null;
    $resolvedPath = $packageName;
    if (!\preg_match('#^([a-z]:)?[/\\\]#i', $resolvedPath)) {
        $resolvedPath = PathUtil::append($projectRoot, $resolvedPath);
    }
    if (\is_dir($resolvedPath)) {
        $manifest = PackageManifest::fromDirectory(\realpath($resolvedPath));
        if ($manifest) {
            $packageName = $manifest->getPackageName();
        }
    }

    // Also try pkgDir/packageName as a directory package
    if (!$manifest) {
        $pkgDirPath = PathUtil::append($pkgDir, $packageName);
        if (\is_dir($pkgDirPath)) {
            $manifest = PackageManifest::fromDirectory(\realpath($pkgDirPath));
            if ($manifest) {
                $packageName = $manifest->getPackageName();
            }
        }
    }

    // Fall back to registry lookup if direct path didn't work
    if (!$manifest) {
        $registry = new PackageRegistry($pkgDir);
        $manifest = $registry->find($packageName);
    }

    if (!$manifest) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Package not found: ' . $packageName, true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Searched in: ' . $pkgDir, true);
        $this->writeLineLogging('Tip: Use {@c:cyan}-d dist_code/vendor/module{@reset} to run a dist module.', true);
        $this->writeLineLogging('Run {@c:cyan}php Razy.phar pkg list{@reset} to see available packages.', true);

        return false;
    }

    $this->writeLineLogging('{@s:bu}Razy Package Runner', true);
    $this->writeLineLogging('', true);
    $this->writeLineLogging('  {@c:cyan}Package:{@reset}  ' . $manifest->getPackageName(), true);
    $this->writeLineLogging('  {@c:cyan}Version:{@reset}  ' . $manifest->getVersion(), true);
    $this->writeLineLogging('  {@c:cyan}Mode:{@reset}     ' . $manifest->getMode(), true);
    $this->writeLineLogging('  {@c:cyan}Source:{@reset}   ' . $manifest->getSourceType(), true);

    if ($daemonMode && $manifest->getMode() !== 'serve') {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}[WARN]{@reset} --daemon is only applicable to serve-mode packages. Ignoring.', true);
        $daemonMode = false;
    }

    // ── Dry-run display ───────────────────────────────────────────
    if ($dryRun) {
        $this->writeLineLogging('', true);
        $this->writeLineLogging('{@c:yellow}[DRY-RUN]{@reset} Execution plan:', true);

        $prereqs = $manifest->getPrerequisite();
        if (!empty($prereqs)) {
            $autoloadPath = PathUtil::append($runtimeDir, 'autoload', $manifest->getPackageName(), $manifest->getVersion());
            $this->writeLineLogging('  1. Install prerequisites → ' . $autoloadPath, true);
            foreach ($prereqs as $pkg => $ver) {
                $this->writeLineLogging('     - ' . $pkg . ': ' . $ver, true);
            }
        }

        $depends = $manifest->getOnDepend();
        if (!empty($depends) && !$noDepend) {
            $this->writeLineLogging('  2. Resolve dependencies:', true);
            foreach ($depends as $dep) {
                $this->writeLineLogging('     - ' . $dep['package'] . ' (wait: ' . $dep['wait'] . ')', true);
            }
        }

        $this->writeLineLogging('  3. Run package (' . $manifest->getMode() . ' mode)', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  {@c:cyan}Note:{@reset} PackageTrait events handle lifecycle (start/stop/healthcheck).', true);

        return true;
    }

    // ── Create PackageRunner and execute ──────────────────────────
    $runner = new PackageRunner(
        manifest: $manifest,
        projectRoot: $projectRoot,
        runtimeDir: $runtimeDir,
        pkgDir: $pkgDir,
        logger: function (string $msg, bool $raw = false) {
            $this->writeLineLogging($msg, !$raw);
        },
    );

    // Override healthcheck timeout if requested
    if ($timeoutOverride !== null) {
        $runner->setHealthcheckTimeout($timeoutOverride);
    }

    // Step 1: Install prerequisites
    $this->writeLineLogging('', true);
    if (!$runner->installPrerequisites()) {
        $this->writeLineLogging('{@c:red}[Error]{@reset} Failed to install prerequisites.', true);

        return false;
    }

    // Step 2: Resolve dependencies (unless --no-depend)
    if (!$noDepend) {
        $registry = new PackageRegistry($pkgDir);
        if (!$runner->resolveDependencies($registry)) {
            $this->writeLineLogging('{@c:red}[Error]{@reset} Dependency resolution failed.', true);

            return false;
        }
    }

    // Step 3: Run the package
    $this->writeLineLogging('', true);

    // Daemon mode for serve packages: spawn in background, write PID file
    if ($manifest->getMode() === 'serve' && $daemonMode) {
        $pidDir = PathUtil::append($runtimeDir, 'pid');
        if (!\is_dir($pidDir)) {
            \mkdir($pidDir, 0755, true);
        }
        $pidFile = PathUtil::append($pidDir, \str_replace('/', '__', $manifest->getPackageName()) . '.pid');

        // Rebuild the command without --daemon/--silent flags
        $filteredArgs = \array_filter($args, fn ($a) => $a !== '--daemon' && $a !== '--silent');
        $pharPath = Phar::running(false) ?: (\defined('RAZY_PHAR_PATH') ? RAZY_PHAR_PATH : 'Razy.phar');
        $cmd = \escapeshellarg(PHP_BINARY) . ' ' . \escapeshellarg($pharPath) . ' pkg ' . \implode(' ', \array_map('escapeshellarg', $filteredArgs));

        if (PHP_OS_FAMILY === 'Windows') {
            $bgCmd = 'start /B ' . $cmd . ' > NUL 2>&1';
            \pclose(\popen($bgCmd, 'r'));
            \usleep(500_000);

            // Try to capture PID
            \exec('wmic process where "CommandLine like \'%pkg%\'" get ProcessId 2>NUL', $wmicOut);
            $pid = 0;
            foreach (($wmicOut ?? []) as $line) {
                $line = \trim($line);
                if (\is_numeric($line) && (int) $line > 0) {
                    $pid = (int) $line;
                    break;
                }
            }
        } else {
            $bgCmd = 'nohup ' . $cmd . ' > /dev/null 2>&1 & echo $!';
            $pid = (int) \trim(\shell_exec($bgCmd) ?? '0');
        }

        if ($pid > 0) {
            \file_put_contents($pidFile, (string) $pid);
            $this->writeLineLogging("{@c:green}[serve]{@reset} Daemon started (PID: {$pid})", true);
            $this->writeLineLogging('  Stop with: php Razy.phar pkg stop ' . $manifest->getPackageName(), true);
        } else {
            $this->writeLineLogging('{@c:yellow}[serve]{@reset} Daemon started but PID could not be captured.', true);
        }

        return true;
    }

    if ($manifest->getMode() === 'serve') {
        $result = $runner->runServe($passArgs);
    } else {
        $result = $runner->runExec($passArgs);
    }

    return $result;
};

/**
 * Check whether a process with the given PID is still running.
 *
 * @param int $pid Process ID
 *
 * @return bool
 */
function isProcessRunning(int $pid): bool
{
    if ($pid < 1) {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        \exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $output);
        foreach ($output as $line) {
            if (\str_contains($line, (string) $pid)) {
                return true;
            }
        }

        return false;
    }

    // POSIX: signal 0 checks existence without actually sending a signal
    return \posix_kill($pid, 0);
}

/**
 * Stop a process (cross-platform).
 *
 * @param int $pid Process ID
 * @param string $name Human-readable name for logging
 */
function stopProcess(int $pid, string $name): void
{
    if ($pid < 1) {
        return;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        \exec('taskkill /F /T /PID ' . $pid . ' 2>&1', $output, $exitCode);
    } else {
        \exec('kill ' . $pid . ' 2>&1', $output, $exitCode);
    }

    if ($exitCode === 0) {
        echo "[OK] Stopped {$name} (PID {$pid})\n";
    } else {
        echo "[WARN] Could not stop {$name} (PID {$pid}) — may have already exited.\n";
    }
}
