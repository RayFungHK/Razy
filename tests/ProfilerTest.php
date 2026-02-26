<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Profiler;

/**
 * Tests for Profiler — runtime performance snapshots and checkpoint comparison.
 */
#[CoversClass(Profiler::class)]
class ProfilerTest extends TestCase
{
    // ─── Construction ─────────────────────────────────────

    public function testConstructorCreatesInitSample(): void
    {
        $profiler = new Profiler();
        // After construction, reportTo will fail because no checkpoints exist yet
        $this->expectException(\InvalidArgumentException::class);
        $profiler->reportTo('nonexistent');
    }

    // ─── checkpoint() ─────────────────────────────────────

    public function testCheckpointReturnsFluentSelf(): void
    {
        $profiler = new Profiler();
        $result = $profiler->checkpoint('a');
        $this->assertSame($profiler, $result);
    }

    public function testCheckpointChaining(): void
    {
        $profiler = new Profiler();
        $result = $profiler->checkpoint('a')->checkpoint('b')->checkpoint('c');
        $this->assertSame($profiler, $result);
    }

    public function testCheckpointEmptyLabelThrows(): void
    {
        $profiler = new Profiler();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('label');
        $profiler->checkpoint('');
    }

    public function testCheckpointWhitespaceLabelThrows(): void
    {
        $profiler = new Profiler();
        $this->expectException(\InvalidArgumentException::class);
        $profiler->checkpoint('   ');
    }

    public function testCheckpointDuplicateLabelThrows(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('alpha');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $profiler->checkpoint('alpha');
    }

    // ─── report() — basic delta ───────────────────────────

    public function testReportWithTwoCheckpoints(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('start');
        // Do some work to create a measurable delta
        $dummy = str_repeat('x', 1024);
        $profiler->checkpoint('end');

        $report = $profiler->report();
        $this->assertIsArray($report);
        $this->assertNotEmpty($report);
        // execution_time delta should be non-negative
        $this->assertArrayHasKey('execution_time', $report);
        $this->assertGreaterThanOrEqual(0, $report['execution_time']);
    }

    public function testReportKeys(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('a');
        $profiler->checkpoint('b');

        $report = $profiler->report();
        $expectedKeys = [
            'memory_usage',
            'memory_allocated',
            'output_buffer',
            'user_mode_time',
            'system_mode_time',
            'execution_time',
            'defined_functions',
            'declared_classes',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $report, "Missing key: $key");
        }
    }

    public function testReportNotEnoughCheckpointsThrows(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('only_one');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough checkpoints');
        $profiler->report();
    }

    public function testReportNoCheckpointsThrows(): void
    {
        $profiler = new Profiler();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough checkpoints');
        $profiler->report();
    }

    // ─── report() — compareWithInit ───────────────────────

    public function testReportCompareWithInit(): void
    {
        $profiler = new Profiler();
        // With zero checkpoints, compareWithInit creates a fresh sample as the end
        $report = $profiler->report(true);
        $this->assertIsArray($report);
        $this->assertArrayHasKey('execution_time', $report);
        $this->assertGreaterThanOrEqual(0, $report['execution_time']);
    }

    public function testReportCompareWithInitAndOneCheckpoint(): void
    {
        $profiler = new Profiler();
        $dummy = str_repeat('x', 4096);
        $profiler->checkpoint('mid');

        $report = $profiler->report(true);
        $this->assertIsArray($report);
        $this->assertGreaterThanOrEqual(0, $report['execution_time']);
    }

    // ─── report() — specific labels ──────────────────────

    public function testReportWithSpecificLabels(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('a');
        $profiler->checkpoint('b');
        $profiler->checkpoint('c');

        // Request labeled report between a, b, c in order
        $report = $profiler->report(false, 'a', 'b', 'c');
        $this->assertIsArray($report);
        // Should contain deltas for b (a→b) and c (b→c)
        $this->assertArrayHasKey('b', $report);
        $this->assertArrayHasKey('c', $report);
        $this->assertArrayNotHasKey('a', $report); // 'a' is the starting point, not a delta
    }

    public function testReportWithSpecificLabelsIncludeInit(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('x');
        $profiler->checkpoint('y');

        // Include init as the baseline
        $report = $profiler->report(true, 'x', 'y');
        $this->assertIsArray($report);
        // Should have init→x as 'x' and x→y as 'y'
        $this->assertArrayHasKey('x', $report);
        $this->assertArrayHasKey('y', $report);
    }

    // ─── reportTo() — compare label to init ──────────────

    public function testReportToExistingLabel(): void
    {
        $profiler = new Profiler();
        $dummy = range(1, 1000);
        $profiler->checkpoint('after_work');

        $report = $profiler->reportTo('after_work');
        $this->assertIsArray($report);
        $this->assertArrayHasKey('execution_time', $report);
        $this->assertArrayHasKey('memory_usage', $report);
        $this->assertGreaterThanOrEqual(0, $report['execution_time']);
    }

    public function testReportToNonexistentLabelThrows(): void
    {
        $profiler = new Profiler();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('was not found');
        $profiler->reportTo('missing');
    }

    // ─── Numeric deltas are computed correctly ────────────

    public function testExecutionTimeDeltaIsPositive(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('before');
        usleep(10000); // 10ms
        $profiler->checkpoint('after');

        $report = $profiler->report();
        $this->assertGreaterThan(0, $report['execution_time']);
    }

    // ─── Array deltas (functions/classes) ──────────────────

    public function testNewDeclaredClassesAppearInDelta(): void
    {
        $profiler = new Profiler();
        $profiler->checkpoint('before_class');

        // Create anonymous class — it will be declared between checkpoints
        $anon = new class {};

        $profiler->checkpoint('after_class');

        $report = $profiler->report();
        $this->assertArrayHasKey('declared_classes', $report);
        $this->assertIsArray($report['declared_classes']);
    }

    // ─── Multiple independent profilers ────────────────────

    public function testMultipleProfilersAreIndependent(): void
    {
        $p1 = new Profiler();
        $p2 = new Profiler();

        $p1->checkpoint('p1_a');
        $p2->checkpoint('p2_a');
        $p1->checkpoint('p1_b');
        $p2->checkpoint('p2_b');

        // Each should generate its own report without cross-contamination
        $r1 = $p1->report();
        $r2 = $p2->report();

        $this->assertIsArray($r1);
        $this->assertIsArray($r2);
    }
}
