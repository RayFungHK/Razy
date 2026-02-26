<?php

/**
 * Tests for WorkerState enum.
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Worker\WorkerState;

#[CoversClass(WorkerState::class)]
class WorkerStateTest extends TestCase
{
    public function testBootingCannotAcceptRequests(): void
    {
        $this->assertFalse(WorkerState::Booting->canAcceptRequests());
    }

    public function testReadyCanAcceptRequests(): void
    {
        $this->assertTrue(WorkerState::Ready->canAcceptRequests());
    }

    public function testSwappingCanAcceptRequests(): void
    {
        $this->assertTrue(WorkerState::Swapping->canAcceptRequests());
    }

    public function testDrainingCannotAcceptRequests(): void
    {
        $this->assertFalse(WorkerState::Draining->canAcceptRequests());
    }

    public function testTerminatedCannotAcceptRequests(): void
    {
        $this->assertFalse(WorkerState::Terminated->canAcceptRequests());
    }

    public function testOnlyTerminatedShouldExit(): void
    {
        $this->assertFalse(WorkerState::Booting->shouldExit());
        $this->assertFalse(WorkerState::Ready->shouldExit());
        $this->assertFalse(WorkerState::Swapping->shouldExit());
        $this->assertFalse(WorkerState::Draining->shouldExit());
        $this->assertTrue(WorkerState::Terminated->shouldExit());
    }

    public function testEnumValues(): void
    {
        $this->assertSame('booting', WorkerState::Booting->value);
        $this->assertSame('ready', WorkerState::Ready->value);
        $this->assertSame('draining', WorkerState::Draining->value);
        $this->assertSame('swapping', WorkerState::Swapping->value);
        $this->assertSame('terminated', WorkerState::Terminated->value);
    }
}
