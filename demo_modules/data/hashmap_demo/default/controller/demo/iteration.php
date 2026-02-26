<?php
/**
 * HashMap Iteration Demo
 * 
 * @llm Demonstrates iterating over HashMap.
 */

use Razy\Controller;
use Razy\HashMap;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Foreach Iteration ===
    $map = new HashMap([
        'a' => 1,
        'b' => 2,
        'c' => 3,
    ]);
    
    $values = [];
    $keys = [];
    foreach ($map as $key => $value) {
        $keys[] = $key;
        $values[] = $value;
    }
    
    $results['foreach'] = [
        'keys' => $keys,     // Numeric position keys (0, 1, 2)
        'values' => $values, // Actual values
        'description' => 'foreach returns position as key, value as value',
    ];
    
    // === Rewind Test ===
    $map = new HashMap(['x' => 10, 'y' => 20]);
    
    $first = [];
    foreach ($map as $v) {
        $first[] = $v;
    }
    
    $second = [];
    foreach ($map as $v) {
        $second[] = $v;
    }
    
    $results['rewind'] = [
        'first_iteration' => $first,
        'second_iteration' => $second,
        'same' => $first === $second,
        'description' => 'Iterator properly rewinds for multiple iterations',
    ];
    
    // === Generator Access ===
    $map = new HashMap();
    $map->push('alpha', 'a')
        ->push('beta', 'b')
        ->push('gamma', 'c');
    
    $generator = $map->getGenerator();
    $fromGenerator = [];
    foreach ($generator as $value) {
        $fromGenerator[] = $value;
    }
    
    $results['generator'] = [
        'values' => $fromGenerator,
        'description' => 'getGenerator() returns Generator for lazy iteration',
    ];
    
    // === Count ===
    $map = new HashMap();
    $map->push('a')->push('b')->push('c');
    
    $results['count'] = [
        'count' => count($map),
        'countable' => $map instanceof \Countable,
        'description' => 'HashMap implements Countable',
    ];
    
    // === Iteration Order ===
    $map = new HashMap();
    $map->push('first', 'key1')
        ->push('second', 'key2')
        ->push('third', 'key3');
    
    // Remove middle element
    $map->remove('c:key2');
    
    // Add another
    $map->push('fourth', 'key4');
    
    $order = [];
    foreach ($map as $value) {
        $order[] = $value;
    }
    
    $results['order'] = [
        'iteration_order' => $order,
        'description' => 'Maintains insertion order, gaps from removal',
    ];
    
    // === Working with Objects in Iteration ===
    $map = new HashMap();
    
    $obj1 = new \stdClass();
    $obj1->id = 1;
    
    $obj2 = new \stdClass();
    $obj2->id = 2;
    
    $map->push($obj1)->push($obj2);
    
    $ids = [];
    foreach ($map as $obj) {
        $ids[] = $obj->id;
    }
    
    $results['object_iteration'] = [
        'ids' => $ids,
        'description' => 'Iterate objects stored in HashMap',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
