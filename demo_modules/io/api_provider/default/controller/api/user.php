<?php
/**
 * User API Command
 * 
 * @llm Simulates user data operations (get, list, create).
 */

return function (string $action, int $id = 0, array $data = []): array {
    // Simulated user database
    $users = [
        1 => ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin'],
        2 => ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user'],
        3 => ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'role' => 'user'],
    ];
    
    return match ($action) {
        'get' => isset($users[$id]) 
            ? ['user' => $users[$id], 'found' => true]
            : ['user' => null, 'found' => false, 'error' => "User {$id} not found"],
            
        'list' => ['users' => array_values($users), 'count' => count($users)],
        
        'create' => [
            'user' => array_merge(['id' => count($users) + 1], $data),
            'created' => true,
            'message' => 'User created (simulated)',
        ],
        
        'validate' => [
            'valid' => !empty($data['name']) && !empty($data['email']),
            'errors' => array_filter([
                empty($data['name']) ? 'Name is required' : null,
                empty($data['email']) ? 'Email is required' : null,
            ]),
        ],
        
        default => ['error' => "Unknown action: {$action}", 'supported' => ['get', 'list', 'create', 'validate']],
    };
};
