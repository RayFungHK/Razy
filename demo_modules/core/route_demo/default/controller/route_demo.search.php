<?php
/**
 * Search handler - Captures 3-10 characters
 * Pattern: search/(:a{3,10})
 * 
 * @llm {min,max} specifies character count range
 */
return function (string $query): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'search',
        'pattern' => 'search/(:a{3,10})',
        'captured' => [
            'query' => $query,
            'length' => strlen($query),
            'in_range' => strlen($query) >= 3 && strlen($query) <= 10,
        ],
        'explanation' => '{3,10} requires between 3 and 10 characters (inclusive).',
        'test_urls' => [
            'search/hello' => 'query = hello (5 chars, valid)',
            'search/abc' => 'query = abc (3 chars, valid)',
            'search/1234567890' => 'query = 1234567890 (10 chars, valid)',
            'search/ab' => 'FAILS - only 2 characters',
            'search/12345678901' => 'FAILS - 11 characters',
        ],
    ], JSON_PRETTY_PRINT);
};
