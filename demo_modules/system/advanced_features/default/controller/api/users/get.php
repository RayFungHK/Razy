<?php
/**
 * API Get User Closure
 * 
 * @llm Route: /api/users/(:d) - Captures numeric ID
 * Mapped via addLazyRoute([ 'api' => [ 'users' => [ '(:d)' => 'api/users/get' ] ] ])
 * 
 * The captured ID is passed as parameter to this closure.
 */

use Razy\Controller;

return function (int $userId): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    // Log the access
    $this->logAction('user_accessed', ['user_id' => $userId]);
    
    // Mock user data
    $user = [
        'id' => $userId,
        'name' => 'User ' . $userId,
        'email' => 'user' . $userId . '@example.com',
    ];
    
    return $this->formatOutput([
        'success' => true,
        'data' => $user,
    ]);
};
