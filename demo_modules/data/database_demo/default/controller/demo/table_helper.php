<?php
/**
 * TableHelper Demo - ALTER TABLE operations
 * 
 * @llm Demonstrates TableHelper class for generating ALTER TABLE SQL statements.
 * 
 * ## Features Demonstrated
 * - Rename table
 * - Add/modify/drop columns
 * - Add/drop indexes (INDEX, UNIQUE, FULLTEXT, PRIMARY KEY)
 * - Add/drop foreign keys
 * - Change charset, collation, engine, comment
 */

use Razy\Database\Table;
use Razy\Database\Table\TableHelper;

return function () {
    header('Content-Type: application/json; charset=UTF-8');
    
    $results = [];
    
    // Create a table instance
    $table = new Table('users');
    
    // Demo 1: Rename table
    $helper = new TableHelper($table);
    $helper->rename('customers');
    $results['rename_table'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Rename table from users to customers',
        'code' => '$table = new Table(\'users\');' . "\n" . '$helper = new TableHelper($table);' . "\n" . '$helper->rename(\'customers\');',
    ];
    
    // Demo 2: Add column
    $helper = new TableHelper($table);
    $helper->addColumn('email=type(text),nullable');
    $results['add_column'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Add a TEXT column with nullable',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->addColumn(\'email=type(text),nullable\');',
    ];
    
    // Demo 3: Add column with position
    $helper = new TableHelper($table);
    $helper->addColumn('phone=type(text)', 'AFTER email');
    $results['add_column_after'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Add column after a specific column',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->addColumn(\'phone=type(text)\', \'AFTER email\');',
    ];
    
    // Demo 4: Drop column
    $helper = new TableHelper($table);
    $helper->dropColumn('deprecated_field');
    $results['drop_column'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Drop a column',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->dropColumn(\'deprecated_field\');',
    ];
    
    // Demo 5: Add unique index
    $helper = new TableHelper($table);
    $helper->addUniqueIndex('email', 'uniq_email');
    $results['add_unique_index'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Add unique index on email column',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->addUniqueIndex(\'email\', \'uniq_email\');',
    ];
    
    // Demo 6: Add composite index
    $helper = new TableHelper($table);
    $helper->addIndex('INDEX', ['first_name', 'last_name'], 'idx_name');
    $results['add_composite_index'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Add composite index on multiple columns',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->addIndex(\'INDEX\', [\'first_name\', \'last_name\'], \'idx_name\');',
    ];
    
    // Demo 7: Add foreign key
    $helper = new TableHelper($table);
    $helper->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
    $results['add_foreign_key'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Add foreign key with CASCADE on delete/update',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->addForeignKey(\'role_id\', \'roles\', \'id\', \'CASCADE\', \'CASCADE\');',
    ];
    
    // Demo 8: Change table charset/collation
    $helper = new TableHelper($table);
    $helper->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
    $results['change_charset'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Change table charset and collation',
        'code' => '$helper = new TableHelper($table);' . "\n" . '$helper->charset(\'utf8mb4\')->collation(\'utf8mb4_unicode_ci\');',
    ];
    
    // Demo 9: Using createHelper() from Table
    $helper = $table->createHelper();
    $helper->engine('InnoDB')->comment('User accounts table');
    $results['engine_comment'] = [
        'sql' => $helper->getSyntax(),
        'description' => 'Change engine and add table comment via $table->createHelper()',
        'code' => '$helper = $table->createHelper();' . "\n" . '$helper->engine(\'InnoDB\')->comment(\'User accounts table\');',
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
};
