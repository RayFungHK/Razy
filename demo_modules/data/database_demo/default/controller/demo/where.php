<?php
/**
 * WhereSyntax Demo
 * 
 * @llm Demonstrates Razy's where clause syntax.
 * 
 * ## Basic Operators
 * 
 * | Syntax | SQL | Description |
 * |--------|-----|-------------|
 * | `col=val` | `col = val` | Equals |
 * | `col!=val` | `col != val` | Not equals |
 * | `col>val` | `col > val` | Greater than |
 * | `col<val` | `col < val` | Less than |
 * | `col>=val` | `col >= val` | Greater or equal |
 * | `col<=val` | `col <= val` | Less or equal |
 * 
 * ## String Matching
 * 
 * | Syntax | SQL | Description |
 * |--------|-----|-------------|
 * | `col%=val` | `col LIKE 'val%'` | Starts with |
 * | `col=%val` | `col LIKE '%val'` | Ends with |
 * | `col%=%val` | `col LIKE '%val%'` | Contains |
 * 
 * ## Logical Operators
 * 
 * - `,` = AND (comma separates AND conditions)
 * - `|` = OR (pipe for OR conditions)
 * - `()` = Grouping
 * 
 * ## Negation
 * 
 * - `!` prefix negates: `!disabled` ??`disabled = 0` or `NOT disabled`
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic Comparisons ===
    $stmt = $db->prepare()->from('users')
        ->where('id=1');  // Equals
    $results['equals'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('users')
        ->where('age>18');  // Greater than
    $results['greater'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('products')
        ->where('price<=100.00');  // Less or equal
    $results['less_equal'] = $stmt->getSyntax();
    
    // === String Matching (LIKE) ===
    $stmt = $db->prepare()->from('users')
        ->where('email%=@gmail.com');  // LIKE '%@gmail.com'
    $results['ends_with'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('users')
        ->where('username=%=john');  // LIKE 'john%'
    $results['starts_with'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('posts')
        ->where('title%=%php%');  // LIKE '%php%'
    $results['contains'] = $stmt->getSyntax();
    
    // === NULL Checks ===
    $stmt = $db->prepare()->from('users')
        ->where('deleted_at=NULL');  // IS NULL
    $results['is_null'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('users')
        ->where('deleted_at!=NULL');  // IS NOT NULL
    $results['not_null'] = $stmt->getSyntax();
    
    // === IN Operator ===
    $stmt = $db->prepare()->from('users')
        ->where('status=["active","pending","review"]');  // IN (...)
    $results['in'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('users')
        ->where('id=[1,2,3,4,5]');  // IN (1,2,3,4,5)
    $results['in_numbers'] = $stmt->getSyntax();
    
    // === BETWEEN ===
    $stmt = $db->prepare()->from('products')
        ->where('price><[10,100]');  // BETWEEN 10 AND 100
    $results['between'] = $stmt->getSyntax();
    
    // === NOT IN ===
    $stmt = $db->prepare()->from('users')
        ->where('status!=["banned","deleted"]');  // NOT IN (...)
    $results['not_in'] = $stmt->getSyntax();
    
    // === AND Conditions (comma) ===
    $stmt = $db->prepare()->from('users')
        ->where('active=1,role="admin",verified=1');
    $results['and'] = $stmt->getSyntax();
    
    // === OR Conditions (pipe) ===
    $stmt = $db->prepare()->from('users')
        ->where('role="admin"|role="moderator"');
    $results['or'] = $stmt->getSyntax();
    
    // === Grouped Conditions ===
    $stmt = $db->prepare()->from('posts')
        ->where('status="published",(category="tech"|category="news")');
    $results['grouped'] = $stmt->getSyntax();
    
    // === Negation (!) ===
    $stmt = $db->prepare()->from('users')
        ->where('!disabled');  // disabled = 0 or NOT disabled
    $results['negation'] = $stmt->getSyntax();
    
    $stmt = $db->prepare()->from('users')
        ->where('!deleted,!banned');  // Multiple negations
    $results['multi_negation'] = $stmt->getSyntax();
    
    // === Parameter Binding ===
    $stmt = $db->prepare()->from('users')
        ->where('id=?,status=?');
    // Execute: $stmt->lazy(['id' => 1, 'status' => 'active']);
    $results['parameters'] = $stmt->getSyntax();
    
    // === Complex Example ===
    $stmt = $db->prepare()
        ->select('*')
        ->from('products')
        ->where('active=1,price><[10,1000],(category="electronics"|category="computers"),!discontinued')
        ->order('<price')
        ->limit(20);
    
    $results['complex'] = [
        'sql' => $stmt->getSyntax(),
        'description' => 'Active products, price 10-1000, electronics or computers, not discontinued',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
