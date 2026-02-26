<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Collection;

#[CoversClass(Collection::class)]
class CollectionExtraTest extends TestCase
{
    // ── array() method ──────────────────────────────────────────

    public function testArrayReturnsPlainArray(): void
    {
        $c = new Collection(['a' => 1, 'b' => 2]);
        $result = $c->array();
        $this->assertIsArray($result);
        $this->assertSame(['a' => 1, 'b' => 2], $result);
    }

    public function testArrayConvertsNestedCollections(): void
    {
        $inner = new Collection(['x' => 10]);
        $outer = new Collection(['nested' => $inner, 'flat' => 'val']);
        $result = $outer->array();

        $this->assertIsArray($result['nested']);
        $this->assertSame(['x' => 10], $result['nested']);
        $this->assertSame('val', $result['flat']);
    }

    public function testArrayDeeplyNested(): void
    {
        $c = new Collection([
            'l1' => new Collection([
                'l2' => new Collection([
                    'value' => 42,
                ]),
            ]),
        ]);

        $result = $c->array();
        $this->assertSame(42, $result['l1']['l2']['value']);
    }

    // ── Serialization ───────────────────────────────────────────

    public function testSerializeRoundtrip(): void
    {
        $data = ['name' => 'test', 'items' => [1, 2, 3]];
        $c = new Collection($data);

        $serialized = \serialize($c);
        $restored = \unserialize($serialized);

        $this->assertInstanceOf(Collection::class, $restored);
        $this->assertSame($data, $restored->array());
    }

    public function testSerializeWithNestedCollection(): void
    {
        $inner = new Collection(['x' => 1]);
        $c = new Collection(['nested' => $inner]);
        $serialized = \serialize($c);
        $restored = \unserialize($serialized);

        $result = $restored->array();
        $this->assertSame(['x' => 1], $result['nested']);
    }

    // ── offsetGet for missing key ──────────────────────────────

    public function testOffsetGetMissingKeyReturnsNull(): void
    {
        $c = new Collection(['a' => 1]);
        $this->assertNull($c['missing']);
    }

    // ── Empty collection ────────────────────────────────────────

    public function testEmptyCollectionArray(): void
    {
        $c = new Collection([]);
        $this->assertSame([], $c->array());
    }

    // ── Numeric keys ────────────────────────────────────────────

    public function testNumericKeysPreserved(): void
    {
        $c = new Collection([10 => 'a', 20 => 'b', 30 => 'c']);
        $arr = $c->array();
        $this->assertArrayHasKey(10, $arr);
        $this->assertArrayHasKey(20, $arr);
        $this->assertSame('a', $arr[10]);
    }

    // ── Countable / Iterable ────────────────────────────────────

    public function testCountReturnsCorrectSize(): void
    {
        $c = new Collection(['a', 'b', 'c', 'd', 'e']);
        $this->assertCount(5, $c);
    }

    public function testIterateOverElements(): void
    {
        $data = ['x' => 1, 'y' => 2, 'z' => 3];
        $c = new Collection($data);
        $result = [];
        foreach ($c as $k => $v) {
            $result[$k] = $v;
        }
        $this->assertSame($data, $result);
    }

    // ── Modification via ArrayAccess ────────────────────────────

    public function testSetAndUnset(): void
    {
        $c = new Collection([]);
        $c['key'] = 'value';
        $this->assertSame('value', $c['key']);
        $this->assertTrue(isset($c['key']));

        unset($c['key']);
        $this->assertFalse(isset($c['key']));
    }

    public function testExchangeArrayReturnsOldData(): void
    {
        $c = new Collection(['old' => 1]);
        $old = $c->exchangeArray(['new' => 2]);
        $this->assertSame(['old' => 1], $old);
        $this->assertSame(2, $c['new']);
        $this->assertNull($c['old']);
    }
}
