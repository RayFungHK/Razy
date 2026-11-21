<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Extracted from Module god class (Phase 2.2).
 * Manages API and bridge command registration and execution.
 *
 *
 * @license MIT
 */

namespace Razy\Module;

use Razy\Controller;
use Razy\Exception\ModuleException;
use Razy\ModuleInfo;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Manages registered API and bridge commands for a module.
 *
 * API commands are accessible to other modules within the same distributor.
 * Bridge commands are exposed for cross-distributor communication.
 */
class CommandRegistry
{
    /** Outcome statuses of the checked execution path. */
    public const STATUS_OK = 'ok';

    public const STATUS_DENIED = 'denied';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_FAILED = 'failed';

    /** @var list<string>|null Lower-cased public method names of Controller, built on first use */
    private static ?array $reservedNames = null;

    /** @var array<string, string> Registered API commands: command => closure path */
    private array $apiCommands = [];

    /** @var array<string, string> Registered bridge commands for cross-distributor calls */
    private array $bridgeCommands = [];

    /**
     * Names an API command may not take: the public methods of Controller itself, read from the class so the list cannot drift.
     *
     * @return list<string> Lower-cased names (PHP method names are case-insensitive)
     */
    public static function reservedNames(): array
    {
        return self::$reservedNames ??= \array_map(
            static fn (ReflectionMethod $method): string => \strtolower($method->getName()),
            \array_filter(
                (new ReflectionClass(Controller::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => !$method->isStatic(),
            ),
        );
    }

    /**
     * @return array{status: 'ok'|'denied'|'unknown'|'failed', value: mixed, error: ?Throwable}
     */
    private static function outcome(string $status, mixed $value = null, ?Throwable $error = null): array
    {
        return ['status' => $status, 'value' => $value, 'error' => $error];
    }

    /**
     * Register an API command.
     *
     * Commands prefixed with {@code #} are registered both as cross-module API commands
     * and as internal bindings ({@see ClosureLoader::bind()}, {@see Controller::__call()}).
     * Prefer explicit {@code $agent->bind()} when a handler should be internal-only.
     *
     * @param string $command The API command name (prefix with {@code #} for dual registration)
     * @param string $path The closure file path
     * @param ClosureLoader $closureLoader The closure loader for optional internal binding
     *
     * @throws ModuleException If the command is already registered or is named like a Controller method
     */
    public function addAPICommand(string $command, string $path, ClosureLoader $closureLoader): void
    {
        $bindInternally = false;
        // Commands prefixed with '#' are registered both as API and internal bindings
        if ($command[0] === '#') {
            $command = \substr($command, 1);
            $bindInternally = true;
        }

        if (\in_array(\strtolower($command), self::reservedNames(), true)) {
            throw new ModuleException('The command `' . $command . '` is named like a Controller method and would run that method; pick another name.');
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
     * Whether the command name is registered as an API command.
     *
     * Mirrors executeCommand()'s lookup exactly — registration is required even
     * for slash-less commands that resolve to a controller method — so probes
     * never lie about dispatchability.
     *
     * @param string $command The command name to probe
     *
     * @return bool True when api()->{$command}() would dispatch
     */
    public function has(string $command): bool
    {
        return \array_key_exists($command, $this->apiCommands);
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
     * Execute an API command with the permission check and report the outcome.
     *
     * Same dispatch as {@see execute()}; the caller learns why there is no value.
     *
     * @return array{status: 'ok'|'denied'|'unknown'|'failed', value: mixed, error: ?Throwable}
     */
    public function executeChecked(ModuleInfo $module, string $command, array $args, Controller $controller, ClosureLoader $closureLoader): array
    {
        return $this->dispatch($this->apiCommands, $command, $args, $controller, $closureLoader, fn () => $controller->__onAPICall($module, $command), true);
    }

    /**
     * Execute an API command without a gate (operator-trust path) and report the outcome.
     *
     * @return array{status: 'ok'|'denied'|'unknown'|'failed', value: mixed, error: ?Throwable}
     */
    public function executeInternalChecked(string $command, array $args, Controller $controller, ClosureLoader $closureLoader): array
    {
        return $this->dispatch($this->apiCommands, $command, $args, $controller, $closureLoader, null, true);
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
     * @param callable|null $permissionCheck Optional permission gate; must return true to proceed
     *
     * @return mixed The command result, or null if not found, denied or failed (see dispatch())
     */
    private function executeCommand(
        array $registry,
        string $command,
        array $args,
        Controller $controller,
        ClosureLoader $closureLoader,
        ?callable $permissionCheck = null,
    ): mixed {
        return $this->dispatch($registry, $command, $args, $controller, $closureLoader, $permissionCheck)['value'];
    }

    /**
     * Run a command and say what happened.
     *
     * A handler that throws goes to the controller's __onError() hook first, then
     * comes back as `failed` with the exception. The mixed-result callers let an
     * exception from the hook propagate (as before); a checked caller always gets
     * the outcome, with the handler's exception, whatever the hook does.
     *
     * @param array<string, string> $registry The command registry to look up
     * @param callable|null $permissionCheck Optional permission gate; must return true to proceed
     * @param bool $containHook Swallow an exception thrown by __onError() (checked path)
     *
     * @return array{status: 'ok'|'denied'|'unknown'|'failed', value: mixed, error: ?Throwable}
     */
    private function dispatch(
        array $registry,
        string $command,
        array $args,
        Controller $controller,
        ClosureLoader $closureLoader,
        ?callable $permissionCheck = null,
        bool $containHook = false,
    ): array {
        if (!\array_key_exists($command, $registry)) {
            return self::outcome(self::STATUS_UNKNOWN);
        }

        try {
            if ($permissionCheck !== null && !$permissionCheck()) {
                return self::outcome(self::STATUS_DENIED);
            }

            // A subclass method named like the command wins over the closure file
            // (names of Controller's own methods cannot be registered)
            if (!\str_contains($command, '/') && \method_exists($controller, $command)) {
                $closure = [$controller, $command];
            } elseif (($loaded = $closureLoader->getClosure($registry[$command], $controller)) !== null && ($bound = $loaded->bindTo($controller)) !== null) {
                $closure = $bound;
            } else {
                return self::outcome(self::STATUS_FAILED, null, new ModuleException('The handler of command `' . $command . '` could not be loaded.'));
            }

            return self::outcome(self::STATUS_OK, \call_user_func_array($closure, $args));
        } catch (Throwable $exception) {
            try {
                $controller->__onError($command, $exception);
            } catch (Throwable $fromHook) {
                if (!$containHook) {
                    throw $fromHook;
                }
            }

            return self::outcome(self::STATUS_FAILED, null, $exception);
        }
    }
}
