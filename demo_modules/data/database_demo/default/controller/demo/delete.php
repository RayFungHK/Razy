<?php
/**
 * DELETE Query Demo
 * 
 * @llm Demonstrates DELETE statement building with getSyntax() for SQL generation.
 * 
 * ## Statement::delete($tableName, $parameters, $whereSyntax)
 * 
 * - `$tableName` (string) - Table name
 * - `$parameters` (array) - Key-value pairs for WHERE conditions
 * - `$whereSyntax` (string) - Optional custom WHERE syntax (auto-generated if empty)
 * 
 * When `$parameters` is provided without `$whereSyntax`:
 * - Scalar values generate `column=?`
 * - Array values generate `column|=?` (IN clause)
 * 
 * ## Execution Methods (require DB connection)
 * 
 * - `$stmt->query($params)` - Execute and get Query result
 * - `$query->affected()` - Get affected row count
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic DELETE with Parameters ===
    $stmt = $db->prepare()
        ->delete('sessions', [
            'expired_at' => '2024-01-01',
        ], 'expired_at<?');
    
    $results['basic'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'DELETE expired sessions with parameter binding',
    ];
    
    // === DELETE with Auto-generated WHERE ===
    $stmt = $db->prepare()
        ->delete('users', [
            'id' => 123,
        ]);
    // Auto-generates: WHERE id = ?
    
    $results['auto_where'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'DELETE with auto-generated WHERE from parameters',
    ];
    
    // === DELETE with Multiple Conditions ===
    $stmt = $db->prepare()
        ->delete('posts', [
            'status' => 'draft',
            'author_id' => 5,
        ]);
    // Auto-generates: WHERE status = ? AND author_id = ?
    
    $results['multi_condition'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'DELETE with multiple conditions (auto-generated AND)',
    ];
    
    // === DELETE with Custom WHERE Syntax ===
    $stmt = $db->prepare()
        ->delete('logs', [
            'created_at' => '2024-01-01',
            'level' => 'debug',
        ], 'created_at<?,level=?');
    
    $results['custom_where'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'DELETE with custom WHERE syntax (less-than comparison)',
    ];
    
    // === DELETE with IN Clause ===
    $stmt = $db->prepare()
        ->delete('tags', [
            'id' => [1, 2, 3, 4, 5],
        ]);
    // Array values auto-generate IN clause: WHERE id IN (?)
    
    $results['in_clause'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'DELETE with IN clause (array parameter)',
    ];
    
    // === Soft Delete Pattern (Recommended) ===
    $stmt = $db->prepare()
        ->update('users', [
            'deleted=1',
            'deleted_at={{NOW()}}',
        ])
        ->where('id=?');
    
    $results['soft_delete'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Soft delete pattern: UPDATE deleted flag instead of DELETE',
    ];
    
    // === Database Shortcut ===
    $results['shortcut_info'] = [
        'description' => 'Database::delete($table, $params, $where) is shortcut for prepare()->delete()',
        'example' => '$db->delete("users", ["id" => 123])',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
