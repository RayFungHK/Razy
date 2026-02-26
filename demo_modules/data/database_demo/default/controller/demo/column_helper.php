<?php
/**
 * ColumnHelper Demo - Column modification operations
 * 
 * @llm Demonstrates ColumnHelper class for generating column-specific ALTER statements.
 * 
 * ## Features Demonstrated
 * - Modify column type (varchar, int, bigint, decimal, text, etc.)
 * - Rename column
 * - Set nullable/not null
 * - Set default values
 * - Set charset and collation
 * - Add comments
 * - Control position (FIRST/AFTER)
 */

use Razy\Database\Table;
use Razy\Database\Table\ColumnHelper;

return function () {
    header('Content-Type: application/json; charset=UTF-8');
    
    $results = [];
    
    // Create a table instance
    $table = new Table('users');
    
    // Demo 1: Modify column type
    $column = new ColumnHelper($table, 'username');
    $column->varchar(100)->notNull();
    $results['varchar'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Modify column to VARCHAR(100) NOT NULL',
        'code' => '$column = new ColumnHelper($table, \'username\');' . "\n" . '$column->varchar(100)->notNull();',
    ];
    
    // Demo 2: Rename column
    $column = new ColumnHelper($table, 'old_name');
    $column->rename('new_name')->varchar(255);
    $results['rename'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Rename column and set type',
        'code' => '$column = new ColumnHelper($table, \'old_name\');' . "\n" . '$column->rename(\'new_name\')->varchar(255);',
    ];
    
    // Demo 3: Integer types
    $column = new ColumnHelper($table, 'count');
    $column->int(11)->notNull()->default('0');
    $results['int'] = [
        'sql' => $column->getSyntax(),
        'description' => 'INT(11) NOT NULL DEFAULT 0',
        'code' => '$column = new ColumnHelper($table, \'count\');' . "\n" . '$column->int(11)->notNull()->default(\'0\');',
    ];
    
    $column = new ColumnHelper($table, 'big_id');
    $column->bigint()->notNull();
    $results['bigint'] = [
        'sql' => $column->getSyntax(),
        'description' => 'BIGINT NOT NULL',
        'code' => '$column = new ColumnHelper($table, \'big_id\');' . "\n" . '$column->bigint()->notNull();',
    ];
    
    $column = new ColumnHelper($table, 'flag');
    $column->tinyint(1)->notNull()->default('0');
    $results['tinyint'] = [
        'sql' => $column->getSyntax(),
        'description' => 'TINYINT(1) for boolean flags',
        'code' => '$column = new ColumnHelper($table, \'flag\');' . "\n" . '$column->tinyint(1)->notNull()->default(\'0\');',
    ];
    
    // Demo 4: Decimal for money
    $column = new ColumnHelper($table, 'price');
    $column->decimal(10, 2)->notNull()->default('0.00');
    $results['decimal'] = [
        'sql' => $column->getSyntax(),
        'description' => 'DECIMAL(10,2) for money values',
        'code' => '$column = new ColumnHelper($table, \'price\');' . "\n" . '$column->decimal(10, 2)->notNull()->default(\'0.00\');',
    ];
    
    // Demo 5: Text types
    $column = new ColumnHelper($table, 'bio');
    $column->text()->nullable();
    $results['text'] = [
        'sql' => $column->getSyntax(),
        'description' => 'TEXT nullable',
        'code' => '$column = new ColumnHelper($table, \'bio\');' . "\n" . '$column->text()->nullable();',
    ];
    
    $column = new ColumnHelper($table, 'content');
    $column->longtext()->nullable();
    $results['longtext'] = [
        'sql' => $column->getSyntax(),
        'description' => 'LONGTEXT nullable',
        'code' => '$column = new ColumnHelper($table, \'content\');' . "\n" . '$column->longtext()->nullable();',
    ];
    
    // Demo 6: Date/Time types
    $column = new ColumnHelper($table, 'created_at');
    $column->datetime()->nullable();
    $results['datetime'] = [
        'sql' => $column->getSyntax(),
        'description' => 'DATETIME nullable',
        'code' => '$column = new ColumnHelper($table, \'created_at\');' . "\n" . '$column->datetime()->nullable();',
    ];
    
    $column = new ColumnHelper($table, 'updated_at');
    $column->timestamp()->defaultCurrentTimestamp();
    $results['timestamp'] = [
        'sql' => $column->getSyntax(),
        'description' => 'TIMESTAMP with DEFAULT CURRENT_TIMESTAMP',
        'code' => '$column = new ColumnHelper($table, \'updated_at\');' . "\n" . '$column->timestamp()->defaultCurrentTimestamp();',
    ];
    
    // Demo 7: JSON type
    $column = new ColumnHelper($table, 'metadata');
    $column->json()->nullable();
    $results['json'] = [
        'sql' => $column->getSyntax(),
        'description' => 'JSON column nullable',
        'code' => '$column = new ColumnHelper($table, \'metadata\');' . "\n" . '$column->json()->nullable();',
    ];
    
    // Demo 8: ENUM type
    $column = new ColumnHelper($table, 'status');
    $column->enum(['active', 'inactive', 'pending'])->notNull()->default('pending');
    $results['enum'] = [
        'sql' => $column->getSyntax(),
        'description' => 'ENUM with default value',
        'code' => '$column = new ColumnHelper($table, \'status\');' . "\n" . '$column->enum([\'active\', \'inactive\', \'pending\'])->notNull()->default(\'pending\');',
    ];
    
    // Demo 9: Column with charset and collation
    $column = new ColumnHelper($table, 'name');
    $column->varchar(100)->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
    $results['charset_collation'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Column with specific charset and collation',
        'code' => '$column = new ColumnHelper($table, \'name\');' . "\n" . '$column->varchar(100)->charset(\'utf8mb4\')->collation(\'utf8mb4_unicode_ci\');',
    ];
    
    // Demo 10: Column with comment
    $column = new ColumnHelper($table, 'email');
    $column->varchar(320)->notNull()->comment('User email address');
    $results['comment'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Column with comment',
        'code' => '$column = new ColumnHelper($table, \'email\');' . "\n" . '$column->varchar(320)->notNull()->comment(\'User email address\');',
    ];
    
    // Demo 11: Column position (FIRST/AFTER)
    $column = new ColumnHelper($table, 'id');
    $column->int(11)->autoIncrement()->first();
    $results['position_first'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Auto-increment PRIMARY KEY at FIRST position',
        'code' => '$column = new ColumnHelper($table, \'id\');' . "\n" . '$column->int(11)->autoIncrement()->first();',
    ];
    
    $column = new ColumnHelper($table, 'phone');
    $column->varchar(20)->nullable()->after('email');
    $results['position_after'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Column positioned AFTER email',
        'code' => '$column = new ColumnHelper($table, \'phone\');' . "\n" . '$column->varchar(20)->nullable()->after(\'email\');',
    ];
    
    // Demo 12: Using columnHelper() from Table
    $column = $table->columnHelper('avatar');
    $column->varchar(500)->nullable()->comment('Profile picture URL');
    $results['via_table'] = [
        'sql' => $column->getSyntax(),
        'description' => 'Column created via $table->columnHelper()',
        'code' => '$column = $table->columnHelper(\'avatar\');' . "\n" . '$column->varchar(500)->nullable()->comment(\'Profile picture URL\');',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
