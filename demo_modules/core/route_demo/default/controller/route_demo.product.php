<?php
/**
 * Product handler - Captures product name (alphabets only)
 * Pattern: /route_demo/product/(:w)
 * 
 * @llm :w matches alphabets only (a-zA-Z)
 *      Numbers or special chars in name will NOT match
 */
return function (string $name): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'product',
        'pattern' => '/route_demo/product/(:w)',
        'captured' => [
            'name' => $name,
            'length' => strlen($name),
        ],
        'explanation' => ':w matches ONLY alphabets (a-zA-Z). No digits or special chars.',
        'test_urls' => [
            '/route_demo/product/Widget' => 'name=Widget (valid)',
            '/route_demo/product/Item' => 'name=Item (valid)',
            '/route_demo/product/widget123' => 'FAILS - contains digits',
            '/route_demo/product/my-item' => 'FAILS - contains hyphen',
        ],
    ], JSON_PRETTY_PRINT);
};
