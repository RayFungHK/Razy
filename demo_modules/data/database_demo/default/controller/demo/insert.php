<?php
/**
 * INSERT Query Demo
 * 
 * @llm Demonstrates INSERT statement building with getSyntax() for SQL generation.
 * 
 * ## Statement::insert($tableName, $columns, $duplicateKeys)
 * 
 * - `$tableName` (string) - Table name
 * - `$columns` (array) - Column name strings: `['username', 'email', 'active']`
 * - `$duplicateKeys` (array) - Optional columns for ON DUPLICATE KEY UPDATE
 * 
 * ## Statement::assign($parameters)
 * 
 * Binds values to columns:
 * ```php
 * $stmt->assign(['username' => 'john', 'email' => 'j@example.com', 'active' => 1]);
 * ```
 * 
 * ## Execution Methods (require DB connection)
 * 
 * - `$stmt->query($params)` - Execute and get Query result
 * - `$stmt->lazy($params)` - Execute and fetch first row
 * - `$query->lastID()` - Get last insert ID after execution
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic INSERT ===
    $stmt = $db->prepare()
        ->insert('users', ['username', 'email', 'active', 'created_at'])
        ->assign([
            'username' => 'john_doe',
            'email' => 'john@example.com',
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    
    $results['basic'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'INSERT with assigned values',
    ];
    
    // === INSERT with Parameter Placeholders ===
    $stmt = $db->prepare()
        ->insert('users', ['username', 'email', 'active']);
    // Without assign(), values default to :column_name placeholders
    
    $results['placeholders'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'INSERT with named parameter placeholders (no assign)',
    ];
    
    // === INSERT with Partial Assignment ===
    $stmt = $db->prepare()
        ->insert('users', ['username', 'email', 'active', 'role'])
        ->assign([
            'username' => 'jane_doe',
            'email' => 'jane@example.com',
        ]);
    
    $results['partial'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'INSERT with mixed assigned and placeholder values',
    ];
    
    // === INSERT with SQL Expression ===
    $stmt = $db->prepare()
        ->insert('activity_log', ['user_id', 'action', 'created_at'])
        ->assign([
            'user_id' => 1,
            'action' => 'login',
            'created_at' => '{{NOW()}}',
        ]);
    
    $results['expression'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'INSERT with SQL expression using {{NOW()}}',
    ];
    
    // === INSERT with ON DUPLICATE KEY UPDATE ===
    $stmt = $db->prepare()
        ->insert('user_stats', ['user_id', 'login_count', 'last_login'], ['login_count', 'last_login'])
        ->assign([
            'user_id' => 1,
            'login_count' => 1,
            'last_login' => date('Y-m-d H:i:s'),
        ]);
    
    $results['on_duplicate'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'INSERT with ON DUPLICATE KEY UPDATE for login_count and last_login',
    ];
    
    // === Database Shortcut ===
    $results['shortcut_info'] = [
        'description' => 'Database::insert($table, $columns) is shortcut for prepare()->insert()',
        'example' => '$db->insert("users", ["username", "email"])->assign([...])',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
