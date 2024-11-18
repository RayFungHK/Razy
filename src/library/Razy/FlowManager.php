<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use Razy\FlowManager\Flow;
use Razy\FlowManager\Transmitter;
use Throwable;

class FlowManager
{
    use PluginTrait;

    private array $flows = [];
    private array $storage = [];
    private array $storageByIdentifier = [];
    private ?Transmitter $transmitter = null;

    public function __construct()
    {

    }

    /**
     * @param ...$args
     * @return bool
     */
    public function resolve(...$args): bool
    {
        foreach ($this->flows as $name => $flow) {
            if (!$flow->reesolve(...$args)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return Transmitter
     */
    public function getTransmitter(): Transmitter
    {
        return ($this->transmitter ?: $this->transmitter = new Transmitter($this));
    }

    /**
     * @return array
     */
    public function getFlows(): array
    {
        return $this->flows;
    }

    /**
     * @param Flow $flow
     * @return $this
     */
    public function append(Flow $flow): static
    {
        $this->flows[] = $flow->join($this);
        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @param string $identifier
     * @return $this
     */
    public function setStorage(string $name, mixed $value = null, string $identifier = ''): static
    {
        $identifier = trim($identifier);
        if ($identifier) {
            $this->storageByIdentifier[$name] = $this->storageByIdentifier[$name] ?? [];
            $this->storageByIdentifier[$name][$identifier] = $value;
        } else {
            $this->storage[$name] = $value;
        }

        return $this;
    }

    /**
     * @param string $name
     * @param string $identifier
     * @return mixed
     */
    public function getStorage(string $name, string $identifier = ''): mixed
    {
        $identifier = trim($identifier);
        if ($identifier) {
            if (isset($this->storageByIdentifier[$name])) {
                return $this->storageByIdentifier[$name][$identifier] ?? null;
            }
        }
        return $this->storage[$name] ?? null;
    }

    /**
     * @param string $method
     * @param array $arguments
     * @return Flow|null
     * @throws Error
     */
    public function start(string $method, ...$arguments): ?Flow
    {
        if ($flow = self::CreateFlowInstance($method, ...$arguments)) {
            if ($flow->request($method)) {
                $this->flows[] = $flow;
                return $flow->connect($this);
            }
            return null;
        }

        return null;
    }

    /**
     * @param string $typeOfFlow
     * @param ...$arguments
     * @return Flow|null
     * @throws Error
     */
    static public function CreateFlowInstance(string $typeOfFlow, ...$arguments): ?Flow
    {
        $entity = null;
        if (preg_match('/^(\w[\w-]+)(?::(\w+))?$/', $typeOfFlow, $matches)) {
            $plugin = self::GetPlugin($matches[1]);
            if ($plugin) {
                try {
                    return $plugin['entity'](...$arguments)->init($matches[1], $matches[2] ?? '');
                } catch (Throwable $e) {
                    throw new Error('Failed to create flow: ' . $typeOfFlow);
                }
            }
        }
        throw new Error('Failed to create flow: ' . $typeOfFlow);
    }

    /**
     * @param mixed $flow
     * @return bool
     */
    static public function IsFlow(mixed $flow): bool
    {
        return is_object($flow) && is_subclass_of($flow, 'Razy\\FlowManager\\Flow');
    }

    /**
     * @return array
     */
    public function getMap(): array
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