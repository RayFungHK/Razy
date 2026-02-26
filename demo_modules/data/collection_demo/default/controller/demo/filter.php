<?php
/**
 * Collection Filter Demo
 * 
 * @llm Demonstrates Collection filter syntax via __invoke().
 * 
 * ## Filter Syntax
 * 
 * ```php
 * $collection('key')                // Select single key
 * $collection('*')                  // Select all elements
 * $collection('path.to.key')        // Nested path with dot notation
 * $collection('key:filter(args)')   // Apply filter plugin
 * $collection('a, b, c')            // Multiple selectors (comma separated)
 * ```
 * 
 * ## Built-in Filters
 * 
 * - `:istype(type)` - Filter by type (string, integer, etc.)
 */

use Razy\Controller;
use Razy\Collection;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Simple Key Selection ===
    $collection = new Collection([
        'name' => 'John',
        'email' => 'john@example.com',
        'age' => 30,
    ]);
    
    $processor = $collection('name');
    
    $results['simple'] = [
        'selected' => $processor->getArray(),
        'description' => "collection('name') selects single key",
    ];
    
    // === Wildcard Selection ===
    $processor = $collection('*');
    
    $results['wildcard'] = [
        'selected' => $processor->getArray(),
        'description' => "collection('*') selects all values",
    ];
    
    // === Nested Path ===
    $collection = new Collection([
        'user' => [
            'profile' => [
                'name' => 'Jane',
                'bio' => 'Developer',
            ],
            'settings' => [
                'theme' => 'dark',
            ],
        ],
    ]);
    
    $processor = $collection('user.profile.name');
    
    $results['nested'] = [
        'selected' => $processor->getArray(),
        'description' => 'Dot notation for nested access',
    ];
    
    // === Nested Wildcard ===
    $processor = $collection('user.*');
    
    $results['nested_wildcard'] = [
        'selected' => $processor->getArray(),
        'description' => 'user.* selects profile and settings',
    ];
    
    // === Deep Wildcard ===
    $collection = new Collection([
        'users' => [
            ['name' => 'John', 'age' => 30],
            ['name' => 'Jane', 'age' => 25],
            ['name' => 'Bob', 'age' => 35],
        ],
    ]);
    
    $processor = $collection('users.*.name');
    
    $results['deep_wildcard'] = [
        'selected' => $processor->getArray(),
        'description' => 'users.*.name extracts all user names',
    ];
    
    // === Filter: istype ===
    $collection = new Collection([
        'string_val' => 'hello',
        'int_val' => 42,
        'float_val' => 3.14,
        'bool_val' => true,
    ]);
    
    $stringOnly = $collection('*:istype(string)');
    
    $results['istype'] = [
        'strings_only' => $stringOnly->getArray(),
        'description' => ':istype(string) filters to string values',
    ];
    
    // === Multiple Selectors ===
    $collection = new Collection([
        'a' => 1,
        'b' => 2,
        'c' => 3,
        'd' => 4,
    ]);
    
    $processor = $collection('a, c');
    
    $results['multiple'] = [
        'selected' => $processor->getArray(),
        'description' => 'Comma-separated selectors: a, c',
    ];
    
    // === Get vs GetArray ===
    $collection = new Collection(['x' => 10, 'y' => 20]);
    
    $processorResult = $collection('*');
    $asCollection = $processorResult->get();   // Returns Collection
    $asArray = $processorResult->getArray();    // Returns array
    
    $results['get_vs_getarray'] = [
        'get_type' => get_class($asCollection),
        'getArray_type' => gettype($asArray),
        'description' => 'get() returns Collection, getArray() returns array',
    ];
    
    // === Complex Nested Structure ===
    $collection = new Collection([
        'departments' => [
            'engineering' => [
                'employees' => [
                    ['name' => 'Alice', 'role' => 'Lead'],
                    ['name' => 'Bob', 'role' => 'Senior'],
                ],
            ],
            'design' => [
                'employees' => [
                    ['name' => 'Carol', 'role' => 'Lead'],
                ],
            ],
        ],
    ]);
    
    $allEmployeeNames = $collection('departments.*.employees.*.name');
    
    $results['complex'] = [
        'all_names' => $allEmployeeNames->getArray(),
        'description' => 'Deep nested wildcard extraction',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
