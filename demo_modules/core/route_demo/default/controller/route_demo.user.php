<?php
/**
 * User handler - Captures numeric user ID
 * Pattern: /route_demo/user/(:d)
 * 
 * @llm :d matches digits only (0-9)
 *      () captures the value and passes it to this function
 */
return function (string $id): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'user',
        'pattern' => '/route_demo/user/(:d)',
        'captured' => [
            'id' => $id,
            'type' => gettype($id),
            'parsed_int' => (int) $id,
        ],
        'explanation' => ':d matches only digits (0-9). The captured value is passed as first parameter.',
    ], JSON_PRETTY_PRINT);
};
