<?php
/**
 * Tests for WorkerLifecycleManager - orchestrates all three update strategies.
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Worker\ModuleChangeDetector;
use Razy\Worker\RestartSignal;
use Razy\Worker\WorkerLifecycleManager;
use Razy\Worker\WorkerState;

#[CoversClass(WorkerLifecycleManager::class)]
class WorkerLifecycleManagerTest extends TestCase
{
    private WorkerLifecycleManager $manager;
    private string $signalPath;
    private array $logs;

    protected function setUp(): void
    {
        $this->signalPath = sys_get_temp_dir() . '/razy_wlm_test_' . uniqid() . '.json';
        $this->logs = [];
        $this->manager = new WorkerLifecycleManager(
            signalPath: $this->signalPath,
            drainTimeoutSeconds: 5,
            checkInterval: 0  // check every request for testing
        );
        $this->manager->setLogger(function (string $msg) {
            $this->logs[] = $msg;
        });
    }

    protected function tearDown(): void
    {
        if (is_file($this->signalPath)) {
            unlink($this->signalPath);
        }
    }

    // ── Initial State ───────────────────────────────────

    public function testInitialStateIsBooting(): void
    {
        $this->assertSame(WorkerState::Booting, $this->manager->getState());
    }

    public function testBootingCannotAcceptRequests(): void
    {
        $this->assertFalse($this->manager->canAcceptRequests());
    }

    public function testInitialInflightCountIsZero(): void
    {
        $this->assertSame(0, $this->manager->getInflightCount());
    }

    public function testShouldNotTerminateInitially(): void
    {
        $this->assertFalse($this->manager->shouldTerminate());
    }

    // ── Request Tracking ────────────────────────────────

    public function testRequestStartedIncrementsCount(): void
    {
        $this->manager->requestStarted();
        $this->assertSame(1, $this->manager->getInflightCount());

        $this->manager->requestStarted();
        $this->assertSame(2, $this->manager->getInflightCount());
    }

    public function testRequestFinishedDecrementsCount(): void
    {
        $this->manager->requestStarted();
        $this->manager->requestStarted();
        $this->manager->requestFinished();
        $this->assertSame(1, $this->manager->getInflightCount());
    }

    public function testRequestFinishedDoesNotGoBelowZero(): void
    {
        $this->manager->requestFinished();
        $this->assertSame(0, $this->manager->getInflightCount());
    }

    // ── Drain (Strategy A) ──────────────────────────────

    public function testBeginDrainWithNoInflightReturnsRestart(): void
    {
        $result = $this->manager->beginDrain('test reason');
        $this->assertSame('restart', $result);
        $this->assertSame(WorkerState::Terminated, $this->manager->getState());
        $this->assertTrue($this->manager->shouldTerminate());
    }

    public function testBeginDrainWithInflightReturnsDraining(): void
    {
        $this->manager->requestStarted();
        $result = $this->manager->beginDrain('test');
        $this->assertSame('draining', $result);
        $this->assertSame(WorkerState::Draining, $this->manager->getState());
    }

    public function testDrainingTransitionsToTerminatedWhenAllRequestsFinish(): void
    {
        $this->manager->requestStarted();
        $this->manager->beginDrain('test');
        $this->assertSame(WorkerState::Draining, $this->manager->getState());

        $this->manager->requestFinished();
        $this->assertSame(WorkerState::Terminated, $this->manager->getState());
        $this->assertTrue($this->manager->shouldTerminate());
    }

    public function testDrainingWithMultipleRequestsWaitsForAll(): void
    {
        $this->manager->requestStarted();
        $this->manager->requestStarted();
        $this->manager->requestStarted();
        $this->manager->beginDrain('test');

        $this->manager->requestFinished();
        $this->assertSame(WorkerState::Draining, $this->manager->getState());

        $this->manager->requestFinished();
        $this->assertSame(WorkerState::Draining, $this->manager->getState());

        $this->manager->requestFinished();
        $this->assertSame(WorkerState::Terminated, $this->manager->getState());
    }

    // ── Signal Handling ─────────────────────────────────

    public function testCheckChangesReturnsContinueWithNoSignalNoChanges(): void
    {
        $this->simulateBoot();
        $result = $this->manager->checkForChanges();
        $this->assertSame('continue', $result);
    }

    public function testRestartSignalTriggersDrain(): void
    {
        $this->simulateBoot();
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART, reason: 'deploy');

        $result = $this->manager->checkForChanges();
        $this->assertSame('restart', $result);
        $this->assertTrue($this->manager->shouldTerminate());
    }

    public function testTerminateSignalForcesTerminate(): void
    {
        $this->simulateBoot();
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_TERMINATE);

        $result = $this->manager->checkForChanges();
        $this->assertSame('terminate', $result);
        $this->assertTrue($this->manager->shouldTerminate());
    }

    public function testStaleSignalIsIgnored(): void
    {
        $this->simulateBoot();
        file_put_contents($this->signalPath, json_encode([
            'action' => 'restart',
            'timestamp' => time() - 600,
        ]));

        $result = $this->manager->checkForChanges();
        $this->assertSame('continue', $result);
        $this->assertFalse(is_file($this->signalPath));
    }

    public function testSignalClearedAfterProcessing(): void
    {
        $this->simulateBoot();
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART);

        $this->manager->checkForChanges();
        $this->assertFalse(is_file($this->signalPath));
    }

    // ── Accessors ───────────────────────────────────────

    public function testGetDetectorReturnsInstance(): void
    {
        $this->assertInstanceOf(ModuleChangeDetector::class, $this->manager->getDetector());
    }

    public function testGetDistributorReturnsNullBeforeBoot(): void
    {
        $this->assertNull($this->manager->getDistributor());
    }

    // ── Configuration ───────────────────────────────────

    public function testSetSignalPath(): void
    {
        $newPath = sys_get_temp_dir() . '/razy_new_signal_' . uniqid();
        $this->manager->setSignalPath($newPath);
        RestartSignal::send($newPath, RestartSignal::ACTION_TERMINATE);

        $this->simulateBoot();
        $result = $this->manager->checkForChanges();
        $this->assertSame('terminate', $result);

        if (is_file($newPath)) unlink($newPath);
    }

    public function testSetDrainTimeout(): void
    {
        $this->manager->setDrainTimeout(30);
        $this->manager->requestStarted();
        $this->manager->beginDrain('test');
        $this->assertSame(WorkerState::Draining, $this->manager->getState());
    }

    public function testLogsAreRecorded(): void
    {
        $this->manager->beginDrain('test reason');
        $this->assertNotEmpty($this->logs);
        $this->assertStringContainsString('test reason', implode(' ', $this->logs));
    }

    // ── Draining state enforcement ──────────────────────

    public function testCheckForChangesReturnsDrainingWhileDraining(): void
    {
        $this->manager->requestStarted();
        $this->manager->beginDrain('test');

        $result = $this->manager->checkForChanges();
        $this->assertSame('draining', $result);
    }

    public function testCheckForChangesReturnsTerminateWhenTerminated(): void
    {
        $this->manager->beginDrain('test');
        $result = $this->manager->checkForChanges();
        $this->assertSame('terminate', $result);
    }

    // ── canAcceptRequests based on state ─────────────────

    public function testReadyStateCanAcceptRequests(): void
    {
        $this->simulateBoot();
        $this->assertTrue($this->manager->canAcceptRequests());
    }

    public function testDrainingStateCannotAcceptRequests(): void
    {
        $this->manager->requestStarted();
        $this->manager->beginDrain('test');
        $this->assertFalse($this->manager->canAcceptRequests());
    }

    // ── Restart signal with in-flight requests ──────────

    public function testRestartSignalWithInflightRequestsReturnsDraining(): void
    {
        $this->simulateBoot();
        $this->manager->requestStarted();
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_RESTART);

        $result = $this->manager->checkForChanges();
        $this->assertSame('draining', $result);
        $this->assertSame(WorkerState::Draining, $this->manager->getState());
    }

    // ── Swap signal handling ────────────────────────────

    public function testSwapSignalWithNoModulesDetectsChanges(): void
    {
        $this->simulateBoot();
        RestartSignal::send($this->signalPath, RestartSignal::ACTION_SWAP, reason: 'deploy');

        $result = $this->manager->checkForChanges();
        // With no actual modules registered, detectOverall returns None → continue
        $this->assertSame('continue', $result);
    }

    // ── checkForChanges return values ───────────────────

    public function testCheckForChangesReturnValues(): void
    {
        $this->simulateBoot();

        // No changes → continue
        $this->assertSame('continue', $this->manager->checkForChanges());
    }

    // ── Helper ──────────────────────────────────────────

    private function simulateBoot(): void
    {
        $ref = new \ReflectionProperty($this->manager, 'state');
        $ref->setValue($this->manager, WorkerState::Ready);
    }
}
