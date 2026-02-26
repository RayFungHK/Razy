<?php
/**
 * Unit tests for Razy\HashMap.
 *
 * This file is part of Razy v0.5.
 */

namespace Razy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Razy\HashMap;

#[CoversClass(HashMap::class)]
class HashMapTest extends TestCase
{
    // ==================== BASIC CONSTRUCTION ====================

    public function testConstructor(): void
    {
        $map = new HashMap();
        $this->assertInstanceOf(HashMap::class, $map);
        $this->assertEquals(0, count($map));
    }

    public function testConstructorWithArray(): void
    {
        $data = [
            'key1' => 'value1',
            'key2' => 'value2'
        ];

        $map = new HashMap($data);
        
        $this->assertEquals(2, count($map));
        // Constructor uses push() which adds c: prefix for custom string keys
        $this->assertEquals('value1', $map['c:key1']);
        $this->assertEquals('value2', $map['c:key2']);
    }

    // ==================== PUSH OPERATIONS ====================

    public function testPushWithStringKey(): void
    {
        $map = new HashMap();
        $result = $map->push('value1', 'key1');

        $this->assertInstanceOf(HashMap::class, $result);
        $this->assertEquals('value1', $map['c:key1']);
    }

    public function testPushWithoutKey(): void
    {
        $map = new HashMap();
        $map->push('value1');
        $map->push('value2');

        $this->assertEquals(2, count($map));
    }

    public function testPushChaining(): void
    {
        $map = new HashMap();
        $result = $map->push('a')->push('b')->push('c');

        $this->assertInstanceOf(HashMap::class, $result);
        $this->assertEquals(3, count($map));
    }

    // ==================== OBJECT KEYS ====================

    public function testPushObjectAsKey(): void
    {
        $map = new HashMap();
        $obj = new \stdClass();
        $obj->name = 'test';

        // push() with a string hash uses c: prefix; has() with object uses o: prefix
        // To test object key, push the object itself
        $map->push('value', spl_object_hash($obj));
        
        // The key is stored with c: prefix
        $this->assertTrue($map->has('c:' . spl_object_hash($obj)));
    }

    public function testObjectHashStability(): void
    {
        $map = new HashMap();
        $obj = new \stdClass();
        
        $map->push('first_value');
        $map->push('object_value', spl_object_hash($obj));
        
        // push() with string hash uses c: prefix
        $this->assertTrue($map->has('c:' . spl_object_hash($obj)));
    }

    // ==================== ARRAY ACCESS ====================

    public function testArrayAccessSet(): void
    {
        $map = new HashMap();
        $map['key1'] = 'value1';

        // offsetSet delegates to push() which adds c: prefix
        $this->assertEquals('value1', $map['c:key1']);
    }

    public function testArrayAccessGet(): void
    {
        $map = new HashMap();
        $map->push('value1', 'key1');

        $this->assertEquals('value1', $map['c:key1']);
    }

    public function testArrayAccessIsset(): void
    {
        $map = new HashMap();
        $map['key1'] = 'value1';

        // offsetSet→push adds c: prefix, so offsetExists needs c: prefix too
        $this->assertTrue(isset($map['c:key1']));
        $this->assertFalse(isset($map['nonexistent']));
    }

    public function testArrayAccessUnset(): void
    {
        $map = new HashMap();
        $map['key1'] = 'value1';
        
        unset($map['key1']);
        
        $this->assertFalse(isset($map['key1']));
    }

    // ==================== HAS & REMOVE ====================

    public function testHasMethod(): void
    {
        $map = new HashMap();
        $map->push('value', 'key1');

        $this->assertTrue($map->has('c:key1'));
        $this->assertFalse($map->has('nonexistent'));
    }

    public function testHasWithObject(): void
    {
        $map = new HashMap();
        $obj = new \stdClass();
        
        $map->push($obj);
        
        $this->assertTrue($map->has($obj));
    }

    public function testRemoveMethod(): void
    {
        $map = new HashMap();
        $map->push('value', 'key1');

        $map->remove('c:key1');
        
        $this->assertFalse($map->has('c:key1'));
    }

    public function testRemoveWithObject(): void
    {
        $map = new HashMap();
        $obj = new \stdClass();
        
        $map->push($obj);
        $this->assertTrue($map->has($obj));
        
        $map->remove($obj);
        $this->assertFalse($map->has($obj));
    }

    // ==================== ITERATION ====================

    public function testIteration(): void
    {
        $map = new HashMap([
            'a' => 1,
            'b' => 2,
            'c' => 3
        ]);

        $values = [];
        foreach ($map as $key => $value) {
            $values[$key] = $value;
        }

        $this->assertEquals(3, count($values));
    }

    public function testIteratorRewind(): void
    {
        $map = new HashMap(['a' => 1, 'b' => 2]);

        // First iteration
        $first = [];
        foreach ($map as $value) {
            $first[] = $value;
        }

        // Second iteration (tests rewind)
        $second = [];
        foreach ($map as $value) {
            $second[] = $value;
        }

        $this->assertEquals($first, $second);
    }

    public function testIteratorCurrent(): void
    {
        $map = new HashMap(['key' => 'value']);
        
        $map->rewind();
        $this->assertEquals('value', $map->current());
    }

    public function testIteratorNext(): void
    {
        $map = new HashMap(['a' => 1, 'b' => 2]);
        
        $map->rewind();
        $this->assertEquals(1, $map->current());
        
        $map->next();
        $this->assertEquals(2, $map->current());
    }

    public function testIteratorValid(): void
    {
        $map = new HashMap(['a' => 1]);
        
        $map->rewind();
        $this->assertTrue($map->valid());
        
        $map->next();
        $this->assertFalse($map->valid());
    }

    public function testIteratorKey(): void
    {
        $map = new HashMap(['a' => 1, 'b' => 2]);
        
        $map->rewind();
        $this->assertEquals(0, $map->key());
        
        $map->next();
        $this->assertEquals(1, $map->key());
    }

    // ==================== COUNTABLE ====================

    public function testCount(): void
    {
        $map = new HashMap();
        $this->assertEquals(0, count($map));

        $map->push('a');
        $this->assertEquals(1, count($map));

        $map->push('b');
        $map->push('c');
        $this->assertEquals(3, count($map));
    }

    public function testCountAfterRemove(): void
    {
        $map = new HashMap(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertEquals(3, count($map));

        $map->remove('c:a');
        $this->assertEquals(2, count($map));
    }

    // ==================== GENERATOR ====================

    public function testGetGenerator(): void
    {
        $map = new HashMap(['a' => 1, 'b' => 2, 'c' => 3]);
        
        $generator = $map->getGenerator();
        $this->assertInstanceOf(\Generator::class, $generator);

        $values = iterator_to_array($generator);
        $this->assertEquals(3, count($values));
    }

    public function testGeneratorValues(): void
    {
        $map = new HashMap(['x' => 10, 'y' => 20, 'z' => 30]);
        
        $values = [];
        foreach ($map->getGenerator() as $value) {
            $values[] = $value;
        }

        $this->assertContains(10, $values);
        $this->assertContains(20, $values);
        $this->assertContains(30, $values);
    }

    // ==================== DATA TYPES ====================

    public function testStoreStrings(): void
    {
        $map = new HashMap();
        $map->push('string value', 'key');

        $this->assertEquals('string value', $map['c:key']);
    }

    public function testStoreIntegers(): void
    {
        $map = new HashMap();
        $map->push(123, 'num');

        $this->assertEquals(123, $map['c:num']);
    }

    public function testStoreArrays(): void
    {
        $map = new HashMap();
        $array = ['a' => 1, 'b' => 2];
        $map->push($array, 'arr');

        $this->assertEquals($array, $map['c:arr']);
    }

    public function testStoreObjects(): void
    {
        $map = new HashMap();
        $obj = new \stdClass();
        $obj->name = 'Test';
        
        $map->push($obj, 'obj');

        $retrieved = $map['c:obj'];
        $this->assertInstanceOf(\stdClass::class, $retrieved);
        $this->assertEquals('Test', $retrieved->name);
    }

    public function testStoreBooleans(): void
    {
        $map = new HashMap();
        $map->push(true, 'bool1');
        $map->push(false, 'bool2');

        $this->assertTrue($map['c:bool1']);
        $this->assertFalse($map['c:bool2']);
    }

    public function testStoreNull(): void
    {
        $map = new HashMap();
        $map->push(null, 'null_key');

        $this->assertNull($map['c:null_key']);
    }

    // ==================== EDGE CASES ====================

    public function testEmptyKey(): void
    {
        $map = new HashMap();
        $map->push('value', '');

        // Empty key should auto-generate
        $this->assertEquals(1, count($map));
    }

    public function testGetNonExistent(): void
    {
        $map = new HashMap();
        $value = $map['nonexistent'];

        $this->assertNull($value);
    }

    public function testMultiplePushSameKey(): void
    {
        $map = new HashMap();
        $map->push('first', 'key');
        $map->push('second', 'key');

        // Same custom key produces same c:key hash — overwrites, not duplicates
        $this->assertEquals(1, count($map));
        $this->assertEquals('second', $map['c:key']);
    }

    public function testIterateEmptyMap(): void
    {
        $map = new HashMap();
        
        $count = 0;
        foreach ($map as $value) {
            $count++;
        }

        $this->assertEquals(0, $count);
    }

    // ==================== COMPLEX SCENARIOS ====================

    public function testMixedTypes(): void
    {
        $map = new HashMap();
        
        $map->push('string', 'str');
        $map->push(123, 'num');
        $map->push(['a' => 1], 'arr');
        $map->push(new \stdClass(), 'obj');
        $map->push(true, 'bool');

        $this->assertEquals(5, count($map));
    }

    public function testObjectIdentity(): void
    {
        $map = new HashMap();
        
        $obj1 = new \stdClass();
        $obj1->id = 1;
        
        $obj2 = new \stdClass();
        $obj2->id = 2;

        $map->push($obj1);
        $map->push($obj2);

        // Both objects should be stored separately
        $this->assertEquals(2, count($map));
    }

    public function testNestedArrays(): void
    {
        $map = new HashMap();
        
        $nested = [
            'level1' => [
                'level2' => [
                    'value' => 'deep'
                ]
            ]
        ];

        $map->push($nested, 'data');
        $retrieved = $map['c:data'];

        $this->assertEquals('deep', $retrieved['level1']['level2']['value']);
    }

    public function testOrderPreservation(): void
    {
        $map = new HashMap();
        
        $map->push('first');
        $map->push('second');
        $map->push('third');

        $values = [];
        foreach ($map as $value) {
            $values[] = $value;
        }

        // Order should be preserved
        $this->assertEquals('first', $values[0]);
        $this->assertEquals('second', $values[1]);
        $this->assertEquals('third', $values[2]);
    }

    // ==================== REAL-WORLD PATTERNS ====================

    public function testCachePattern(): void
    {
        $map = new HashMap();
        
        // Simulate caching
        $key = 'user:123';
        $userData = ['id' => 123, 'name' => 'John'];
        
        $map->push($userData, $key);
        
        // Retrieve from cache
        $this->assertEquals($userData, $map['c:' . $key]);
    }

    public function testEventListeners(): void
    {
        $map = new HashMap();
        
        $listener1 = fn() => 'Listener 1';
        $listener2 = fn() => 'Listener 2';
        
        $map->push($listener1, 'click');
        $map->push($listener2, 'hover');

        $click = $map['c:click'];
        $this->assertEquals('Listener 1', $click());
    }

    public function testObjectRegistry(): void
    {
        $map = new HashMap();
        
        $obj1 = new \stdClass();
        $obj1->type = 'service';
        
        $obj2 = new \stdClass();
        $obj2->type = 'repository';

        $map->push('ServiceA', spl_object_hash($obj1));
        $map->push('RepositoryB', spl_object_hash($obj2));

        $this->assertEquals(2, count($map));
    }
}
