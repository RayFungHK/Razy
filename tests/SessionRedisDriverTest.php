<?php

/**
 * This file is part of Razy v1.0.
 *
 * Redis session driver tests (Lane B1: horizontal-scale session storage).
 *
 * Same skip policy as RedisAdapterTest: no extension / no server => skipped.
 * Exercised fully in the docker test env (benchmark/docker + .docker compose.test).
 */

declare(strict_types=1);

namespace Razy\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Session\Driver\RedisDriver;
use Redis;
use RedisException;
use stdClass;

#[CoversClass(RedisDriver::class)]
class SessionRedisDriverTest extends TestCase
{
    private ?Redis $redis = null;

    private ?RedisDriver $driver = null;

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

        $this->prefix = 'razy_sess_test_' . \uniqid() . '_';
        $this->driver = new RedisDriver($this->redis, $this->prefix, 86400);
    }

    protected function tearDown(): void
    {
        if ($this->redis instanceof Redis && $this->driver instanceof RedisDriver) {
            $cursor = null;
            do {
                $keys = $this->redis->scan($cursor, $this->prefix . '*', 500);
                if (\is_array($keys) && $keys !== []) {
                    $this->redis->del(...$keys);
                }
            } while (\is_int($cursor) && $cursor > 0);
        }

        parent::tearDown();
    }

    public function testOpenPingsConnection(): void
    {
        $this->assertTrue($this->driver->open());
    }

    public function testCloseIsNoopSuccess(): void
    {
        $this->assertTrue($this->driver->close());
    }

    public function testReadMissingSessionReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->driver->read('no-such-session'));
    }

    public function testWriteReadRoundTripPreservesNestedData(): void
    {
        $data = ['user' => ['id' => 7, 'roles' => ['admin', 'editor']], 'flash' => ['⚡ 通知']];

        $this->assertTrue($this->driver->write('abc123', $data));
        $this->assertSame($data, $this->driver->read('abc123'));
    }

    public function testWriteOverwritesPreviousPayload(): void
    {
        $this->driver->write('sid', ['v' => 1]);
        $this->driver->write('sid', ['v' => 2]);

        $this->assertSame(['v' => 2], $this->driver->read('sid'));
    }

    public function testWriteSetsNativeTtl(): void
    {
        $this->driver->write('ttl-check', ['a' => 1]);

        $ttl = $this->redis->ttl($this->prefix . 'ttl-check');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(86400, $ttl);
    }

    public function testRewriteRefreshesSlidingTtl(): void
    {
        $short = new RedisDriver($this->redis, $this->prefix, 5);
        $short->write('slide', ['a' => 1]);
        $this->assertLessThanOrEqual(5, $this->redis->ttl($this->prefix . 'slide'));

        $this->driver->write('slide', ['a' => 2]);
        $this->assertGreaterThan(60, $this->redis->ttl($this->prefix . 'slide'), 'write must refresh the sliding window');
    }

    public function testDestroyRemovesSessionAndIsIdempotent(): void
    {
        $this->driver->write('gone', ['a' => 1]);

        $this->assertTrue($this->driver->destroy('gone'));
        $this->assertSame([], $this->driver->read('gone'));
        $this->assertTrue($this->driver->destroy('gone'), 'destroy of absent session must report success (FileDriver parity)');
    }

    public function testReadRefusesObjectPayloads(): void
    {
        // Hostile store content: serialized object must NOT be hydrated.
        $this->redis->setex($this->prefix . 'evil', 60, \serialize(new stdClass()));

        $this->assertSame([], $this->driver->read('evil'));
    }

    public function testGcRemovesForeignKeysWithoutTtl(): void
    {
        $this->redis->set($this->prefix . 'no-ttl', \serialize(['x' => 1]));

        $this->assertSame(1, $this->driver->gc(3600), 'keys without TTL are unsafe and must be evicted');
        $this->assertSame([], $this->driver->read('no-ttl'));
    }

    public function testGcRemovesKeysAgedBeyondMaxLifetime(): void
    {
        // Simulate a session whose remaining TTL implies last access 86340s ago:
        // age = lifetime(86400) - remaining(60) = 86340 > maxLifetime(600).
        $this->redis->setex($this->prefix . 'aged', 60, \serialize(['x' => 1]));

        $this->assertSame(1, $this->driver->gc(600));
    }

    public function testGcKeepsFreshSessions(): void
    {
        $this->driver->write('fresh', ['x' => 1]);

        $this->assertSame(0, $this->driver->gc(600));
        $this->assertSame(['x' => 1], $this->driver->read('fresh'));
    }

    public function testGcDoesNotTouchOtherPrefixes(): void
    {
        $decoy = 'other_prefix_decoy_' . \uniqid();
        $this->redis->set($decoy, 'keep-me');

        try {
            $this->driver->gc(0);
            $this->assertSame('keep-me', $this->redis->get($decoy));
        } finally {
            $this->redis->del($decoy);
        }
    }

    public function testEmptySessionIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->read('');
    }

    public function testNulByteSessionIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->driver->write("bad\x00id", []);
    }

    public function testZeroLifetimeRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RedisDriver($this->redis, $this->prefix, 0);
    }

    public function testAccessors(): void
    {
        $this->assertSame($this->redis, $this->driver->getRedis());
        $this->assertSame($this->prefix, $this->driver->getPrefix());
        $this->assertSame(86400, $this->driver->getLifetime());
    }
}
