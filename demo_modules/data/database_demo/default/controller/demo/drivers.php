<?php
/**
 * Database Drivers Demo
 * 
 * @llm Demonstrates multi-database driver support (MySQL, PostgreSQL, SQLite).
 * 
 * ## Driver Support
 * 
 * Razy supports multiple database drivers:
 * - MySQL: `Database::DRIVER_MYSQL`
 * - PostgreSQL: `Database::DRIVER_PGSQL`
 * - SQLite: `Database::DRIVER_SQLITE`
 * 
 * ## Connection Methods
 * 
 * MySQL (backward compatible):
 * `$db->connect($host, $user, $pass, $database)`
 * 
 * New method (all drivers):
 * `$db->connectWithDriver($driverType, $config)`
 * 
 * ## Driver-Specific SQL
 * 
 * The Statement class automatically generates driver-appropriate SQL:
 * - LIMIT syntax varies by driver
 * - UPSERT syntax differs (ON DUPLICATE KEY vs ON CONFLICT)
 * - Identifier quoting (backticks vs double-quotes)
 */

use Razy\Controller;
use Razy\Database;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [
        'title' => 'Database Driver Tests',
        'drivers' => []
    ];
    
    // =====================
    // SQLite Test (in-memory, no server needed)
    // =====================
    $sqliteResults = [
        'driver' => 'SQLite',
        'status' => 'testing',
        'tests' => []
    ];
    
    try {
        $sqlite = new Database('sqlite_demo');
        $connected = $sqlite->connectWithDriver(Database::DRIVER_SQLITE, [
            'database' => ':memory:'  // In-memory database
        ]);
        
        if ($connected) {
            $sqliteResults['tests']['connect'] = ['status' => 'pass', 'message' => 'Connected to in-memory SQLite'];
            
            // Test driver type
            $driverType = $sqlite->getDriverType();
            $sqliteResults['tests']['driver_type'] = [
                'status' => ($driverType === 'sqlite') ? 'pass' : 'fail',
                'value' => $driverType
            ];
            
            // Create test table
            $pdo = $sqlite->getDBAdapter();
            $pdo->exec('CREATE TABLE demo_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )');
            $sqliteResults['tests']['create_table'] = ['status' => 'pass', 'message' => 'Created demo_users table'];
            
            // Test table exists
            $exists = $sqlite->isTableExists('demo_users');
            $sqliteResults['tests']['table_exists'] = [
                'status' => $exists ? 'pass' : 'fail',
                'value' => $exists
            ];
            
            // Insert test data
            $pdo->exec("INSERT INTO demo_users (name, email) VALUES ('John Doe', 'john@example.com')");
            $pdo->exec("INSERT INTO demo_users (name, email) VALUES ('Jane Smith', 'jane@example.com')");
            $pdo->exec("INSERT INTO demo_users (name, email) VALUES ('Bob Wilson', 'bob@example.com')");
            $sqliteResults['tests']['insert_data'] = ['status' => 'pass', 'message' => 'Inserted 3 test records'];
            
            // Test SELECT with LIMIT
            $stmt = $sqlite->prepare()->select('*')->from('demo_users');
            $stmt->limit(2, 1);  // offset 1, limit 2
            $selectSql = $stmt->getSyntax();
            
            // SQLite should use "LIMIT x OFFSET y" syntax
            $hasCorrectLimit = (strpos($selectSql, 'LIMIT 2 OFFSET 1') !== false);
            $sqliteResults['tests']['limit_syntax'] = [
                'status' => $hasCorrectLimit ? 'pass' : 'fail',
                'sql' => $selectSql,
                'expected' => 'LIMIT 2 OFFSET 1'
            ];
            
            // Execute and verify results
            $rows = $stmt->query()->fetchAll();
            $sqliteResults['tests']['query_execution'] = [
                'status' => (count($rows) === 2) ? 'pass' : 'fail',
                'row_count' => count($rows),
                'data' => $rows
            ];
            
            // Test driver methods
            $driver = $sqlite->getDriver();
            
            // Identifier quoting (SQLite uses double quotes)
            $quoted = $driver->quoteIdentifier('column_name');
            $sqliteResults['tests']['quote_identifier'] = [
                'status' => ($quoted === '"column_name"') ? 'pass' : 'fail',
                'value' => $quoted,
                'expected' => '"column_name"'
            ];
            
            // Concat syntax (SQLite uses ||)
            $concatSql = $driver->getConcatSyntax(['first_name', "' '", 'last_name']);
            $sqliteResults['tests']['concat_syntax'] = [
                'status' => (strpos($concatSql, '||') !== false) ? 'pass' : 'fail',
                'value' => $concatSql,
                'expected' => "first_name || ' ' || last_name"
            ];
            
            // Auto-increment syntax
            $autoSql = $driver->getAutoIncrementSyntax(11);
            $sqliteResults['tests']['auto_increment'] = [
                'status' => (strpos($autoSql, 'AUTOINCREMENT') !== false) ? 'pass' : 'fail',
                'value' => $autoSql
            ];
            
            $sqliteResults['status'] = 'pass';
        } else {
            $sqliteResults['status'] = 'fail';
            $sqliteResults['tests']['connect'] = ['status' => 'fail', 'message' => 'Connection failed'];
        }
    } catch (Exception $e) {
        $sqliteResults['status'] = 'error';
        $sqliteResults['error'] = $e->getMessage();
    }
    
    $results['drivers']['sqlite'] = $sqliteResults;
    
    // =====================
    // MySQL Driver Tests (syntax only, no connection required)
    // =====================
    $mysqlResults = [
        'driver' => 'MySQL',
        'status' => 'testing',
        'tests' => []
    ];
    
    try {
        $driver = Database::CreateDriver('mysql');
        $mysqlResults['tests']['driver_created'] = ['status' => 'pass', 'type' => $driver->getType()];
        
        // Identifier quoting (MySQL uses backticks)
        $quoted = $driver->quoteIdentifier('column_name');
        $mysqlResults['tests']['quote_identifier'] = [
            'status' => ($quoted === '`column_name`') ? 'pass' : 'fail',
            'value' => $quoted,
            'expected' => '`column_name`'
        ];
        
        // LIMIT syntax (MySQL uses "LIMIT offset, count")
        $limitSql = $driver->getLimitSyntax(10, 5);
        $mysqlResults['tests']['limit_syntax'] = [
            'status' => (strpos($limitSql, 'LIMIT 10, 5') !== false) ? 'pass' : 'fail',
            'value' => $limitSql,
            'expected' => ' LIMIT 10, 5'
        ];
        
        // Concat syntax (MySQL uses CONCAT())
        $concatSql = $driver->getConcatSyntax(['first_name', "' '", 'last_name']);
        $mysqlResults['tests']['concat_syntax'] = [
            'status' => (strpos($concatSql, 'CONCAT(') !== false) ? 'pass' : 'fail',
            'value' => $concatSql,
            'expected' => "CONCAT(first_name, ' ', last_name)"
        ];
        
        // Auto-increment syntax
        $autoSql = $driver->getAutoIncrementSyntax(11);
        $mysqlResults['tests']['auto_increment'] = [
            'status' => (strpos($autoSql, 'AUTO_INCREMENT') !== false) ? 'pass' : 'fail',
            'value' => $autoSql
        ];
        
        // Upsert syntax (MySQL uses ON DUPLICATE KEY UPDATE)
        $upsertSql = $driver->getUpsertSyntax(
            'users',
            ['id', 'name', 'email'],
            ['id'],
            fn($col) => "VALUES({$col})"
        );
        $mysqlResults['tests']['upsert_syntax'] = [
            'status' => (strpos($upsertSql, 'ON DUPLICATE KEY UPDATE') !== false) ? 'pass' : 'fail',
            'value' => $upsertSql
        ];
        
        $mysqlResults['status'] = 'pass';
    } catch (Exception $e) {
        $mysqlResults['status'] = 'error';
        $mysqlResults['error'] = $e->getMessage();
    }
    
    $results['drivers']['mysql'] = $mysqlResults;
    
    // =====================
    // PostgreSQL Driver Tests (syntax only, no connection required)
    // =====================
    $pgsqlResults = [
        'driver' => 'PostgreSQL',
        'status' => 'testing',
        'tests' => []
    ];
    
    try {
        $driver = Database::CreateDriver('pgsql');
        $pgsqlResults['tests']['driver_created'] = ['status' => 'pass', 'type' => $driver->getType()];
        
        // Identifier quoting (PostgreSQL uses double quotes)
        $quoted = $driver->quoteIdentifier('column_name');
        $pgsqlResults['tests']['quote_identifier'] = [
            'status' => ($quoted === '"column_name"') ? 'pass' : 'fail',
            'value' => $quoted,
            'expected' => '"column_name"'
        ];
        
        // LIMIT syntax (PostgreSQL uses "LIMIT count OFFSET offset")
        $limitSql = $driver->getLimitSyntax(10, 5);
        $pgsqlResults['tests']['limit_syntax'] = [
            'status' => (strpos($limitSql, 'LIMIT 5 OFFSET 10') !== false) ? 'pass' : 'fail',
            'value' => $limitSql,
            'expected' => ' LIMIT 5 OFFSET 10'
        ];
        
        // Concat syntax (PostgreSQL uses ||)
        $concatSql = $driver->getConcatSyntax(['first_name', "' '", 'last_name']);
        $pgsqlResults['tests']['concat_syntax'] = [
            'status' => (strpos($concatSql, '||') !== false) ? 'pass' : 'fail',
            'value' => $concatSql,
            'expected' => "first_name || ' ' || last_name"
        ];
        
        // Auto-increment syntax (PostgreSQL uses SERIAL)
        $autoSql = $driver->getAutoIncrementSyntax(11);
        $pgsqlResults['tests']['auto_increment'] = [
            'status' => (strpos($autoSql, 'SERIAL') !== false) ? 'pass' : 'fail',
            'value' => $autoSql
        ];
        
        // Upsert syntax (PostgreSQL uses ON CONFLICT ... DO UPDATE)
        $upsertSql = $driver->getUpsertSyntax(
            'users',
            ['id', 'name', 'email'],
            ['id'],
            fn($col) => "EXCLUDED.{$col}"
        );
        $pgsqlResults['tests']['upsert_syntax'] = [
            'status' => (strpos($upsertSql, 'ON CONFLICT') !== false) ? 'pass' : 'fail',
            'value' => $upsertSql
        ];
        
        $pgsqlResults['status'] = 'pass';
    } catch (Exception $e) {
        $pgsqlResults['status'] = 'error';
        $pgsqlResults['error'] = $e->getMessage();
    }
    
    $results['drivers']['pgsql'] = $pgsqlResults;
    
    // =====================
    // Summary
    // =====================
    $passCount = 0;
    $failCount = 0;
    
    foreach ($results['drivers'] as $driverResult) {
        if (isset($driverResult['tests'])) {
            foreach ($driverResult['tests'] as $test) {
                if (isset($test['status'])) {
                    if ($test['status'] === 'pass') {
                        $passCount++;
                    } elseif ($test['status'] === 'fail') {
                        $failCount++;
                    }
                }
            }
        }
    }
    
    $results['summary'] = [
        'total_tests' => $passCount + $failCount,
        'passed' => $passCount,
        'failed' => $failCount,
        'success_rate' => ($passCount + $failCount > 0) 
            ? round(($passCount / ($passCount + $failCount)) * 100, 1) . '%' 
            : 'N/A'
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
};
