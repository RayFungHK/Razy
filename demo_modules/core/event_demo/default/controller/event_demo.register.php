<?php
/**
 * Register Handler - Fire user_registered event
 * 
 * @llm Demonstrates firing the user_registered event.
 * Query params: ?name=John&email=john@example.com
 */
return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    
    // Query-string input: no framework query-input API exists; values sanitized at read.
    $name = htmlspecialchars($_GET['name'] ?? 'Guest', ENT_QUOTES, 'UTF-8'); // lint-allow: RZ-003 escaped at read
    $email = filter_var($_GET['email'] ?? 'guest@example.com', FILTER_SANITIZE_EMAIL); // lint-allow: RZ-003 FILTER_SANITIZE at read
    
    $userData = [
        'id'         => uniqid('user_'),
        'name'       => $name,
        'email'      => $email,
        'registered' => date('Y-m-d H:i:s'),
    ];
    
    $result = $this->fireUserRegistered($userData);
    
    echo json_encode([
        'success'   => true,
        'event'     => 'event_demo:user_registered',
        'message'   => "Event fired! User '{$name}' registered.",
        'user'      => $userData,
        'listeners' => count($result['responses']),
        'responses' => $result['responses'],
    ], JSON_PRETTY_PRINT);
};
