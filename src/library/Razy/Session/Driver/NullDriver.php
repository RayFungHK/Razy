<?php

/**
 * This file is part of Razy v0.5.
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

use Razy\Contract\SessionDriverInterface;

/**
 * Null session driver — discards all data.
 *
 * Useful for stateless request contexts (APIs, CLI commands, etc.)
 * where session support is structurally expected but not needed.
 */
class NullDriver implements SessionDriverInterface
{
    /**
     * {@inheritdoc}
     */
    public function open(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $id): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $id, array $data): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): int
    {
        return 0;
    }
}
