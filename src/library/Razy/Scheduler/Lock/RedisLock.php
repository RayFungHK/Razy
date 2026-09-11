<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Scheduler\Lock;

use Redis;

/**
 * Redis overlap lock for multi-node schedulers (SET NX EX + token-safe release).
 *
 * Every K8s pod may run `schedule:run` (cron via CronJob-style runners); only
 * the pod that wins SET NX executes the job. Release uses compare-and-delete
 * (LUA) so a node can never delete a lock that already expired and was
 * re-acquired by somebody else.
 */
class RedisLock implements LockInterface
{
    /** @var string CAS release script */
    private const RELEASE_SCRIPT = <<<'LUA'
        if redis.call("get", KEYS[1]) == ARGV[1] then
            return redis.call("del", KEYS[1])
        end
        return 0
        LUA;

    /** @var array<string, string> Ownership tokens per held lock */
    private array $tokens = [];

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'razy_sched_lock_',
    ) {
    }

    public function acquire(string $name, int $maxSeconds): bool
    {
        if (isset($this->tokens[$name])) {
            return true; // Re-entrant within the same process
        }

        $token = \bin2hex(\random_bytes(16));

        if ($this->redis->set($this->prefix . $name, $token, ['NX', 'EX' => \max(1, $maxSeconds)]) !== true) {
            return false;
        }

        $this->tokens[$name] = $token;

        return true;
    }

    public function release(string $name): void
    {
        if (!isset($this->tokens[$name])) {
            return;
        }

        $this->redis->eval(self::RELEASE_SCRIPT, [$this->prefix . $name, $this->tokens[$name]], 1);
        unset($this->tokens[$name]);
    }
}
