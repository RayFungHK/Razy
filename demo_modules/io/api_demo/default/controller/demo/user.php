<?php
/**
 * User API Demo
 * 
 * @llm Demonstrates the user API command with CRUD operations.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('User API', 'User CRUD operations demo');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    
    // List all users
    $list = $this->api('io/api_provider')->user('list');
    
    echo '<div class="card">';
    echo '<h2>List Users</h2>';
    echo '<pre>$result = $this->api(\'io/api_provider\')->user(\'list\');</pre>';
    echo '<h4>Result</h4>';
    echo '<table class="table"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr></thead><tbody>';
    
    foreach ($list['users'] as $user) {
        echo '<tr>';
        echo '<td>' . $user['id'] . '</td>';
        echo '<td>' . htmlspecialchars($user['name']) . '</td>';
        echo '<td>' . htmlspecialchars($user['email']) . '</td>';
        echo '<td><span class="badge">' . $user['role'] . '</span></td>';
        echo '</tr>';
    }
    
    echo '</tbody></table>';
    echo '<p>Total: <strong>' . $list['count'] . '</strong> users</p>';
    echo '</div>';
    
    // Get single user
    $user1 = $this->api('io/api_provider')->user('get', 1);
    $user99 = $this->api('io/api_provider')->user('get', 99);
    
    echo '<div class="card">';
    echo '<h2>Get User by ID</h2>';
    
    echo '<h4>Get User #1 (exists)</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->user(\'get\', 1);</pre>';
    echo '<pre>' . json_encode($user1, JSON_PRETTY_PRINT) . '</pre>';
    
    echo '<h4>Get User #99 (not found)</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->user(\'get\', 99);</pre>';
    echo '<pre>' . json_encode($user99, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Create user (simulated)
    $newUser = $this->api('io/api_provider')->user('create', 0, [
        'name' => 'David',
        'email' => 'david@example.com',
        'role' => 'user',
    ]);
    
    echo '<div class="card">';
    echo '<h2>Create User</h2>';
    echo '<pre>$this->api(\'io/api_provider\')->user(\'create\', 0, [
    \'name\' => \'David\',
    \'email\' => \'david@example.com\',
    \'role\' => \'user\',
]);</pre>';
    echo '<h4>Result</h4>';
    echo '<pre>' . json_encode($newUser, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    // Validate user data
    $validData = $this->api('io/api_provider')->user('validate', 0, [
        'name' => 'Test',
        'email' => 'test@example.com',
    ]);
    $invalidData = $this->api('io/api_provider')->user('validate', 0, [
        'name' => '',
        'email' => '',
    ]);
    
    echo '<div class="card">';
    echo '<h2>Validate User Data</h2>';
    
    echo '<h4>Valid Data</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->user(\'validate\', 0, [\'name\' => \'Test\', \'email\' => \'test@example.com\']);</pre>';
    echo '<pre>' . json_encode($validData, JSON_PRETTY_PRINT) . '</pre>';
    
    echo '<h4>Invalid Data (empty fields)</h4>';
    echo '<pre>$this->api(\'io/api_provider\')->user(\'validate\', 0, [\'name\' => \'\', \'email\' => \'\']);</pre>';
    echo '<pre>' . json_encode($invalidData, JSON_PRETTY_PRINT) . '</pre>';
    echo '</div>';
    
    echo $footer;
};
