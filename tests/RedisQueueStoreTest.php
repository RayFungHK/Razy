<?php

declare(strict_types=1);

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Queue\JobStatus;
use Razy\Queue\RedisQueueStore;
use Redis;
use Throwable;

/**
 * RedisQueueStore semantics. Requires ext-redis AND a reachable Redis on
 * 127.0.0.1:6379 (CI docker job provides it; local runs skip honestly).
 *
 * Each test runs against a unique throwaway prefix and flushes it after.
 */
#[CoversClass(RedisQueueStore::class)]
class RedisQueueStoreTest extends TestCase
{
    private ?Redis $redis = null;

    private string $prefix = '';

    private RedisQueueStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis not loaded');
        }

        $redis = new Redis();

        try {
            @$redis->connect('127.0.0.1', 6379, 1.0);
            $redis->ping();
        } catch (Throwable) {
            $this->markTestSkipped('no Redis on 127.0.0.1:6379');
        }

        $this->redis = $redis;
        $this->prefix = 'razy_test_' . \bin2hex(\random_bytes(4)) . ':';
        $this->store = new RedisQueueStore($redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        if ($this->redis !== null) {
            foreach ($this->redis->keys($this->prefix . '*') ?: [] as $key) {
                $this->redis->del($key);
            }
            $this->redis->close();
        }
        parent::tearDown();
    }

    public function testEnsureStoragePings(): void
    {
        $this->store->ensureStorage(); // must not throw against a live server
    }

    public function testPushReturnsIncrementingIdsAndCounts(): void
    {
        $id1 = $this->store->push('emails', 'App\JobA', ['to' => 'a@b.c']);
        $id2 = $this->store->push('emails', 'App\JobB');

        $this->assertIsInt($id1);
        $this->assertGreaterThan($id1, $id2);
        $this->assertSame(2, $this->store->count('emails', JobStatus::Pending));
        $this->assertSame(0, $this->store->count('other', JobStatus::Pending));
    }

    public function testReserveReturnsOldestJobAsReservedWithAttemptBumped(): void
    {
        $id = $this->store->push('emails', 'App\JobA', ['n' => 7]);

        $job = $this->store->reserve('emails');

        $this->assertNotNull($job);
        $this->assertSame($id, $job->id);
        $this->assertSame('App\JobA', $job->handler);
        $this->assertSame(['n' => 7], $job->payload);
        $this->assertSame(1, $job->attempts, 'attempts++ at reserve, like DatabaseStore');
        $this->assertSame(JobStatus::Reserved, $job->status);
        $this->assertSame(0, $this->store->count('emails', JobStatus::Pending));
        $this->assertSame(1, $this->store->count('emails', JobStatus::Reserved));
    }

    public function testReserveEmptyReturnsNull(): void
    {
        $this->assertNull($this->store->reserve('nothing_here'));
    }

    public function testPriorityOrderingWithinOneSecond(): void
    {
        $this->store->push('p', 'Low', [], 0, 3, 0, 50);
        $this->store->push('p', 'High', [], 0, 3, 0, 1);

        $first = $this->store->reserve('p');

        $this->assertSame('High', $first?->handler, 'lower priority number pops first');
    }

    public function testDelayHidesJobUntilAvailable(): void
    {
        $this->store->push('d', 'Later', [], 3600);

        $this->assertNull($this->store->reserve('d'), 'delayed job must not be poppable now');
    }

    public function testCompleteRemovesEveryTrace(): void
    {
        $id = $this->store->push('c', 'App\JobA');
        $this->store->reserve('c');

        $this->store->complete($id);

        $this->assertNull($this->store->find($id));
        $this->assertSame(0, $this->store->count('c', JobStatus::Reserved));
    }

    public function testReleaseReturnsJobToPendingWithBackoff(): void
    {
        $id = $this->store->push('r', 'App\JobA');
        $this->store->reserve('r');

        $this->store->release($id, 0, 'boom');

        $this->assertSame(1, $this->store->count('r', JobStatus::Pending));
        $again = $this->store->reserve('r');
        $this->assertNotNull($again);
        $this->assertSame(2, $again->attempts);
    }

    public function testBuryPersistsErrorAndClearCollects(): void
    {
        $id = $this->store->push('b', 'App\JobA');
        $this->store->bury($id, 'dead');

        $job = $this->store->find($id);
        $this->assertNotNull($job);
        $this->assertSame(JobStatus::Buried, $job->status);
        $this->assertSame('dead', $job->error);
        $this->assertSame(1, $this->store->count('b', JobStatus::Buried));

        $this->assertSame(1, $this->store->clear('b'));
        $this->assertNull($this->store->find($id));
    }

    public function testDeleteRemovesJob(): void
    {
        $id = $this->store->push('x', 'App\JobA');
        $this->store->delete($id);

        $this->assertNull($this->store->find($id));
    }

    public function testIdsAreUniqueAcrossQueues(): void
    {
        $a = $this->store->push('q1', 'J');
        $b = $this->store->push('q2', 'J');

        $this->assertNotSame($a, $b, 'bare-id addressing (complete/find) requires a global id space');
    }

    public function testCrossQueueIsolation(): void
    {
        $id = $this->store->push('q1', 'J');

        $this->assertNull($this->store->reserve('q2'));
        $this->assertSame(1, $this->store->count('q1', JobStatus::Pending));
        $this->store->complete($id);
    }
}
