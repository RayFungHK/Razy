<?php

/**
 * Unit tests for Razy\Cache and Razy\Cache\* adapters.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Razy\Cache;
use Razy\Cache\CacheInterface;
use Razy\Cache\FileAdapter;
use Razy\Cache\InvalidArgumentException;
use Razy\Cache\NullAdapter;
use Razy\Contract\SimpleCache\PsrCacheInterface;
use stdClass;

#[CoversClass(Cache::class)]
#[CoversClass(FileAdapter::class)]
#[CoversClass(NullAdapter::class)]
#[CoversClass(InvalidArgumentException::class)]
class CacheTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'razy_cache_test_' . \uniqid();
        Cache::reset();
    }

    protected function tearDown(): void
    {
        Cache::reset();
        $this->removeDirectory($this->cacheDir);
    }

    // ==================== FILE ADAPTER: DATA TYPES ====================

    public static function cacheDataTypeProvider(): array
    {
        return [
            'array' => ['array.key', ['name' => 'Razy', 'version' => '0.5.4', 'nested' => ['a' => 1]]],
            'integer' => ['int.key', 42],
            'float' => ['float.key', 3.14],
            'bool true' => ['bool.true', true],
            'bool false' => ['bool.false', false],
        ];
    }

    // ==================== FILE ADAPTER: KEY VALIDATION ====================

    public static function invalidCacheKeyProvider(): array
    {
        return [
            'empty key' => ['', 'get', []],
            'curly braces' => ['invalid{key}', 'set', ['value']],
            'slash' => ['invalid/key', 'set', ['value']],
            'colon' => ['invalid:key', 'set', ['value']],
        ];
    }

    public static function nullAdapterTrueMethodProvider(): array
    {
        return [
            'set' => ['set', ['any.key', 'value']],
            'delete' => ['delete', ['any.key']],
            'clear' => ['clear', []],
            'setMultiple' => ['setMultiple', [['a' => 1, 'b' => 2]]],
            'deleteMultiple' => ['deleteMultiple', [['a', 'b']]],
        ];
    }

    // ==================== FILE ADAPTER: BASIC OPERATIONS ====================

    public function testFileAdapterSetAndGet(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $this->assertTrue($adapter->set('test.key', 'hello'));
        $this->assertSame('hello', $adapter->get('test.key'));
    }

    public function testFileAdapterGetMissReturnsDefault(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $this->assertNull($adapter->get('nonexistent'));
        $this->assertSame('fallback', $adapter->get('nonexistent', 'fallback'));
    }

    public function testFileAdapterDelete(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('to.delete', 'value');
        $this->assertTrue($adapter->has('to.delete'));

        $this->assertTrue($adapter->delete('to.delete'));
        $this->assertFalse($adapter->has('to.delete'));
    }

    public function testFileAdapterDeleteNonexistent(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $this->assertTrue($adapter->delete('nonexistent'));
    }

    public function testFileAdapterHas(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $this->assertFalse($adapter->has('test.has'));
        $adapter->set('test.has', 'exists');
        $this->assertTrue($adapter->has('test.has'));
    }

    public function testFileAdapterClear(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('key1', 'val1');
        $adapter->set('key2', 'val2');
        $adapter->set('key3', 'val3');

        $this->assertTrue($adapter->clear());
        $this->assertFalse($adapter->has('key1'));
        $this->assertFalse($adapter->has('key2'));
        $this->assertFalse($adapter->has('key3'));
    }

    #[DataProvider('cacheDataTypeProvider')]
    public function testFileAdapterStoresDataTypes(string $key, mixed $value): void
    {
        $adapter = new FileAdapter($this->cacheDir);
        $adapter->set($key, $value);
        $this->assertSame($value, $adapter->get($key));
    }

    public function testFileAdapterStoresNull(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('null.key', null);
        // has() should return true since the key exists
        $this->assertTrue($adapter->has('null.key'));
        // get() with a different default confirms null was stored
        $this->assertNull($adapter->get('null.key', 'not-null'));
    }

    public function testFileAdapterStoresObjects(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $obj = new stdClass();
        $obj->name = 'test';
        $obj->value = 123;

        $adapter->set('obj.key', $obj);
        $result = $adapter->get('obj.key');

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('test', $result->name);
        $this->assertSame(123, $result->value);
    }

    // ==================== FILE ADAPTER: TTL ====================

    public function testFileAdapterTTLWithIntegerSeconds(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('ttl.key', 'value', 3600);
        $this->assertTrue($adapter->has('ttl.key'));
        $this->assertSame('value', $adapter->get('ttl.key'));
    }

    public function testFileAdapterTTLWithDateInterval(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $ttl = new DateInterval('PT1H'); // 1 hour
        $adapter->set('interval.key', 'value', $ttl);
        $this->assertTrue($adapter->has('interval.key'));
        $this->assertSame('value', $adapter->get('interval.key'));
    }

    public function testFileAdapterTTLExpiredEntryReturnsDefault(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        // Set with 1-second TTL then wait for expiry
        $adapter->set('expiring.key', 'value', 1);
        \sleep(2);

        $this->assertNull($adapter->get('expiring.key'));
        $this->assertFalse($adapter->has('expiring.key'));
    }

    public function testFileAdapterNullTTLNeverExpires(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('forever.key', 'value', null);
        $this->assertTrue($adapter->has('forever.key'));
        $this->assertSame('value', $adapter->get('forever.key'));
    }

    public function testFileAdapterZeroTTLDeletesEntry(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('zero.ttl', 'value');
        $adapter->set('zero.ttl', 'new-value', 0);

        $this->assertFalse($adapter->has('zero.ttl'));
    }

    // ==================== FILE ADAPTER: BATCH OPERATIONS ====================

    public function testFileAdapterGetMultiple(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('multi.a', 'alpha');
        $adapter->set('multi.b', 'beta');

        $result = $adapter->getMultiple(['multi.a', 'multi.b', 'multi.c'], 'default');

        $this->assertSame('alpha', $result['multi.a']);
        $this->assertSame('beta', $result['multi.b']);
        $this->assertSame('default', $result['multi.c']);
    }

    public function testFileAdapterSetMultiple(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $this->assertTrue($adapter->setMultiple([
            'batch.x' => 'x-value',
            'batch.y' => 'y-value',
            'batch.z' => 'z-value',
        ]));

        $this->assertSame('x-value', $adapter->get('batch.x'));
        $this->assertSame('y-value', $adapter->get('batch.y'));
        $this->assertSame('z-value', $adapter->get('batch.z'));
    }

    public function testFileAdapterDeleteMultiple(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->setMultiple(['del.a' => 1, 'del.b' => 2, 'del.c' => 3]);

        $this->assertTrue($adapter->deleteMultiple(['del.a', 'del.c']));
        $this->assertFalse($adapter->has('del.a'));
        $this->assertTrue($adapter->has('del.b'));
        $this->assertFalse($adapter->has('del.c'));
    }

    #[DataProvider('invalidCacheKeyProvider')]
    public function testFileAdapterRejectsInvalidKey(string $key, string $method, array $args): void
    {
        $adapter = new FileAdapter($this->cacheDir);
        $this->expectException(InvalidArgumentException::class);
        $adapter->$method($key, ...$args);
    }

    // ==================== FILE ADAPTER: STATS & GC ====================

    public function testFileAdapterStats(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('stat.a', 'value1');
        $adapter->set('stat.b', 'value2');

        $stats = $adapter->getStats();

        $this->assertSame($this->cacheDir, $stats['directory']);
        $this->assertSame(2, $stats['files']);
        $this->assertGreaterThan(0, $stats['size']);
    }

    public function testFileAdapterGarbageCollection(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('gc.live', 'alive', 3600);
        $adapter->set('gc.dead', 'expired', 1);
        \sleep(2);

        $removed = $adapter->gc();

        $this->assertSame(1, $removed);
        $this->assertTrue($adapter->has('gc.live'));
        $this->assertFalse($adapter->has('gc.dead'));
    }

    // ==================== FILE ADAPTER: DIRECTORY CREATION ====================

    public function testFileAdapterCreatesDirectory(): void
    {
        $dir = $this->cacheDir . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deep';
        $adapter = new FileAdapter($dir);

        $this->assertDirectoryExists($dir);
    }

    public function testFileAdapterRejectsNonWritableDirectory(): void
    {
        // Skip on Windows where permission model differs
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permission test skipped on Windows.');
        }

        $dir = '/proc/razy_cache_test_' . \uniqid();

        $this->expectException(InvalidArgumentException::class);
        new FileAdapter($dir);
    }

    // ==================== NULL ADAPTER ====================

    public function testNullAdapterGetReturnsDefault(): void
    {
        $adapter = new NullAdapter();

        $this->assertNull($adapter->get('any.key'));
        $this->assertSame('custom', $adapter->get('any.key', 'custom'));
    }

    #[DataProvider('nullAdapterTrueMethodProvider')]
    public function testNullAdapterMethodReturnsTrue(string $method, array $args): void
    {
        $adapter = new NullAdapter();
        $this->assertTrue($adapter->$method(...$args));
    }

    public function testNullAdapterHasReturnsFalse(): void
    {
        $adapter = new NullAdapter();

        $adapter->set('any.key', 'value');
        $this->assertFalse($adapter->has('any.key'));
    }

    // ==================== NullAdapter: delete/clear/setMultiple/deleteMultiple covered by DataProvider ====================

    // ==================== CACHE FACADE: INITIALIZATION ====================

    public function testCacheFacadeNotInitializedByDefault(): void
    {
        $this->assertFalse(Cache::isInitialized());
        $this->assertFalse(Cache::isEnabled());
    }

    public function testCacheFacadeInitializeWithDirectory(): void
    {
        Cache::initialize($this->cacheDir);

        $this->assertTrue(Cache::isInitialized());
        $this->assertTrue(Cache::isEnabled());
        $this->assertInstanceOf(FileAdapter::class, Cache::getAdapter());
    }

    public function testCacheFacadeInitializeWithCustomAdapter(): void
    {
        $adapter = new NullAdapter();
        Cache::initialize('', $adapter);

        $this->assertTrue(Cache::isInitialized());
        $this->assertInstanceOf(NullAdapter::class, Cache::getAdapter());
    }

    public function testCacheFacadeInitializeWithEmptyStringUsesNullAdapter(): void
    {
        Cache::initialize('');

        $this->assertTrue(Cache::isInitialized());
        $this->assertInstanceOf(NullAdapter::class, Cache::getAdapter());
    }

    public function testCacheFacadeSetAdapter(): void
    {
        $adapter = new FileAdapter($this->cacheDir);
        Cache::setAdapter($adapter);

        $this->assertTrue(Cache::isInitialized());
        $this->assertSame($adapter, Cache::getAdapter());
    }

    // ==================== CACHE FACADE: ENABLE/DISABLE ====================

    public function testCacheFacadeDisable(): void
    {
        Cache::initialize($this->cacheDir);
        Cache::set('pre.disable', 'value');

        Cache::setEnabled(false);

        $this->assertFalse(Cache::isEnabled());
        $this->assertNull(Cache::get('pre.disable'));
        $this->assertFalse(Cache::has('pre.disable'));
        $this->assertFalse(Cache::set('post.disable', 'value'));
    }

    public function testCacheFacadeReEnable(): void
    {
        Cache::initialize($this->cacheDir);
        Cache::set('persist', 'data');

        Cache::setEnabled(false);
        Cache::setEnabled(true);

        // Data set before disable should still be accessible after re-enable
        $this->assertSame('data', Cache::get('persist'));
    }

    // ==================== CACHE FACADE: OPERATIONS ====================

    public function testCacheFacadeSetAndGet(): void
    {
        Cache::initialize($this->cacheDir);

        $this->assertTrue(Cache::set('facade.key', 'facade-value'));
        $this->assertSame('facade-value', Cache::get('facade.key'));
    }

    public function testCacheFacadeGetDefaultWhenUninitialized(): void
    {
        $this->assertNull(Cache::get('any.key'));
        $this->assertSame('fallback', Cache::get('any.key', 'fallback'));
    }

    public function testCacheFacadeDelete(): void
    {
        Cache::initialize($this->cacheDir);

        Cache::set('to.remove', 'value');
        $this->assertTrue(Cache::delete('to.remove'));
        $this->assertFalse(Cache::has('to.remove'));
    }

    public function testCacheFacadeClear(): void
    {
        Cache::initialize($this->cacheDir);

        Cache::set('clear.a', 1);
        Cache::set('clear.b', 2);

        $this->assertTrue(Cache::clear());
        $this->assertFalse(Cache::has('clear.a'));
        $this->assertFalse(Cache::has('clear.b'));
    }

    public function testCacheFacadeHas(): void
    {
        Cache::initialize($this->cacheDir);

        $this->assertFalse(Cache::has('facade.has'));
        Cache::set('facade.has', 'yes');
        $this->assertTrue(Cache::has('facade.has'));
    }

    public function testCacheFacadeGetMultiple(): void
    {
        Cache::initialize($this->cacheDir);

        Cache::set('fm.x', 'alpha');
        Cache::set('fm.y', 'beta');

        $result = Cache::getMultiple(['fm.x', 'fm.y', 'fm.z'], 'none');

        $this->assertSame('alpha', $result['fm.x']);
        $this->assertSame('beta', $result['fm.y']);
        $this->assertSame('none', $result['fm.z']);
    }

    public function testCacheFacadeSetMultiple(): void
    {
        Cache::initialize($this->cacheDir);

        $this->assertTrue(Cache::setMultiple(['sm.a' => 'one', 'sm.b' => 'two']));
        $this->assertSame('one', Cache::get('sm.a'));
        $this->assertSame('two', Cache::get('sm.b'));
    }

    public function testCacheFacadeDeleteMultiple(): void
    {
        Cache::initialize($this->cacheDir);

        Cache::setMultiple(['dm.a' => 1, 'dm.b' => 2, 'dm.c' => 3]);
        $this->assertTrue(Cache::deleteMultiple(['dm.a', 'dm.c']));

        $this->assertFalse(Cache::has('dm.a'));
        $this->assertTrue(Cache::has('dm.b'));
        $this->assertFalse(Cache::has('dm.c'));
    }

    // ==================== CACHE FACADE: FILE VALIDATION ====================

    public function testCacheFacadeGetValidatedCachesFileData(): void
    {
        Cache::initialize($this->cacheDir);

        $tempFile = \tempnam(\sys_get_temp_dir(), 'razy_cache_test_');
        \file_put_contents($tempFile, 'original content');

        $data = ['key' => 'value', 'list' => [1, 2, 3]];
        Cache::setValidated('validated.key', $tempFile, $data);

        $result = Cache::getValidated('validated.key', $tempFile);
        $this->assertSame($data, $result);

        @\unlink($tempFile);
    }

    public function testCacheFacadeGetValidatedDetectsFileChange(): void
    {
        Cache::initialize($this->cacheDir);

        $tempFile = \tempnam(\sys_get_temp_dir(), 'razy_cache_test_');
        \file_put_contents($tempFile, 'original');

        Cache::setValidated('validchange.key', $tempFile, ['original' => true]);

        // Modify the file (ensure mtime changes)
        \sleep(1);
        \file_put_contents($tempFile, 'modified');
        \clearstatcache(true, $tempFile);

        $result = Cache::getValidated('validchange.key', $tempFile);
        $this->assertNull($result);

        @\unlink($tempFile);
    }

    public function testCacheFacadeGetValidatedReturnDefaultForMissingFile(): void
    {
        Cache::initialize($this->cacheDir);

        $result = Cache::getValidated('nofile.key', '/nonexistent/file.txt', 'default');
        $this->assertSame('default', $result);
    }

    public function testCacheFacadeSetValidatedFailsForMissingFile(): void
    {
        Cache::initialize($this->cacheDir);

        $this->assertFalse(Cache::setValidated('nofile.set', '/nonexistent/file.txt', 'data'));
    }

    public function testCacheFacadeGetValidatedUninitializedReturnsDefault(): void
    {
        $result = Cache::getValidated('any.key', '/any/file.txt', 'fallback');
        $this->assertSame('fallback', $result);
    }

    // ==================== CACHE FACADE: ERROR HANDLING ====================

    public function testCacheFacadeGracefulWhenDisabled(): void
    {
        Cache::initialize($this->cacheDir);
        Cache::setEnabled(false);

        $this->assertNull(Cache::get('any'));
        $this->assertFalse(Cache::set('any', 'val'));
        $this->assertFalse(Cache::delete('any'));
        $this->assertFalse(Cache::has('any'));
        $this->assertFalse(Cache::deleteMultiple(['any']));
        $this->assertFalse(Cache::setMultiple(['any' => 'val']));
    }

    public function testCacheFacadeGracefulWhenUninitialized(): void
    {
        $this->assertNull(Cache::get('any'));
        $this->assertFalse(Cache::set('any', 'val'));
        $this->assertFalse(Cache::delete('any'));
        $this->assertFalse(Cache::has('any'));
        $this->assertFalse(Cache::clear());
    }

    // ==================== CACHE FACADE: RESET ====================

    public function testCacheFacadeReset(): void
    {
        Cache::initialize($this->cacheDir);
        Cache::set('before.reset', 'value');

        Cache::reset();

        $this->assertFalse(Cache::isInitialized());
        $this->assertNull(Cache::get('before.reset'));
    }

    // ==================== CACHE INTERFACE IMPLEMENTATION ====================

    public function testFileAdapterImplementsCacheInterface(): void
    {
        $adapter = new FileAdapter($this->cacheDir);
        $this->assertInstanceOf(CacheInterface::class, $adapter);
    }

    public function testNullAdapterImplementsCacheInterface(): void
    {
        $adapter = new NullAdapter();
        $this->assertInstanceOf(CacheInterface::class, $adapter);
    }

    // ==================== FILE ADAPTER: OVERWRITE ====================

    public function testFileAdapterOverwriteExistingKey(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('overwrite.key', 'original');
        $adapter->set('overwrite.key', 'updated');

        $this->assertSame('updated', $adapter->get('overwrite.key'));
    }

    // ==================== FILE ADAPTER: LARGE DATA ====================

    public function testFileAdapterLargeData(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $data = \array_fill(0, 1000, 'item-' . \str_repeat('x', 100));
        $adapter->set('large.key', $data);

        $this->assertSame($data, $adapter->get('large.key'));
    }

    // ==================== FILE ADAPTER: CONCURRENT-SAFE KEYS ====================

    public function testFileAdapterDotSeparatedKeys(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $adapter->set('yaml.config.database', ['host' => 'localhost']);
        $adapter->set('yaml.config.cache', ['enabled' => true]);

        $this->assertSame(['host' => 'localhost'], $adapter->get('yaml.config.database'));
        $this->assertSame(['enabled' => true], $adapter->get('yaml.config.cache'));
    }

    public function testFileAdapterSpecialCharactersInValues(): void
    {
        $adapter = new FileAdapter($this->cacheDir);

        $value = "Line 1\nLine 2\tTabbed\r\nWindows line\0Null byte";
        $adapter->set('special.chars', $value);

        $this->assertSame($value, $adapter->get('special.chars'));
    }

    // ==================== INVALID ARGUMENT EXCEPTION ====================

    public function testInvalidArgumentExceptionExtendsPHPException(): void
    {
        $e = new InvalidArgumentException('Test message');

        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertSame('Test message', $e->getMessage());
    }

    // ==================== PSR-16 COMPLIANCE ====================

    public function testFileAdapterImplementsPsr16Interface(): void
    {
        $adapter = new FileAdapter($this->cacheDir);
        $this->assertInstanceOf(PsrCacheInterface::class, $adapter);
    }

    public function testNullAdapterImplementsPsr16Interface(): void
    {
        $adapter = new NullAdapter();
        $this->assertInstanceOf(PsrCacheInterface::class, $adapter);
    }

    public function testCacheInterfaceExtendsPsr16Interface(): void
    {
        $this->assertTrue(
            \is_subclass_of(CacheInterface::class, PsrCacheInterface::class),
            'Razy\Cache\CacheInterface should extend Psr\SimpleCache\CacheInterface',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (\is_dir($path)) {
                $this->removeDirectory($path);
                @\rmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
