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
    // Note: Subqueries in WHERE use the alias() pattern (Statement.php:175).
    // Raw SQL is NOT an escape hatch (RZ-003): prepare('SELECT…') with interpolated
    // SQL is a banned pattern — there is no sanctioned raw-SQL path in modules.
    $subquery = $db->prepare()
        ->select('user_id')
        ->from('premium_members')
        ->where('active=1');
    
    $results['subquery'] = [
        'subquery_sql' => $subquery->getSyntax(),
        'description' => 'Subqueries in WHERE/joins use alias() (never raw string interpolation)',
        'example_code' => <<<'PHP'
// Using alias() for subquery in FROM/join — the sanctioned pattern:
$stmt = $db->prepare()
    ->select('*')
    ->from('u.users');
$alias = $stmt->alias('premium');  // Create subquery alias
$alias->select('user_id')->from('premium_members')->where('active=1');
// Then join: ->from('u.users-premium.premium_members[user_id]')

// Need an IN over an id set? Build it with the builder's where + assign()
// value params, or express the same intent as the join above. Passing a raw
// SQL string to prepare() with interpolated sub-SQL is an RZ-003 violation
// and an injection hazard — modules have no sanctioned raw-SQL path.
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
    
    // === Complex SQL (builder only) ===
    $results['raw_sql'] = [
        'description' => 'JSON functions, IN and aggregates are builder-expressible — modules have no raw-SQL path (RZ-003)',
        'code' => <<<'PHP'
// JSON_CONTAINS via the '~=' where-operator (WhereSyntax.php:425, 657):
$stmt = $db->prepare()
    ->select('*')
    ->from('users')
    ->where('roles~=:role')
    ->assign(['role' => 'admin']);   // value JSON-cast safely (WhereSyntax.php:768-770)
$query = $stmt->query();

// IN via the '|=' where-operator (WhereSyntax.php:424):
$stmt = $db->prepare()
    ->select('*')
    ->from('users')
    ->where('id|=:ids')
    ->assign(['ids' => [1, 2, 3]]);

// GROUP_CONCAT + join + group — all builder (group() at Statement.php:531):
$stmt = $db->prepare()
    ->select('u.*, GROUP_CONCAT(ur.role_id) AS roles')
    ->from('u.users-ur.user_roles[user_id]')
    ->where('u.active=1')
    ->group('u.id');

// Why no raw-SQL path exists: string-built SQL is where injection enters
// (RZ-003). If the builder truly cannot express something, extend it via a
// Statement plugin ('plugins' section below) — never bypass it.
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
