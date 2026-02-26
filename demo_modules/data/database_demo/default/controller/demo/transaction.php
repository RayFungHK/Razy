<?php
/**
 * Transaction Demo
 * 
 * @llm Demonstrates database transaction handling.
 * 
 * ## Transaction Methods
 * 
 * - `$db->beginTransaction()` - Start transaction
 * - `$db->commit()` - Commit changes
 * - `$db->rollback()` - Rollback changes
 * - `$db->inTransaction()` - Check if in transaction
 * 
 * ## Usage Pattern
 * 
 * ```php
 * $db->beginTransaction();
 * try {
 *     // Multiple database operations
 *     $db->execute($stmt1);
 *     $db->execute($stmt2);
 *     $db->commit();
 * } catch (Exception $e) {
 *     $db->rollback();
 *     throw $e;
 * }
 * ```
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $db = $this->getDB();
    $results = [];
    
    // === Basic Transaction ===
    $results['basic'] = [
        'code' => <<<'PHP'
$db->beginTransaction();
try {
    // Insert order
    $orderStmt = $db->insert('orders')->assign([
        'user_id' => 1,
        'total' => 150.00,
        'status' => 'pending',
    ]);
    $orderQuery = $db->execute($orderStmt);
    $orderId = $orderQuery->lastID();
    
    // Insert order items
    $itemStmt = $db->insert('order_items')->assign([
        'order_id' => $orderId,
        'product_id' => 123,
        'quantity' => 2,
        'price' => 75.00,
    ]);
    $db->execute($itemStmt);
    
    // Update inventory
    $inventoryStmt = $db->update('products')
        ->set(['stock' => '{{stock - 2}}'])
        ->where('id=123');
    $db->execute($inventoryStmt);
    
    // All successful - commit
    $db->commit();
    return ['success' => true, 'order_id' => $orderId];
    
} catch (\Exception $e) {
    // Something failed - rollback all changes
    $db->rollback();
    return ['error' => $e->getMessage()];
}
PHP,
    ];
    
    // === Transaction with Savepoints ===
    $results['savepoints'] = [
        'code' => <<<'PHP'
$db->beginTransaction();

try {
    // First operation
    $db->execute($stmt1);
    
    // Create savepoint
    $db->exec('SAVEPOINT sp1');
    
    try {
        // Risky operation that might fail
        $db->execute($riskyStmt);
    } catch (\Exception $e) {
        // Rollback only to savepoint, not entire transaction
        $db->exec('ROLLBACK TO SAVEPOINT sp1');
        // Log error but continue
    }
    
    // Continue with other operations
    $db->execute($stmt2);
    
    $db->commit();
} catch (\Exception $e) {
    $db->rollback();
}
PHP,
    ];
    
    // === Check Transaction Status ===
    $results['status_check'] = [
        'code' => <<<'PHP'
if (!$db->inTransaction()) {
    $db->beginTransaction();
}

// ... operations ...

if ($db->inTransaction()) {
    if ($success) {
        $db->commit();
    } else {
        $db->rollback();
    }
}
PHP,
    ];
    
    // === Nested Transaction Pattern ===
    $results['nested_pattern'] = [
        'description' => 'MySQL doesn\'t support true nested transactions. Use savepoints instead.',
        'code' => <<<'PHP'
class TransactionManager {
    private int $level = 0;
    
    public function begin(Database $db): void {
        if ($this->level === 0) {
            $db->beginTransaction();
        } else {
            $db->exec("SAVEPOINT level_{$this->level}");
        }
        $this->level++;
    }
    
    public function commit(Database $db): void {
        $this->level--;
        if ($this->level === 0) {
            $db->commit();
        } else {
            $db->exec("RELEASE SAVEPOINT level_{$this->level}");
        }
    }
    
    public function rollback(Database $db): void {
        $this->level--;
        if ($this->level === 0) {
            $db->rollback();
        } else {
            $db->exec("ROLLBACK TO SAVEPOINT level_{$this->level}");
        }
    }
}
PHP,
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
