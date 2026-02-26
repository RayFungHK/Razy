<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the abstract Action class for the Razy Pipeline system. Actions are
 * composable units of work in a hierarchical pipeline, supporting parent-child
 * relationships, conditional chaining, recursive delegation, and inter-action
 * communication via shared storage and broadcasting.
 *
 *
 * @license MIT
 */

namespace Razy\Pipeline;

use Razy\HashMap;
use Razy\Pipeline;

/**
 * Abstract base class for a unit of work in the Pipeline.
 *
 * Actions form a tree structure managed by Pipeline at the root. Each Action can
 * be attached to a parent (another Action or Pipeline), adopt child actions,
 * broadcast arguments, and execute its work. Subclasses implement domain-specific
 * logic by overriding `execute()`, `broadcast()`, and `accept()`.
 *
 * Pipeline API:
 * ```php
 * $action->then('Validate', 'email')    // Chain a child action step
 *         ->then('NoEmpty')              // Continue chaining
 *         ->then('Custom', fn($v) => strtolower($v));
 *
 * $action->when($isEditing, fn($a) => $a->then('Unique'));  // Conditional step
 * $action->tap(fn($a) => error_log($a->getActionType()));   // Inspect without breaking chain
 * ```
 *
 * API Changes (v0.5.4):
 *   Flow          → Action        Renamed class for clarity
 *   next()        → then()        Chain a child action step
 *   resolve()     → execute()     Execute this action's work
 *   request()     → accept()      Check if connection is allowed
 *   connect()     → attachTo()    Attach this action to a parent
 *   join()        → adopt()       Adopt a child action
 *   eject()       → detach()      Detach from parent
 *   detach(child) → remove(child) Remove a specific child
 *   kill()        → terminate()   Terminate this action and all children
 *   getParent()   → findOwner()   Walk up the chain to find a parent by type
 *                   getOwner()    Get the direct parent entity
 *   getFlowManager() → getManager()  Get the root Pipeline
 *   transmit()    → broadcast()   Broadcast to all children
 *   isResolved()  → isExecuted()  Check if executed
 *   hasFlow()     → hasChild()    Check if a child exists
 *   isJoined()    → isAttached()  Check attachment status
 *   getFlowType() → getActionType() Get the action type identifier
 *   $parent       → $owner        Direct parent reference (protected)
 *   $flows        → $children     Child actions collection (protected)
 *   $resolved     → $executed     Execution state flag (protected)
 *   $flowType     → $actionType   Type identifier (protected)
 *
 * New methods:
 *   when(bool, callable)   Conditional pipeline building
 *   tap(callable)          Side-effect inspection without breaking chain
 *   getChildren()          Public accessor for child actions
 *
 * @class Action
 */
abstract class Action
{
    /** @var HashMap Collection of child Action instances */
    protected HashMap $children;

    /** @var Action|Pipeline|null The parent entity this Action is attached to */
    protected Action|Pipeline|null $owner = null;

    /** @var string The type identifier for this Action (e.g., "Validate", "FormWorker") */
    protected string $actionType = '';

    /** @var bool Whether this Action has been executed */
    protected bool $executed = false;

    /** @var bool Whether child creation delegates to this action's parent (recursive mode) */
    private bool $recursive = false;

    /** @var string Unique sub-type identifier for this Action instance */
    private string $identifier = '';

    /**
     * Initialize the Action with its type and identifier. Called once after construction.
     *
     * @param string $actionType The action type name
     * @param string $identifier The optional sub-type identifier
     *
     * @return $this
     */
    final public function init(string $actionType = '', string $identifier = ''): static
    {
        if (!$this->actionType) {
            $this->children = new HashMap();
            $this->actionType = $actionType;
            $this->identifier = $identifier;
        }
        return $this;
    }

    /**
     * Get the sub-type identifier of this Action.
     *
     * @return string
     */
    final public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Get the type name of this Action.
     *
     * @return string
     */
    public function getActionType(): string
    {
        return $this->actionType;
    }

    // ─── Pipeline Building ─────────────────────────────────────────────

    /**
     * Create and chain a child Action as the next step in the pipeline.
     *
     * In recursive mode, delegates child creation to the parent Action so that
     * all descendant steps become siblings (flat list under the recursive parent).
     *
     * ```php
     * $worker->then('Validate', 'email')->then('NoEmpty')->then('Unique');
     * ```
     *
     * @param string $method The action type to create (e.g., "Validate", "NoEmpty")
     * @param mixed ...$arguments Arguments for the action constructor
     *
     * @return Action|null The created child action for further chaining
     *
     * @throws Error If action creation fails
     */
    final public function then(string $method, ...$arguments): ?self
    {
        // Recursive mode: delegate to parent so all children are siblings
        if ($this->owner instanceof self && $this->owner->isRecursive()) {
            return $this->owner->then($method, ...$arguments);
        }

        $child = Pipeline::createAction($method, ...$arguments);
        if ($child) {
            $child->attachTo($this);
        }
        return $child;
    }

    /**
     * Conditionally add pipeline steps. If the condition is true, the callback
     * receives this action for adding child steps. Always returns $this for chaining.
     *
     * ```php
     * $action->then('Validate', 'email')
     *        ->then('NoEmpty')
     *        ->when($isEditing, fn($a) => $a->then('Unique'));
     * ```
     *
     * @param bool $condition Whether to execute the callback
     * @param callable $callback Receives this Action as its argument
     *
     * @return $this Chainable (always returns self regardless of condition)
     */
    final public function when(bool $condition, callable $callback): static
    {
        if ($condition) {
            $callback($this);
        }
        return $this;
    }

    /**
     * Execute a callback with this action for side-effects (logging, debugging)
     * without breaking the chain. Always returns $this.
     *
     * ```php
     * $action->then('Validate', 'email')
     *        ->tap(fn($a) => error_log("Type: " . $a->getActionType()))
     *        ->then('NoEmpty');
     * ```
     *
     * @param callable $callback Receives this Action as its argument
     *
     * @return $this Chainable
     */
    final public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    // ─── Tree Structure ────────────────────────────────────────────────

    /**
     * Attach this Action to a parent (Action or Pipeline).
     *
     * For Pipeline parents, directly sets the owner.
     * For Action parents, checks `accept()` before establishing the relationship.
     * Automatically detaches from any previous parent first.
     *
     * @param Action|Pipeline $parent The parent entity to attach to
     *
     * @return $this Chainable
     */
    final public function attachTo(self|Pipeline $parent): static
    {
        if ($parent instanceof Pipeline) {
            $this->detach();
            $this->owner = $parent;
        } else {
            if ($this->accept($parent->getActionType())) {
                if ($this->owner instanceof self) {
                    $this->owner->remove($this);
                }
                $parent->adopt($this);
                $this->owner = $parent;
            }
        }
        return $this;
    }

    /**
     * Adopt a child Action into this action's children list.
     *
     * Verifies the child accepts this action's type before adding.
     * Establishes bidirectional parent-child relationship.
     *
     * @param Action $child The child action to adopt
     *
     * @return $this Chainable
     */
    final public function adopt(self $child): static
    {
        if (!$this->children->has($child)) {
            if ($child->accept($this->actionType)) {
                $this->children[] = $child;
                if ($child->getOwner() !== $this) {
                    $child->attachTo($this);
                }
            }
        }
        return $this;
    }

    /**
     * Remove a specific child Action from this action's children.
     *
     * @param Action $child The child action to remove
     *
     * @return $this Chainable
     */
    final public function remove(self $child): static
    {
        if ($this->children->has($child)) {
            $this->children->remove($child);
            if ($child->getOwner() === $this) {
                $child->detach();
            }
        }
        return $this;
    }

    /**
     * Detach this Action from its current parent.
     *
     * @return $this Chainable
     */
    final public function detach(): static
    {
        if ($this->owner instanceof self) {
            $previous = $this->owner;
            $this->owner = null;
            $previous->remove($this);
        } elseif ($this->owner instanceof Pipeline) {
            $this->owner = null;
        }
        return $this;
    }

    /**
     * Terminate this Action and recursively terminate all children.
     *
     * @return $this
     */
    final public function terminate(): static
    {
        $this->detach();
        foreach ($this->children as $child) {
            $child->terminate();
        }
        return $this;
    }

    // ─── Hierarchy Traversal ────────────────────────────────────────────

    /**
     * Get the direct owner of this Action (parent Action or Pipeline).
     *
     * @return Action|Pipeline|null
     */
    final public function getOwner(): self|Pipeline|null
    {
        return $this->owner;
    }

    /**
     * Walk up the ownership chain to find a parent Action matching the given type.
     *
     * Supports type matching by action type name and/or identifier using the
     * format "typeName", ":identifier", or "typeName:identifier".
     *
     * ```php
     * $formWorker = $this->findOwner('FormWorker');
     * $specific   = $this->findOwner('Validate:email');
     * $direct     = $this->findOwner();  // Direct parent Action (or null if Pipeline)
     * ```
     *
     * @param string $actionType Action type pattern to match (empty = direct parent Action)
     *
     * @return Action|null The matching parent Action, or null if not found
     */
    final public function findOwner(string $actionType = ''): ?self
    {
        // Return null if no parent or parent is a Pipeline (not an Action)
        if (!$this->owner || $this->owner instanceof Pipeline) {
            return null;
        }

        // Match against the direct parent
        if (\preg_match('/^(\w[\w-]+)?(?::(\w+))?$/', $actionType, $matches)) {
            if (!$actionType
                || ((!isset($matches[1]) || $this->owner->getActionType() === $matches[1])
                 && (!isset($matches[2]) || $this->owner->getIdentifier() === $matches[2]))) {
                return $this->owner;
            }
        }

        // Recurse up the chain
        return $this->owner->findOwner($actionType);
    }

    /**
     * Get the root Pipeline by walking up the ownership chain.
     *
     * @return Pipeline|null The root pipeline, or null if not attached to one
     */
    final public function getManager(): ?Pipeline
    {
        $current = $this;
        while (true) {
            $parent = $current->getOwner();
            if ($parent instanceof Pipeline) {
                return $parent;
            }
            if (!$parent instanceof self) {
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * Check if this Action is reachable (attached to a Pipeline root).
     *
     * @return bool
     */
    final public function isReachable(): bool
    {
        return $this->getManager() !== null;
    }

    /**
     * Check if a given Action is in this action's children list.
     *
     * @param Action $action The action to check
     *
     * @return bool
     */
    final public function hasChild(self $action): bool
    {
        return $this->children->has($action);
    }

    /**
     * Get all child Action instances.
     *
     * @return HashMap
     */
    final public function getChildren(): HashMap
    {
        return $this->children;
    }

    /**
     * Check if this Action is attached to a given entity (or any entity if null).
     *
     * For Pipeline: checks if this action ultimately belongs to that pipeline.
     * For Action: walks up the chain to check ancestry.
     * For null: returns true if attached to anything.
     *
     * @param Action|Pipeline|null $entity The entity to check against
     *
     * @return bool
     */
    final public function isAttached(self|Pipeline|null $entity = null): bool
    {
        if ($entity instanceof Pipeline) {
            return $this->getManager() === $entity;
        }

        if ($entity instanceof self) {
            $current = $this;
            while ($parent = $current->findOwner()) {
                if ($parent === $entity) {
                    return true;
                }
                $current = $parent;
            }
            return false;
        }

        return $this->owner !== null;
    }

    // ─── Execution ─────────────────────────────────────────────────────

    /**
     * Determine if this Action accepts a connection from the given action type.
     *
     * Override in subclasses to restrict which parent action types this action
     * can be attached to. Returns true by default (accepts all).
     *
     * @param string $actionType The parent's action type name
     *
     * @return bool True to accept the connection
     */
    public function accept(string $actionType = ''): bool
    {
        return true;
    }

    /**
     * Execute this Action's work.
     *
     * Override in subclasses to implement the action's execution logic.
     * The default implementation marks the action as executed and returns true.
     *
     * @param mixed ...$args Execution arguments
     *
     * @return bool True if execution succeeded
     */
    public function execute(...$args): bool
    {
        if (!$this->executed) {
            $this->executed = true;
        }
        return true;
    }

    /**
     * Check if this Action has been executed.
     *
     * @return bool
     */
    public function isExecuted(): bool
    {
        return $this->executed;
    }

    /**
     * Reject this Action. Propagates rejection up to the parent Action.
     *
     * @param mixed $message The rejection reason or error code
     *
     * @return Action|null The parent action that handled the rejection
     */
    public function reject(mixed $message): ?self
    {
        if ($this->owner instanceof self) {
            return $this->owner->reject($message);
        }
        return null;
    }

    // ─── Broadcasting ──────────────────────────────────────────────────

    /**
     * Broadcast arguments to all child actions.
     *
     * Each child's `broadcast()` method is called with the same arguments,
     * enabling recursive propagation through the tree.
     *
     * @param mixed ...$args Arguments to broadcast
     *
     * @return $this Chainable
     */
    public function broadcast(...$args): static
    {
        foreach ($this->children as $child) {
            $child->broadcast(...$args);
        }
        return $this;
    }

    // ─── Introspection ─────────────────────────────────────────────────

    /**
     * Get the structural map of this action's children (recursive).
     *
     * @return array<array{name: string, map: array}>
     */
    final public function getMap(): array
    {
        $map = [];
        foreach ($this->children as $child) {
            $map[] = [
                'name' => $child->getActionType(),
                'map' => $child->getMap(),
            ];
        }
        return $map;
    }

    // ─── Recursive Mode ────────────────────────────────────────────────

    /**
     * Enable or disable recursive mode.
     *
     * When enabled, any child's `then()` call delegates back to this action,
     * making all descendants become direct children (flat list).
     *
     * @param bool $recursive Whether to enable recursive mode
     *
     * @return $this Chainable
     */
    protected function setRecursive(bool $recursive): static
    {
        $this->recursive = $recursive;
        return $this;
    }

    /**
     * Check if this Action is in recursive mode.
     *
     * @return bool
     */
    protected function isRecursive(): bool
    {
        return $this->recursive;
    }
}
