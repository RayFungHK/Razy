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

namespace Razy\Queue;

use Redis;
use RuntimeException;

/**
 * Redis-backed queue store.
 *
 * Storage layout (all keys carry a configurable prefix):
 *   {p}ids:{queue}            INCR counter → job IDs
 *   {p}job:{queue}:{id}       HASH of Job::toArray() fields (payload stays JSON)
 *   {p}z:{queue}:{status}     ZSET of job ids per status
 *
 * Pending-job ordering: score = available_at_seconds + priority/1000, so a job
 * becomes poppable exactly at its availability time and lower `priority`
 * numbers win inside the same second — mirroring DatabaseStore's
 * `ORDER BY priority ASC, available_at ASC` over the available window.
 *
 * `reserve()` pops atomically via a Lua script (ZRANGEBYSCORE+ZREM can race
 * without it). The status flip + attempts++ then update the hash outside the
 * script: a crash in that microsecond window leaves the job pending — the
 * same at-most-once nuance DatabaseStore has, and stale RESERVED jobs are
 * likewise not auto-recovered (parity by design, not omission).
 *
 * Multi-key Lua assumes all keys share a hash slot: single-node or prefixed
 * cluster usage. Follows the inject-a-Redis-client pattern of the Cache/Session
 * Redis drivers (no hidden connection construction).
 */
class RedisQueueStore implements QueueStoreInterface
{
    /**
     * Pop the lowest-scoring job that has become available (score <= now).
     * Returns nil for an empty window; the ZREM in-script prevents two
     * workers claiming the same job.
     */
    private const RESERVE_SCRIPT = <<<'LUA'
        local id = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 1)[1]
        if not id then
            return nil
        end
        redis.call('ZREM', KEYS[1], id)
        return id
        LUA;

    /** @var list<string> Statuses a single job can occupy in a ZSET at any time */
    private const TRACKED_STATUSES = ['pending', 'reserved', 'failed', 'buried'];

    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'razy_q_',
    ) {
    }

    public function push(
        string $queue,
        string $handler,
        array $payload = [],
        int $delay = 0,
        int $maxAttempts = 3,
        int $retryDelay = 0,
        int $priority = 100,
    ): int|string {
        $id = (int) $this->redis->incr($this->idsKey($queue));
        $availableAt = \time() + \max(0, $delay);

        $this->redis->hMSet($this->jobKey($queue, $id), [
            'id' => (string) $id,
            'queue' => $queue,
            'handler' => $handler,
            'payload' => \json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => '0',
            'max_attempts' => (string) $maxAttempts,
            'retry_delay' => (string) $retryDelay,
            'priority' => (string) $priority,
            'available_at' => \date('Y-m-d H:i:s', $availableAt),
            'created_at' => \date('Y-m-d H:i:s'),
            'reserved_at' => '',
            'status' => JobStatus::Pending->value,
            'error' => '',
        ]);

        $this->redis->zAdd($this->zKey($queue, JobStatus::Pending->value), $this->score($availableAt, $priority), (string) $id);

        // The design (idsKey() below) says "the push-time index maps id →
        // queue" — and this SET was the line the implementation never wrote:
        // locate() read an index nobody created, so release()/bury()/find()
        // threw "job not found" for EVERY job since this store shipped. CI's
        // real-redis suite caught it 47 releases of red later (2026-09).
        $this->redis->set($this->indexKey($id), $queue);

        return $id;
    }

    public function reserve(string $queue): ?Job
    {
        // Availability window: everything scored at or below "now + 0.999" is
        // due this second (priority lives in the sub-second fraction). Using a
        // full +1 here would let a delay=1 job pop one second early.
        $raw = $this->redis->eval(self::RESERVE_SCRIPT, [$this->zKey($queue, JobStatus::Pending->value), (string) (\time() + 0.999)], 1);

        // phpredis surfaces Lua nil as false/''/[] depending on version/cluster mode.
        if ($raw === false || $raw === '' || $raw === null || $raw === []) {
            return null;
        }

        $id = (int) $raw;
        $key = $this->jobKey($queue, $id);
        $row = $this->redis->hGetAll($key);

        if ($row === false || $row === []) {
            // Job hash vanished (deleted mid-reserve) — drop and report empty.
            return null;
        }

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $now = \date('Y-m-d H:i:s');

        $this->redis->hMSet($key, [
            'status' => JobStatus::Reserved->value,
            'attempts' => (string) $attempts,
            'reserved_at' => $now,
        ]);
        $this->redis->zAdd($this->zKey($queue, JobStatus::Reserved->value), (float) \time(), (string) $id);

        return Job::fromArray([...$row, 'id' => $id, 'attempts' => $attempts, 'status' => JobStatus::Reserved->value, 'reserved_at' => $now]);
    }

    public function complete(int|string $jobId): void
    {
        $this->removeJob($jobId);
    }

    public function release(int|string $jobId, int $retryDelay = 0, string $error = ''): void
    {
        [$queue, $row] = $this->locate($jobId);

        $availableAt = \time() + \max(0, $retryDelay);
        $priority = (int) ($row['priority'] ?? 100);

        $this->redis->hMSet($this->jobKey($queue, $jobId), [
            'status' => JobStatus::Pending->value,
            'available_at' => \date('Y-m-d H:i:s', $availableAt),
            'reserved_at' => '',
            'error' => $error,
        ]);
        $this->redis->zAdd($this->zKey($queue, JobStatus::Pending->value), $this->score($availableAt, $priority), (string) $jobId);
    }

    public function bury(int|string $jobId, string $error = ''): void
    {
        [$queue, $row] = $this->locate($jobId);

        $this->redis->hMSet($this->jobKey($queue, $jobId), [
            'status' => JobStatus::Buried->value,
            'error' => $error,
        ]);
        $this->redis->zAdd($this->zKey($queue, JobStatus::Buried->value), (float) \time(), (string) $jobId);
    }

    public function delete(int|string $jobId): void
    {
        $this->removeJob($jobId);
    }

    public function find(int|string $jobId): ?Job
    {
        [$queue, $row] = $this->locate($jobId, quiet: true);

        if ($row === []) {
            return null;
        }

        return Job::fromArray([...$row, 'id' => $jobId]);
    }

    public function count(string $queue, JobStatus $status): int
    {
        // Completed jobs are deleted on complete() — count(Completed) is 0,
        // exactly matching DatabaseStore (whose complete() deletes rows too).
        return (int) $this->redis->zCard($this->zKey($queue, $status->value));
    }

    public function clear(string $queue): int
    {
        $zBuried = $this->zKey($queue, JobStatus::Buried->value);
        $ids = $this->redis->zRange($zBuried, 0, -1);

        foreach ($ids as $id) {
            $this->redis->del($this->jobKey($queue, (int) $id));
        }
        $this->redis->del($zBuried);

        return \count($ids);
    }

    public function ensureStorage(): void
    {
        // Redis needs no schema; fail loudly when the connection is unusable.
        if (!$this->redis->ping()) {
            throw new RuntimeException('RedisQueueStore: connection to Redis failed.');
        }
    }

    // ── Internal ──────────────────────────────────────────────

    private function locate(int|string $jobId, bool $quiet = false): array
    {
        $queue = $this->redis->get($this->indexKey($jobId));

        if (!\is_string($queue) || $queue === '') {
            if ($quiet) {
                return ['', []];
            }
            throw new RuntimeException('Queue job not found: ' . $jobId);
        }

        $row = $this->redis->hGetAll($this->jobKey($queue, $jobId));

        if ($row === false) {
            $row = [];
        }

        if ($row === [] && !$quiet) {
            throw new RuntimeException('Queue job not found: ' . $jobId);
        }

        return [$queue, $row];
    }

    private function removeJob(int|string $jobId): void
    {
        [$queue] = $this->locate($jobId, quiet: true);

        $this->redis->del($this->jobKey($queue, $jobId));
        $this->redis->del($this->indexKey($jobId));

        foreach (self::TRACKED_STATUSES as $status) {
            $this->redis->zRem($this->zKey($queue, $status), (string) $jobId);
        }
    }

    private function score(int $availableAt, int $priority): float
    {
        // Priority occupies the sub-second fraction (capped to <1s so a high
        // priority value can never spill into the next availability second).
        return $availableAt + \min(\max($priority, 0), 999) / 1000;
    }

    private function idsKey(string $queue): string
    {
        // GLOBAL id counter: complete()/release()/find() address jobs by bare
        // id (interface contract, same as DatabaseStore's single-table PK), so
        // ids must be unique across every queue — a per-queue counter would
        // collide. The push-time index maps id → queue for later lookups.
        return $this->prefix . 'ids';
    }

    private function jobKey(string $queue, int|string $id): string
    {
        return $this->prefix . 'job:' . $queue . ':' . $id;
    }

    private function zKey(string $queue, string $status): string
    {
        return $this->prefix . 'z:' . $queue . ':' . $status;
    }

    private function indexKey(int|string $jobId): string
    {
        return $this->prefix . 'index:' . $jobId;
    }
}
