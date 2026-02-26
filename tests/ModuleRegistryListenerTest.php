<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Distributor\ModuleRegistry;
use Razy\Module;
use Razy\ModuleInfo;

/**
 * Tests for P3: ModuleRegistry centralized listener index.
 */
#[CoversClass(ModuleRegistry::class)]
class ModuleRegistryListenerTest extends TestCase
{
    private ModuleRegistry $registry;

    protected function setUp(): void
    {
        $distributor = new \stdClass();
        $this->registry = new ModuleRegistry($distributor);
    }

    private function createModuleMock(string $code = 'vendor/test'): Module
    {
        $moduleInfo = $this->createMock(ModuleInfo::class);
        $moduleInfo->method('getCode')->willReturn($code);

        $module = $this->createMock(Module::class);
        $module->method('getModuleInfo')->willReturn($moduleInfo);

        return $module;
    }

    // ─── registerListener / getEventListeners ─────────────────

    public function testGetEventListenersEmptyByDefault(): void
    {
        $listeners = $this->registry->getEventListeners('vendor/source', 'onEvent');
        $this->assertSame([], $listeners);
    }

    public function testRegisterListenerAddsToIndex(): void
    {
        $module = $this->createModuleMock('vendor/listener');
        $this->registry->registerListener('vendor/source', 'onEvent', $module);

        $listeners = $this->registry->getEventListeners('vendor/source', 'onEvent');
        $this->assertCount(1, $listeners);
        $this->assertSame($module, $listeners[0]);
    }

    public function testMultipleListenersForSameEvent(): void
    {
        $module1 = $this->createModuleMock('vendor/listener1');
        $module2 = $this->createModuleMock('vendor/listener2');

        $this->registry->registerListener('vendor/source', 'onEvent', $module1);
        $this->registry->registerListener('vendor/source', 'onEvent', $module2);

        $listeners = $this->registry->getEventListeners('vendor/source', 'onEvent');
        $this->assertCount(2, $listeners);
        $this->assertSame($module1, $listeners[0]);
        $this->assertSame($module2, $listeners[1]);
    }

    public function testDifferentEventsHaveSeparateListeners(): void
    {
        $module1 = $this->createModuleMock('vendor/a');
        $module2 = $this->createModuleMock('vendor/b');

        $this->registry->registerListener('vendor/source', 'eventA', $module1);
        $this->registry->registerListener('vendor/source', 'eventB', $module2);

        $listenersA = $this->registry->getEventListeners('vendor/source', 'eventA');
        $listenersB = $this->registry->getEventListeners('vendor/source', 'eventB');

        $this->assertCount(1, $listenersA);
        $this->assertCount(1, $listenersB);
        $this->assertSame($module1, $listenersA[0]);
        $this->assertSame($module2, $listenersB[0]);
    }

    // ─── unregisterModuleListeners ───────────────────────────────

    public function testUnregisterModuleListenersRemovesSpecificModule(): void
    {
        $module1 = $this->createModuleMock('vendor/listener1');
        $module2 = $this->createModuleMock('vendor/listener2');

        $this->registry->registerListener('vendor/source', 'onEvent', $module1);
        $this->registry->registerListener('vendor/source', 'onEvent', $module2);

        $this->registry->unregisterModuleListeners($module1);

        $listeners = $this->registry->getEventListeners('vendor/source', 'onEvent');
        $this->assertCount(1, $listeners);
        $this->assertSame($module2, $listeners[0]);
    }

    public function testUnregisterModuleListenersRemovesFromAllEvents(): void
    {
        $module = $this->createModuleMock('vendor/listener');

        $this->registry->registerListener('vendor/a', 'event1', $module);
        $this->registry->registerListener('vendor/b', 'event2', $module);

        $this->registry->unregisterModuleListeners($module);

        $this->assertSame([], $this->registry->getEventListeners('vendor/a', 'event1'));
        $this->assertSame([], $this->registry->getEventListeners('vendor/b', 'event2'));
    }

    public function testUnregisterNonexistentModuleDoesNothing(): void
    {
        $module1 = $this->createModuleMock('vendor/a');
        $module2 = $this->createModuleMock('vendor/b');

        $this->registry->registerListener('vendor/source', 'onEvent', $module1);
        $this->registry->unregisterModuleListeners($module2);

        $listeners = $this->registry->getEventListeners('vendor/source', 'onEvent');
        $this->assertCount(1, $listeners);
    }

    // ─── clearListenerIndex ──────────────────────────────────────

    public function testClearListenerIndexRemovesAll(): void
    {
        $module1 = $this->createModuleMock('vendor/a');
        $module2 = $this->createModuleMock('vendor/b');

        $this->registry->registerListener('vendor/source', 'event1', $module1);
        $this->registry->registerListener('vendor/source', 'event2', $module2);

        $this->registry->clearListenerIndex();

        $this->assertSame([], $this->registry->getEventListeners('vendor/source', 'event1'));
        $this->assertSame([], $this->registry->getEventListeners('vendor/source', 'event2'));
    }

    public function testClearListenerIndexAllowsReregistration(): void
    {
        $module = $this->createModuleMock('vendor/listener');

        $this->registry->registerListener('vendor/source', 'onEvent', $module);
        $this->registry->clearListenerIndex();

        $this->assertSame([], $this->registry->getEventListeners('vendor/source', 'onEvent'));

        $this->registry->registerListener('vendor/source', 'onEvent', $module);
        $this->assertCount(1, $this->registry->getEventListeners('vendor/source', 'onEvent'));
    }
}
