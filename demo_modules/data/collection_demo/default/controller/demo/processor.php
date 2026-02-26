<?php
/**
 * Collection Processor Demo
 * 
 * @llm Demonstrates Collection Processor for value transformation.
 * 
 * ## Processor Chaining
 * 
 * After filtering, Processor methods transform matched values:
 * ```php
 * $collection('*')
 *     ->trim()    // Trim strings
 *     ->int()     // Convert to int
 *     ->getArray();
 * ```
 * 
 * ## Built-in Processors
 * 
 * - `trim()` - Trim whitespace from strings
 * - `int()` - Convert to integer
 * - `float()` - Convert to float
 */

use Razy\Controller;
use Razy\Collection;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === trim() Processor ===
    $collection = new Collection([
        'name' => '  John Doe  ',
        'email' => '  john@example.com  ',
        'count' => 42,  // Non-string ignored
    ]);
    
    $trimmed = $collection('name, email')->trim()->getArray();
    
    $results['trim'] = [
        'original' => ['name' => '  John Doe  ', 'email' => '  john@example.com  '],
        'trimmed' => $trimmed,
        'description' => 'trim() removes whitespace from strings',
    ];
    
    // === int() Processor ===
    $collection = new Collection([
        'price' => '99',
        'quantity' => '5',
        'discount' => '10.5',
    ]);
    
    $integers = $collection('*')->int()->getArray();
    
    $results['int'] = [
        'original' => $collection->getArrayCopy(),
        'converted' => $integers,
        'description' => 'int() converts strings to integers',
    ];
    
    // === float() Processor ===
    $collection = new Collection([
        'price' => '99.99',
        'tax' => '7.5',
        'fee' => '2',
    ]);
    
    $floats = $collection('*')->float()->getArray();
    
    $results['float'] = [
        'original' => $collection->getArrayCopy(),
        'converted' => $floats,
        'description' => 'float() converts strings to floats',
    ];
    
    // === Chaining Multiple Processors ===
    $collection = new Collection([
        'input1' => '  42  ',
        'input2' => '  100  ',
    ]);
    
    $processed = $collection('*')
        ->trim()
        ->int()
        ->getArray();
    
    $results['chaining'] = [
        'original' => $collection->getArrayCopy(),
        'processed' => $processed,
        'description' => 'Chain trim() then int()',
    ];
    
    // === Processing Nested Values ===
    $collection = new Collection([
        'users' => [
            ['name' => '  Alice  ', 'score' => '85'],
            ['name' => '  Bob  ', 'score' => '92'],
        ],
    ]);
    
    $names = $collection('users.*.name')->trim()->getArray();
    $scores = $collection('users.*.score')->int()->getArray();
    
    $results['nested'] = [
        'trimmed_names' => $names,
        'int_scores' => $scores,
        'description' => 'Process nested values with wildcard',
    ];
    
    // === Filter + Process Combination ===
    $collection = new Collection([
        'a' => '  text  ',
        'b' => 123,
        'c' => '  more text  ',
        'd' => 456,
    ]);
    
    $stringsOnly = $collection('*:istype(string)')
        ->trim()
        ->getArray();
    
    $results['filter_process'] = [
        'result' => $stringsOnly,
        'description' => 'Filter to strings, then trim',
    ];
    
    // === get() vs getArray() After Processing ===
    $collection = new Collection(['num' => '42']);
    
    $processor = $collection('*')->int();
    
    $asCollection = $processor->get();
    $asArray = $processor->getArray();
    
    $results['output_types'] = [
        'get_type' => get_class($asCollection),
        'getArray_type' => gettype($asArray),
        'values_match' => $asCollection->getArrayCopy() === $asArray,
        'description' => 'get() returns Collection, getArray() returns array',
    ];
    
    // === Real World: Form Input Sanitization ===
    $formData = new Collection([
        'name' => '  John Doe  ',
        'email' => '  john@example.com  ',
        'age' => '30',
        'phone' => '  +1-555-1234  ',
    ]);
    
    // Trim all string inputs
    $sanitized = [];
    $sanitized['text_fields'] = $formData('name, email, phone')
        ->trim()
        ->getArray();
    
    // Convert numeric
    $sanitized['age'] = $formData('age')
        ->int()
        ->getArray();
    
    $results['form_sanitization'] = [
        'original' => $formData->getArrayCopy(),
        'sanitized' => $sanitized,
        'description' => 'Form input sanitization pattern',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
