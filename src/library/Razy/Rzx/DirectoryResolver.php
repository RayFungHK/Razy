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

    /**
     * @param array<string, string> $namespaces
     */
    public function __construct(array $namespaces = [])
    {
        foreach ($namespaces as $namespace => $directory) {
            $this->add((string) $namespace, $directory);
        }
    }

    public function add(string $namespace, string $directory): self
    {
        if ($namespace !== '' && !Names::isComponent($namespace)) {
            throw new InvalidArgumentException('Invalid .rzx namespace: ' . $namespace);
        }
        $this->namespaces[$namespace] = \rtrim($directory, '/\\');

        return $this;
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
        $path = $this->namespaces[$namespace] . \DIRECTORY_SEPARATOR . $local . '.rzx';

        return \is_file($path) ? $path : null;
    }
}
