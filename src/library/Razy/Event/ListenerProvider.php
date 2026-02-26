<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PSR-14 compliant listener provider with priority support.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Event;

use Razy\Contract\EventDispatcher\ListenerProviderInterface;

/**
 * Concrete listener provider implementing PSR-14.
 *
 * Allows registering callable listeners for specific event classes,
 * with optional priority ordering. Higher priority listeners are
 * invoked first.
 */
class ListenerProvider implements ListenerProviderInterface
{
    /**
     * Registered listeners keyed by event class name.
     *
     * Each entry is an array of [priority, callable] tuples.
     *
     * @var array<class-string, list<array{int, callable}>>
     */
    private array $listeners = [];

    /**
     * Whether the listeners have been sorted since the last addition.
     *
     * @var array<class-string, bool>
     */
    private array $sorted = [];

    /**
     * Register a listener for a specific event class.
     *
     * @param string   $eventClass Fully-qualified class name of the event
     * @param callable $listener   The listener callable — receives the event as its only argument
     * @param int      $priority   Listener priority (higher = invoked first, default 0)
     *
     * @return static
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): static
    {
        $this->listeners[$eventClass][] = [$priority, $listener];
        $this->sorted[$eventClass] = false;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * Returns listeners registered for the event's class and any of its
     * parent classes or interfaces, sorted by descending priority.
     */
    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = get_class($event);

        // Collect listeners for the concrete class, its parents, and interfaces
        $matchingClasses = array_merge(
            [$eventClass],
            class_parents($event),
            class_implements($event)
        );

        $matched = [];

        foreach ($matchingClasses as $class) {
            if (!isset($this->listeners[$class])) {
                continue;
            }

            // Sort by priority descending (higher first) if needed
            if (empty($this->sorted[$class])) {
                usort($this->listeners[$class], static fn(array $a, array $b): int => $b[0] <=> $a[0]);
                $this->sorted[$class] = true;
            }

            foreach ($this->listeners[$class] as [$priority, $listener]) {
                $matched[] = [$priority, $listener];
            }
        }

        // Re-sort the merged set by priority descending
        usort($matched, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        foreach ($matched as [, $listener]) {
            yield $listener;
        }
    }
}
