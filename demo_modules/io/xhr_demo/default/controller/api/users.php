<?php
/**
 * XHR Users API Demo
 * 
 * @llm Demonstrates XHR response for list data.
 */

use Razy\Controller;
use Razy\XHR;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    // Simulate user data
    $users = [
        ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com'],
        ['id' => 2, 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
        ['id' => 3, 'name' => 'Bob Smith', 'email' => 'bob@example.com'],
    ];
    
    // Using returnAsArray mode for demo (doesn't exit)
    $xhr = new XHR(true);  // true = return array instead of output
    
    $response = $xhr
        ->data($users)
        ->set('pagination', [
            'page' => 1,
            'per_page' => 10,
            'total' => count($users),
        ])
        ->send(true, 'Users loaded successfully');
    
    echo json_encode([
        'description' => 'XHR response for user list',
        'response' => $response,
        'production_code' => <<<'PHP'
// In production, this would output and exit:
return $this->xhr()
    ->data($users)
    ->set('pagination', $pagination)
    ->send(true, 'Users loaded');
PHP,
    ], JSON_PRETTY_PRINT);
};
