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
 * Manages closure file loading, caching, and method binding for a module's controller.
 *
 *
 * @license MIT
 */

namespace Razy\Module;

use Closure;
use Razy\Controller;
use Razy\Exception\ModuleLoadException;
use Razy\ModuleInfo;
use Razy\Util\PathUtil;

/**
 * Loads and caches closure files from a module's controller directory,
 * and manages method-name-to-closure-path bindings.
 *
 * Closures are loaded once and cached, bound to the controller's scope
 * for `$this` access within the closure.
 */
class ClosureLoader
{
    /** @var array<string, string> Bound method-name => closure-path mappings */
    private array $binding = [];

    /** @var array<string, Closure> Cache of loaded closure files keyed by full path */
    private array $closures = [];

    /**
     * @param ModuleInfo $moduleInfo Module metadata for path resolution
     * @param bool $strict Whether strict mode is enabled (missing closures throw errors)
     * @param string $distCode Distributor code for error messages
     */
    public function __construct(
        private readonly ModuleInfo $moduleInfo,
        private readonly bool $strict,
        private readonly string $distCode,
    ) {
    }

    /**
     * Bind a method name to a closure file path.
     *
     * @param string $method The method name to bind
     * @param string $path The closure file path
     */
    public function bind(string $method, string $path): void
    {
        $path = \trim($path);
        if ($path) {
            $path = PathUtil::tidy($path);
            $this->binding[$method] = $path;
        }
    }

    /**
     * Get the bound closure path for a method name.
     *
     * @param string $method The method name
     *
     * @return string The path, or empty string if not bound
     */
    public function getBinding(string $method): string
    {
        return $this->binding[$method] ?? '';
    }

    /**
     * Load the closure under the module controller folder.
     *
     * Resolves the path, checks for a direct controller method,
     * loads and caches the closure file, and binds it to the controller's scope.
     *
     * @param string $path The closure path (relative to controller directory)
     * @param Controller $controller The controller instance for binding
     *
     * @return Closure|null The loaded closure, or null if not found (non-strict mode)
     *
     * @throws ModuleLoadException If the file doesn't return a Closure, or is missing in strict mode
     */
    public function getClosure(string $path, Controller $controller): ?Closure
    {
        // Fast-path: check closure cache FIRST using the raw path as key.
        // For controller methods (the most common hot-path case), the closure
        // was cached on the first request and subsequent lookups skip all the
        // PathUtil::tidy / substr_count / method_exists overhead entirely.
        if (isset($this->closures[$path])) {
            return $this->closures[$path];
        }

        $originalPath = $path;
        $path = PathUtil::tidy($path, false, '/');

        if (!\str_contains($path, '/')) {
            // No directory separator — check if it's a direct controller method
            if (\method_exists($controller, $path)) {
                $closure = $controller->$path(...);
                // Cache under the original (raw) path for next-request fast-path
                $this->closures[$originalPath] = $closure;
                return $closure;
            }
            // Standalone closure files are prefixed with the module class name
            $path = $this->moduleInfo->getClassName() . '.' . $path;
        }

        // Resolve full filesystem path to the closure PHP file
        $fullPath = PathUtil::append($this->moduleInfo->getPath(), 'controller', $path . '.php');

        if (!isset($this->closures[$fullPath])) {
            if (\is_file($fullPath)) {
                /** @var mixed $closure */
                $closure = require $fullPath;
                if (!($closure instanceof Closure)) {
                    throw new ModuleLoadException("File '{$fullPath}' in module '{$this->moduleInfo->getCode()}' must return a Closure, got " . \gettype($closure) . '.');
                }
                // Bind the closure to the controller's scope for $this access
                $this->closures[$fullPath] = $closure->bindTo($controller, \get_class($controller));
            } elseif ($this->strict) {
                // In strict mode, missing closure files cause a hard error
                throw new ModuleLoadException(
                    "Missing closure file in module '{$this->moduleInfo->getCode()}'.\n" .
                    "Expected file: {$fullPath}\n" .
                    "Closure path: {$originalPath}\n" .
                    "Run 'php Razy.phar validate {$this->distCode} --generate' to create dummy files.",
                );
            }
        }

        $result = $this->closures[$fullPath] ?? null;
        // Also cache under the original raw path for fast-path lookup next time
        if ($result !== null) {
            $this->closures[$originalPath] = $result;
        }
        return $result;
    }

    /**
     * Reset all bindings and cached closures (used in worker mode between requests).
     */
    public function reset(): void
    {
        $this->binding = [];
        $this->closures = [];
    }
}
