<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Module god class (Phase 2.2).
 * Manages event listener registration and dispatching within a module.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Module;

use Closure;
use Razy\Contract\EventDispatcherInterface;
use Razy\Controller;
use Razy\Exception\ModuleException;
use Throwable;

/**
 * Manages event listener registration and dispatching for a module.
 *
 * Events follow the format 'vendor/module:event_name'. When an event is fired,
 * the registered closure (or direct controller method) is invoked.
 */
class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, array<string, string|Closure>> Event listeners grouped by module code */
    private array $events = [];

    /**
     * Register a listener for an event from another module.
     *
     * @param string $event Event name in format 'vendor/module:event_name'
     * @param string|Closure $path Closure or path to closure file
     *
     * @throws ModuleException If the event is already registered
     */
    public function listen(string $event, string|Closure $path): void
    {
        // Parse "vendor/module:event_name" into module code and event name
        [$moduleCode, $eventName] = explode(':', $event);
        if (!isset($this->events[$moduleCode])) {
            $this->events[$moduleCode] = [];
        }

        if (array_key_exists($eventName, $this->events[$moduleCode])) {
            throw new ModuleException('The event `' . $eventName . '` is already registered.');
        }
        $this->events[$moduleCode][$eventName] = $path;
    }

    /**
     * Check if this module is listening for a specific event.
     *
     * @param string $moduleCode The source module code
     * @param string $event The event name
     *
     * @return bool True if listening
     */
    public function isEventListening(string $moduleCode, string $event): bool
    {
        return isset($this->events[$moduleCode]) && array_key_exists($event, $this->events[$moduleCode]);
    }

    /**
     * Fire an event, invoking the registered listener.
     *
     * If the listener is a string path, it resolves to either a direct controller method
     * or a closure file loaded via the ClosureLoader. If it's a Closure, it's invoked directly.
     *
     * @param string $moduleCode The source module code
     * @param string $event The event name
     * @param array $args The arguments to pass to the listener
     * @param Controller $controller The module's controller
     * @param ClosureLoader $closureLoader The closure loader for resolving paths
     *
     * @return mixed The listener's return value, or null
     */
    public function fireEvent(string $moduleCode, string $event, array $args, Controller $controller, ClosureLoader $closureLoader): mixed
    {
        $result = null;

        try {
            if (isset($this->events[$moduleCode]) && array_key_exists($event, $this->events[$moduleCode])) {
                $path = $this->events[$moduleCode][$event];
                if (is_string($path)) {
                    // String path: try direct controller method first, then load closure file
                    $closure = null;
                    if (!str_contains($event, '/') && method_exists($controller, $event)) {
                        $closure = [$controller, $event];
                    } elseif (($closure = $closureLoader->getClosure($path, $controller)) !== null) {
                        $closure = $closure->bindTo($controller);
                    }

                    if ($closure) {
                        $result = call_user_func_array($closure, $args);
                    }
                } else {
                    // Closure path: invoke directly as a callable
                    $result = call_user_func_array($path, $args);
                }
            }
        } catch (Throwable $exception) {
            $controller->__onError($event, $exception);
        }

        return $result;
    }

    /**
     * Reset all event listeners (used in worker mode between requests).
     */
    public function reset(): void
    {
        $this->events = [];
    }
}
