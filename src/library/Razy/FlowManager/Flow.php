<?php

namespace Razy\FlowManager;

use Razy\Error;
use Razy\FlowManager;
use Razy\HashMap;
use Razy\PluginTrait;
use ReflectionException;

abstract class Flow
{
    use PluginTrait;

    protected HashMap $flows;
    protected Flow|FlowManager|null $parent = null;
    protected string $flowType = '';
    protected bool $resolved = false;
    private ?Flow $falseFlow = null;
    private bool $_isRecursive = false;

    /**
     * Plugin initialize when loaded success
     *
     * @param string $flowType
     * @return $this
     */
    final public function init(string $flowType = ''): static
    {
        if (!$this->flowType) {
            $this->flows = new HashMap();
            $this->flowType = $flowType;
        }
        return $this;
    }

    /**
     * Kill all child Flow
     *
     * @return $this
     */
    final function kill(): Flow
    {
        $this->eject();
        foreach ($this->flows as $flow) {
            $flow->kill();
        }
        return $this;
    }

    /**
     * Set the Flow is recursive loop
     *
     * @param bool $isRecursive
     * @return $this
     */
    protected function recursive(bool $isRecursive): Flow
    {
        $this->_isRecursive = $isRecursive;
        return $this;
    }

    /**
     * Return true if the Flow is recursive loop
     *
     * @return bool
     */
    protected function isRecursive(): bool
    {
        return $this->_isRecursive;
    }

    /**
     * Get the Flow Type
     *
     * @return string
     */
    public function getFlowType(): string
    {
        return $this->flowType;
    }

    /**
     * Get the connected FlowManager
     *
     * @return FlowManager|null
     */
    final public function getFlowManager(): ?FlowManager
    {
        $parent = $this;
        while ($parent = $parent->getParent()) {
            if ($parent instanceof FlowManager) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Return true if the Flow is reachable by FlowManager
     *
     * @return bool
     */
    final public function isReachable(): bool
    {
        return $this->getFlowManager() !== null;
    }

    /**
     * Detach the child by specified Flow
     *
     * @param Flow $child
     * @return $this
     */
    final public function detach(Flow $child): static
    {
        if ($this->flows->has($child)) {
            $this->flows->remove($child->eject());
        }
        return $this;
    }

    /**
     * Eject current Flow out of the Flow chain
     *
     * @return $this
     */
    final public function eject(): static
    {
        if ($this->parent) {
            $parent = $this->parent;
            $this->parent = null;
            $parent->detach($this);
        }

        return $this;
    }

    /**
     * Join another Flow under the flow list
     *
     * @param Flow $child
     * @return $this
     */
    final public function join(Flow $child): static
    {
        if (!$this->flows->has($child)) {
            if ($child->request($this->flowType)) {
                $this->flows[] = $child;
                if ($child->getParent() !== $this) {
                    $child->connect($this);
                }
            }
        }
        return $this;
    }

    /**
     * Connect Flow to another Flow
     *
     * @param Flow|FlowManager $parent
     * @return $this
     */
    final public function connect(Flow|FlowManager $parent): static
    {
        if ($parent instanceof FlowManager) {
            $this->eject();
            $this->parent = $parent;
        } else {
            if ($this->request($parent->getFlowType())) {
                if ($this->parent) {
                    $this->parent->detach($this);
                }
                $parent->join($this);
                $this->parent = $parent;
            }
        }

        return $this;
    }

    /**
     * Check if the given Flow is in the child list
     *
     * @param Flow $flow
     * @return bool
     * @return bool
     */
    final public function hasFlow(Flow $flow): bool
    {
        return $this->flows->has($flow);
    }

    /**
     * Get the parent Flow. If a type name is provided, retrieve the specified Flow.
     *
     * @param string $flowType
     * @return Flow|null
     */
    final public function getParent(string $flowType = ''): ?Flow
    {
        if ($flowType && $this->parent->getFlowType() !== $flowType) {
            return $this->parent->getParent($flowType);
        }
        return $this->parent;
    }

    /**
     * Reject the Flow
     *
     * @param string $message
     * @return Flow|null
     */
    public function reject(mixed $message): ?Flow
    {
        if ($this->parent instanceof Flow) {
            return $this->parent->reject($message);
        }
        return null;
    }

    /**
     * Return true if the Flow is joined to another Flow or FlowManager
     *
     * @param Flow|FlowManager|null $entity
     * @return bool
     */
    final public function isJoined(Flow|FlowManager|null $entity = null): bool
    {
        if ($entity instanceof FlowManager) {
            return $this->getFlowManager() === $entity;
        }

        if ($entity instanceof Flow) {
            while ($parent = $entity->getParent()) {
                if ($parent === $entity) {
                    return true;
                }
            }
            return false;
        }

        if (!$this->parent) {
            return false;
        }

        return true;
    }

    /**
     * Request check: return false to disallow connection
     *
     * @param string $typeOfFlow
     * @return bool
     */
    public function request(string $typeOfFlow = ''): bool
    {
        return true;
    }

    /**
     * Resolve the Flow
     *
     * @param ...$args
     * @return bool
     */
    public function resolve(...$args): bool
    {
        if (!$this->resolved) {
            $this->resolved = true;
        }
        return true;
    }

    /**
     * Check if the Flow is resolved
     *
     * @return bool
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Transmit arguments to all Flows within
     *
     * @param ...$args
     * @return $this
     */
    public function transmit(...$args): Flow
    {
        foreach ($this->flows as $flow) {
            call_user_func_array([$flow, 'transmit'], $args);
        }
        return $this;
    }

    /**
     * Create a child Flow
     *
     * @param string $method
     * @param array $arguments
     * @return Flow|null
     * @throws Error
     * @throws ReflectionException
     */
    final public function next(string $method, ...$arguments): ?Flow
    {
        if ($this->parent instanceof Flow && $this->parent->isRecursive()) {
            return $this->parent->next($method, ...$arguments);
        }

        $child = FlowManager::CreateFlowInstance($method, ...$arguments);
        if ($child->request($method)) {
            $this->flows[] = $child;
        }
        return $child->connect($this);
    }

    /**
     * Get the array of map of the child
     *
     * @return array
     */
    final public function getMap(): array
    {
        $map = [];
        foreach ($this->flows as $flow) {
            $map[] = [
                'name' => $flow->getFlowType(),
                'map' => $flow->getMap(),
            ];
        }

        return $map;
    }
}