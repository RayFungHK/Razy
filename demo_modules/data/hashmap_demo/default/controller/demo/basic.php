<?php
/**
 * Basic HashMap Demo
 * 
 * @llm Demonstrates basic HashMap operations.
 * 
 * ## Creating HashMap
 * 
 * ```php
 * $map = new HashMap();           // Empty
 * $map = new HashMap(['key' => 'value']); // From array
 * ```
 * 
 * ## Key Prefixes
 * 
 * When using push() with a custom key, the key is prefixed with 'c:'.
 * Access with the full prefixed key: `$map['c:key']`
 */

use Razy\Controller;
use Razy\HashMap;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Constructor ===
    $map = new HashMap();
    $results['empty'] = [
        'count' => count($map),
        'description' => 'Empty HashMap',
    ];
    
    // === Constructor with Array ===
    $map = new HashMap([
        'key1' => 'value1',
        'key2' => 'value2',
    ]);
    $results['from_array'] = [
        'count' => count($map),
        'key1' => $map['key1'],  // Keys from constructor use as-is
        'key2' => $map['key2'],
        'description' => 'HashMap from array',
    ];
    
    // === Push Method ===
    $map = new HashMap();
    $map->push('value1', 'my_key');  // Custom key -> c:my_key
    $map->push('value2');            // Auto-generated key -> i:guid
    
    $results['push'] = [
        'count' => count($map),
        'custom_key_value' => $map['c:my_key'],  // Access with c: prefix
        'description' => 'push() adds values with optional custom key',
    ];
    
    // === Fluent Chaining ===
    $map = new HashMap();
    $map->push('a', 'first')
        ->push('b', 'second')
        ->push('c', 'third');
    
    $results['chaining'] = [
        'count' => count($map),
        'values' => [$map['c:first'], $map['c:second'], $map['c:third']],
        'description' => 'push() returns $this for chaining',
    ];
    
    // === ArrayAccess Interface ===
    $map = new HashMap();
    $map['key1'] = 'value1';  // Sets via offsetSet -> push
    
    $results['array_access'] = [
        'isset' => isset($map['key1']),
        'get' => $map['key1'],
        'description' => 'ArrayAccess: set, get, isset',
    ];
    
    // === Has Method ===
    $map = new HashMap();
    $map->push('value', 'test_key');
    
    $results['has'] = [
        'exists' => $map->has('c:test_key'),
        'not_exists' => $map->has('nonexistent'),
        'description' => 'has() checks key existence',
    ];
    
    // === Remove Method ===
    $map = new HashMap();
    $map->push('temp', 'to_remove');
    $map->remove('c:to_remove');
    
    $results['remove'] = [
        'after_remove' => $map->has('c:to_remove'),
        'count' => count($map),
        'description' => 'remove() deletes by key',
    ];
    
    // === Unset via ArrayAccess ===
    $map = new HashMap();
    $map['key'] = 'value';
    unset($map['key']);
    
    $results['unset'] = [
        'after_unset' => isset($map['key']),
        'description' => 'unset() removes element',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
