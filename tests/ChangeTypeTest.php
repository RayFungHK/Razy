<?php

/**
 * Tests for ChangeType enum.
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Worker\ChangeType;

#[CoversClass(ChangeType::class)]
class ChangeTypeTest extends TestCase
{
    public function testNoneDoesNotRequireRestart(): void
    {
        $this->assertFalse(ChangeType::None->requiresRestart());
        $this->assertFalse(ChangeType::None->canHotSwap());
    }

    public function testConfigCanHotSwap(): void
    {
        $this->assertFalse(ChangeType::Config->requiresRestart());
        $this->assertTrue(ChangeType::Config->canHotSwap());
    }

    public function testClassFileRequiresRestart(): void
    {
        $this->assertTrue(ChangeType::ClassFile->requiresRestart());
        $this->assertFalse(ChangeType::ClassFile->canHotSwap());
    }

    public function testEnumValues(): void
    {
        $this->assertSame('none', ChangeType::None->value);
        $this->assertSame('config', ChangeType::Config->value);
        $this->assertSame('class', ChangeType::ClassFile->value);
        $this->assertSame('rebindable', ChangeType::Rebindable->value);
    }

    // ── Rebindable ──────────────────────────────────────

    public function testRebindableDoesNotRequireRestart(): void
    {
        $this->assertFalse(ChangeType::Rebindable->requiresRestart());
    }

    public function testRebindableCannotHotSwap(): void
    {
        $this->assertFalse(ChangeType::Rebindable->canHotSwap());
    }

    public function testRebindableCanRebind(): void
    {
        $this->assertTrue(ChangeType::Rebindable->canRebind());
    }

    // ── canRebind ───────────────────────────────────────

    public function testNoneCannotRebind(): void
    {
        $this->assertFalse(ChangeType::None->canRebind());
    }

    public function testConfigCanRebind(): void
    {
        $this->assertTrue(ChangeType::Config->canRebind());
    }

    public function testClassFileCannotRebind(): void
    {
        $this->assertFalse(ChangeType::ClassFile->canRebind());
    }

    // ── severity ────────────────────────────────────────

    public function testSeverityOrdering(): void
    {
        $this->assertSame(0, ChangeType::None->severity());
        $this->assertSame(1, ChangeType::Config->severity());
        $this->assertSame(2, ChangeType::Rebindable->severity());
        $this->assertSame(3, ChangeType::ClassFile->severity());
    }

    public function testSeverityClassFileIsHighest(): void
    {
        $this->assertGreaterThan(ChangeType::Rebindable->severity(), ChangeType::ClassFile->severity());
        $this->assertGreaterThan(ChangeType::Config->severity(), ChangeType::Rebindable->severity());
        $this->assertGreaterThan(ChangeType::None->severity(), ChangeType::Config->severity());
    }
}
