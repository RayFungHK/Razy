<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\EventDispatcher\StoppableEventInterface;
use Razy\Event\EventDispatcher;
use Razy\Event\ListenerProvider;
use Razy\Event\StoppableEvent;
use stdClass;

#[CoversClass(StoppableEvent::class)]
#[CoversClass(ListenerProvider::class)]
#[CoversClass(EventDispatcher::class)]
class EventSystemTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // StoppableEvent
    // ═══════════════════════════════════════════════════════════

    public function testStoppableEventDefaultNotStopped(): void
    {
        $event = new StoppableEvent();
        $this->assertFalse($event->isPropagationStopped());
    }

    public function testStoppableEventStopPropagation(): void
    {
        $event = new StoppableEvent();
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testStoppableEventStopIsIdempotent(): void
    {
        $event = new StoppableEvent();
        $event->stopPropagation();
        $event->stopPropagation();
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testStoppableEventImplementsInterface(): void
    {
        $event = new StoppableEvent();
        $this->assertInstanceOf(StoppableEventInterface::class, $event);
    }

    // ═══════════════════════════════════════════════════════════
    // ListenerProvider
    // ═══════════════════════════════════════════════════════════

    public function testAddListenerReturnsSelf(): void
    {
        $provider = new ListenerProvider();
        $result = $provider->addListener(StoppableEvent::class, function () {
        });
        $this->assertSame($provider, $result);
    }

    public function testGetListenersForEventReturnsRegistered(): void
    {
        $provider = new ListenerProvider();
        $called = false;
        $provider->addListener(StoppableEvent::class, function () use (&$called) {
            $called = true;
        });

        foreach ($provider->getListenersForEvent(new StoppableEvent()) as $listener) {
            $listener(new StoppableEvent());
        }

        $this->assertTrue($called);
    }

    public function testListenersOrderedByPriority(): void
    {
        $provider = new ListenerProvider();
        $order = [];

        $provider->addListener(StoppableEvent::class, function () use (&$order) {
            $order[] = 'low';
        }, 1);

        $provider->addListener(StoppableEvent::class, function () use (&$order) {
            $order[] = 'high';
        }, 10);

        $provider->addListener(StoppableEvent::class, function () use (&$order) {
            $order[] = 'medium';
        }, 5);

        foreach ($provider->getListenersForEvent(new StoppableEvent()) as $listener) {
            $listener(new StoppableEvent());
        }

        $this->assertSame(['high', 'medium', 'low'], $order);
    }

    public function testNoListenersForUnregisteredEvent(): void
    {
        $provider = new ListenerProvider();
        $listeners = \iterator_to_array($provider->getListenersForEvent(new stdClass()));
        $this->assertEmpty($listeners);
    }

    public function testListenersMatchParentClass(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        // Register listener for parent class
        $provider->addListener(StoppableEvent::class, function () use (&$called) {
            $called = true;
        });

        // Create a concrete child event
        $childEvent = new class() extends StoppableEvent {};

        foreach ($provider->getListenersForEvent($childEvent) as $listener) {
            $listener($childEvent);
        }

        $this->assertTrue($called);
    }

    public function testListenersMatchInterface(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        // Register for the interface
        $provider->addListener(StoppableEventInterface::class, function () use (&$called) {
            $called = true;
        });

        foreach ($provider->getListenersForEvent(new StoppableEvent()) as $listener) {
            $listener(new StoppableEvent());
        }

        $this->assertTrue($called);
    }

    // ═══════════════════════════════════════════════════════════
    // EventDispatcher
    // ═══════════════════════════════════════════════════════════

    public function testDispatchReturnsEvent(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $event = new StoppableEvent();

        $result = $dispatcher->dispatch($event);
        $this->assertSame($event, $result);
    }

    public function testDispatchCallsListeners(): void
    {
        $provider = new ListenerProvider();
        $counter = 0;

        $provider->addListener(StoppableEvent::class, function () use (&$counter) {
            $counter++;
        });
        $provider->addListener(StoppableEvent::class, function () use (&$counter) {
            $counter++;
        });

        $dispatcher = new EventDispatcher($provider);
        $dispatcher->dispatch(new StoppableEvent());

        $this->assertSame(2, $counter);
    }

    public function testDispatchRespectsStoppedPropagation(): void
    {
        $provider = new ListenerProvider();

        $provider->addListener(StoppableEvent::class, function (StoppableEvent $e) {
            $e->stopPropagation();
        }, 10);

        $secondCalled = false;
        $provider->addListener(StoppableEvent::class, function () use (&$secondCalled) {
            $secondCalled = true;
        }, 1);

        $dispatcher = new EventDispatcher($provider);
        $event = new StoppableEvent();
        $dispatcher->dispatch($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertFalse($secondCalled);
    }

    public function testDispatchSkipsAlreadyStoppedEvent(): void
    {
        $provider = new ListenerProvider();
        $called = false;

        $provider->addListener(StoppableEvent::class, function () use (&$called) {
            $called = true;
        });

        $dispatcher = new EventDispatcher($provider);
        $event = new StoppableEvent();
        $event->stopPropagation();
        $dispatcher->dispatch($event);

        $this->assertFalse($called);
    }

    public function testDispatchNonStoppableEventCallsAll(): void
    {
        $provider = new ListenerProvider();
        $counter = 0;

        $provider->addListener(stdClass::class, function () use (&$counter) {
            $counter++;
        });
        $provider->addListener(stdClass::class, function () use (&$counter) {
            $counter++;
        });

        $dispatcher = new EventDispatcher($provider);
        $dispatcher->dispatch(new stdClass());

        $this->assertSame(2, $counter);
    }
}
