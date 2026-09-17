<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Compiler;

use Closure;
use FilesystemIterator;
use Razy\Contract\DistributorInterface;
use Razy\Distributor;
use Razy\Exception\ConfigurationException;
use Razy\Route;
use Razy\Standalone;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * COMPILE-ON-DEPLOY — deploy-time boot snapshot (M2).
 *
 * A distributor boot assembles pure data every single time: the module
 * manifest (module.php/package.php), the merged dist config, and — through
 * each module's `__onInit` — the declaration tables (routes, API/bridge
 * commands, bindings, event listeners/observers, module middleware). Under
 * plain php-fpm that assembly is paid PER REQUEST (profiled 2026-09: route
 * churn + FS probing dominate the per-request CPU); under worker mode it is
 * paid per thread at boot (pod start-up, scale-from-zero, cold first
 * request). This class captures the assembly once, at deploy, and replays
 * it verbatim.
 *
 * Contract (the honest trade, same bargain Laravel's route:cache strikes):
 * - `__onInit` stays DECLARATION-PURE — Golden Rule RZ-009 already says
 *   "register in __onInit, act in __onReady/__onRouted/__onEntry". Compiled
 *   boot replays __onInit's OUTPUT and does not re-run it; state setup
 *   belongs in __onLoad/__onRequire, which still run, every boot, live.
 * - Anything a snapshot cannot carry — a Closure listener, a closure
 *   middleware, `await()`-registered runtime callbacks, non-serializable
 *   route payloads — makes the dist REFUSE compilation with the offending
 *   items named. No silent semantic change, ever (Laravel precedent).
 * - Registration determinism is compile-verified: the dist boots twice from
 *   clean state and the dumps must match, else compilation is refused
 *   (conditional-by-clock/env registration must be made explicit).
 * - The artifact self-inactivates: schema id, framework version, and a
 *   stat fingerprint (mtime+size of every .php in the dist + its config
 *   files) are checked at boot; mismatch falls back to the full legacy
 *   boot, loudly once in the error log. The fingerprint AUTO-SHORTENS when
 *   OPcache is on with validate_timestamps off (frozen bytecode makes its
 *   protection target invisible — see fingerprintSkipped()) and is skippable
 *   via RAZY_COMPILE_TRUST=1; every other process pays the full stats.
 *   Deploy discipline
 *   (`php Razy.phar compile <dist>`) stays in the pipeline; staleness can
 *   only cost speed, never correctness.
 *
 * The opt-in door is dist.php: `'compiled_boot' => true`. Absent (default)
 * = today's boot, byte for byte.
 *
 * @license MIT
 */
final class BootCompiler
{
    /** Artifact schema + replay-semantics id; bump on ANY shape change. */
    public const SCHEMA = 'compiled-boot-1';

    /** env RAZY_COMPILE_TRUST=1 skips the stat fingerprint (pure deploy discipline). */
    private const TRUST_ENV = 'RAZY_COMPILE_TRUST';

    private function __construct()
    {
        // static surface only
    }

    /**
     * Does the stat fingerprint apply THIS process? It shortens in exactly
     * two situations, each measured or argued from evidence (EPOCH-2026-09
     * paired runs: the fingerprint's per-request file sweep cost -22% at
     * 60-module scale, while the replay it guards was worth +17%):
     *
     *  1. the operator declares trust: RAZY_COMPILE_TRUST=1;
     *  2. OPcache is genuinely on AND validate_timestamps is off — the
     *     frozen-bytecode case. The fingerprint protects against hot-edited
     *     files arriving at boot; under frozen opcache PHP serves the OLD
     *     bytecode to every boot anyway, so the protection target does not
     *     exist (a hot edit is already invisible), and correct deploys
     *     (recompile inside the rebuild) re-verify it for free. Dev
     *     (opcache off, or vt=1 where hot edits are REAL) keeps the full
     *     stats — there the sweep guards a live threat and throughput is
     *     not the point.
     *
     * @codeCoverageIgnore trivial env/ini reads; the gate's WIRING is pinned
     * in tests (the opcache branch cannot be built in the suite, which runs
     * without opcache and must keep full stats).
     */
    public static function fingerprintSkipped(): bool
    {
        if ('1' === (string) \getenv(self::TRUST_ENV)) {
            return true;
        }

        if (!\function_exists('opcache_get_status')) {
            return false;
        }

        $status = @\opcache_get_status(false);
        // The STATUS shape is flat: 'opcache_enabled' is a top-level key
        // (the nested ['opcache'][...] tree belongs to opcache_get_
        // CONFIGURATION — confusing the two shipped a gate that silently
        // always fell back to full stats; caught by the paired re-run,
        // 2026-09-17. The safe direction, but it cost a measurement round).
        if (!\is_array($status) || !($status['opcache_enabled'] ?? false)) {
            return false;
        }

        return !\filter_var(\ini_get('opcache.validate_timestamps'), \FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Where the artifact lives for a dist@tag (DATA_FOLDER is the writable
     * runtime tree; the compile CLI writes it at deploy, the runtime only
     * ever reads it — opcache makes each boot one hot require).
     * The wildcard tag '*' is filed as '_' (a literal '*' is not a legal
     * filename on Windows-family filesystems).
     */
    public static function artifactPath(string $distCode, string $tag = '*'): string
    {
        return PathUtil::append(DATA_FOLDER, 'compiled', $distCode . '@' . \str_replace('*', '_', $tag) . '.php');
    }

    /**
     * Standalone counterpart: the artifact for a standalone APPLICATION
     * folder. There is no dist.php to carry a flag here, so the opt-in is
     * the artifact's own existence — running `compile --standalone <path>`
     * IS the operator's decision, and deleting the artifact is the revert.
     * Keyed by the folder path (a deploy moves folders, never renames them
     * mid-traffic).
     */
    public static function standaloneArtifactPath(string $folderPath): string
    {
        $normalized = \str_replace('\\', '/', \rtrim($folderPath, '\/'));

        return PathUtil::append(DATA_FOLDER, 'compiled', 'standalone@' . \md5($normalized) . '.php');
    }

    /**
     * Standalone fingerprint: every .php under the application folder
     * (module.php/package.php/controller/actions all live in the ultra-flat
     * tree). config.inc.php deliberately NOT included: runtime config is
     * read LIVE by the boot around the replay — it never fed declarations.
     */
    public static function standaloneFingerprint(string $folderPath): string
    {
        return self::fingerprintFolders([\rtrim($folderPath, '\/')]);
    }

    /**
     * Usable compiled boot for a standalone app? Artifact existence IS the
     * opt-in (see standaloneArtifactPath); the fingerprint check keeps the
     * same fail-toward-full-boot law.
     */
    public static function usableStandalone(Standalone $standalone): ?array
    {
        $folder = $standalone->getFolderPath();
        $artifact = self::standaloneArtifactPath($folder);
        if (!\is_file($artifact)) {
            return null;
        }

        $data = @require $artifact;
        if (!\is_array($data) || ($data['schema'] ?? '') !== self::SCHEMA || ($data['framework'] ?? '') !== self::frameworkVersion()) {
            return null;
        }

        if (!self::fingerprintSkipped()) {
            if (($data['fingerprint'] ?? '') !== self::standaloneFingerprint($folder)) {
                \error_log('[Razy] compiled boot STALE for standalone \'' . $folder . '\' — full boot this process; rerun: php Razy.phar compile --standalone ' . $folder);

                return null;
            }
        }

        return $data;
    }

    /**
     * Write a standalone artifact (deploy-time, CLI context).
     */
    public static function writeStandalone(Standalone $standalone, array $dump): string
    {
        $folder = $standalone->getFolderPath();
        $artifact = self::standaloneArtifactPath($folder);

        $data = [
            'schema' => self::SCHEMA,
            'framework' => self::frameworkVersion(),
            'identity' => 'standalone@' . $folder,
            'fingerprint' => self::standaloneFingerprint($folder),
            'generated' => \date('c'),
        ] + $dump;

        if (!\is_dir($dir = \dirname($artifact))) {
            \mkdir($dir, 0775, true);
        }

        $php = "<?php\n\n/**\n * Razy compiled boot artifact (standalone) — GENERATED by\n * `php Razy.phar compile --standalone`. App: {$folder}\n * Generated: {$data['generated']}  Do not edit; delete to revert to the full boot.\n */\n\nreturn " . \var_export($data, true) . ";\n";

        if (\file_put_contents($artifact, $php, \LOCK_EX) === false) {
            throw new ConfigurationException("Could not write compiled artifact: {$artifact}");
        }

        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($artifact, true);
        }

        return $artifact;
    }

    /**
     * Delete standalone artifacts (all folder keys). For --clear <path>.
     */
    public static function clearStandalone(string $folderPath): array
    {
        $artifact = self::standaloneArtifactPath($folderPath);
        if (\is_file($artifact) && \unlink($artifact)) {
            return [$artifact];
        }

        return [];
    }

    /**
     * Stat fingerprint: mtime+size of every .php under the dist folder and
     * its per-distributor config folder. No file CONTENT is read — a stat
     * per file, realpath-cache warm. Worker mode already trusts exactly
     * this granularity for its in-process distributor cache
     * (getConfigFingerprint), so compiled boot inherits the established
     * trust level rather than inventing a new one.
     */
    public static function fingerprint(string $distCode): string
    {
        return self::fingerprintFolders([
            PathUtil::append(SITES_FOLDER, $distCode),
            PathUtil::append(SYSTEM_ROOT, 'config', $distCode),
        ]);
    }

    /**
     * Is a compiled boot usable RIGHT NOW for this distributor?
     * (opt-in flag set, artifact exists, identity + fingerprint match).
     * Returns the artifact data on success, null for "full boot today".
     */
    public static function usableData(Distributor $distributor): ?array
    {
        $artifact = self::artifactPath($distributor->getCode(), $distributor->getTag());
        if (!\is_file($artifact)) {
            return null;
        }

        $data = @require $artifact; // opcache-hot; legacy path pays worse anyway
        if (!\is_array($data) || ($data['schema'] ?? '') !== self::SCHEMA || ($data['framework'] ?? '') !== self::frameworkVersion()) {
            return null;
        }

        if (!self::fingerprintSkipped()) {
            if (($data['fingerprint'] ?? '') !== self::fingerprint($distributor->getCode())) {
                \error_log('[Razy] compiled boot STALE for \'' . $distributor->getIdentity() . '\' — full boot this process; rerun: php Razy.phar compile ' . $distributor->getCode());

                return null;
            }
        }

        return $data;
    }

    /**
     * Capture every declaration the compiled replay must reproduce.
     * Called at compile time on a fully-initialized distributor.
     *
     * @return array{modules: array, routes: array, named: array, module_middleware: array}
     *
     * @throws ConfigurationException listing EVERY uncompilable item
     */
    public static function dump(DistributorInterface $distributor): array
    {
        $refusals = [];
        $modules = [];

        if ($distributor->getRegistry()->countAwaits() > 0) {
            $refusals[] = 'await() runtime callbacks are registered (compiled boot replays declarations; RZ-009)';
        }

        foreach ($distributor->getRegistry()->getModules() as $code => $module) {
            $decl = $module->dumpDeclarations();

            foreach ($decl['_refusals'] as $what) {
                $refusals[] = "{$code}: {$what}";
            }
            unset($decl['_refusals']);

            // Replay feeds the Module constructor the manifest array the
            // scanner handed it (module.php for multisite, the synthesized
            // array for standalone) — read straight off the live ModuleInfo,
            // so replay reconstructs byte-identical module shells and the
            // ModuleInfo validation re-runs on the same input a legacy boot
            // would have read. (getRawConfig is the single source for both
            // modes; no extra require / no folder-shape assumption.)
            $moduleConfig = $module->getModuleInfo()->getRawConfig();
            try {
                \serialize($moduleConfig);
            } catch (Throwable) {
                // the manifest is meant to be a plain array; anything carrying
                // runtime objects refuses, never truncates.
                $refusals[] = "{$code}: module manifest carries non-serializable values";

                continue;
            }

            $decl['manifest'] = [
                'folder' => $module->getModuleInfo()->getContainerPath(),
                'version' => $module->getModuleInfo()->getVersion(),
                'shared' => $module->getModuleInfo()->isShared(),
                'standalone' => $module->getModuleInfo()->isStandalone(),
                'config' => $moduleConfig,
            ];

            $modules[$code] = $decl;
        }

        // Route table: getRoutes() rows carry the live Module object and
        // sometimes a Route entity — reduce both to data (and REFUSE
        // anything data cannot carry).
        $routes = [];
        foreach ($distributor->getRouter()->getRoutes() as $key => $row) {
            $path = $row['path'];

            if ($path instanceof Route) {
                $spec = self::dumpRoute($path, $key, $refusals);
                if ($spec === null) {
                    continue; // refusal already recorded
                }
                $row['path'] = ['__route' => $spec];
            } elseif ($path instanceof Closure) {
                $refusals[] = "route {$key}: closure handler";
                unset($row);

                continue;
            }

            $row['module'] = $row['module_code'] ?? $row['module']->getModuleInfo()->getCode(); // replay re-links by code (lazy rows carry no module_code column)
            unset($row['target']);
            if (isset($row['is_script'])) {
                // CLI script rows are not an HTTP concern; skip
            }
            $routes[$key] = $row;
        }

        // Shadow routes reference Module objects — refuse for M1 honesty.
        foreach ($distributor->getRouter()->getRoutes() as $key => $row) {
            if (isset($row['target'])) {
                $refusals[] = "shadow route {$key}: cross-module target reference";
            }
        }

        if ($refusals !== []) {
            throw new ConfigurationException(
                "Distributor '{$distributor->getIdentity()}' is not compilable:\n  - "
                . \implode("\n  - ", \array_unique($refusals))
                . "\nCompiled boot replays declarations only (RZ-009 territory)."
            );
        }

        return [
            'modules' => $modules,
            'queue_order' => \array_keys($distributor->getRegistry()->getQueue()),
            'routes' => $routes,
            'named' => $distributor->getRouter()->getNamedRoutes(),
            'module_middleware' => self::dumpModuleMiddleware($distributor, $refusals),
        ];
    }

    /**
     * Write the artifact (deploy-time, CLI context).
     */
    public static function write(Distributor $distributor, array $dump): string
    {
        $artifact = self::artifactPath($distributor->getCode(), $distributor->getTag());

        $data = [
            'schema' => self::SCHEMA,
            'framework' => self::frameworkVersion(),
            'identity' => $distributor->getIdentity(),
            'fingerprint' => self::fingerprint($distributor->getCode()),
            'generated' => \date('c'),
        ] + $dump;

        if (!\is_dir($dir = \dirname($artifact))) {
            \mkdir($dir, 0775, true);
        }

        $php = "<?php\n\n/**\n * Razy compiled boot artifact — GENERATED by `php Razy.phar compile`.\n * Dist: {$data['identity']}  Generated: {$data['generated']}\n * Do not edit; delete (or recompile) to fall back to the full boot.\n */\n\nreturn " . \var_export($data, true) . ";\n";

        if (\file_put_contents($artifact, $php, \LOCK_EX) === false) {
            throw new ConfigurationException("Could not write compiled artifact: {$artifact}");
        }

        if (\function_exists('opcache_invalidate')) {
            \opcache_invalidate($artifact, true);
        }

        return $artifact;
    }

    /**
     * Delete a dist's artifacts (all tags). Returns paths removed.
     */
    public static function clear(string $distCode): array
    {
        $removed = [];
        $dir = PathUtil::append(DATA_FOLDER, 'compiled');
        foreach ((array) \glob(PathUtil::append($dir, $distCode . '@*.php')) as $f) {
            if (\unlink($f)) {
                $removed[] = $f;
            }
        }

        return $removed;
    }

    /**
     * Shared stat fingerprint over a set of folders (see fingerprint()).
     */
    private static function fingerprintFolders(array $dirs): string
    {
        $parts = [self::SCHEMA, self::frameworkVersion()];

        foreach ($dirs as $dir) {
            if (!\is_dir($dir)) {
                $parts[] = $dir . ':!';

                continue;
            }
            $files = [];
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && 'php' === \strtolower($file->getExtension())) {
                    $files[] = $file->getPathname();
                }
            }
            \sort($files);
            foreach ($files as $f) {
                $parts[] = $f . '|' . \filesize($f) . '|' . \filemtime($f);
            }
        }

        return \md5(\implode("\n", $parts));
    }

    /**
     * Framework version for the identity stamp. RAZY_VERSION is defined by
     * bootstrap.inc.php in real runs; tests boot without it (Health.php uses
     * the same guarded read), so fall back to the installed code's release
     * marker rather than fatal on an undefined constant.
     */
    private static function frameworkVersion(): string
    {
        return \defined('RAZY_VERSION') ? RAZY_VERSION : 'unversioned';
    }

    /**
     * @throws ConfigurationException on any middleware closure
     */
    private static function dumpModuleMiddleware(DistributorInterface $distributor, array &$refusals): array
    {
        $out = [];
        foreach (\array_keys($distributor->getRegistry()->getModules()) as $code) {
            foreach ($distributor->getRouter()->getModuleMiddleware($code) as $mw) {
                if ($mw instanceof Closure) {
                    $refusals[] = "{$code}: closure module middleware";

                    continue;
                }
                if (!self::defaultConstructible($mw, $refusals, (string) $code)) {
                    continue;
                }
                $out[$code][] = \get_class($mw);
            }
        }

        return $out;
    }

    /**
     * Replay rebuilds middleware with `new $class()` — a middleware whose
     * constructor demands arguments cannot ride the artifact. Probe it at
     * compile time with pure reflection (no instantiation, zero side
     * effects) so the dist is REFUSED here instead of exploding at replay.
     */
    private static function defaultConstructible(object $mw, array &$refusals, string $where): bool
    {
        $class = \get_class($mw);
        $ctor = (new ReflectionClass($class))->getConstructor();

        if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
            $refusals[] = "{$where}: middleware {$class} requires constructor arguments (replay rebuilds with none)";

            return false;
        }

        return true;
    }

    /**
     * Route entity -> plain spec (constructor path + the fluent metadata
     * dispatch actually reads). Null + a recorded refusal when it carries
     * anything closures can't wear.
     */
    private static function dumpRoute(Route $route, string $key, array &$refusals): ?array
    {
        $spec = [
            'closure_path' => $route->getClosurePath(),
            'method' => $route->getMethod(),
            'name' => $route->getName(),
            'ready_gate' => $route->hasReadyGate() ? $route->getReadyGate() : null,
            'csrf_exempt' => $route->isCsrfExempt() ? $route->getCsrfExemptReason() : null,
            'middleware' => [],
        ];

        foreach ($route->getMiddleware() as $mw) {
            if ($mw instanceof Closure) {
                $refusals[] = "route {$key}: closure middleware";

                return null;
            }
            if (!self::defaultConstructible($mw, $refusals, "route {$key}")) {
                return null;
            }
            $spec['middleware'][] = \get_class($mw);
        }

        $data = $route->getData();
        if ($data !== null) {
            try {
                \serialize($data);
            } catch (Throwable) {
                $refusals[] = "route {$key}: non-serializable contain() payload";

                return null;
            }
            $spec['data'] = $data;
        }

        return $spec;
    }
}
