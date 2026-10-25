<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

use InvalidArgumentException;

/**
 * Resolves component names from namespace directories:
 * `['' => '/app/rzx', 'Kit' => '/pkg/kit/rzx']` maps `Card` to
 * `/app/rzx/Card.rzx` and `Kit.Dialog` to `/pkg/kit/rzx/Dialog.rzx`.
 *
 * Names are validated PascalCase segments, so a name can never walk out of
 * its directory. Module and package registration builds on this (RZ-28c).
 */
final class DirectoryResolver implements ComponentResolver
{
    /** @var array<string, string> namespace => directory */
    private array $namespaces = [];

    /** @var array<string, string> namespace => profile name */
    private array $profiles = [];

    /**
     * @param array<string, string> $namespaces
     */
    public function __construct(array $namespaces = [])
    {
        foreach ($namespaces as $namespace => $directory) {
            $this->add((string) $namespace, $directory);
        }
    }

    public function add(string $namespace, string $directory, ?string $profile = null): self
    {
        if ($namespace !== '' && !Names::isComponent($namespace)) {
            throw new InvalidArgumentException('Invalid .rzx namespace: ' . $namespace);
        }
        $this->namespaces[$namespace] = \rtrim(\str_replace('\\', '/', $directory), '/');
        if ($profile !== null) {
            $this->profiles[$namespace] = $profile;
        }

        return $this;
    }

    /**
     * @return array<string, string> namespace => directory
     */
    public function namespaces(): array
    {
        return $this->namespaces;
    }

    public function profile(string $name): ?string
    {
        $dot = \strrpos($name, '.');

        return $this->profiles[$dot === false ? '' : \substr($name, 0, $dot)] ?? null;
    }

    /**
     * Every component name under the registered directories (for deploy builds).
     *
     * @return list<string>
     */
    public function all(): array
    {
        $names = [];
        foreach ($this->namespaces as $namespace => $directory) {
            foreach (\glob($directory . '/*.rzx') ?: [] as $file) {
                $local = \basename($file, '.rzx');
                if (Names::isComponent($local)) {
                    $names[] = $namespace === '' ? $local : $namespace . '.' . $local;
                }
            }
        }
        \sort($names);

        return $names;
    }

    public function resolve(string $name): ?string
    {
        if (!Names::isComponent($name)) {
            return null;
        }
        $dot = \strrpos($name, '.');
        $namespace = $dot === false ? '' : \substr($name, 0, $dot);
        $local = $dot === false ? $name : \substr($name, $dot + 1);
        if (!isset($this->namespaces[$namespace])) {
            return null;
        }
        $path = $this->namespaces[$namespace] . '/' . $local . '.rzx';

        return \is_file($path) ? $path : null;
    }
}
