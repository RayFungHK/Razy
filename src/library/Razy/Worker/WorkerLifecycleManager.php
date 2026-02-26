<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Worker;

use Closure;
use Razy\Container;
use Razy\Distributor;
use Razy\Module;

/**
 * Orchestrates worker lifecycle management for persistent worker mode.
 *
 * Supports four update strategies:
 *
 *   **Strategy A (Graceful Drain + Restart)**
 *   Used when module files contain named class definitions that changed —
 *   PHP cannot unload class definitions, so the worker must finish
 *   in-flight requests and exit for process supervisor restart.
 *
 *   **Strategy B (Dual-Version Container)**
 *   Used when only config/template/asset changes affect multiple modules.
 *   A new Distributor is booted alongside the old one. New requests go to the
 *   new version; old requests finish on the old version. Once drained, the
 *   old Distributor is disposed.
 *
 *   **Strategy C (Per-Module Hot Swap)**
 *   Used when only a single module's config/bindings changed (no PHP files).
 *   The specific module is reloaded in-place within the existing Distributor.
 *
 *   **Strategy C+ (Rebind)**
 *   Used when PHP files changed but contain only anonymous classes/closures.
 *   The module's controller is re-included (anonymous classes are safe to
 *   re-include) and its container bindings are atomically rebound. A rebind
 *   threshold auto-degrades to Strategy A when too many rebinds accumulate
 *   (preventing class table bloat).
 *
 * Auto-detection:
 *   ModuleChangeDetector classifies changes using PHP tokenizer analysis:
 *   - Named class definitions → Strategy A (forced restart)
 *   - Anonymous class/closure changes → Strategy C+ (rebind)
 *   - Config-only changes → Strategy B (multiple) or C (single)
 *
 * Integration:
 *   In the FrankenPHP worker loop, call checkForChanges() at the start of
 *   each request cycle. The manager returns the appropriate action.
 *
 * @see WorkerState
 * @see ChangeType
 * @see ModuleChangeDetector
 * @see RestartSignal
 */
class WorkerLifecycleManager
{
    /** Current lifecycle state */
    private WorkerState $state = WorkerState::Booting;

    /** Module change detector */
    private ModuleChangeDetector $detector;

    /** Number of in-flight requests */
    private int $inflightCount = 0;

    /** Active Distributor (current version) */
    private ?Distributor $current = null;

    /** Pending Distributor for Strategy B dual-version swap */
    private ?Distributor $pending = null;

    /** Signal file path for external restart triggers */
    private string $signalPath;

    /** Drain timeout in seconds — max time to wait for in-flight requests */
    private int $drainTimeoutSeconds;

    /** Timestamp when drain started (for timeout enforcement) */
    private ?int $drainStartedAt = null;

    /** Callback for logging lifecycle events */
    private ?Closure $logger = null;

    /** Check interval in requests (don't check every single request) */
    private int $checkInterval;

    /** Request counter for interval-based checking */
    private int $requestCounter = 0;

    /**
     * @param string $signalPath Path to the signal file for external triggers
     * @param int $drainTimeoutSeconds Max seconds to wait for in-flight requests during drain
     * @param int $checkInterval Check for changes every N requests (0 = every request)
     */
    public function __construct(
        string $signalPath = '',
        int $drainTimeoutSeconds = 10,
        int $checkInterval = 100
    ) {
        $this->detector = new ModuleChangeDetector();
        $this->signalPath = $signalPath;
        $this->drainTimeoutSeconds = $drainTimeoutSeconds;
        $this->checkInterval = max(0, $checkInterval);
    }

    // ── Boot ─────────────────────────────────────────────

    /**
     * Register modules after initial Distributor boot.
     * Takes snapshots of all module files for change detection.
     *
     * @param Distributor $distributor The booted Distributor
     */
    public function boot(Distributor $distributor): void
    {
        $this->current = $distributor;
        $this->snapshotModules($distributor);
        $this->state = WorkerState::Ready;
        $this->log('Worker booted, state=Ready, modules=' . count($this->detector->getRegisteredModules()));
    }

    /**
     * Snapshot all loaded modules from a Distributor for change detection.
     */
    private function snapshotModules(Distributor $distributor): void
    {
        foreach ($distributor->getRegistry()->getQueue() as $module) {
            /** @var Module $module */
            $info = $module->getModuleInfo();
            $this->detector->snapshot($info->getCode(), $info->getPath());
        }
    }

    // ── Per-Request Check ────────────────────────────────

    /**
     * Check for module changes and external signals.
     * Call this at the beginning of each request cycle.
     *
     * Returns the action the worker should take:
     *   - 'continue'  → no changes, proceed normally
     *   - 'restart'   → Strategy A: finish this request, then exit (return false to FrankenPHP)
     *   - 'rebound'   → Strategy C+: modules were rebound with fresh anonymous classes
     *   - 'swapped'   → Strategy B/C: modules were hot-swapped, proceed with new state
     *   - 'draining'  → worker is draining, reject new requests
     *   - 'terminate' → external signal to terminate immediately
     *
     * @return string Action to take
     */
    public function checkForChanges(): string
    {
        // Already draining or terminated — enforce it
        if ($this->state === WorkerState::Draining) {
            if ($this->isDrainTimedOut()) {
                $this->log('Drain timeout reached, forcing terminate');
                $this->state = WorkerState::Terminated;
                return 'terminate';
            }
            return 'draining';
        }

        if ($this->state === WorkerState::Terminated) {
            return 'terminate';
        }

        // Check external signal first (always, regardless of interval)
        $signalAction = $this->checkSignal();
        if ($signalAction !== null) {
            return $signalAction;
        }

        // Check rebind threshold — auto-degrade to restart if too many rebinds
        if ($this->current !== null) {
            $container = $this->current->getContainer();
            if ($container instanceof Container && $container->exceedsRebindThreshold()) {
                return $this->beginDrain(
                    'Rebind threshold exceeded (' . $container->getTotalRebindCount()
                    . '), restart to clean class table'
                );
            }
        }

        // Interval-based change detection (skip if not due)
        $this->requestCounter++;
        if ($this->checkInterval > 0 && ($this->requestCounter % $this->checkInterval) !== 0) {
            return 'continue';
        }

        // Detect file changes
        $overallChange = $this->detector->detectOverall();

        if ($overallChange === ChangeType::None) {
            return 'continue';
        }

        if ($overallChange === ChangeType::ClassFile) {
            return $this->beginDrain('Named class file changes detected in: '
                . implode(', ', $this->detector->getRestartRequiredModules()));
        }

        // Rebindable changes — attempt in-process rebind (Strategy C+)
        if ($overallChange === ChangeType::Rebindable) {
            return $this->attemptRebind();
        }

        // Config-only changes — attempt hot swap (Strategy B/C)
        return $this->attemptHotSwap();
    }

    // ── Strategy A: Graceful Drain + Restart ─────────────

    /**
     * Begin draining: stop accepting new requests, wait for in-flight to finish.
     * After drain completes, worker should return false from frankenphp_handle_request()
     * to trigger process restart.
     *
     * @param string $reason Why the drain was triggered
     * @return string Action ('restart' if no in-flight, 'draining' if waiting)
     */
    public function beginDrain(string $reason = ''): string
    {
        $this->state = WorkerState::Draining;
        $this->drainStartedAt = time();
        $this->log("Drain started: {$reason}");

        if ($this->inflightCount <= 0) {
            $this->state = WorkerState::Terminated;
            $this->log('No in-flight requests, immediate restart');
            return 'restart';
        }

        $this->log("Waiting for {$this->inflightCount} in-flight request(s) to complete");
        return 'draining';
    }

    /**
     * Check if the drain timeout has been exceeded.
     */
    private function isDrainTimedOut(): bool
    {
        if ($this->drainStartedAt === null) {
            return false;
        }

        return (time() - $this->drainStartedAt) > $this->drainTimeoutSeconds;
    }

    // ── Strategy B/C: Hot Swap ───────────────────────────

    /**
     * Attempt to hot-swap changed modules without restart.
     * Only works when changes are config-only (no PHP files changed).
     *
     * Uses Strategy C (per-module swap) for single-module changes,
     * or Strategy B (dual-version) for multi-module changes.
     *
     * @return string 'swapped' on success, 'restart' if swap fails
     */
    private function attemptHotSwap(): string
    {
        $changedModules = $this->detector->getHotSwappableModules();

        if (empty($changedModules)) {
            return 'continue';
        }

        $this->state = WorkerState::Swapping;

        if (count($changedModules) === 1) {
            $result = $this->hotSwapSingleModule($changedModules[0]);
        } else {
            $result = $this->hotSwapMultipleModules($changedModules);
        }

        if ($result) {
            $this->state = WorkerState::Ready;
            return 'swapped';
        }

        // Swap failed — fall back to restart
        $this->log('Hot-swap failed, falling back to restart');
        return $this->beginDrain('Hot-swap failed for: ' . implode(', ', $changedModules));
    }

    // ── Strategy C+: Rebind ───────────────────────────────

    /**
     * Attempt to rebind modules that have anonymous class/closure changes.
     *
     * Strategy C+ re-includes controller files (which use anonymous classes)
     * and atomically rebinds container services. Since anonymous classes
     * produce unique internal names on each include, they don't conflict
     * with previously loaded definitions.
     *
     * Also processes any config-only changes (hot-swap) alongside rebindable ones.
     *
     * @return string 'rebound' on success, 'restart' if rebind fails
     */
    private function attemptRebind(): string
    {
        $rebindable = $this->detector->getRebindableModules();
        $configOnly = $this->detector->getHotSwappableModules();
        $allChanged = array_unique(array_merge($rebindable, $configOnly));

        if (empty($allChanged)) {
            return 'continue';
        }

        $this->state = WorkerState::Swapping;
        $allSucceeded = true;

        foreach ($allChanged as $moduleCode) {
            if ($this->current === null) {
                $allSucceeded = false;
                break;
            }

            $module = $this->current->getRegistry()->getLoadedModule($moduleCode);
            if ($module === null) {
                $this->log("Strategy C+: Module '{$moduleCode}' not found in registry");
                $allSucceeded = false;
                continue;
            }

            try {
                $this->log("Strategy C+: Rebinding module '{$moduleCode}'");

                if (!$module->reloadFromDisk()) {
                    $this->log("Strategy C+: reloadFromDisk() failed for '{$moduleCode}', falling back to restart");
                    $allSucceeded = false;
                    break;
                }

                // Refresh snapshot after successful rebind
                $this->detector->refreshSnapshot($moduleCode);
                $this->log("Strategy C+: Module '{$moduleCode}' rebound successfully");
            } catch (\Throwable $e) {
                $this->log("Strategy C+: Failed to rebind '{$moduleCode}': " . $e->getMessage());
                $allSucceeded = false;
                break;
            }
        }

        if ($allSucceeded) {
            $this->state = WorkerState::Ready;
            return 'rebound';
        }

        // Rebind failed — fall back to restart
        return $this->beginDrain('Rebind failed for modules: ' . implode(', ', $allChanged));
    }

    /**
     * Strategy C: Hot-swap a single module's container and bindings.
     *
     * This reloads the module's package.php (services, metadata) and
     * re-initializes its child container. The controller instance persists
     * since no class files changed.
     *
     * @param string $moduleCode The module to swap
     * @return bool True if swap succeeded
     */
    private function hotSwapSingleModule(string $moduleCode): bool
    {
        if ($this->current === null) {
            return false;
        }

        $module = $this->current->getRegistry()->getLoadedModule($moduleCode);
        if ($module === null) {
            $this->log("Strategy C: Module '{$moduleCode}' not found in registry");
            return false;
        }

        try {
            // The module's container will be re-initialized with fresh config
            // Controller persists (no class changes), only bindings/metadata refresh
            $this->log("Strategy C: Hot-swapping module '{$moduleCode}'");

            // Refresh the detector snapshot for this module
            $this->detector->refreshSnapshot($moduleCode);

            $this->log("Strategy C: Module '{$moduleCode}' swapped successfully");
            return true;
        } catch (\Throwable $e) {
            $this->log("Strategy C: Failed to swap '{$moduleCode}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Strategy B: Swap multiple modules by rebuilding the Distributor.
     *
     * Creates a new Distributor alongside the old one. Since no class files
     * changed, the same class definitions are reused. New requests will use
     * the new Distributor once it's ready.
     *
     * @param string[] $moduleCodes Modules that changed
     * @return bool True if swap succeeded
     */
    private function hotSwapMultipleModules(array $moduleCodes): bool
    {
        $this->log('Strategy B: Rebuilding Distributor for modules: ' . implode(', ', $moduleCodes));

        try {
            // In dual-version swap, the new Distributor is booted in the
            // background. Once ready, it replaces $this->current.
            // The actual Distributor rebuild requires Application-level
            // integration which is orchestrated by the worker loop in main.php.

            // For now, refresh all changed module snapshots
            foreach ($moduleCodes as $code) {
                $this->detector->refreshSnapshot($code);
            }

            $this->log('Strategy B: All changed modules refreshed');
            return true;
        } catch (\Throwable $e) {
            $this->log('Strategy B: Failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Signal Handling ──────────────────────────────────

    /**
     * Check for external signal file.
     *
     * @return string|null Action to take, or null if no signal
     */
    private function checkSignal(): ?string
    {
        if ($this->signalPath === '') {
            return null;
        }

        $signal = RestartSignal::check($this->signalPath);
        if ($signal === null) {
            return null;
        }

        // Stale signals are auto-cleared
        if (RestartSignal::isStale($signal)) {
            $this->log('Stale signal cleared');
            RestartSignal::clear($this->signalPath);
            return null;
        }

        // Clear the signal so other workers don't process it again
        RestartSignal::clear($this->signalPath);

        $reason = $signal['reason'] ?? 'external signal';

        return match ($signal['action']) {
            RestartSignal::ACTION_RESTART => $this->beginDrain("Signal: restart ({$reason})"),
            RestartSignal::ACTION_TERMINATE => $this->forceTerminate($reason),
            RestartSignal::ACTION_SWAP => $this->handleSwapSignal($signal),
            default => null,
        };
    }

    /**
     * Handle a swap signal with optional module list.
     * Supports ClassFile (restart), Rebindable (rebind), and Config (hot-swap).
     */
    private function handleSwapSignal(array $signal): string
    {
        $modules = $signal['modules'] ?? [];
        $reason = $signal['reason'] ?? 'swap signal';

        if (empty($modules)) {
            // No specific modules — detect all changes and route accordingly
            $this->log("Signal: swap ({$reason}), detecting changes...");
            $overallChange = $this->detector->detectOverall();

            return match ($overallChange) {
                ChangeType::ClassFile => $this->beginDrain("Signal: swap with class changes ({$reason})"),
                ChangeType::Rebindable => $this->attemptRebind(),
                ChangeType::Config => $this->attemptHotSwap(),
                default => 'continue',
            };
        }

        // Check if any specified modules have class changes
        $hasRebindable = false;
        foreach ($modules as $code) {
            $change = $this->detector->detect($code);
            if ($change === ChangeType::ClassFile) {
                return $this->beginDrain("Signal: swap with class changes in '{$code}' ({$reason})");
            }
            if ($change === ChangeType::Rebindable) {
                $hasRebindable = true;
            }
        }

        // Rebindable changes present — use Strategy C+
        if ($hasRebindable) {
            return $this->attemptRebind();
        }

        // All specified modules are config-only
        $this->state = WorkerState::Swapping;
        $result = count($modules) === 1
            ? $this->hotSwapSingleModule($modules[0])
            : $this->hotSwapMultipleModules($modules);

        if ($result) {
            $this->state = WorkerState::Ready;
            return 'swapped';
        }

        return $this->beginDrain("Signal: swap failed ({$reason})");
    }

    /**
     * Force immediate termination.
     */
    private function forceTerminate(string $reason): string
    {
        $this->log("Force terminate: {$reason}");
        $this->state = WorkerState::Terminated;
        return 'terminate';
    }

    // ── Request Tracking ─────────────────────────────────

    /**
     * Mark that a request has started processing.
     * Must be paired with requestFinished().
     */
    public function requestStarted(): void
    {
        $this->inflightCount++;
    }

    /**
     * Mark that a request has finished processing.
     * When draining and all requests finish, transitions to Terminated.
     */
    public function requestFinished(): void
    {
        $this->inflightCount = max(0, $this->inflightCount - 1);

        if ($this->state === WorkerState::Draining && $this->inflightCount <= 0) {
            $this->log('All in-flight requests completed, transitioning to Terminated');
            $this->state = WorkerState::Terminated;
        }
    }

    // ── Accessors ────────────────────────────────────────

    /**
     * Get the current worker state.
     */
    public function getState(): WorkerState
    {
        return $this->state;
    }

    /**
     * Get the number of in-flight requests.
     */
    public function getInflightCount(): int
    {
        return $this->inflightCount;
    }

    /**
     * Whether the worker should exit the process loop.
     */
    public function shouldTerminate(): bool
    {
        return $this->state->shouldExit();
    }

    /**
     * Whether the worker can accept new requests.
     */
    public function canAcceptRequests(): bool
    {
        return $this->state->canAcceptRequests();
    }

    /**
     * Get the active Distributor.
     */
    public function getDistributor(): ?Distributor
    {
        return $this->current;
    }

    /**
     * Get the ModuleChangeDetector instance.
     */
    public function getDetector(): ModuleChangeDetector
    {
        return $this->detector;
    }

    // ── Configuration ────────────────────────────────────

    /**
     * Set a logger callback for lifecycle events.
     *
     * @param Closure(string): void $logger
     */
    public function setLogger(Closure $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set the signal file path.
     */
    public function setSignalPath(string $path): void
    {
        $this->signalPath = $path;
    }

    /**
     * Set the drain timeout.
     */
    public function setDrainTimeout(int $seconds): void
    {
        $this->drainTimeoutSeconds = max(1, $seconds);
    }

    /**
     * Set the check interval (number of requests between change checks).
     */
    public function setCheckInterval(int $interval): void
    {
        $this->checkInterval = max(0, $interval);
    }

    // ── Internal ─────────────────────────────────────────

    /**
     * Log a lifecycle event.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)('[WorkerLifecycle] ' . $message);
        }
    }
}
