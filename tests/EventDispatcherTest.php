<?php

declare(strict_types=1);

namespace Razy\Tests;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Contract\EventDispatcherInterface;
use Razy\Controller;
use Razy\Exception\ModuleException;
use Razy\Module\ClosureLoader;
use Razy\Module\EventDispatcher;
use RuntimeException;

/**
 * Tests for EventDispatcher — event listener registration, lookup, and firing.
 */
#[CoversClass(EventDispatcher::class)]
class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    // ─── Interface contract ───────────────────────────────

    public function testImplementsEventDispatcherInterface(): void
    {
        $this->assertInstanceOf(EventDispatcherInterface::class, $this->dispatcher);
    }

    // ─── listen() ─────────────────────────────────────────

    public function testListenRegistersEventWithStringPath(): void
    {
        $this->dispatcher->listen('vendor/module:on_ready', '/path/to/handler');
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'on_ready'));
    }

    public function testListenRegistersEventWithClosure(): void
    {
        $this->dispatcher->listen('vendor/module:on_load', fn () => 'loaded');
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'on_load'));
    }

    public function testListenDuplicateEventThrows(): void
    {
        $this->dispatcher->listen('vendor/module:start', '/path');
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('already registered');
        $this->dispatcher->listen('vendor/module:start', '/other');
    }

    public function testListenMultipleEventsFromSameModule(): void
    {
        $this->dispatcher->listen('vendor/module:event_a', '/a');
        $this->dispatcher->listen('vendor/module:event_b', '/b');

        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'event_a'));
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'event_b'));
    }

    public function testListenEventsFromDifferentModules(): void
    {
        $this->dispatcher->listen('vendor/mod1:init', '/handler1');
        $this->dispatcher->listen('vendor/mod2:init', '/handler2');

        $this->assertTrue($this->dispatcher->isEventListening('vendor/mod1', 'init'));
        $this->assertTrue($this->dispatcher->isEventListening('vendor/mod2', 'init'));
    }

    // ─── isEventListening() ───────────────────────────────

    public function testIsEventListeningReturnsFalseForUnknownModule(): void
    {
        $this->assertFalse($this->dispatcher->isEventListening('unknown/module', 'event'));
    }

    public function testIsEventListeningReturnsFalseForUnknownEvent(): void
    {
        $this->dispatcher->listen('vendor/module:known', '/path');
        $this->assertFalse($this->dispatcher->isEventListening('vendor/module', 'unknown'));
    }

    public function testIsEventListeningReturnsTrueForRegistered(): void
    {
        $this->dispatcher->listen('vendor/module:ready', fn () => true);
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'ready'));
    }

    // ─── fireEvent() with Closure ─────────────────────────

    public function testFireEventWithClosureInvokes(): void
    {
        $called = false;
        $this->dispatcher->listen('vendor/module:test_event', function () use (&$called) {
            $called = true;
            return 'result';
        });

        $controller = new Controller(null);
        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'test_event', [], $controller, $closureLoader);
        $this->assertTrue($called);
        $this->assertSame('result', $result);
    }

    public function testFireEventWithClosurePassesArgs(): void
    {
        $receivedArgs = [];
        $this->dispatcher->listen('vendor/module:echo', function (...$args) use (&$receivedArgs) {
            $receivedArgs = $args;
            return \count($args);
        });

        $controller = new Controller(null);
        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'echo', ['a', 'b', 'c'], $controller, $closureLoader);
        $this->assertSame(['a', 'b', 'c'], $receivedArgs);
        $this->assertSame(3, $result);
    }

    // ─── fireEvent() — unregistered event ─────────────────

    public function testFireEventUnregisteredReturnsNull(): void
    {
        $controller = new Controller(null);
        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'nonexistent', [], $controller, $closureLoader);
        $this->assertNull($result);
    }

    // ─── fireEvent() — exception handling ─────────────────

    public function testFireEventClosureExceptionCallsOnError(): void
    {
        $exception = new RuntimeException('test error');

        $this->dispatcher->listen('vendor/module:fail', function () use ($exception) {
            throw $exception;
        });

        // Create a mock controller to verify __onError is called
        $controller = $this->getMockBuilder(Controller::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['__onError'])
            ->getMock();

        $controller->expects($this->once())
            ->method('__onError')
            ->with('fail', $exception);

        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'fail', [], $controller, $closureLoader);
        $this->assertNull($result);
    }

    // ─── reset() ──────────────────────────────────────────

    public function testResetClearsAllListeners(): void
    {
        $this->dispatcher->listen('vendor/mod1:event1', '/path1');
        $this->dispatcher->listen('vendor/mod2:event2', fn () => null);

        $this->dispatcher->reset();

        $this->assertFalse($this->dispatcher->isEventListening('vendor/mod1', 'event1'));
        $this->assertFalse($this->dispatcher->isEventListening('vendor/mod2', 'event2'));
    }

    public function testResetAllowsReregistration(): void
    {
        $this->dispatcher->listen('vendor/module:event', '/original');
        $this->dispatcher->reset();

        // Should not throw — event was cleared
        $this->dispatcher->listen('vendor/module:event', '/new');
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'event'));
    }

    public function testResetIsIdempotent(): void
    {
        $this->dispatcher->reset();
        $this->dispatcher->reset();
        $this->assertFalse($this->dispatcher->isEventListening('any', 'event'));
    }

    // ─── Edge cases ───────────────────────────────────────

    public function testEventNameWithSpecialCharacters(): void
    {
        $this->dispatcher->listen('vendor/module:on-user.created', '/handler');
        $this->assertTrue($this->dispatcher->isEventListening('vendor/module', 'on-user.created'));
    }

    public function testSameEventNameDifferentModules(): void
    {
        // Same event name under different modules should not conflict
        $this->dispatcher->listen('vendor/mod_a:init', '/a');
        $this->dispatcher->listen('vendor/mod_b:init', '/b');

        $this->assertTrue($this->dispatcher->isEventListening('vendor/mod_a', 'init'));
        $this->assertTrue($this->dispatcher->isEventListening('vendor/mod_b', 'init'));
    }

    // ─── fireEvent() with string-path handler ─────────────

    public function testFireEventStringPathMatchesControllerMethod(): void
    {
        // Register event with a string path (not a closure)
        $this->dispatcher->listen('vendor/module:on_ready', '/handler/path');

        // Create a controller mock with on_ready method
        $controller = $this->getMockBuilder(Controller::class)
            ->setConstructorArgs([null])
            ->addMethods(['on_ready'])
            ->getMock();

        $controller->expects($this->once())
            ->method('on_ready')
            ->willReturn('controller_method_result');

        $closureLoader = $this->createMock(ClosureLoader::class);
        // ClosureLoader should NOT be called since controller method matches
        $closureLoader->expects($this->never())
            ->method('getClosure');

        $result = $this->dispatcher->fireEvent('vendor/module', 'on_ready', [], $controller, $closureLoader);
        $this->assertSame('controller_method_result', $result);
    }

    public function testFireEventStringPathMatchesControllerMethodWithArgs(): void
    {
        $this->dispatcher->listen('vendor/module:on_save', '/handler/path');

        $controller = $this->getMockBuilder(Controller::class)
            ->setConstructorArgs([null])
            ->addMethods(['on_save'])
            ->getMock();

        $controller->expects($this->once())
            ->method('on_save')
            ->with('arg1', 'arg2')
            ->willReturn('saved');

        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'on_save', ['arg1', 'arg2'], $controller, $closureLoader);
        $this->assertSame('saved', $result);
    }

    public function testFireEventStringPathFallsBackToClosureLoader(): void
    {
        // Event name contains '/' so won't match direct controller method
        $this->dispatcher->listen('vendor/module:sub/event_handler', '/closure/path');

        $controller = new Controller(null);

        // Create a closure that will be returned by the loader
        $closureCalled = false;
        $closure = function () use (&$closureCalled) {
            $closureCalled = true;
            return 'closure_result';
        };

        $closureLoader = $this->createMock(ClosureLoader::class);
        $closureLoader->expects($this->once())
            ->method('getClosure')
            ->with('/closure/path', $controller)
            ->willReturn($closure);

        $result = $this->dispatcher->fireEvent('vendor/module', 'sub/event_handler', [], $controller, $closureLoader);
        $this->assertTrue($closureCalled);
        $this->assertSame('closure_result', $result);
    }

    public function testFireEventStringPathClosureLoaderReturnsNull(): void
    {
        // Event name contains '/' so won't match controller method
        $this->dispatcher->listen('vendor/module:deep/handler', '/missing/path');

        $controller = new Controller(null);

        $closureLoader = $this->createMock(ClosureLoader::class);
        $closureLoader->expects($this->once())
            ->method('getClosure')
            ->willReturn(null);

        // Should return null gracefully when no handler resolves
        $result = $this->dispatcher->fireEvent('vendor/module', 'deep/handler', [], $controller, $closureLoader);
        $this->assertNull($result);
    }

    public function testFireEventStringPathNoControllerMethodFallsToClosureLoader(): void
    {
        // Event name has no '/' but controller doesn't have the method
        $this->dispatcher->listen('vendor/module:custom_event', '/custom/handler');

        $controller = new Controller(null);

        $closure = fn () => 'loaded_from_file';

        $closureLoader = $this->createMock(ClosureLoader::class);
        $closureLoader->expects($this->once())
            ->method('getClosure')
            ->with('/custom/handler', $controller)
            ->willReturn($closure);

        $result = $this->dispatcher->fireEvent('vendor/module', 'custom_event', [], $controller, $closureLoader);
        $this->assertSame('loaded_from_file', $result);
    }

    public function testFireEventStringPathExceptionCallsOnError(): void
    {
        $this->dispatcher->listen('vendor/module:error_handler', '/path');

        $exception = new RuntimeException('string path error');

        $controller = $this->getMockBuilder(Controller::class)
            ->setConstructorArgs([null])
            ->addMethods(['error_handler'])
            ->onlyMethods(['__onError'])
            ->getMock();

        $controller->expects($this->once())
            ->method('error_handler')
            ->willThrowException($exception);

        $controller->expects($this->once())
            ->method('__onError')
            ->with('error_handler', $exception);

        $closureLoader = $this->createMock(ClosureLoader::class);

        $result = $this->dispatcher->fireEvent('vendor/module', 'error_handler', [], $controller, $closureLoader);
        $this->assertNull($result);
    }
}
