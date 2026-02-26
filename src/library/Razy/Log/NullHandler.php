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

namespace Razy\Log;

use Razy\Contract\Log\LogHandlerInterface;

/**
 * Null log handler — silently discards all log messages.
 *
 * Useful for testing or disabling logging on specific channels.
 */
class NullHandler implements LogHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public function handle(string $level, string $message, array $context, string $timestamp, string $channel): void
    {
        // Intentionally empty — discard everything
    }

    /**
     * {@inheritdoc}
     */
    public function isHandling(string $level): bool
    {
        return true;
    }
}
