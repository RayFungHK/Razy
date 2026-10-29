<?php

/**
 * Comprehensive tests for #14: Redis Cache Driver.
 *
 * Covers RedisAdapter — PSR-16 cache operations with Redis.
 * Tests are SKIPPED if the Redis extension or server is unavailable.
 *
 * This file is part of Razy.
 */

declare(strict_types=1);

namespace Razy\Tests;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Cache\CacheInterface;
use Razy\Cache\InvalidArgumentException;
use Razy\Cache\RedisAdapter;
use Redis;
use RedisException;

#[CoversClass(RedisAdapter::class)]
class RedisAdapterTest extends TestCase
{
    private ?Redis $redis = null;

    private ?RedisAdapter $adapter = null;

    private string $prefix;

    protected function setUp(): void
    {
        if (!\extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available.');
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        try {
            $this->redis = new Redis();
            if (!@$this->redis->connect($host, $port, 1.0)) {
                $this->markTestSkipped("Cannot connect to Redis at {$host}:{$port}.");
            }
        } catch (RedisException $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }

        $this->prefix = 'razy_test_' . \uniqid() . '_';
        $this->adapter = new RedisAdapter($this->redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        if ($this->redis !== null && $this->adapter !== null) {
            $this->adapter->clear();
        }
    }

    public static function reservedCharProvider(): array
    {
        return [
            'curly open' => ['key{bad}'],
            'curly close' => ['key}bad'],
            'parenthesis' => ['key(bad)'],
            'slash' => ['key/bad'],
            'backslash' => ['key\bad'],
            'at sign' => ['key@bad'],
            'colon' => ['key:bad'],
        ];
    }

    // ═══════════════════════════════════════════════════════
    //  1. Interface & Construction
    // ═══════════════════════════════════════════════════════

    public function testImplementsCacheInterface(): void
    {
        $this->assertInstanceOf(CacheInterface::class, $this->adapter);
    }

    public function testGetRedisReturnsInstance(): void
    {
        $this->assertSame($this->redis, $this->adapter->getRedis());
    }

    // ═══════════════════════════════════════════════════════
    //  2. Basic set / get
    // ═══════════════════════════════════════════════════════

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->adapter->set('key1', 'value1'));
        $this->assertSame('value1', $this->adapter->get('key1'));
    }

    public function testGetMissReturnsNull(): void
    {
        $this->assertNull($this->adapter->get('missing'));
    }

    public function testGetMissReturnsCustomDefault(): void
    {
        $this->assertSame('fallback', $this->adapter->get('missing', 'fallback'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->adapter->set('key', 'first');
        $this->adapter->set('key', 'second');
        $this->assertSame('second', $this->adapter->get('key'));
    }

    // ═══════════════════════════════════════════════════════
    //  3. Value Type Serialization / Deserialization
    // ═══════════════════════════════════════════════════════

    public function testStoresAndRetrievesNull(): void
    {
        $this->adapter->set('null-key', null);
        // Should be null, not the default
        $this->assertNull($this->adapter->get('null-key', 'not-null'));
    }

    public function testStoresAndRetrievesInteger(): void
    {
        $this->adapter->set('int', 42);
        $this->assertSame(42, $this->adapter->get('int'));
    }

    public function testStoresAndRetrievesFloat(): void
    {
        $this->adapter->set('float', 3.14);
        $this->assertSame(3.14, $this->adapter->get('float'));
    }

    public function testStoresAndRetrievesBoolean(): void
    {
        $this->adapter->set('true', true);
        $this->adapter->set('false', false);

        // Failure messages carry the RAW stored bytes: the false arm of this
        // pair is the one that exposed phpredis's miss-sentinel vs stored-false
        // ambiguity in CI (2026-09) — if it ever reddens again, the raw line
        // names the culprit without another blind fix round.
        $rawFalse = $this->adapter->getRedis()->get($this->prefix . 'false');
        $this->assertTrue($this->adapter->get('true'), 'true round-trip; raw=' . \var_export($this->adapter->getRedis()->get($this->prefix . 'true'), true));
        $this->assertFalse($this->adapter->get('false'), 'false round-trip; raw=' . \var_export($rawFalse, true));
    }

    public function testStoresAndRetrievesString(): void
    {
        $this->adapter->set('str', 'hello world');
        $this->assertSame('hello world', $this->adapter->get('str'));
    }

    public function testStoresAndRetrievesIndexedArray(): void
    {
        $arr = [1, 'two', [3]];
        $this->adapter->set('arr', $arr);
        $this->assertSame($arr, $this->adapter->get('arr'));
    }

    public function testStoresAndRetrievesAssociativeArray(): void
    {
        $data = ['name' => 'Alice', 'age' => 30, 'nested' => ['a' => 1]];
        $this->adapter->set('assoc', $data);
        $this->assertSame($data, $this->adapter->get('assoc'));
    }

    public function testStoresAndRetrievesEmptyArray(): void
    {
        $this->adapter->set('empty-arr', []);
        $this->assertSame([], $this->adapter->get('empty-arr'));
    }

    public function testStoresAndRetrievesEmptyString(): void
    {
        $this->adapter->set('empty-str', '');
        $this->assertSame('', $this->adapter->get('empty-str'));
    }

    public function testStoresAndRetrievesZero(): void
    {
        $this->adapter->set('zero', 0);
        $this->assertSame(0, $this->adapter->get('zero'));
    }

    // ═══════════════════════════════════════════════════════
    //  4. delete()
    // ═══════════════════════════════════════════════════════

    public function testDeleteExistingKey(): void
    {
        $this->adapter->set('del', 'value');
        $this->assertTrue($this->adapter->has('del'));
        $this->assertTrue($this->adapter->delete('del'));
        $this->assertFalse($this->adapter->has('del'));
    }

    public function testDeleteNonexistentKeyReturnTrue(): void
    {
        $this->assertTrue($this->adapter->delete('nonexistent'));
    }

    // ═══════════════════════════════════════════════════════
    //  5. has()
    // ═══════════════════════════════════════════════════════

    public function testHasReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->adapter->has('nope'));
    }

    public function testHasReturnsTrueForExisting(): void
    {
        $this->adapter->set('exist', 'yes');
        $this->assertTrue($this->adapter->has('exist'));
    }

    public function testHasAfterDelete(): void
    {
        $this->adapter->set('tmp', 'val');
        $this->adapter->delete('tmp');
        $this->assertFalse($this->adapter->has('tmp'));
    }

    // ═══════════════════════════════════════════════════════
    //  6. TTL
    // ═══════════════════════════════════════════════════════

    public function testSetWithIntegerTtl(): void
    {
        $this->adapter->set('ttl', 'val', 3600);
        $this->assertSame('val', $this->adapter->get('ttl'));
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $this->adapter->set('interval', 'val', new DateInterval('PT1H'));
        $this->assertSame('val', $this->adapter->get('interval'));
    }

    public function testSetWithNullTtlPersists(): void
    {
        $this->adapter->set('persist', 'val', null);
        $this->assertSame('val', $this->adapter->get('persist'));
    }

    public function testSetWithZeroTtlDeletesKey(): void
    {
        $this->adapter->set('zero-ttl', 'val');
        $this->adapter->set('zero-ttl', 'new', 0);
        $this->assertFalse($this->adapter->has('zero-ttl'));
    }

    public function testSetWithNegativeTtlDeletesKey(): void
    {
        $this->adapter->set('neg-ttl', 'val');
        $this->adapter->set('neg-ttl', 'new', -1);
        $this->assertFalse($this->adapter->has('neg-ttl'));
    }

    // ═══════════════════════════════════════════════════════
    //  7. getMultiple / setMultiple / deleteMultiple
    // ═══════════════════════════════════════════════════════

    public function testSetMultipleAndGetMultiple(): void
    {
        $this->adapter->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);

        $result = $this->adapter->getMultiple(['a', 'b', 'c']);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $result);
    }

    public function testGetMultipleReturnsDefaultForMisses(): void
    {
        $this->adapter->set('x', 'found');
        $result = $this->adapter->getMultiple(['x', 'y'], 'nope');

        $this->assertSame('found', $result['x']);
        $this->assertSame('nope', $result['y']);
    }

    public function testGetMultipleEmptyKeysReturnsEmpty(): void
    {
        $this->assertSame([], $this->adapter->getMultiple([]));
    }

    public function testDeleteMultiple(): void
    {
        $this->adapter->setMultiple(['p' => 1, 'q' => 2, 'r' => 3]);
        $this->adapter->deleteMultiple(['p', 'r']);

        $this->assertFalse($this->adapter->has('p'));
        $this->assertTrue($this->adapter->has('q'));
        $this->assertFalse($this->adapter->has('r'));
    }

    public function testSetMultipleWithTtl(): void
    {
        $this->assertTrue($this->adapter->setMultiple(['t1' => 1, 't2' => 2], 3600));
        $this->assertSame(1, $this->adapter->get('t1'));
        $this->assertSame(2, $this->adapter->get('t2'));
    }

    public function testSetMultipleWithZeroTtlDeletesKeys(): void
    {
        $this->adapter->setMultiple(['d1' => 'a', 'd2' => 'b']);
        $this->adapter->setMultiple(['d1' => 'x', 'd2' => 'y'], 0);

        $this->assertFalse($this->adapter->has('d1'));
        $this->assertFalse($this->adapter->has('d2'));
    }

    // ═══════════════════════════════════════════════════════
    //  8. clear()
    // ═══════════════════════════════════════════════════════

    public function testClearRemovesPrefixedKeysOnly(): void
    {
        $this->adapter->set('k1', 'v1');
        $this->adapter->set('k2', 'v2');

        // Set a key with a different prefix directly in Redis
        $this->redis->set('other_prefix_test', 'should-survive');

        $this->assertTrue($this->adapter->clear());
        $this->assertFalse($this->adapter->has('k1'));
        $this->assertFalse($this->adapter->has('k2'));

        // The other-prefix key should still exist
        $this->assertSame('should-survive', $this->redis->get('other_prefix_test'));
        $this->redis->del('other_prefix_test');
    }

    public function testClearOnEmptyCacheReturnsTrue(): void
    {
        $this->assertTrue($this->adapter->clear());
    }

    public function testClearRemovesMoreKeysThanOneScanPage(): void
    {
        for ($i = 0; $i < 250; ++$i) {
            $this->adapter->set('page-' . $i, $i);
        }

        $this->redis->set('other_prefix_page', 'survive');

        $this->assertTrue($this->adapter->clear());

        for ($i = 0; $i < 250; ++$i) {
            $this->assertFalse($this->adapter->has('page-' . $i), "page-{$i} still present");
        }

        $this->assertSame('survive', $this->redis->get('other_prefix_page'));
        $this->redis->del('other_prefix_page');
    }

    public function testClearContinuesAfterAnEmptyScanBatch(): void
    {
        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 6379);

        $redis = new ScanEmptyBatchRedis();

        try {
            if (!@$redis->connect($host, $port, 1.0)) {
                $this->markTestSkipped("Cannot connect to Redis at {$host}:{$port}.");
            }
        } catch (RedisException $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }

        $optionBefore = $redis->getOption(Redis::OPT_SCAN);
        $prefix = 'razy_test_empty_' . \uniqid() . '_';
        $adapter = new RedisAdapter($redis, $prefix);
        $adapter->set('keep-me', 'yes');
        $adapter->set('also', 'yes');
        $redis->set('other_prefix_empty_batch', 'survive');

        $this->assertTrue($adapter->clear());
        $this->assertTrue($redis->injectedEmptyBatch(), 'clear() never saw the empty SCAN page');
        $this->assertFalse($adapter->has('keep-me'));
        $this->assertFalse($adapter->has('also'));
        $this->assertSame('survive', $redis->get('other_prefix_empty_batch'));
        $this->assertSame($optionBefore, $redis->getOption(Redis::OPT_SCAN));

        $redis->del('other_prefix_empty_batch');
        $adapter->clear();
    }

    public function testClearDeletesOddDirectWritesAndRefusesAnEmptyPrefix(): void
    {
        $odd = "odd key\"\n<script>";
        $this->redis->set($this->prefix . $odd, 'x');

        $this->assertTrue($this->adapter->clear());
        $this->assertSame(0, $this->redis->exists($this->prefix . $odd));

        $bare = new RedisAdapter($this->redis, '');
        $this->redis->set('must-survive-flush', 'yes');

        try {
            $bare->clear();
            $this->fail('empty prefix must throw');
        } catch (InvalidArgumentException) {
            $this->assertSame('yes', $this->redis->get('must-survive-flush'));
        } finally {
            $this->redis->del('must-survive-flush');
        }
    }

    public function testClearDoesNotChangeScanOption(): void
    {
        $before = $this->redis->getOption(Redis::OPT_SCAN);
        $this->adapter->set('opt', 'v');
        $this->assertTrue($this->adapter->clear());
        $this->assertSame($before, $this->redis->getOption(Redis::OPT_SCAN));
    }

    // ═══════════════════════════════════════════════════════
    //  9. Key Validation
    // ═══════════════════════════════════════════════════════

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->get('');
    }

    #[DataProvider('reservedCharProvider')]
    public function testReservedCharactersInKeyThrows(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->get($key);
    }

    public function testValidKeyCharactersWork(): void
    {
        // Valid PSR-16 key characters
        $this->adapter->set('valid-key_123.test', 'ok');
        $this->assertSame('ok', $this->adapter->get('valid-key_123.test'));
    }

    public function testEmptyKeyInSetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->set('', 'value');
    }

    public function testEmptyKeyInHasThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->has('');
    }

    public function testEmptyKeyInDeleteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->delete('');
    }

    // ═══════════════════════════════════════════════════════
    //  10. Prefix Isolation
    // ═══════════════════════════════════════════════════════

    public function testDifferentPrefixesAreIsolated(): void
    {
        $adapter2 = new RedisAdapter($this->redis, 'razy_other_' . \uniqid() . '_');

        $this->adapter->set('shared', 'from-A');
        $adapter2->set('shared', 'from-B');

        $this->assertSame('from-A', $this->adapter->get('shared'));
        $this->assertSame('from-B', $adapter2->get('shared'));

        $adapter2->clear();
    }

    // ═══════════════════════════════════════════════════════
    //  11. Overwrite & State Round-trips
    // ═══════════════════════════════════════════════════════

    public function testOverwriteDifferentTypes(): void
    {
        $this->adapter->set('morph', 'string');
        $this->assertSame('string', $this->adapter->get('morph'));

        $this->adapter->set('morph', 42);
        $this->assertSame(42, $this->adapter->get('morph'));

        $this->adapter->set('morph', ['array']);
        $this->assertSame(['array'], $this->adapter->get('morph'));
    }

    public function testSetDeleteSetRoundTrip(): void
    {
        $this->adapter->set('rt', 'first');
        $this->adapter->delete('rt');
        $this->adapter->set('rt', 'second');

        $this->assertSame('second', $this->adapter->get('rt'));
    }

    // ═══════════════════════════════════════════════════════
    //  12. Batch Operations Edge Cases
    // ═══════════════════════════════════════════════════════

    public function testGetMultipleSingleKey(): void
    {
        $this->adapter->set('only', 'val');
        $result = $this->adapter->getMultiple(['only']);
        $this->assertSame(['only' => 'val'], $result);
    }

    public function testDeleteMultipleEmptyKeys(): void
    {
        // Should not throw or break
        $this->assertTrue($this->adapter->deleteMultiple([]));
    }

    public function testSetMultipleEmptyValues(): void
    {
        $this->assertTrue($this->adapter->setMultiple([]));
    }

    // ═══════════════════════════════════════════════════════
    //  13. Large Data Sets
    // ═══════════════════════════════════════════════════════

    public function testManyKeysSetAndRetrieve(): void
    {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data["key_{$i}"] = "value_{$i}";
        }

        $this->adapter->setMultiple($data);

        $result = $this->adapter->getMultiple(\array_keys($data));
        $this->assertSame($data, $result);
    }
}

/**
 * Real Redis client whose first scan() page is empty and whose cursor stays
 * non-zero, then the stashed real page is returned. clear() that stops on a
 * falsy page never deletes the keys this connection actually holds.
 */
class ScanEmptyBatchRedis extends Redis
{
    private bool $armed = true;

    private bool $injected = false;

    /** @var array<int, string>|false|null */
    private array|false|null $stashed = null;

    private mixed $stashedCursor = null;

    /**
     * @param mixed $iterator
     * @param string|null $pattern
     * @param int $count
     *
     * @return array<int, string>|false
     */
    public function scan(&$iterator, $pattern = null, $count = 0): array|false
    {
        if ($this->armed) {
            $this->armed = false;
            $this->injected = true;
            $cursor = null;
            $this->stashed = parent::scan($cursor, $pattern, $count);
            $this->stashedCursor = $cursor;
            $iterator = 1;

            return [];
        }

        if ($this->stashed !== null) {
            $keys = $this->stashed;
            $cursor = $this->stashedCursor;
            $this->stashed = null;
            $iterator = $cursor;

            return \is_array($keys) ? $keys : false;
        }

        $keys = parent::scan($iterator, $pattern, $count);

        return \is_array($keys) ? $keys : false;
    }

    public function injectedEmptyBatch(): bool
    {
        return $this->injected;
    }
}
