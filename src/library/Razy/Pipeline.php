<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy;

use Razy\Exception\PipelineException;
use Razy\Pipeline\Action;
use Razy\Pipeline\Relay;
use Throwable;

/**
 * Class Pipeline.
 *
 * Manages a sequential pipeline of Action instances for data processing, validation,
 * and transformation. Actions are created from registered plugins, executed in order,
 * and share a common storage for inter-action communication.
 *
 * Usage:
 * ```php
 * $pipeline = new Pipeline();
 * $worker = $pipeline->pipe('FormWorker', $database, 'users', 'user_id');
 * $worker->then('Validate', 'email')->then('NoEmpty')->then('Unique');
 * $worker->then('Validate', 'name')->then('NoEmpty');
 * $pipeline->execute();
 * ```
 *
 * API Changes (v0.5.4):
 *   start()       → pipe()        Create and add an action by type
 *   append()      → add()         Add an existing action instance
 *   resolve()     → execute()     Execute all actions in the pipeline
 *   Flow          → Action        Renamed class for clarity
 *   FlowManager   → Pipeline      Renamed class for clarity
 *   Transmitter   → Relay         Renamed class for clarity
 *
 * @class Pipeline
 *
 * @package Razy
 */
class Pipeline
{
    use PluginTrait;

    /** @var array<Action> Ordered list of Action instances in the pipeline */
    private array $actions = [];

    /** @var array<string, mixed> Shared key-value storage accessible by all actions */
    private array $storage = [];

    /** @var array<string, array<string, mixed>> Scoped storage grouped by name and identifier */
    private array $scopedStorage = [];

    /** @var Relay|null Lazy-initialized relay for broadcasting to all actions */
    private ?Relay $relay = null;

    /**
     * Pipeline constructor.
     */
    public function __construct()
    {
    }

    /**
     * Create a new Action instance from a registered plugin type.
     *
     * The type string format is "pluginName" or "pluginName:subType" where
     * subType becomes the action's identifier.
     *
     * @param string $actionType Action type identifier
     * @param mixed ...$arguments Arguments for the action plugin constructor
     *
     * @return Action|null The created action instance
     *
     * @throws PipelineException If the plugin is not registered or action creation fails
     */
    public static function createAction(string $actionType, ...$arguments): ?Action
    {
        if (\preg_match('/^(\w[\w-]+)(?::(\w+))?$/', $actionType, $matches)) {
            $plugin = self::GetPlugin($matches[1]);
            if ($plugin) {
                try {
                    return $plugin['entity'](...$arguments)->init($matches[1], $matches[2] ?? '');
                } catch (Throwable $e) {
                    throw new PipelineException("Failed to create action '$actionType': " . $e->getMessage(), 0, $e);
                }
            }
        }
        throw new PipelineException("Action plugin not found: '$actionType'");
    }

    /**
     * Check whether the given value is an Action instance (or subclass).
     *
     * @param mixed $action The value to check
     *
     * @return bool True if the value is an Action subclass instance
     */
    public static function isAction(mixed $action): bool
    {
        return \is_object($action) && \is_subclass_of($action, Action::class);
    }

    /**
     * Create an Action from a registered plugin and add it to the pipeline.
     *
     * The action type string format is "pluginName" or "pluginName:subType".
     *
     * ```php
     * $worker = $manager->pipe('FormWorker', $db, 'users', 'user_id', 'disabled');
     * ```
     *
     * @param string $method The action type identifier (e.g., "FormWorker", "Validate:email")
     * @param mixed ...$arguments Arguments forwarded to the action plugin constructor
     *
     * @return Action|null The created Action instance for chaining, or null if declined
     *
     * @throws PipelineException If the action type plugin is not found or creation fails
     */
    public function pipe(string $method, ...$arguments): ?Action
    {
        $action = self::createAction($method, ...$arguments);
        if ($action && $action->accept($method)) {
            $action->attachTo($this);
            $this->actions[] = $action;
            return $action;
        }
        return null;
    }

    /**
     * Add an existing Action instance to the pipeline.
     *
     * ```php
     * $manager->add($existingAction)->add($anotherAction);
     * ```
     *
     * @param Action $action The action instance to add
     *
     * @return $this Chainable
     */
    public function add(Action $action): static
    {
        $action->attachTo($this);
        $this->actions[] = $action;
        return $this;
    }

    /**
     * Execute all actions in the pipeline sequentially.
     * Stops and returns false if any action fails.
     *
     * @param mixed ...$args Arguments passed to each action's execute method
     *
     * @return bool True if all actions executed successfully
     */
    public function execute(...$args): bool
    {
        foreach ($this->actions as $action) {
            if (!$action->execute(...$args)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get or create the Relay for broadcasting method calls to all actions.
     *
     * @return Relay The relay instance
     */
    public function getRelay(): Relay
    {
        return $this->relay ??= new Relay($this);
    }

    /**
     * Get all registered Action instances in the pipeline.
     *
     * @return array<Action> List of actions
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * Store a value in shared storage, optionally scoped by an identifier.
     *
     * @param string $name The storage key
     * @param mixed $value The value to store
     * @param string $identifier Optional scope identifier for grouped storage
     *
     * @return $this Chainable
     */
    public function setStorage(string $name, mixed $value = null, string $identifier = ''): static
    {
        $identifier = \trim($identifier);
        if ($identifier) {
            $this->scopedStorage[$name] ??= [];
            $this->scopedStorage[$name][$identifier] = $value;
        } else {
            $this->storage[$name] = $value;
        }
        return $this;
    }

    /**
     * Retrieve a value from shared storage, optionally scoped by an identifier.
     *
     * @param string $name The storage key
     * @param string $identifier Optional scope identifier
     *
     * @return mixed The stored value, or null if not found
     */
    public function getStorage(string $name, string $identifier = ''): mixed
    {
        $identifier = \trim($identifier);
        if ($identifier) {
            return $this->scopedStorage[$name][$identifier] ?? null;
        }
        return $this->storage[$name] ?? null;
    }

    /**
     * Get the pipeline map showing each action's type and internal structure.
     *
     * @return array<array{name: string, map: array}> Array of action type names and their maps
     */
    public function getMap(): array
    {
        $map = [];
        foreach ($this->actions as $action) {
            $map[] = [
                'name' => $action->getActionType(),
                'map' => $action->getMap(),
            ];
        }
        return $map;
    }
}
