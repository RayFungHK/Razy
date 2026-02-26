<?php
/**
 * SELECT Query Demo
 * 
 * @llm Demonstrates SELECT statement building with getSyntax() for SQL generation.
 * 
 * ## Basic SELECT
 * 
 * ```php
 * $stmt = $db->prepare()
 *     ->select('col1, col2')  // Columns (comma-separated string)
 *     ->from('table_name')    // Table
 *     ->where('condition')    // Where clause
 *     ->order('>column')      // > for DESC, < for ASC
 *     ->limit(10, 0);         // Limit, offset
 * ```
 * 
 * ## Column Selection
 * 
 * - `select('*')` - All columns
 * - `select('col1, col2')` - Multiple columns (comma-separated)
 * - `select('t.col AS alias')` - Column alias
 * - `select('COUNT(*) AS total')` - Aggregates
 * 
 * ## Execution Methods (require DB connection)
 * 
 * - `->query($params)` - Execute and get Query result
 * - `->lazy($params)` - Execute and fetch first row
 * - `->lazyKeyValuePair($key, $value)` - Get as key=>value array
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic SELECT ===
    $stmt = $db->prepare()
        ->select('*')
        ->from('users')
        ->limit(10);
    
    $results['basic'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'SELECT all columns from users, limit 10',
    ];
    
    // === SELECT with Specific Columns ===
    $stmt = $db->prepare()
        ->select('id, username, email, created_at')
        ->from('users')
        ->where('active=1')
        ->order('>created_at')  // > for DESC
        ->limit(5);
    
    $results['columns'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Active users sorted by newest, limit 5',
    ];
    
    // === SELECT with Alias ===
    $stmt = $db->prepare()
        ->select('u.id, u.username AS name, u.email')
        ->from('u.users')
        ->where('u.active=1');
    
    $results['alias'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Table alias: users AS u',
    ];
    
    // === SELECT with Aggregates ===
    $stmt = $db->prepare()
        ->select('COUNT(*) AS total, MAX(created_at) AS latest')
        ->from('users');
    
    $results['aggregate'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Aggregate functions: COUNT, MAX',
    ];
    
    // === SELECT with GROUP BY ===
    $stmt = $db->prepare()
        ->select('status, COUNT(*) AS count')
        ->from('users')
        ->group('status');
    
    $results['groupby'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Group by status with count',
    ];
    
    // === SELECT with ORDER BY ===
    $stmt = $db->prepare()
        ->select('id, username, created_at')
        ->from('users')
        ->where('active=1')
        ->order('>created_at,<username');  // DESC created_at, ASC username
    
    $results['orderby'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Multiple ORDER BY: created_at DESC, username ASC',
    ];
    
    // === SELECT with WHERE + JOIN ===
    $stmt = $db->prepare()
        ->select('u.id, u.username, p.title AS post_title')
        ->from('u.users<p.posts[user_id]')
        ->where('u.active=1,p.status="published"')
        ->order('>p.created_at')
        ->limit(20);
    
    $results['join_select'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'SELECT with LEFT JOIN, WHERE, ORDER BY, LIMIT',
    ];
    
    // === Execution Methods (require real DB connection) ===
    $results['execution_methods'] = [
        'description' => 'Methods for executing queries (require database connection)',
        'methods' => [
            'query($params)' => 'Execute and return Query object: $stmt->query()',
            'lazy($params)' => 'Execute and fetch first row: $stmt->lazy()',
            'lazyKeyValuePair($key, $val)' => 'Get key=>value pairs: $stmt->lazyKeyValuePair("id", "name")',
        ],
        'example' => '$query = $stmt->query(); $rows = $query->all(); $first = $query->first();',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
