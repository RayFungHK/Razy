<?php
/**
 * Advanced Database Features Demo
 * 
 * @llm Demonstrates advanced database features:
 * - Complex query patterns
 * - Query result handling
 * - Statement plugins
 * - Query debugging
 * - Pagination
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Complex Multi-Join Query ===
    $stmt = $db->prepare()
        ->select('u.id, u.username, p.title, c.content AS comment')
        ->from('u.users<p.posts[user_id]<c.comments[post_id]')
        ->where('u.active=1,p.status="published"')
        ->order('>p.created_at')
        ->limit(50);
    
    $results['complex_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Multi-join: Users → Posts → Comments with filtering',
    ];
    
    // === Correlated Subquery in SELECT ===
    $stmt = $db->prepare()
        ->select('u.*, (SELECT COUNT(*) FROM posts WHERE user_id = u.id) AS post_count')
        ->from('u.users')
        ->where('u.active=1');
    
    $results['correlated'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Users with their post count (correlated subquery in SELECT)',
    ];
    
    // === Subquery Approach (documentation) ===
    // Note: Subqueries in WHERE clause require raw SQL or alias() pattern
    $subquery = $db->prepare()
        ->select('user_id')
        ->from('premium_members')
        ->where('active=1');
    
    $results['subquery'] = [
        'subquery_sql' => $subquery->getSyntax(),
        'description' => 'Subqueries in WHERE use alias() or raw SQL',
        'example_code' => <<<'PHP'
// Using alias() for subquery in FROM:
$stmt = $db->prepare()
    ->select('*')
    ->from('u.users');
$alias = $stmt->alias('premium');  // Create subquery alias
$alias->select('user_id')->from('premium_members')->where('active=1');
// Then join: ->from('u.users-premium.premium_members[user_id]')

// Or use raw SQL for complex subqueries:
$subSQL = $subquery->getSyntax();
$stmt = $db->prepare("SELECT * FROM users WHERE id IN ($subSQL)");
PHP,
    ];
    
    // === Query Result Handling ===
    $results['query_handling'] = [
        'methods' => [
            'all()' => 'Get all rows as array',
            'first()' => 'Get first row',
            'last()' => 'Get last row',
            'column($name)' => 'Get all values of a column',
            'count()' => 'Number of rows returned',
            'affected()' => 'Number of rows affected (INSERT/UPDATE/DELETE)',
            'lastID()' => 'Last inserted ID',
            'hasRecord()' => 'Check if any rows returned',
        ],
        'code' => <<<'PHP'
$query = $stmt->query();

// Get results
$allRows = $query->all();           // Array of all rows
$firstRow = $query->first();        // First row only
$emails = $query->column('email');  // All email values

// Metadata
$count = $query->count();           // Number of rows
$hasData = $query->hasRecord();     // Boolean

// For INSERT
$newId = $query->lastID();

// For UPDATE/DELETE
$affected = $query->affected();

// Iterate results
foreach ($query as $row) {
    echo $row['username'];
}
PHP,
    ];
    
    // === Pagination Helper ===
    $results['pagination'] = [
        'code' => <<<'PHP'
function paginate(Database $db, int $page, int $perPage = 20): array {
    $offset = ($page - 1) * $perPage;
    
    // Get total count
    $countStmt = $db->prepare()
        ->select('COUNT(*) AS total')
        ->from('posts')
        ->where('published=1');
    $total = $countStmt->lazy()['total'];
    
    // Get page data
    $dataStmt = $db->prepare()
        ->select('*')
        ->from('posts')
        ->where('published=1')
        ->order('>created_at')
        ->limit($perPage, $offset);
    
    $query = $dataStmt->query();
    
    return [
        'data' => $query->all(),
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => (int) $total,
            'total_pages' => ceil($total / $perPage),
        ],
    ];
}
PHP,
    ];
    
    // === Query Debugging ===
    $results['debugging'] = [
        'code' => <<<'PHP'
// Get last SQL syntax (before execution)
$sql = $stmt->getSyntax();

// Get all executed queries
$history = $db->getQueried();
foreach ($history as $query) {
    echo "SQL: " . $query['sql'] . "\n";
    echo "Time: " . $query['time'] . "ms\n";
}

// Enable query logging
// All queries are automatically logged in $db->getQueried()
PHP,
    ];
    
    // === Raw SQL Execution ===
    $results['raw_sql'] = [
        'code' => <<<'PHP'
// Prepared statement with raw SQL
$stmt = $db->prepare("SELECT * FROM users WHERE JSON_CONTAINS(roles, ?)");
$query = $stmt->query(['admin']);

// For complex queries not supported by builder
$stmt = $db->prepare("
    SELECT u.*, GROUP_CONCAT(r.name) AS roles
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.active = 1
    GROUP BY u.id
");
PHP,
    ];
    
    // === Statement Plugins ===
    $results['plugins'] = [
        'description' => 'Extend Statement with custom plugins',
        'code' => <<<'PHP'
// Register plugin folder
Statement::AddPluginFolder('/path/to/plugins');

// Use plugin in statement
$stmt = $db->prepare()
    ->select('*')
    ->from('users')
    ->next('MyPlugin', $arg1, $arg2);  // Call plugin

// Plugin file: MyPlugin.php
return new class extends \Razy\Database\Statement\Plugin {
    protected function onProcess(Statement $statement): void {
        // Modify statement
        $statement->where($this->args[0]);
    }
};
PHP,
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
