<?php
/**
 * Basic Collection Demo
 * 
 * @llm Demonstrates basic Collection operations inherited from ArrayObject.
 */

use Razy\Controller;
use Razy\Collection;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');
    
    $results = [];
    
    // === Constructor ===
    $collection = new Collection(['name' => 'Test', 'value' => 123]);
    
    $results['constructor'] = [
        'name' => $collection['name'],
        'value' => $collection['value'],
        'description' => 'Collection from array',
    ];
    
    // === ArrayAccess Interface ===
    $collection = new Collection(['key' => 'value']);
    
    // Check existence
    $hasKey = isset($collection['key']);
    $hasOther = isset($collection['other']);
    
    // Get value
    $value = $collection['key'];
    
    // Set value
    $collection['new_key'] = 'new_value';
    
    // Unset
    unset($collection['key']);
    
    $results['array_access'] = [
        'has_key' => $hasKey,
        'has_other' => $hasOther,
        'get' => $value,
        'new_key' => $collection['new_key'],
        'after_unset' => isset($collection['key']),
        'description' => 'ArrayAccess: isset, get, set, unset',
    ];
    
    // === Countable ===
    $collection = new Collection(['a' => 1, 'b' => 2, 'c' => 3]);
    
    $results['count'] = [
        'count' => count($collection),
        'description' => 'count() works via Countable interface',
    ];
    
    // === Iteration ===
    $data = ['x' => 10, 'y' => 20, 'z' => 30];
    $collection = new Collection($data);
    
    $iterated = [];
    foreach ($collection as $key => $value) {
        $iterated[$key] = $value;
    }
    
    $results['iteration'] = [
        'original' => $data,
        'iterated' => $iterated,
        'same' => $data === $iterated,
        'description' => 'foreach preserves keys and values',
    ];
    
    // === Nested Access ===
    $collection = new Collection([
        'level1' => [
            'level2' => [
                'level3' => 'deep value'
            ]
        ]
    ]);
    
    $results['nested'] = [
        'deep_value' => $collection['level1']['level2']['level3'],
        'description' => 'Nested array access works naturally',
    ];
    
    // === getArrayCopy ===
    $collection = new Collection(['a' => 1, 'b' => 2]);
    $copy = $collection->getArrayCopy();
    
    $results['copy'] = [
        'type' => gettype($copy),
        'data' => $copy,
        'description' => 'getArrayCopy() returns plain array',
    ];
    
    // === array() Method ===
    $nested = new Collection([
        'simple' => 'value',
        'nested' => new Collection(['inner' => 'content']),
    ]);
    
    $results['array_method'] = [
        'recursively_converted' => $nested->array(),
        'description' => 'array() recursively converts nested Collections',
    ];
    
    // === exchangeArray ===
    $collection = new Collection(['old' => 'data']);
    $old = $collection->exchangeArray(['new' => 'data']);
    
    $results['exchange'] = [
        'old_data' => $old,
        'new_data' => $collection->getArrayCopy(),
        'description' => 'exchangeArray replaces and returns old data',
    ];
    
    // === append ===
    $collection = new Collection([]);
    $collection->append('first');
    $collection->append('second');
    
    $results['append'] = [
        'values' => $collection->getArrayCopy(),
        'description' => 'append() adds to numeric index',
    ];
    
    // === Mixed Types ===
    $collection = new Collection([
        'string' => 'text',
        'int' => 42,
        'float' => 3.14,
        'bool' => true,
        'null' => null,
        'array' => [1, 2, 3],
    ]);
    
    $results['mixed'] = [
        'types' => array_map('gettype', $collection->getArrayCopy()),
        'description' => 'Collection holds any type',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
