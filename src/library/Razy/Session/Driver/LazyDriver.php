<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Session\Driver;

use Closure;
use Razy\Contract\SessionDriverInterface;

/**
 * RZ-05: builds the real driver on first use, so arming a dist session at
 * boot (and CLI paths that only introspect a dist) opens no connection and
 * runs no DDL.
 */
final class LazyDriver implements SessionDriverInterface
{
    private ?SessionDriverInterface $driver = null;

    /**
     * @param Closure(): SessionDriverInterface $factory
     */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function resolve(): SessionDriverInterface
    {
        return $this->driver ??= ($this->factory)();
    }

    public function open(): bool
    {
        return $this->resolve()->open();
    }

    public function close(): bool
    {
        return $this->resolve()->close();
    }

    public function read(string $id): array
    {
        return $this->resolve()->read($id);
    }

    public function write(string $id, array $data): bool
    {
        return $this->resolve()->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->resolve()->destroy($id);
    }

    public function gc(int $maxLifetime): int
    {
        return $this->resolve()->gc($maxLifetime);
    }
}
