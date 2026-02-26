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
 * Manages API and bridge command registration and execution.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Module;

use Razy\Controller;
use Razy\Exception\ModuleException;
use Razy\ModuleInfo;
use Throwable;

/**
 * Manages registered API and bridge commands for a module.
 *
 * API commands are accessible to other modules within the same distributor.
 * Bridge commands are exposed for cross-distributor communication.
 */
class CommandRegistry
{
    /** @var array<string, string> Registered API commands: command => closure path */
    private array $apiCommands = [];

    /** @var array<string, string> Registered bridge commands for cross-distributor calls */
    private array $bridgeCommands = [];

    /**
     * Register an API command.
     *
     * Commands prefixed with '#' are registered both as API commands
     * and as internal bindings (accessible via Controller::__call).
     *
     * @param string $command The API command name (prefix with '#' for dual registration)
     * @param string $path The closure file path
     * @param ClosureLoader $closureLoader The closure loader for optional internal binding
     *
     * @throws ModuleException If the command is already registered
     */
    public function addAPICommand(string $command, string $path, ClosureLoader $closureLoader): void
    {
        $bindInternally = false;
        // Commands prefixed with '#' are registered both as API and internal bindings
        if ($command[0] === '#') {
            $command = \substr($command, 1);
            $bindInternally = true;
        }

        if (\array_key_exists($command, $this->apiCommands)) {
            throw new ModuleException('The command `' . $command . '` is already registered.');
        }
        $this->apiCommands[$command] = $path;

        // Also bind internally so the controller can call it via $this->bind()
        if ($bindInternally) {
            $closureLoader->bind($command, $path);
        }
    }

    /**
     * Get all registered API commands.
     *
     * @return array<string, string> Array of command => closure path
     */
    public function getAPICommands(): array
    {
        return $this->apiCommands;
    }

    /**
     * Register a bridge command for cross-distributor communication.
     *
     * @param string $command The bridge command name
     * @param string $path The path to the closure file
     *
     * @throws ModuleException If the command is already registered
     */
    public function addBridgeCommand(string $command, string $path): void
    {
        if (\array_key_exists($command, $this->bridgeCommands)) {
            throw new ModuleException('The bridge command `' . $command . '` is already registered.');
        }
        $this->bridgeCommands[$command] = $path;
    }

    /**
     * Get all registered bridge commands.
     *
     * @return array<string, string> Array of command => closure path
     */
    public function getBridgeCommands(): array
    {
        return $this->bridgeCommands;
    }

    /**
     * Execute an API command with permission check.
     *
     * The controller's __onAPICall method is consulted before execution.
     *
     * @param ModuleInfo $module The requesting module's info
     * @param string $command The API command to execute
     * @param array $args The arguments to pass
     * @param Controller $controller The module's controller
     * @param ClosureLoader $closureLoader The closure loader for resolving command handlers
     *
     * @return mixed The command result, or null if not found/denied
     */
    public function execute(ModuleInfo $module, string $command, array $args, Controller $controller, ClosureLoader $closureLoader): mixed
    {
        return $this->executeCommand(
            $this->apiCommands,
            $command,
            $args,
            $controller,
            $closureLoader,
            fn () => $controller->__onAPICall($module, $command),
        );
    }

    /**
     * Execute an API command internally without ModuleInfo validation.
     * Used by the internal HTTP bridge.
     *
     * @param string $command The command to execute
     * @param array $args The arguments to pass
     * @param Controller $controller The module's controller
     * @param ClosureLoader $closureLoader The closure loader for resolving command handlers
     *
     * @return mixed The command result, or null if not found
     */
    public function executeInternalCommand(string $command, array $args, Controller $controller, ClosureLoader $closureLoader): mixed
    {
        return $this->executeCommand(
            $this->apiCommands,
            $command,
            $args,
            $controller,
            $closureLoader,
        );
    }

    /**
     * Execute a bridge command for cross-distributor calls.
     *
     * The controller's __onBridgeCall method is consulted before execution.
     *
     * @param string $sourceDistributor The identifier of the calling distributor
     * @param string $command The bridge command to execute
     * @param array $args The arguments to pass
     * @param Controller $controller The module's controller
     * @param ClosureLoader $closureLoader The closure loader for resolving command handlers
     *
     * @return mixed The command result, or null if not found/denied
     */
    public function executeBridgeCommand(string $sourceDistributor, string $command, array $args, Controller $controller, ClosureLoader $closureLoader): mixed
    {
        return $this->executeCommand(
            $this->bridgeCommands,
            $command,
            $args,
            $controller,
            $closureLoader,
            fn () => $controller->__onBridgeCall($sourceDistributor, $command),
        );
    }

    /**
     * Unified command execution logic shared by API, internal, and bridge commands.
     *
     * @param array<string, string> $registry The command registry to look up
     * @param string $command The command name
     * @param array $args The arguments to pass
     * @param Controller $controller The module's controller
     * @param ClosureLoader $closureLoader The closure loader for resolving handlers
     * @param callable|null $permissionCheck Optional permission gate; must return true to proceed
     *
     * @return mixed The command result, or null if not found/denied
     */
    private function executeCommand(
        array $registry,
        string $command,
        array $args,
        Controller $controller,
        ClosureLoader $closureLoader,
        ?callable $permissionCheck = null,
    ): mixed {
        $result = null;
        try {
            if (\array_key_exists($command, $registry)) {
                // Check permission if a gate callback is provided
                if ($permissionCheck !== null && !$permissionCheck()) {
                    return null;
                }

                $closure = null;
                // Prefer a direct controller method if the command has no path separator
                if (!\str_contains($command, '/') && \method_exists($controller, $command)) {
                    $closure = [$controller, $command];
                } elseif (($loaded = $closureLoader->getClosure($registry[$command], $controller)) !== null) {
                    $closure = $loaded->bindTo($controller);
                }

                if ($closure) {
                    $result = \call_user_func_array($closure, $args);
                }
            }
        } catch (Throwable $exception) {
            $controller->__onError($command, $exception);
        }

        return $result;
    }
}
