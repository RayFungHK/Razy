<?php
/**
 * TableJoinSyntax Demo
 * 
 * @llm Demonstrates Razy's powerful join syntax.
 * 
 * ## Join Symbols
 * 
 * | Symbol | Join Type | Description |
 * |--------|-----------|-------------|
 * | `<`    | LEFT JOIN | Keep all left rows |
 * | `<<`   | LEFT OUTER JOIN | Exclude matched |
 * | `>`    | RIGHT JOIN | Keep all right rows |
 * | `>>`   | RIGHT OUTER JOIN | Exclude matched |
 * | `-`    | INNER JOIN | Only matched rows |
 * | `*`    | CROSS JOIN | Cartesian product |
 * 
 * ## Join Condition Syntax
 * 
 * - `table1<table2[column]` - Join on column
 * - `table1<table2[:column]` - USING(column)
 * - `table1<table2[?t1.col=t2.col]` - Custom ON condition
 * 
 * ## Table Aliases
 * 
 * `a.users<b.posts[user_id]` ??`users AS a LEFT JOIN posts AS b`
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === LEFT JOIN ===
    // Get all users with their posts (users without posts included)
    $stmt = $db->prepare()
        ->select('u.*', 'p.title AS post_title')
        ->from('u.users<p.posts[user_id]');
    
    $results['left_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'All users, with posts if any',
    ];
    
    // === INNER JOIN ===
    // Get only users who have posts
    $stmt = $db->prepare()
        ->select('u.*', 'p.title')
        ->from('u.users-p.posts[user_id]');
    
    $results['inner_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Only users with posts',
    ];
    
    // === RIGHT JOIN ===
    // Get all posts with their authors
    $stmt = $db->prepare()
        ->select('u.username', 'p.*')
        ->from('u.users>p.posts[user_id]');
    
    $results['right_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'All posts, with author if exists',
    ];
    
    // === CROSS JOIN ===
    // Cartesian product of tables
    $stmt = $db->prepare()
        ->select('*')
        ->from('sizes*colors');
    
    $results['cross_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Every combination of sizes and colors',
    ];
    
    // === Multiple Joins ===
    // Chain multiple joins
    $stmt = $db->prepare()
        ->select('u.username', 'p.title', 'c.content AS comment')
        ->from('u.users<p.posts[user_id]<c.comments[post_id]');
    
    $results['multi_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Users ??Posts ??Comments',
    ];
    
    // === USING Clause ===
    // When column names are identical in both tables
    $stmt = $db->prepare()
        ->select('*')
        ->from('orders<order_items[:order_id]');
    
    $results['using'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'JOIN USING (order_id)',
    ];
    
    // === Custom ON Condition ===
    // Complex join condition with ?
    $stmt = $db->prepare()
        ->select('p.*', 'h.effective_time')
        ->from('p.products<h.price_history[?p.id=h.product_id AND h.is_active=1]');
    
    $results['custom_on'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Custom ON with multiple conditions',
    ];
    
    // === Self Join ===
    // Join table to itself
    $stmt = $db->prepare()
        ->select('e.name AS employee', 'm.name AS manager')
        ->from('e.employees<m.employees[?e.manager_id=m.id]');
    
    $results['self_join'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Employee with their manager',
    ];
    
    // === Complex Production Example ===
    // From production-sample: membership_share_transfer
    $stmt = $db->prepare()
        ->select('lh.*', 'l.share_code', 'b.minter_code', 'c.chinese_name', 'c.company_code')
        ->from('lh.membership_share_history<l.membership_share[share_id]<b.minter_license[minter_id]<c.company[company_id]');
    
    $results['production'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Real production query with 4-table join',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
