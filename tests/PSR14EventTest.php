<?php

/**
 * Unit tests for the PSR-14 Event Dispatcher implementation.
 *
 * Covers:
 *   - Razy\Event\StoppableEvent
 *   - Razy\Event\ListenerProvider
 *   - Razy\Event\EventDispatcher
 *
 * This file is part of Razy v0.5.
 */

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\EventDispatcher\ListenerProviderInterface;
use Razy\Contract\EventDispatcher\PsrEventDispatcherInterface;
use Razy\Contract\EventDispatcher\StoppableEventInterface;
use Razy\Event\EventDispatcher;
use Razy\Event\ListenerProvider;
use Razy\Event\StoppableEvent;

// ── Test event hierarchy ──────────────────────────────────

interface PSR14TestEventInterface
{
}

class PSR14SimpleEvent
{
}

class PSR14StoppableTestEvent extends StoppableEvent
{
}

class PSR14ParentEvent
{
}

class PSR14ChildEvent extends PSR14ParentEvent implements PSR14TestEventInterface
{
}

class PSR14InterfaceOnlyEvent implements PSR14TestEventInterface
{
}

class PSR14ModifiableEvent
{
    public array $log = [];
}

class PSR14StoppableModifiableEvent extends StoppableEvent
{
    public array $log = [];
}

// ── PSR-14 Event tests ────────────────────────────────────

#[CoversClass(StoppableEvent::class)]
#[CoversClass(ListenerProvider::class)]
#[CoversClass(EventDispatcher::class)]
class PSR14EventTest extends TestCase
{
    // ─── StoppableEvent ───────────────────────────────────

    public function testStoppableEventNotStoppedByDefault(): void
    {
        $event = new PSR14StoppableTestEvent();
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testStoppableEventStopPropagationWorks(): void
    {
        $event = new PSR14StoppableTestEvent();
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testStoppableEventReturnsTrueAfterStopping(): void
    {
        $event = new PSR14StoppableTestEvent();
        $this->assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testStoppableEventImplementsInterface(): void
    {
        $event = new PSR14StoppableTestEvent();
        $this->assertInstanceOf(StoppableEventInterface::class, $event);
    }

    // ─── ListenerProvider ─────────────────────────────────

    public function testListenerProviderImplementsInterface(): void
    {
        $provider = new ListenerProvider();
        $this->assertInstanceOf(ListenerProviderInterface::class, $provider);
    }

    public function testListenerProviderRegisterAndRetrieve(): void
    {
        $provider = new ListenerProvider();
        $called = false;
        $listener = function (PSR14SimpleEvent $e) use (&$called) {
            $called = true;
        };

        $provider->addListener(PSR14SimpleEvent::class, $listener);

        $event = new PSR14SimpleEvent();
        $listeners = \iterator_to_array($provider->getListenersForEvent($event));

        $this->assertCount(1, $listeners);
        $listeners[0]($event);
        $this->assertTrue($called);
    }

    public function testListenerProviderPriorityOrdering(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(PSR14SimpleEvent::class, function () use (&$order) {
            $order[] = 'low';
        }, 0);

        $provider->addListener(PSR14SimpleEvent::class, function () use (&$order) {
            $order[] = 'high';
        }, 100);

        $provider->addListener(PSR14SimpleEvent::class, function () use (&$order) {
            $order[] = 'medium';
        }, 50);

        $event = new PSR14SimpleEvent();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        $this->assertSame(['high', 'medium', 'low'], $order);
    }

    public function testListenerProviderParentClassTriggered(): void
    {
        $provider = new ListenerProvider();
        $parentCalled = false;
        $provider->addListener(PSR14ParentEvent::class, function () use (&$parentCalled) {
            $parentCalled = true;
        });

        $event = new PSR14ChildEvent();
        $listeners = \iterator_to_array($provider->getListenersForEvent($event));

        $this->assertCount(1, $listeners);
        $listeners[0]($event);
        $this->assertTrue($parentCalled);
    }

    public function testListenerProviderInterfaceTriggered(): void
    {
        $provider = new ListenerProvider();
        $interfaceCalled = false;
        $provider->addListener(PSR14TestEventInterface::class, function () use (&$interfaceCalled) {
            $interfaceCalled = true;
        });

        $event = new PSR14InterfaceOnlyEvent();
        $listeners = \iterator_to_array($provider->getListenersForEvent($event));

        $this->assertCount(1, $listeners);
        $listeners[0]($event);
        $this->assertTrue($interfaceCalled);
    }

    public function testListenerProviderParentAndInterfaceCombined(): void
    {
        $provider = new ListenerProvider();
        $log = [];

        $provider->addListener(PSR14ChildEvent::class, function () use (&$log) {
            $log[] = 'child';
        }, 30);

        $provider->addListener(PSR14ParentEvent::class, function () use (&$log) {
            $log[] = 'parent';
        }, 20);

        $provider->addListener(PSR14TestEventInterface::class, function () use (&$log) {
            $log[] = 'interface';
        }, 10);

        $event = new PSR14ChildEvent();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        $this->assertSame(['child', 'parent', 'interface'], $log);
    }

    public function testListenerProviderNoListenersReturnsEmpty(): void
    {
        $provider = new ListenerProvider();
        $event = new PSR14SimpleEvent();
        $listeners = \iterator_to_array($provider->getListenersForEvent($event));
        $this->assertSame([], $listeners);
    }

    public function testListenerProviderAddListenerReturnsSelf(): void
    {
        $provider = new ListenerProvider();
        $result = $provider->addListener(PSR14SimpleEvent::class, function () {
        });
        $this->assertSame($provider, $result);
    }

    public function testListenerProviderNegativePriority(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(PSR14SimpleEvent::class, function () use (&$order) {
            $order[] = 'default';
        }, 0);

        $provider->addListener(PSR14SimpleEvent::class, function () use (&$order) {
            $order[] = 'negative';
        }, -10);

        $event = new PSR14SimpleEvent();
        foreach ($provider->getListenersForEvent($event) as $listener) {
            $listener($event);
        }

        $this->assertSame(['default', 'negative'], $order);
    }

    // ─── EventDispatcher ──────────────────────────────────

    public function testEventDispatcherImplementsInterface(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $this->assertInstanceOf(PsrEventDispatcherInterface::class, $dispatcher);
    }

    public function testEventDispatcherDispatchesToListeners(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'first';
        });
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'second';
        });

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14ModifiableEvent();
        $dispatcher->dispatch($event);

        $this->assertSame(['first', 'second'], $event->log);
    }

    public function testEventDispatcherReturnsEventObject(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);

        $event = new PSR14SimpleEvent();
        $returned = $dispatcher->dispatch($event);

        $this->assertSame($event, $returned);
    }

    public function testEventDispatcherStopsPropagation(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(PSR14StoppableModifiableEvent::class, function (PSR14StoppableModifiableEvent $e) {
            $e->log[] = 'first';
            $e->stopPropagation();
        }, 10);

        $provider->addListener(PSR14StoppableModifiableEvent::class, function (PSR14StoppableModifiableEvent $e) {
            $e->log[] = 'second';
        }, 0);

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14StoppableModifiableEvent();
        $result = $dispatcher->dispatch($event);

        $this->assertSame(['first'], $event->log);
        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame($event, $result);
    }

    public function testEventDispatcherAlreadyStoppedSkipsAll(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(PSR14StoppableModifiableEvent::class, function (PSR14StoppableModifiableEvent $e) {
            $e->log[] = 'should-not-run';
        });

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14StoppableModifiableEvent();
        $event->stopPropagation();

        $dispatcher->dispatch($event);

        $this->assertSame([], $event->log);
    }

    public function testEventDispatcherAllListenersWhenNotStoppable(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'a';
        });
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'b';
        });
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'c';
        });

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14ModifiableEvent();
        $dispatcher->dispatch($event);

        $this->assertSame(['a', 'b', 'c'], $event->log);
    }

    public function testEventDispatcherEmptyListenerListUnchanged(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);

        $event = new PSR14ModifiableEvent();
        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertSame([], $event->log);
    }

    public function testEventDispatcherPriorityOrder(): void
    {
        $provider = new ListenerProvider();
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'low';
        }, 0);
        $provider->addListener(PSR14ModifiableEvent::class, function (PSR14ModifiableEvent $e) {
            $e->log[] = 'high';
        }, 100);

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14ModifiableEvent();
        $dispatcher->dispatch($event);

        $this->assertSame(['high', 'low'], $event->log);
    }

    public function testEventDispatcherInheritedListeners(): void
    {
        $provider = new ListenerProvider();
        $called = false;
        $provider->addListener(PSR14ParentEvent::class, function (PSR14ParentEvent $e) use (&$called) {
            $called = true;
        });

        $dispatcher = new EventDispatcher($provider);
        $event = new PSR14ChildEvent();
        $result = $dispatcher->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertTrue($called);
    }
}
