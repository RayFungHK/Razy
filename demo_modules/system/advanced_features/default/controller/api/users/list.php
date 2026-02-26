<?php
/**
 * API Users List Closure
 * 
 * @llm Route: /api/users (via @self)
 * Mapped via addLazyRoute([ 'api' => [ 'users' => [ '@self' => 'api/users/list' ] ] ])
 * 
 * Demonstrates @self in nested structure
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    // Using internal binding for validation/formatting
    $users = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];
    
    // Use internal bindings via # prefix
    $this->logAction('users_listed', ['count' => count($users)]);
    
    return $this->formatOutput([
        'success' => true,
        'data' => $users,
    ]);
};
