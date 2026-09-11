<?php
/**
 * razymod/queue-admin — dashboard core service.
 *
 * Deliberately store-agnostic: talks ONLY to QueueStoreInterface, so the same
 * code serves DatabaseStore and RedisQueueStore. The store is injected (or
 * resolved by the caller) — this class performs no IO of its own and is fully
 * unit-testable (tests/QueueAdminServiceTest.php).
 *
 * Known limitation, stated honestly: QueueStoreInterface cannot enumerate
 * queue names (count() needs the name), so `status()` takes the queue list
 * from the caller (module setting / query param) instead of auto-discovery.
 */

namespace Razy\Module\queueadmin;

use Razy\Queue\JobStatus;
use Razy\Queue\QueueStoreInterface;
use Throwable;

final class QueueAdminService
{
    /** Action kinds accepted by action(); anything else is rejected. */
    public const KINDS = ['release', 'bury', 'delete'];

    public function __construct(
        private readonly QueueStoreInterface $store,
    ) {
        $this->store->ensureStorage();
    }

    /**
     * Per-queue counts for every status.
     *
     * @param list<string> $queues
     *
     * @return array<string, array<string, int>>
     */
    public function status(array $queues = ['default']): array
    {
        $out = [];

        foreach ($queues as $queue) {
            $queue = (string) $queue;

            foreach (JobStatus::cases() as $case) {
                $out[$queue][$case->value] = $this->store->count($queue, $case);
            }
        }

        return $out;
    }

    /**
     * Normalised job snapshot for display/API.
     *
     * @return array<string, mixed>|null null when the id does not exist
     */
    public function job(int|string $id): ?array
    {
        $job = $this->store->find($id);

        if ($job === null) {
            return null;
        }

        return [
            'id'           => $job->id,
            'queue'        => $job->queue,
            'handler'      => $job->handler,
            'payload'      => $job->payload,
            'attempts'     => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'retry_delay'  => $job->retryDelay,
            'priority'     => $job->priority,
            'status'       => $job->status->value,
            'available_at' => $job->availableAt,
            'created_at'   => $job->createdAt,
            'error'        => $job->error,
        ];
    }

    /**
     * Guarded mutation. Existence + kind are validated BEFORE touching the
     * store; unknown ids/kinds return ok:false without exceptions.
     *
     * @return array{ok: bool, error?: string, job?: array<string, mixed>|null}
     */
    public function action(int|string $id, string $kind, int $retryDelay = 0): array
    {
        if (!\in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'error' => 'unknown action kind: ' . $kind];
        }

        $job = $this->store->find($id);

        if ($job === null) {
            return ['ok' => false, 'error' => 'job not found: ' . $id];
        }

        try {
            if ($kind === 'delete') {
                $this->store->delete($id);
            } elseif ($kind === 'bury') {
                $this->store->bury($id, (string) ($job->error ?? 'buried via queue-admin'));
            } else {
                $this->store->release($id, \max(0, $retryDelay), 'released via queue-admin');
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e::class . ': ' . $e->getMessage()];
        }

        return ['ok' => true, 'job' => $this->job($id)]; // delete → job nulls out
    }

    /**
     * Purge finished/buried jobs of one queue (store clear() semantics).
     *
     * @return array{ok: bool, cleared: int}
     */
    public function purge(string $queue): array
    {
        return ['ok' => true, 'cleared' => $this->store->clear($queue)];
    }
}
