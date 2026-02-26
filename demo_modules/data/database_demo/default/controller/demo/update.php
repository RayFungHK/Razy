<?php
/**
 * UPDATE Query Demo
 * 
 * @llm Demonstrates UPDATE statement building with getSyntax() for SQL generation.
 * 
 * ## Statement::update($tableName, $updateSyntax)
 * 
 * - `$tableName` (string) - Table name
 * - `$updateSyntax` (array) - Update Simple Syntax strings:
 *   - `'column=value'` — Set column to literal value
 *   - `'column=?'` or `'column'` — Named parameter placeholder (:column)
 *   - `'column++'` — Increment by 1
 *   - `'column--'` — Decrement by 1
 *   - `'column+=5'` — Add to current value
 *   - `'column={{NOW()}}'` — SQL expression
 * 
 * ## Execution Methods (require DB connection)
 * 
 * - `$stmt->query($params)` - Execute and get Query result
 * - `$stmt->lazy($params)` - Execute and fetch first row
 * - `$query->affected()` - Get affected row count
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic UPDATE ===
    $stmt = $db->prepare()
        ->update('users', [
            'username=updated_user',
            'email=updated@example.com',
        ])
        ->where('id=1');
    
    $results['basic'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with literal values and WHERE',
    ];
    
    // === UPDATE with Parameter Placeholders ===
    $stmt = $db->prepare()
        ->update('users', [
            'username',    // becomes SET username = :username
            'email',       // becomes SET email = :email
        ])
        ->where('id=?');
    
    $results['parameters'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with named parameter placeholders',
    ];
    
    // === UPDATE with Increment/Decrement ===
    $stmt = $db->prepare()
        ->update('user_stats', [
            'login_count++',          // Increment by 1
            'failed_attempts--',      // Decrement by 1
        ])
        ->where('user_id=1');
    
    $results['increment'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with ++ and -- operators',
    ];
    
    // === UPDATE with Arithmetic ===
    $stmt = $db->prepare()
        ->update('products', [
            'price*=0.9',             // Multiply (10% discount)
            'stock-=1',               // Subtract
        ])
        ->where('category=electronics');
    
    $results['arithmetic'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with arithmetic operators (*=, -=)',
    ];
    
    // === UPDATE with WHERE and ORDER/LIMIT ===
    $stmt = $db->prepare()
        ->update('tasks', [
            'status=processing',
        ])
        ->where('status=pending')
        ->order('>priority')   // > for DESC
        ->limit(10);
    
    $results['limit'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with WHERE, ORDER BY DESC, and LIMIT',
    ];
    
    // === UPDATE Multiple Conditions ===
    $stmt = $db->prepare()
        ->update('posts', [
            'status=published',
        ])
        ->where('status=draft,created_at<"2024-01-01"');
    
    $results['multi_where'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'UPDATE with multiple WHERE conditions (AND)',
    ];
    
    // === Database Shortcut ===
    $results['shortcut_info'] = [
        'description' => 'Database::update($table, $syntax) is shortcut for prepare()->update()',
        'example' => '$db->update("users", ["username=new_name"])->where("id=1")',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
