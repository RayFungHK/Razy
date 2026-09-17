<?php

/**
 * CLI Command: compile.
 *
 * COMPILE-ON-DEPLOY (M2) — the deploy-time boot snapshot. Boots the dist
 * once (twice — determinism), captures the module manifest and every
 * declaration table (__onInit's OUTPUT), and writes one opcache-hot
 * artifact that the runtime replays instead of reassembling. FPM sites stop
 * paying full framework assembly per request; worker sites cut thread boot
 * (pod start-up, scale-from-zero, cold first request).
 *
 * Contract (see Razy\Compiler\BootCompiler + the COMPILE-ON-DEPLOY dossier):
 * - dist.php must opt in: 'compiled_boot' => true.
 * - Anything data cannot carry (closure listener/middleware, await(),
 *   closure handler, non-serializable payloads, shadow routes) REFUSES
 *   compilation with every offender named. Nothing is ever silently
 *   dropped; an uncompilable dist keeps today's boot and today's cost.
 * - The artifact self-inactivates on fingerprint/schema/version drift
 *   (fall back to full boot, one error_log line). RAZY_COMPILE_TRUST=1
 *   skips the stat check for operators who prefer pure deploy discipline.
 *
 * Usage:
 *   php Razy.phar compile <dist> [tag]     Compile (or refresh) the artifact
 *   php Razy.phar compile <dist> --status  Artifact state vs current files
 *   php Razy.phar compile <dist> --clear   Remove artifact(s) (instant revert)
 *
 * @license MIT
 */

namespace Razy;

use Razy\Compiler\BootCompiler;
use Razy\Route;
use Throwable;

return function () {
    /** @var list<string> $args */
    $args = \func_get_args();

    $distCode = null;
    $tag = '*';
    $options = [];

    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--')) {
            $parts = \explode('=', \substr($arg, 2), 2);
            $options[$parts[0]] = $parts[1] ?? true;
        } elseif ($distCode === null) {
            $distCode = $arg;
        } else {
            $tag = $arg;
        }
    }

    if ($distCode === null && !isset($options['standalone'])) {
        $this->writeLineLogging('{@s:b}Compile — deploy-time boot snapshot{@reset}', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('Usage:', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar compile <dist> [tag]{@reset}   Compile (or refresh) the artifact', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar compile <dist> --status{@reset} Artifact state (read-only)', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar compile <dist> --clear{@reset}  Remove artifact(s) — instant revert', true);
        $this->writeLineLogging('  {@c:cyan}php Razy.phar compile --standalone=<path>[--clear]{@reset}  Same, for a standalone app folder', true);
        $this->writeLineLogging('', true);
        $this->writeLineLogging('  Opt-in per dist: dist.php \'compiled_boot\' => true (standalone: the artifact\'s presence is the opt-in).', true);
        $this->writeLineLogging('  Recompile after ANY module change (deploy step, like migrate).', true);
        $this->writeLineLogging('', true);
        exit(1);
    }

    // ---- standalone application arm: same laws, folder-keyed artifact ----
    if (isset($options['standalone'])) {
        // The artifact is keyed by the folder path AS RUNTIME SEES IT —
        // Application hands Standalone an absolute path, so resolve here too
        // (a relative './standalone' would otherwise key an artifact nothing
        // ever finds).
        $folder = \realpath((string) $options['standalone']);
        if ($folder === false) {
            $this->writeLineLogging('{@c:red}[REFUSED]{@reset} not a directory: ' . $options['standalone'], true);
            exit(1);
        }
        $folder = \rtrim($folder, '\\/');

        if (isset($options['clear'])) {
            $removed = BootCompiler::clearStandalone($folder);
            $this->writeLineLogging($removed === [] ? "No compiled artifact for {$folder}." : '[removed] ' . $removed[0], true);
            exit(0);
        }

        if (!\is_file($folder . '/module.php') && !\is_file($folder . '/controller/App.php') && !\is_file($folder . '/controller/app.php')) {
            $this->writeLineLogging("{@c:red}[REFUSED]{@reset} {$folder} does not look like a standalone app folder (module.php / controller/App.php missing)", true);
            exit(1);
        }

        try {
            $sa = new Standalone($folder);
            $sa->initialize();
            $dumpA = BootCompiler::dump($sa);

            $second = new Standalone($folder);
            $second->initialize();
            $dumpB = BootCompiler::dump($second);
        } catch (Throwable $e) {
            $this->writeLineLogging("{@c:red}[REFUSED]{@reset} {$e->getMessage()}", true);
            exit(1);
        }

        if ($dumpA !== $dumpB) {
            $this->writeLineLogging('{@c:red}[REFUSED]{@reset} registration is not deterministic — two boots produced different declarations.', true);
            exit(1);
        }

        $artifact = BootCompiler::writeStandalone($sa, $dumpA);

        // Replay self-proof (third boot now finds the artifact and replays):
        $replay = new Standalone($folder);
        $replay->initialize();
        $sig = function (array $rows): array {
            $out = [];
            foreach ($rows as $key => $r) {
                $path = $r['path'];
                $rowCode = $r['module_code'] ?? $r['module']->getModuleInfo()->getCode();
                $out[$key] = $rowCode . '|' . $r['method'] . '|' . $r['route'] . '|'
                    . ($path instanceof Route ? $path->getClosurePath() : (\is_string($path) ? $path : '?'));
            }
            ksort($out);
            return $out;
        };
        if ($sig($sa->getRouter()->getRoutes()) !== $sig($replay->getRouter()->getRoutes())) {
            BootCompiler::clearStandalone($folder);
            $this->writeLineLogging('{@c:red}[REFUSED]{@reset} compiled replay diverged from the legacy boot — artifact discarded.', true);
            exit(1);
        }

        $this->writeLineLogging("{@c:green}[compiled]{@reset} {$folder} (replay verified: " . \count($dumpA['routes']) . ' routes match)', true);
        $this->writeLineLogging("  artifact: {$artifact} (" . \round(\filesize($artifact) / 1024, 1) . ' KiB)', true);
        exit(0);
    }

    if (isset($options['clear'])) {
        $removed = BootCompiler::clear($distCode);
        if ($removed === []) {
            $this->writeLineLogging("No compiled artifacts for '{$distCode}'.", true);
            exit(0);
        }
        foreach ($removed as $f) {
            $this->writeLineLogging("{@c:green}[removed]{@reset} {$f}", true);
        }
        exit(0);
    }

    if (isset($options['status'])) {
        $artifact = BootCompiler::artifactPath($distCode, (string) $tag);
        if (!\is_file($artifact)) {
            $this->writeLineLogging("{@c:yellow}ABSENT{@reset}  no artifact for {$distCode}@{$tag} (runtime uses the full boot)", true);
            exit(0);
        }
        $data = require $artifact;
        $fresh = ($data['fingerprint'] ?? '') === BootCompiler::fingerprint($distCode);
        $verdict = $fresh ? '{@c:green}UP-TO-DATE{@reset}' : '{@c:red}STALE{@reset}      (auto-falls back to full boot until recompiled)';
        $this->writeLineLogging("{$verdict}  {$artifact}", true);
        $this->writeLineLogging("  generated: " . ($data['generated'] ?? '?') . "  modules: " . \count($data['modules'] ?? []) . '  routes: ' . \count($data['routes'] ?? []), true);
        exit($fresh ? 0 : 1);
    }

    // --- Compile: boot twice, compare dumps, refuse loudly, write once ---
    try {
        $distributor = new Distributor($distCode, $tag);
        $distributor->initialize();
        $dumpA = BootCompiler::dump($distributor);
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[REFUSED]{@reset} {$e->getMessage()}", true);
        exit(1);
    }

    // Determinism: a second clean assembly must produce an identical dump.
    // Conditional-by-clock registration would surface here, not in prod.
    try {
        $second = new Distributor($distCode, $tag);
        $second->initialize();
        $dumpB = BootCompiler::dump($second);
    } catch (Throwable $e) {
        $this->writeLineLogging("{@c:red}[REFUSED]{@reset} second boot diverged: {$e->getMessage()}", true);
        exit(1);
    }

    if ($dumpA !== $dumpB) {
        $this->writeLineLogging('{@c:red}[REFUSED]{@reset} registration is not deterministic — two boots produced different declarations.', true);
        $this->writeLineLogging('  __onInit must declare the same things every boot (RZ-009); make any env-dependent branch explicit at deploy.', true);
        exit(1);
    }

    $artifact = BootCompiler::write($distributor, $dumpA);

    // Replay self-proof: boot a THIRD distributor forced through the compiled
    // path and confirm its live route table equals the legacy assembly. A
    // replay that assembles something different is a compiler bug — better
    // caught here, at deploy, than served to traffic. (The dist.php flag is
    // not required for this check; compile drives the compiled boot directly.)
    $replay = new Distributor($distCode, $tag);
    $replay->enableCompiledBoot();
    $replay->initialize();
    $legacyRoutes = $distributor->getRouter()->getRoutes();
    $replayRoutes = $replay->getRouter()->getRoutes();

    $sig = function (array $rows): array {
        $out = [];
        foreach ($rows as $key => $r) {
            $path = $r['path'];
            $out[$key] = $r['module_code'] . '|' . $r['method'] . '|' . $r['route'] . '|'
                . ($path instanceof Route ? $path->getClosurePath() : (\is_string($path) ? $path : '?'));
        }
        ksort($out);
        return $out;
    };

    if ($sig($legacyRoutes) !== $sig($replayRoutes)) {
        BootCompiler::clear($distCode); // never leave a misfiring artifact behind
        $this->writeLineLogging('{@c:red}[REFUSED]{@reset} compiled replay diverged from the legacy boot — artifact discarded.', true);
        $this->writeLineLogging('  legacy routes: ' . \count($legacyRoutes) . '   replay routes: ' . \count($replayRoutes), true);
        exit(1);
    }

    $this->writeLineLogging("{@c:green}[compiled]{@reset} {$distCode}@{$tag} (replay verified: " . \count($replayRoutes) . ' routes match)', true);
    $this->writeLineLogging('  modules: ' . \count($dumpA['modules']) . '   routes: ' . \count($dumpA['routes']) . '   named: ' . \count($dumpA['named']), true);
    $this->writeLineLogging("  artifact: {$artifact} (" . \round(\filesize($artifact) / 1024, 1) . ' KiB)', true);
    $this->writeLineLogging("  opt-in door: dist.php 'compiled_boot' => true (fpm and worker both replay from here)", true);
    exit(0);
};
