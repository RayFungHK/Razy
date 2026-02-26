<?php

/**
 * Unit tests for Razy\Collection.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\Collection;

#[CoversClass(Collection::class)]
class CollectionTest extends TestCase
{
    public function testConstructorWithArray(): void
    {
        $data = ['name' => 'Test', 'value' => 123];
        $collection = new Collection($data);

        $this->assertEquals('Test', $collection['name']);
        $this->assertEquals(123, $collection['value']);
    }

    public function testArrayAccess(): void
    {
        $collection = new Collection(['key' => 'value']);

        $this->assertTrue(isset($collection['key']));
        $this->assertEquals('value', $collection['key']);

        $collection['new_key'] = 'new_value';
        $this->assertEquals('new_value', $collection['new_key']);

        unset($collection['key']);
        $this->assertFalse(isset($collection['key']));
    }

    public function testCount(): void
    {
        $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertCount(3, $collection);
    }

    public function testIteration(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];
        $collection = new Collection($data);

        $result = [];
        foreach ($collection as $key => $value) {
            $result[$key] = $value;
        }

        $this->assertEquals($data, $result);
    }

    public function testGetArrayCopy(): void
    {
        $data = ['name' => 'Test', 'value' => 123];
        $collection = new Collection($data);

        $copy = $collection->getArrayCopy();

        $this->assertEquals($data, $copy);
        $this->assertIsArray($copy);
    }

    public function testNestedArrayAccess(): void
    {
        $collection = new Collection([
            'level1' => [
                'level2' => [
                    'level3' => 'value',
                ],
            ],
        ]);

        $this->assertEquals('value', $collection['level1']['level2']['level3']);
    }

    public function testEmptyCollection(): void
    {
        $collection = new Collection([]);

        $this->assertCount(0, $collection);
        $this->assertEquals([], $collection->getArrayCopy());
    }

    public function testModifyValues(): void
    {
        $collection = new Collection(['count' => 0]);

        $collection['count']++;
        $this->assertEquals(1, $collection['count']);

        $collection['count'] += 10;
        $this->assertEquals(11, $collection['count']);
    }

    public function testMixedTypes(): void
    {
        $collection = new Collection([
            'string' => 'text',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
        ]);

        $this->assertIsString($collection['string']);
        $this->assertIsInt($collection['int']);
        $this->assertIsFloat($collection['float']);
        $this->assertIsBool($collection['bool']);
        $this->assertNull($collection['null']);
        $this->assertIsArray($collection['array']);
        $this->assertIsArray($collection['nested']);
    }

    public function testExchangeArray(): void
    {
        $collection = new Collection(['old' => 'data']);

        $old = $collection->exchangeArray(['new' => 'data']);

        $this->assertEquals(['old' => 'data'], $old);
        $this->assertEquals('data', $collection['new']);
        $this->assertFalse(isset($collection['old']));
    }

    public function testAppend(): void
    {
        $collection = new Collection([]);

        $collection->append('first');
        $collection->append('second');

        $this->assertCount(2, $collection);
        $this->assertEquals('first', $collection[0]);
        $this->assertEquals('second', $collection[1]);
    }
}
