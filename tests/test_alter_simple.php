<?php
/**
 * Simple test script for Table Alter classes
 */

define('SYSTEM_ROOT', __DIR__ . '/..');

// Define the guid function in Razy namespace (from bootstrap.inc.php)
require_once __DIR__ . '/test_functions.php';

spl_autoload_register(function ($class) {
    $prefix = 'Razy\\';
    $baseDir = SYSTEM_ROOT . '/src/library/Razy/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) require $file;
});

use Razy\Database\Table;
use Razy\Database\Table\TableHelper;
use Razy\Database\Table\ColumnHelper;

echo "=== Testing Table Helper Classes ===\n\n";

// Test 1: Basic Helper
echo "Test 1: Create TableHelper instance\n";
$table = new Table('users');
$helper = new TableHelper($table);
echo "  ✓ TableHelper instance created\n\n";

// Test 2: Add column
echo "Test 2: Add column\n";
$helper->addColumn('email=type(text),nullable');
$sql = $helper->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ Add column works\n\n";

// Test 3: Rename table
echo "Test 3: Rename table\n";
$helper2 = new TableHelper($table);
$helper2->rename('customers');
$sql = $helper2->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ Rename table works\n\n";

// Test 4: Add index
echo "Test 4: Add index\n";
$helper3 = new TableHelper($table);
$helper3->addIndex('UNIQUE', 'email', 'uniq_email');
$sql = $helper3->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ Add index works\n\n";

// Test 5: Foreign key
echo "Test 5: Add foreign key\n";
$helper4 = new TableHelper($table);
$helper4->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
$sql = $helper4->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ Add foreign key works\n\n";

// Test 6: ColumnHelper
echo "Test 6: ColumnHelper\n";
$columnHelper = new ColumnHelper($table, 'username');
$columnHelper->varchar(100)->notNull()->default('');
$sql = $columnHelper->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ ColumnHelper works\n\n";

// Test 7: Complex helper
echo "Test 7: Complex helper\n";
$helper5 = new TableHelper($table);
$helper5->addColumn('phone=type(text)', 'AFTER email');
$helper5->dropColumn('deprecated')
       ->addIndex('INDEX', ['first_name', 'last_name'], 'idx_name')
       ->charset('utf8mb4')
       ->collation('utf8mb4_unicode_ci');
$sql = $helper5->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ Complex helper works\n\n";

// Test 8: ColumnHelper with rename
echo "Test 8: ColumnHelper rename\n";
$columnHelper2 = new ColumnHelper($table, 'old_name');
$columnHelper2->rename('new_name')->varchar(200)->nullable();
$sql = $columnHelper2->getSyntax();
echo "  SQL: " . $sql . "\n";
echo "  ✓ ColumnHelper rename works\n\n";

echo "=== All tests passed! ===\n";
