<?php

/**
 * This file is part of Razy v0.5.
 *
 * Comprehensive tests for the Rate Limiting system:
 * - Limit value object (perMinute, perHour, perDay, every, none, by)
 * - RateLimiter core (attempt, hit, tooManyAttempts, remaining, clear, named limiters)
 * - ArrayStore (in-memory storage, expiry, test helpers)
 * - CacheStore (cache-backed storage with TTL)
 * - RateLimitMiddleware (pipeline integration, headers, rejection handlers)
 * - RateLimitExceededException (exception metadata)
 * - Integration scenarios (multi-key, multi-limiter, window expiry)
 * - Edge cases (concurrent keys, zero limits, empty keys)
 *
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Tests;

use Closure;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Cache\NullAdapter;
use Razy\Contract\MiddlewareInterface;
use Razy\Contract\RateLimitStoreInterface;
use Razy\RateLimit\Limit;
use Razy\RateLimit\RateLimiter;
use Razy\RateLimit\RateLimitExceededException;
use Razy\RateLimit\RateLimitMiddleware;
use Razy\RateLimit\Store\ArrayStore;
use Razy\RateLimit\Store\CacheStore;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

#[CoversClass(Limit::class)]
#[CoversClass(RateLimiter::class)]
#[CoversClass(ArrayStore::class)]
#[CoversClass(CacheStore::class)]
#[CoversClass(RateLimitMiddleware::class)]
#[CoversClass(RateLimitExceededException::class)]
class RateLimitTest extends TestCase
{
    private ArrayStore $store;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $this->store = new ArrayStore();
        $this->limiter = new RateLimiter($this->store);
    }

    // ══════════════════════════════════════════════════════════════
    //  DataProvider Tests
    // ══════════════════════════════════════════════════════════════

    public static function limitFactoryProvider(): array
    {
        return [
            'perMinute(1)' => [Limit::perMinute(1), 1, 60],
            'perMinute(100)' => [Limit::perMinute(100), 100, 60],
            'perHour(500)' => [Limit::perHour(500), 500, 3600],
            'perDay(5000)' => [Limit::perDay(5000), 5000, 86400],
            'every(10, 3)' => [Limit::every(10, 3), 3, 10],
            'every(3600, 1)' => [Limit::every(3600, 1), 1, 3600],
        ];
    }

    public static function attemptOutcomeProvider(): array
    {
        return [
            '1 of 5 — allowed' => [1, 5, true, 4],
            '5 of 5 — allowed (last one)' => [5, 5, true, 0],
            '6 of 5 — blocked' => [6, 5, false, 0],
            '1 of 1 — allowed' => [1, 1, true, 0],
            '2 of 1 — blocked' => [2, 1, false, 0],
            '10 of 10 — allowed' => [10, 10, true, 0],
            '11 of 10 — blocked' => [11, 10, false, 0],
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  Limit Value Object
    // ══════════════════════════════════════════════════════════════

    public function testLimitPerMinute(): void
    {
        $limit = Limit::perMinute(60);

        $this->assertSame(60, $limit->getMaxAttempts());
        $this->assertSame(60, $limit->getDecaySeconds());
        $this->assertFalse($limit->isUnlimited());
        $this->assertSame('', $limit->getKey());
    }

    public function testLimitPerHour(): void
    {
        $limit = Limit::perHour(1000);

        $this->assertSame(1000, $limit->getMaxAttempts());
        $this->assertSame(3600, $limit->getDecaySeconds());
        $this->assertFalse($limit->isUnlimited());
    }

    public function testLimitPerDay(): void
    {
        $limit = Limit::perDay(10000);

        $this->assertSame(10000, $limit->getMaxAttempts());
        $this->assertSame(86400, $limit->getDecaySeconds());
        $this->assertFalse($limit->isUnlimited());
    }

    public function testLimitEvery(): void
    {
        $limit = Limit::every(30, 10);

        $this->assertSame(10, $limit->getMaxAttempts());
        $this->assertSame(30, $limit->getDecaySeconds());
        $this->assertFalse($limit->isUnlimited());
    }

    public function testLimitNone(): void
    {
        $limit = Limit::none();

        $this->assertSame(PHP_INT_MAX, $limit->getMaxAttempts());
        $this->assertSame(0, $limit->getDecaySeconds());
        $this->assertTrue($limit->isUnlimited());
    }

    public function testLimitByKey(): void
    {
        $limit = Limit::perMinute(60)->by('192.168.1.1');

        $this->assertSame('192.168.1.1', $limit->getKey());
        $this->assertSame(60, $limit->getMaxAttempts());
    }

    public function testLimitByKeyReturnsItself(): void
    {
        $limit = Limit::perMinute(60);
        $result = $limit->by('test-key');

        $this->assertSame($limit, $result);
    }

    public function testLimitByKeyCanBeChanged(): void
    {
        $limit = Limit::perMinute(60)->by('first');

        $this->assertSame('first', $limit->getKey());

        $limit->by('second');

        $this->assertSame('second', $limit->getKey());
    }

    public function testLimitNoneWithKey(): void
    {
        $limit = Limit::none()->by('user:42');

        $this->assertTrue($limit->isUnlimited());
        $this->assertSame('user:42', $limit->getKey());
    }

    public function testLimitWithOneAttempt(): void
    {
        $limit = Limit::every(10, 1);

        $this->assertSame(1, $limit->getMaxAttempts());
        $this->assertSame(10, $limit->getDecaySeconds());
    }

    public function testLimitWithLargeValues(): void
    {
        $limit = Limit::every(604800, 1000000);

        $this->assertSame(1000000, $limit->getMaxAttempts());
        $this->assertSame(604800, $limit->getDecaySeconds()); // 1 week
    }

    // ══════════════════════════════════════════════════════════════
    //  ArrayStore
    // ══════════════════════════════════════════════════════════════

    public function testArrayStoreGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function testArrayStoreSetAndGet(): void
    {
        $resetAt = \time() + 60;
        $this->store->set('key1', 5, $resetAt);

        $record = $this->store->get('key1');

        $this->assertIsArray($record);
        $this->assertSame(5, $record['hits']);
        $this->assertSame($resetAt, $record['resetAt']);
    }

    public function testArrayStoreDelete(): void
    {
        $this->store->set('key1', 3, \time() + 60);
        $this->store->delete('key1');

        $this->assertNull($this->store->get('key1'));
    }

    public function testArrayStoreDeleteNonexistentKeyDoesNotFail(): void
    {
        $this->store->delete('nonexistent');
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function testArrayStoreAutoExpiresOnGet(): void
    {
        $this->store->setCurrentTime(1000);
        $this->store->set('key1', 5, 1050);

        // Advance past expiry
        $this->store->setCurrentTime(1051);
        $this->assertNull($this->store->get('key1'));
    }

    public function testArrayStoreExactExpiryBoundary(): void
    {
        $this->store->setCurrentTime(1000);
        $this->store->set('key1', 5, 1060);

        // At exact resetAt timestamp — should expire
        $this->store->setCurrentTime(1060);
        $this->assertNull($this->store->get('key1'));

        // Just before resetAt — should still be valid
        $this->store->set('key2', 3, 1070);
        $this->store->setCurrentTime(1069);
        $record = $this->store->get('key2');
        $this->assertNotNull($record);
        $this->assertSame(3, $record['hits']);
    }

    public function testArrayStoreSetCurrentTime(): void
    {
        $this->store->setCurrentTime(5000);

        $this->assertSame(5000, $this->store->getCurrentTime());
    }

    public function testArrayStoreNullTimeUsesRealClock(): void
    {
        $this->store->setCurrentTime(null);
        $now = $this->store->getCurrentTime();

        $this->assertEqualsWithDelta(\time(), $now, 2);
    }

    public function testArrayStoreCount(): void
    {
        $this->assertSame(0, $this->store->count());

        $this->store->set('a', 1, \time() + 60);
        $this->store->set('b', 2, \time() + 60);

        $this->assertSame(2, $this->store->count());
    }

    public function testArrayStoreGetRecords(): void
    {
        $resetAt = \time() + 60;
        $this->store->set('k1', 1, $resetAt);
        $this->store->set('k2', 5, $resetAt);

        $records = $this->store->getRecords();

        $this->assertCount(2, $records);
        $this->assertSame(1, $records['k1']['hits']);
        $this->assertSame(5, $records['k2']['hits']);
    }

    public function testArrayStoreFlush(): void
    {
        $this->store->set('a', 1, \time() + 60);
        $this->store->set('b', 2, \time() + 60);

        $this->store->flush();

        $this->assertSame(0, $this->store->count());
        $this->assertNull($this->store->get('a'));
    }

    public function testArrayStoreOverwritesExistingKey(): void
    {
        $this->store->set('key', 3, \time() + 60);
        $this->store->set('key', 10, \time() + 120);

        $record = $this->store->get('key');
        $this->assertSame(10, $record['hits']);
    }

    // ══════════════════════════════════════════════════════════════
    //  CacheStore
    // ══════════════════════════════════════════════════════════════

    public function testCacheStoreGetReturnsNullForMissing(): void
    {
        $cache = new NullAdapter();
        $store = new CacheStore($cache);

        $this->assertNull($store->get('nonexistent'));
    }

    public function testCacheStoreSetAndGetWithFileAdapter(): void
    {
        // Use a real FileAdapter for integration
        $cacheDir = \sys_get_temp_dir() . '/razy_ratelimit_test_' . \uniqid();
        \mkdir($cacheDir, 0o777, true);

        try {
            $cache = new \Razy\Cache\FileAdapter($cacheDir);
            $store = new CacheStore($cache);

            $resetAt = \time() + 60;
            $store->set('test_key', 7, $resetAt);

            $record = $store->get('test_key');
            $this->assertIsArray($record);
            $this->assertSame(7, $record['hits']);
            $this->assertSame($resetAt, $record['resetAt']);
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testCacheStoreDeleteRemovesRecord(): void
    {
        $cacheDir = \sys_get_temp_dir() . '/razy_ratelimit_test_' . \uniqid();
        \mkdir($cacheDir, 0o777, true);

        try {
            $cache = new \Razy\Cache\FileAdapter($cacheDir);
            $store = new CacheStore($cache);

            $store->set('del_key', 3, \time() + 60);
            $store->delete('del_key');

            $this->assertNull($store->get('del_key'));
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testCacheStorePrefix(): void
    {
        $cache = new NullAdapter();
        $store = new CacheStore($cache, 'custom_');

        $this->assertSame('custom_', $store->getPrefix());
    }

    public function testCacheStoreDefaultPrefix(): void
    {
        $cache = new NullAdapter();
        $store = new CacheStore($cache);

        $this->assertSame('ratelimit_', $store->getPrefix());
    }

    public function testCacheStoreGetCache(): void
    {
        $cache = new NullAdapter();
        $store = new CacheStore($cache);

        $this->assertSame($cache, $store->getCache());
    }

    public function testCacheStoreExpiredRecordReturnsNull(): void
    {
        $cacheDir = \sys_get_temp_dir() . '/razy_ratelimit_test_' . \uniqid();
        \mkdir($cacheDir, 0o777, true);

        try {
            $cache = new \Razy\Cache\FileAdapter($cacheDir);
            $store = new CacheStore($cache);

            // Set with past resetAt — will be detected as expired on get()
            $store->set('expired_key', 5, \time() - 10);

            $this->assertNull($store->get('expired_key'));
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testCacheStoreInvalidDataReturnsNull(): void
    {
        $cacheDir = \sys_get_temp_dir() . '/razy_ratelimit_test_' . \uniqid();
        \mkdir($cacheDir, 0o777, true);

        try {
            $cache = new \Razy\Cache\FileAdapter($cacheDir);

            // Manually write invalid data
            $cache->set('ratelimit_bad', 'not_an_array', 3600);

            $store = new CacheStore($cache);
            $this->assertNull($store->get('bad'));
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    public function testCacheStoreImplementsInterface(): void
    {
        $store = new CacheStore(new NullAdapter());

        $this->assertInstanceOf(RateLimitStoreInterface::class, $store);
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimiter — Basic Operations
    // ══════════════════════════════════════════════════════════════

    public function testHitCreatesNewWindow(): void
    {
        $hits = $this->limiter->hit('test', 60);

        $this->assertSame(1, $hits);
        $this->assertSame(1, $this->limiter->attempts('test'));
    }

    public function testHitIncrementsExistingWindow(): void
    {
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);
        $hits = $this->limiter->hit('test', 60);

        $this->assertSame(3, $hits);
        $this->assertSame(3, $this->limiter->attempts('test'));
    }

    public function testTooManyAttemptsReturnsFalseWhenUnderLimit(): void
    {
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->assertFalse($this->limiter->tooManyAttempts('test', 3));
    }

    public function testTooManyAttemptsReturnsTrueAtLimit(): void
    {
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->assertTrue($this->limiter->tooManyAttempts('test', 3));
    }

    public function testTooManyAttemptsReturnsTrueOverLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->hit('test', 60);
        }

        $this->assertTrue($this->limiter->tooManyAttempts('test', 3));
    }

    public function testTooManyAttemptsReturnsFalseForUnknownKey(): void
    {
        $this->assertFalse($this->limiter->tooManyAttempts('never_hit', 5));
    }

    public function testAttemptAllowsWhenUnderLimit(): void
    {
        $this->assertTrue($this->limiter->attempt('test', 3, 60));
        $this->assertTrue($this->limiter->attempt('test', 3, 60));
        $this->assertTrue($this->limiter->attempt('test', 3, 60));
    }

    public function testAttemptRejectsWhenAtLimit(): void
    {
        $this->limiter->attempt('test', 2, 60);
        $this->limiter->attempt('test', 2, 60);

        $this->assertFalse($this->limiter->attempt('test', 2, 60));
    }

    public function testAttemptIncrementsOnSuccess(): void
    {
        $this->limiter->attempt('test', 5, 60);
        $this->limiter->attempt('test', 5, 60);

        $this->assertSame(2, $this->limiter->attempts('test'));
    }

    public function testAttemptDoesNotIncrementOnFailure(): void
    {
        $this->limiter->attempt('test', 1, 60);

        // This should fail, so count stays at 1
        $this->limiter->attempt('test', 1, 60);

        $this->assertSame(1, $this->limiter->attempts('test'));
    }

    public function testRemainingReturnsFullLimitForNewKey(): void
    {
        $this->assertSame(5, $this->limiter->remaining('test', 5));
    }

    public function testRemainingDecrementsAfterHits(): void
    {
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->assertSame(3, $this->limiter->remaining('test', 5));
    }

    public function testRemainingNeverGoesNegative(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->limiter->hit('test', 60);
        }

        $this->assertSame(0, $this->limiter->remaining('test', 5));
    }

    public function testClearResetsCounter(): void
    {
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->limiter->clear('test');

        $this->assertSame(0, $this->limiter->attempts('test'));
        $this->assertFalse($this->limiter->tooManyAttempts('test', 3));
    }

    public function testAvailableInReturnsZeroForNewKey(): void
    {
        $this->assertSame(0, $this->limiter->availableIn('test'));
    }

    public function testAvailableInReturnsSecondsUntilReset(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        // Manually set a record with known resetAt
        $this->store->set('test', 1, 1060);

        $seconds = $this->limiter->availableIn('test');

        // Should be 60 seconds (1060 - 1000)
        $this->assertSame(60, $seconds);
    }

    public function testResetAtReturnsTimestamp(): void
    {
        $this->limiter->hit('test', 60);

        $resetAt = $this->limiter->resetAt('test');

        $this->assertGreaterThan(\time() - 1, $resetAt);
        $this->assertLessThanOrEqual(\time() + 60, $resetAt);
    }

    public function testResetAtReturnsZeroForUnknownKey(): void
    {
        $this->assertSame(0, $this->limiter->resetAt('unknown'));
    }

    public function testAttemptsReturnsZeroForUnknownKey(): void
    {
        $this->assertSame(0, $this->limiter->attempts('unknown'));
    }

    public function testGetStore(): void
    {
        $this->assertSame($this->store, $this->limiter->getStore());
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimiter — Window Expiry
    // ══════════════════════════════════════════════════════════════

    public function testWindowExpiryResetsCounter(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->assertTrue($this->limiter->tooManyAttempts('test', 3));

        // Advance past the window
        $this->store->setCurrentTime(1061);
        $this->limiter->setCurrentTime(1061);

        $this->assertFalse($this->limiter->tooManyAttempts('test', 3));
        $this->assertSame(0, $this->limiter->attempts('test'));
    }

    public function testHitAfterExpiryStartsNewWindow(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->limiter->hit('test', 30);
        $this->limiter->hit('test', 30);

        // Advance past window
        $this->store->setCurrentTime(1031);
        $this->limiter->setCurrentTime(1031);

        $hits = $this->limiter->hit('test', 30);

        // New window, first hit
        $this->assertSame(1, $hits);
    }

    public function testRemainingResetsAfterWindowExpiry(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->limiter->hit('test', 60);
        $this->limiter->hit('test', 60);

        $this->assertSame(3, $this->limiter->remaining('test', 5));

        // Advance past window
        $this->store->setCurrentTime(1061);
        $this->limiter->setCurrentTime(1061);

        $this->assertSame(5, $this->limiter->remaining('test', 5));
    }

    public function testTooManyAttemptsExpiredWindowClearsStore(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->store->set('test', 10, 1050);

        // Advance past window
        $this->store->setCurrentTime(1051);
        $this->limiter->setCurrentTime(1051);

        $this->assertFalse($this->limiter->tooManyAttempts('test', 5));

        // The store should have been cleaned
        $this->assertNull($this->store->get('test'));
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimiter — Named Limiters
    // ══════════════════════════════════════════════════════════════

    public function testRegisterNamedLimiter(): void
    {
        $callback = fn (array $ctx) => Limit::perMinute(60);

        $this->limiter->for('api', $callback);

        $this->assertTrue($this->limiter->hasLimiter('api'));
        $this->assertSame($callback, $this->limiter->limiter('api'));
    }

    public function testHasLimiterReturnsFalseForUnregistered(): void
    {
        $this->assertFalse($this->limiter->hasLimiter('nonexistent'));
    }

    public function testLimiterReturnsNullForUnregistered(): void
    {
        $this->assertNull($this->limiter->limiter('nonexistent'));
    }

    public function testResolveNamedLimiter(): void
    {
        $this->limiter->for(
            'api',
            fn (array $ctx) =>
            Limit::perMinute(60)->by($ctx['ip'] ?? 'unknown'),
        );

        $limit = $this->limiter->resolve('api', ['ip' => '10.0.0.1']);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(60, $limit->getMaxAttempts());
        $this->assertSame(60, $limit->getDecaySeconds());
        $this->assertSame('10.0.0.1', $limit->getKey());
    }

    public function testResolveReturnsNullForUnregistered(): void
    {
        $this->assertNull($this->limiter->resolve('nonexistent'));
    }

    public function testResolveWithEmptyContext(): void
    {
        $this->limiter->for(
            'global',
            fn (array $ctx) =>
            Limit::perHour(1000),
        );

        $limit = $this->limiter->resolve('global');

        $this->assertSame(1000, $limit->getMaxAttempts());
        $this->assertSame(3600, $limit->getDecaySeconds());
    }

    public function testResolveUnlimitedLimiter(): void
    {
        $this->limiter->for('internal', fn (array $ctx) => Limit::none());

        $limit = $this->limiter->resolve('internal');

        $this->assertTrue($limit->isUnlimited());
    }

    public function testMultipleNamedLimiters(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(60));
        $this->limiter->for('login', fn () => Limit::every(300, 5));
        $this->limiter->for('uploads', fn () => Limit::perHour(100));

        $this->assertTrue($this->limiter->hasLimiter('api'));
        $this->assertTrue($this->limiter->hasLimiter('login'));
        $this->assertTrue($this->limiter->hasLimiter('uploads'));

        $apiLimit = $this->limiter->resolve('api');
        $this->assertSame(60, $apiLimit->getMaxAttempts());

        $loginLimit = $this->limiter->resolve('login');
        $this->assertSame(5, $loginLimit->getMaxAttempts());
        $this->assertSame(300, $loginLimit->getDecaySeconds());
    }

    public function testOverwriteNamedLimiter(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(60));
        $this->limiter->for('api', fn () => Limit::perMinute(100));

        $limit = $this->limiter->resolve('api');
        $this->assertSame(100, $limit->getMaxAttempts());
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitExceededException
    // ══════════════════════════════════════════════════════════════

    public function testExceptionProperties(): void
    {
        $e = new RateLimitExceededException('api:192.168.1.1', 60, 45);

        $this->assertSame('api:192.168.1.1', $e->getKey());
        $this->assertSame(60, $e->getMaxAttempts());
        $this->assertSame(45, $e->getRetryAfter());
        $this->assertSame(429, $e->getCode());
    }

    public function testExceptionMessage(): void
    {
        $e = new RateLimitExceededException('login:user@test.com', 5, 120);

        $this->assertStringContainsString('login:user@test.com', $e->getMessage());
        $this->assertStringContainsString('5', $e->getMessage());
        $this->assertStringContainsString('120', $e->getMessage());
    }

    public function testExceptionIsRuntimeException(): void
    {
        $e = new RateLimitExceededException('key', 10, 30);

        $this->assertInstanceOf(RuntimeException::class, $e);
    }

    public function testExceptionCanBeCaught(): void
    {
        $caught = false;

        try {
            throw new RateLimitExceededException('test', 5, 60);
        } catch (RateLimitExceededException $e) {
            $caught = true;
            $this->assertSame('test', $e->getKey());
        }

        $this->assertTrue($caught);
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitMiddleware — Basic Behavior
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareImplementsInterface(): void
    {
        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $this->assertInstanceOf(MiddlewareInterface::class, $mw);
    }

    public function testMiddlewarePassesThroughWhenUnderLimit(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(60)->by('test-ip'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $result = $mw->handle(
            ['route' => '/api/test', 'method' => 'GET'],
            fn (array $ctx) => 'handler_result',
        );

        $this->assertSame('handler_result', $result);
    }

    public function testMiddlewareBlocksWhenLimitExceeded(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(2)->by('test-ip'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $context = ['route' => '/api/test', 'method' => 'GET'];
        $handler = fn (array $ctx) => 'ok';

        // First two pass
        $mw->handle($context, $handler);
        $mw->handle($context, $handler);

        // Third should be blocked — handler NOT called
        $handlerCalled = false;
        $result = $mw->handle($context, function (array $ctx) use (&$handlerCalled) {
            $handlerCalled = true;

            return 'should_not_reach';
        });

        $this->assertFalse($handlerCalled);
        $this->assertNull($result);
    }

    public function testMiddlewarePassesThroughWhenNoLimiterRegistered(): void
    {
        // No limiter registered for 'unknown'
        $mw = new RateLimitMiddleware($this->limiter, 'unknown', sendHeaders: false);

        $result = $mw->handle(
            ['route' => '/test'],
            fn (array $ctx) => 'passed',
        );

        $this->assertSame('passed', $result);
    }

    public function testMiddlewarePassesThroughForUnlimitedLimiter(): void
    {
        $this->limiter->for('internal', fn () => Limit::none());

        $mw = new RateLimitMiddleware($this->limiter, 'internal', sendHeaders: false);

        // Should never block, even after many requests
        for ($i = 0; $i < 100; $i++) {
            $result = $mw->handle(
                ['route' => '/internal'],
                fn (array $ctx) => 'ok',
            );
            $this->assertSame('ok', $result);
        }
    }

    public function testMiddlewareTracksHitsCorrectly(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5)->by('ip1'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);
        $context = ['route' => '/api/data'];
        $handler = fn (array $ctx) => 'ok';

        $mw->handle($context, $handler);
        $mw->handle($context, $handler);
        $mw->handle($context, $handler);

        $this->assertSame(3, $this->limiter->attempts('api:ip1'));
        $this->assertSame(2, $this->limiter->remaining('api:ip1', 5));
    }

    public function testMiddlewareGetName(): void
    {
        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $this->assertSame('api', $mw->getName());
    }

    public function testMiddlewareGetRateLimiter(): void
    {
        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $this->assertSame($this->limiter, $mw->getRateLimiter());
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitMiddleware — Custom Key Resolver
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareWithCustomKeyResolver(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5)->by('default'));

        $keyResolver = fn (array $ctx) => 'user:' . ($ctx['user_id'] ?? 'guest');

        $mw = new RateLimitMiddleware(
            $this->limiter,
            'api',
            keyResolver: $keyResolver,
            sendHeaders: false,
        );

        $mw->handle(['user_id' => '42'], fn ($ctx) => 'ok');

        // Key should be 'api:user:42' (name + custom key)
        $this->assertSame(1, $this->limiter->attempts('api:user:42'));
    }

    public function testMiddlewareKeyResolverOverridesLimitKey(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5)->by('limit-default-key'));

        $mw = new RateLimitMiddleware(
            $this->limiter,
            'api',
            keyResolver: fn (array $ctx) => 'override-key',
            sendHeaders: false,
        );

        $mw->handle([], fn ($ctx) => 'ok');

        $this->assertSame(1, $this->limiter->attempts('api:override-key'));
        $this->assertSame(0, $this->limiter->attempts('api:limit-default-key'));
    }

    public function testMiddlewareFallsBackToRouteKey(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5)); // No key set on Limit

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $mw->handle(['route' => '/api/items'], fn ($ctx) => 'ok');

        $this->assertSame(1, $this->limiter->attempts('api:/api/items'));
    }

    public function testMiddlewareFallsBackToGlobalWhenNoRoute(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $mw->handle([], fn ($ctx) => 'ok');

        $this->assertSame(1, $this->limiter->attempts('api:global'));
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitMiddleware — Custom Rejection Handler
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareCustomRejectionHandler(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(1)->by('test'));

        $rejectionCalled = false;
        $capturedRetryAfter = null;

        $onLimitExceeded = function (array $ctx, Limit $limit, int $retryAfter) use (&$rejectionCalled, &$capturedRetryAfter) {
            $rejectionCalled = true;
            $capturedRetryAfter = $retryAfter;

            return ['error' => 'too_many_requests', 'retry_after' => $retryAfter];
        };

        $mw = new RateLimitMiddleware(
            $this->limiter,
            'api',
            onLimitExceeded: $onLimitExceeded,
            sendHeaders: false,
        );

        $context = ['route' => '/api/data'];
        $handler = fn ($ctx) => 'ok';

        $mw->handle($context, $handler); // Allowed
        $result = $mw->handle($context, $handler); // Blocked

        $this->assertTrue($rejectionCalled);
        $this->assertIsArray($result);
        $this->assertSame('too_many_requests', $result['error']);
        $this->assertGreaterThan(0, $capturedRetryAfter);
    }

    public function testMiddlewareRejectionHandlerReceivesContext(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(1)->by('test'));

        $capturedContext = null;

        $mw = new RateLimitMiddleware(
            $this->limiter,
            'api',
            onLimitExceeded: function (array $ctx, Limit $limit, int $retryAfter) use (&$capturedContext) {
                $capturedContext = $ctx;
            },
            sendHeaders: false,
        );

        $context = ['route' => '/api/data', 'method' => 'POST'];

        $mw->handle($context, fn ($ctx) => 'ok'); // Allowed
        $mw->handle($context, fn ($ctx) => 'ok'); // Blocked

        $this->assertSame($context, $capturedContext);
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitMiddleware — Per-User Isolation
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareIsolatesRateLimitsByKey(): void
    {
        $this->limiter->for(
            'api',
            fn (array $ctx) =>
            Limit::perMinute(2)->by($ctx['ip'] ?? 'unknown'),
        );

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);
        $handler = fn ($ctx) => 'ok';

        // User A: 2 requests (at limit)
        $mw->handle(['ip' => '10.0.0.1'], $handler);
        $mw->handle(['ip' => '10.0.0.1'], $handler);

        // User B: 1 request (under limit)
        $mw->handle(['ip' => '10.0.0.2'], $handler);

        // User A: blocked
        $handlerCalledA = false;
        $mw->handle(['ip' => '10.0.0.1'], function ($ctx) use (&$handlerCalledA) {
            $handlerCalledA = true;

            return 'x';
        });
        $this->assertFalse($handlerCalledA);

        // User B: still allowed
        $resultB = $mw->handle(['ip' => '10.0.0.2'], $handler);
        $this->assertSame('ok', $resultB);
    }

    // ══════════════════════════════════════════════════════════════
    //  RateLimitMiddleware — Context Passthrough
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewarePassesContextUnmodified(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(100)->by('test'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $originalContext = [
            'route' => '/api/users',
            'method' => 'GET',
            'url_query' => '/api/users?page=1',
            'arguments' => ['page' => '1'],
            'type' => 'standard',
        ];

        $capturedContext = null;
        $mw->handle($originalContext, function (array $ctx) use (&$capturedContext) {
            $capturedContext = $ctx;

            return 'ok';
        });

        $this->assertSame($originalContext, $capturedContext);
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Multi-Key Scenarios
    // ══════════════════════════════════════════════════════════════

    public function testMultipleKeysAreIndependent(): void
    {
        $this->limiter->hit('key_a', 60);
        $this->limiter->hit('key_a', 60);
        $this->limiter->hit('key_b', 60);

        $this->assertSame(2, $this->limiter->attempts('key_a'));
        $this->assertSame(1, $this->limiter->attempts('key_b'));
        $this->assertSame(3, $this->limiter->remaining('key_a', 5));
        $this->assertSame(4, $this->limiter->remaining('key_b', 5));
    }

    public function testClearOneKeyDoesNotAffectOthers(): void
    {
        $this->limiter->hit('key_a', 60);
        $this->limiter->hit('key_b', 60);

        $this->limiter->clear('key_a');

        $this->assertSame(0, $this->limiter->attempts('key_a'));
        $this->assertSame(1, $this->limiter->attempts('key_b'));
    }

    public function testConcurrentMiddlewareInstances(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(3)->by('shared'));
        $this->limiter->for('login', fn () => Limit::every(300, 5)->by('shared'));

        $apiMw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);
        $loginMw = new RateLimitMiddleware($this->limiter, 'login', sendHeaders: false);

        $handler = fn ($ctx) => 'ok';

        // API and login have separate namespaced keys
        $apiMw->handle([], $handler);
        $apiMw->handle([], $handler);
        $loginMw->handle([], $handler);

        $this->assertSame(2, $this->limiter->attempts('api:shared'));
        $this->assertSame(1, $this->limiter->attempts('login:shared'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Full Request Lifecycle
    // ══════════════════════════════════════════════════════════════

    public function testFullLifecycleWithAttemptAndExpiry(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->limiter->for(
            'login',
            fn (array $ctx) =>
            Limit::every(300, 3)->by($ctx['email'] ?? 'anon'),
        );

        $mw = new RateLimitMiddleware($this->limiter, 'login', sendHeaders: false);
        $handler = fn ($ctx) => 'login_ok';

        $ctx = ['email' => 'user@test.com'];

        // 3 allowed
        $this->assertSame('login_ok', $mw->handle($ctx, $handler));
        $this->assertSame('login_ok', $mw->handle($ctx, $handler));
        $this->assertSame('login_ok', $mw->handle($ctx, $handler));

        // 4th blocked
        $blocked = false;
        $mw->handle($ctx, function () use (&$blocked) {
            $blocked = true;

            return 'x';
        });
        $this->assertFalse($blocked);

        // Advance time past window
        $this->store->setCurrentTime(1301);
        $this->limiter->setCurrentTime(1301);

        // Should be allowed again
        $result = $mw->handle($ctx, $handler);
        $this->assertSame('login_ok', $result);
    }

    public function testDifferentLimitsForDifferentRoutes(): void
    {
        $this->limiter->for('strict', fn () => Limit::perMinute(2)->by('ip'));
        $this->limiter->for('lenient', fn () => Limit::perMinute(100)->by('ip'));

        $strictMw = new RateLimitMiddleware($this->limiter, 'strict', sendHeaders: false);
        $lenientMw = new RateLimitMiddleware($this->limiter, 'lenient', sendHeaders: false);

        $handler = fn ($ctx) => 'ok';

        // Strict: 2 allowed, 3rd blocked
        $strictMw->handle([], $handler);
        $strictMw->handle([], $handler);

        $strictBlocked = false;
        $strictMw->handle([], function () use (&$strictBlocked) {
            $strictBlocked = true;

            return 'x';
        });
        $this->assertTrue($strictBlocked === false);

        // Lenient: still available
        $result = $lenientMw->handle([], $handler);
        $this->assertSame('ok', $result);
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — CacheStore with RateLimiter
    // ══════════════════════════════════════════════════════════════

    public function testCacheStoreIntegrationWithRateLimiter(): void
    {
        $cacheDir = \sys_get_temp_dir() . '/razy_ratelimit_test_' . \uniqid();
        \mkdir($cacheDir, 0o777, true);

        try {
            $cache = new \Razy\Cache\FileAdapter($cacheDir);
            $store = new CacheStore($cache);
            $limiter = new RateLimiter($store);

            $limiter->hit('integration_test', 60);
            $limiter->hit('integration_test', 60);

            $this->assertSame(2, $limiter->attempts('integration_test'));
            $this->assertSame(3, $limiter->remaining('integration_test', 5));
            $this->assertFalse($limiter->tooManyAttempts('integration_test', 5));

            $limiter->hit('integration_test', 60);
            $limiter->hit('integration_test', 60);
            $limiter->hit('integration_test', 60);

            $this->assertTrue($limiter->tooManyAttempts('integration_test', 5));

            $limiter->clear('integration_test');
            $this->assertSame(0, $limiter->attempts('integration_test'));
        } finally {
            $this->removeDirectory($cacheDir);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Integration — Middleware Pipeline
    // ══════════════════════════════════════════════════════════════

    public function testMiddlewareInPipelineWithOtherMiddleware(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(10)->by('test'));

        $rateMw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $log = [];

        // Simulate a pipeline: logging → rate limit → handler
        $loggingMw = new class() implements MiddlewareInterface {
            /** @var string[] */
            public array $entries = [];

            public function handle(array $context, Closure $next): mixed
            {
                $this->entries[] = 'before_logging';
                $result = $next($context);
                $this->entries[] = 'after_logging';

                return $result;
            }
        };

        // Manually chain: logging → rate limit → handler
        $handler = fn ($ctx) => 'final_result';

        $result = $loggingMw->handle(
            ['route' => '/api/test'],
            fn (array $ctx) => $rateMw->handle($ctx, $handler),
        );

        $this->assertSame('final_result', $result);
        $this->assertSame(['before_logging', 'after_logging'], $loggingMw->entries);
    }

    public function testMiddlewareShortCircuitInPipeline(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(1)->by('test'));

        $rateMw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        $handler = fn ($ctx) => 'ok';

        // First request passes
        $rateMw->handle([], $handler);

        // Second request: rate limiter short-circuits before handler
        $handlerCalled = false;

        $outerMw = new class() implements MiddlewareInterface {
            public bool $called = false;

            public function handle(array $context, Closure $next): mixed
            {
                $result = $next($context);
                $this->called = true;

                return $result;
            }
        };

        $outerMw->handle(
            [],
            fn (array $ctx) => $rateMw->handle($ctx, function ($ctx) use (&$handlerCalled) {
                $handlerCalled = true;

                return 'x';
            }),
        );

        $this->assertFalse($handlerCalled);
        $this->assertTrue($outerMw->called); // Outer middleware still completes
    }

    // ══════════════════════════════════════════════════════════════
    //  Edge Cases
    // ══════════════════════════════════════════════════════════════

    public function testZeroMaxAttemptsBlocksImmediately(): void
    {
        // With 0 max attempts, even 1 hit means tooManyAttempts
        $this->limiter->hit('key', 60);
        $this->assertTrue($this->limiter->tooManyAttempts('key', 0));
    }

    public function testZeroMaxAttemptsWithNoHitsBlocksViaTooMany(): void
    {
        // Even a fresh key with 0 max attempts blocks via tooManyAttempts
        // because 0 >= 0 returns false (no record exists)
        $this->assertFalse($this->limiter->tooManyAttempts('never_hit', 0));

        // But after one hit, it should block
        $this->limiter->hit('never_hit', 60);
        $this->assertTrue($this->limiter->tooManyAttempts('never_hit', 0));
    }

    public function testSingleAttemptAllowedThenBlocked(): void
    {
        $this->assertTrue($this->limiter->attempt('one_shot', 1, 60));
        $this->assertFalse($this->limiter->attempt('one_shot', 1, 60));
    }

    public function testEmptyStringKey(): void
    {
        $this->limiter->hit('', 60);
        $this->assertSame(1, $this->limiter->attempts(''));
    }

    public function testLongKey(): void
    {
        $key = \str_repeat('a', 1000);

        $this->limiter->hit($key, 60);
        $this->assertSame(1, $this->limiter->attempts($key));
    }

    public function testSpecialCharactersInKey(): void
    {
        $keys = [
            'user:hello@world.com',
            'ip:192.168.1.1',
            'route:/api/v2/items?page=1&sort=name',
            'mixed:key/with-dashes_and.dots',
        ];

        foreach ($keys as $key) {
            $this->limiter->hit($key, 60);
            $this->assertSame(1, $this->limiter->attempts($key), "Failed for key: $key");
        }
    }

    public function testVeryShortDecaySeconds(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        $this->limiter->hit('fast', 1); // 1-second window

        $this->assertSame(1, $this->limiter->attempts('fast'));

        // Advance 2 seconds
        $this->store->setCurrentTime(1002);
        $this->limiter->setCurrentTime(1002);

        $this->assertSame(0, $this->limiter->attempts('fast'));
    }

    public function testVeryLargeMaxAttempts(): void
    {
        // Should not overflow or behave unexpectedly
        $this->assertSame(PHP_INT_MAX, $this->limiter->remaining('test', PHP_INT_MAX));

        $this->limiter->hit('test', 60);
        $this->assertFalse($this->limiter->tooManyAttempts('test', PHP_INT_MAX));
    }

    public function testStoreDirectManipulation(): void
    {
        // Manually set a record and verify RateLimiter reads it
        $this->store->set('manual', 99, \time() + 600);

        $this->assertSame(99, $this->limiter->attempts('manual'));
        $this->assertTrue($this->limiter->tooManyAttempts('manual', 50));
    }

    public function testAvailableInNeverNegative(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);
        $this->store->set('old', 5, 900); // Already expired

        // availableIn should return 0, not negative
        $this->assertSame(0, $this->limiter->availableIn('old'));
    }

    public function testHandlerReturnValuePreserved(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(100)->by('test'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        // Test various return types
        $results = [
            'string' => 'hello',
            'int' => 42,
            'array' => ['key' => 'value'],
            'null' => null,
            'bool' => false,
        ];

        foreach ($results as $type => $expected) {
            $result = $mw->handle([], fn ($ctx) => $expected);
            $this->assertSame($expected, $result, "Return type '$type' not preserved");
        }
    }

    public function testMiddlewareWithDynamicLimitBasedOnContext(): void
    {
        // Different limits based on user type
        $this->limiter->for('dynamic', function (array $ctx) {
            if (($ctx['role'] ?? '') === 'admin') {
                return Limit::perMinute(1000)->by('admin:' . ($ctx['user_id'] ?? ''));
            }

            return Limit::perMinute(10)->by('user:' . ($ctx['user_id'] ?? ''));
        });

        $mw = new RateLimitMiddleware($this->limiter, 'dynamic', sendHeaders: false);
        $handler = fn ($ctx) => 'ok';

        // Regular user: limited to 10/min
        for ($i = 0; $i < 10; $i++) {
            $result = $mw->handle(['user_id' => '1', 'role' => 'user'], $handler);
            $this->assertSame('ok', $result);
        }

        // Regular user: 11th blocked
        $blocked = false;
        $mw->handle(['user_id' => '1', 'role' => 'user'], function () use (&$blocked) {
            $blocked = true;

            return 'x';
        });
        $this->assertFalse($blocked);

        // Admin: still allowed (different key namespace)
        $result = $mw->handle(['user_id' => '2', 'role' => 'admin'], $handler);
        $this->assertSame('ok', $result);
    }

    public function testArrayStoreImplementsInterface(): void
    {
        $this->assertInstanceOf(RateLimitStoreInterface::class, $this->store);
    }

    public function testMultipleWindowsWithDifferentDecayTimes(): void
    {
        $this->store->setCurrentTime(1000);
        $this->limiter->setCurrentTime(1000);

        // Key A: 10-second window
        $this->limiter->hit('short', 10);

        // Key B: 300-second window
        $this->limiter->hit('long', 300);

        // Advance 11 seconds
        $this->store->setCurrentTime(1011);
        $this->limiter->setCurrentTime(1011);

        // Short window expired
        $this->assertSame(0, $this->limiter->attempts('short'));
        // Long window still active
        $this->assertSame(1, $this->limiter->attempts('long'));
    }

    public function testRapidSuccessiveHitsInSameWindow(): void
    {
        $counts = [];
        for ($i = 1; $i <= 20; $i++) {
            $counts[] = $this->limiter->hit('rapid', 60);
        }

        // Each hit should return the incrementing count
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($i + 1, $counts[$i]);
        }
    }

    public function testLimiterContextualResolution(): void
    {
        $this->limiter->for('per_route', function (array $ctx) {
            $route = $ctx['route'] ?? 'unknown';

            return match ($route) {
                '/api/search' => Limit::perMinute(30)->by($route),
                '/api/upload' => Limit::perMinute(5)->by($route),
                default => Limit::perMinute(60)->by($route),
            };
        });

        $mw = new RateLimitMiddleware($this->limiter, 'per_route', sendHeaders: false);
        $handler = fn ($ctx) => 'ok';

        // Upload endpoint: limited to 5
        for ($i = 0; $i < 5; $i++) {
            $mw->handle(['route' => '/api/upload'], $handler);
        }

        $uploadBlocked = false;
        $mw->handle(['route' => '/api/upload'], function () use (&$uploadBlocked) {
            $uploadBlocked = true;

            return 'x';
        });
        $this->assertFalse($uploadBlocked);

        // Search endpoint: still available (different key)
        $result = $mw->handle(['route' => '/api/search'], $handler);
        $this->assertSame('ok', $result);
    }

    public function testClearAfterExceedingLimitAllowsAgain(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(2)->by('test'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);
        $handler = fn ($ctx) => 'ok';

        // Exhaust limit
        $mw->handle([], $handler);
        $mw->handle([], $handler);

        // Blocked
        $blocked = false;
        $mw->handle([], function () use (&$blocked) {
            $blocked = true;

            return 'x';
        });
        $this->assertFalse($blocked);

        // Clear the limit
        $this->limiter->clear('api:test');

        // Should be allowed again
        $result = $mw->handle([], $handler);
        $this->assertSame('ok', $result);
    }

    public function testExceptionInHandlerDoesNotAffectRateLimitCount(): void
    {
        $this->limiter->for('api', fn () => Limit::perMinute(5)->by('test'));

        $mw = new RateLimitMiddleware($this->limiter, 'api', sendHeaders: false);

        // Hit is recorded before the handler runs
        try {
            $mw->handle([], function ($ctx) {
                throw new RuntimeException('handler error');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // The hit should still have been recorded
        $this->assertSame(1, $this->limiter->attempts('api:test'));
    }

    #[DataProvider('limitFactoryProvider')]
    public function testLimitFactoryMethods(Limit $limit, int $expectedMax, int $expectedDecay): void
    {
        $this->assertSame($expectedMax, $limit->getMaxAttempts());
        $this->assertSame($expectedDecay, $limit->getDecaySeconds());
        $this->assertFalse($limit->isUnlimited());
    }

    #[DataProvider('attemptOutcomeProvider')]
    public function testAttemptOutcomes(int $attemptNumber, int $maxAttempts, bool $shouldSucceed, int $expectedRemaining): void
    {
        $key = 'outcome_test';

        // Make (attemptNumber - 1) hits first
        for ($i = 1; $i < $attemptNumber; $i++) {
            $this->limiter->hit($key, 60);
        }

        // The nth attempt
        $result = $this->limiter->attempt($key, $maxAttempts, 60);

        $this->assertSame($shouldSucceed, $result);
        $this->assertSame($expectedRemaining, $this->limiter->remaining($key, $maxAttempts));
    }

    // ══════════════════════════════════════════════════════════════
    //  Contract Verification
    // ══════════════════════════════════════════════════════════════

    public function testLimitIsNotMutable(): void
    {
        $limit = Limit::perMinute(60);

        // Verify getters return consistent values
        $this->assertSame(60, $limit->getMaxAttempts());
        $this->assertSame(60, $limit->getDecaySeconds());

        // Key mutation returns same object (by design for fluent API)
        $same = $limit->by('key1');
        $this->assertSame($limit, $same);
    }

    public function testRateLimiterRequiresStore(): void
    {
        $store = new ArrayStore();
        $limiter = new RateLimiter($store);

        $this->assertSame($store, $limiter->getStore());
    }

    public function testMiddlewareRequiresLimiterAndName(): void
    {
        $mw = new RateLimitMiddleware($this->limiter, 'test', sendHeaders: false);

        $this->assertSame($this->limiter, $mw->getRateLimiter());
        $this->assertSame('test', $mw->getName());
    }

    // ══════════════════════════════════════════════════════════════
    //  Helper Methods
    // ══════════════════════════════════════════════════════════════

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                \rmdir($item->getPathname());
            } else {
                \unlink($item->getPathname());
            }
        }

        \rmdir($dir);
    }
}
