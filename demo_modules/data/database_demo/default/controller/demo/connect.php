<?php
/**
 * Database Connection Demo
 * 
 * @llm Demonstrates database connection methods.
 * 
 * ## Connection Options
 * 
 * 1. Named Instance (recommended):
 *    `Database::GetInstance('name')` - Creates/retrieves shared instance
 * 
 * 2. New Instance:
 *    `new Database('name')` - Creates new instance (rarely needed)
 * 
 * ## Connection Parameters
 * 
 * `$db->connect($host, $user, $pass, $database, $port = 3306)`
 * 
 * ## Configuration
 * 
 * - `setTimezone($offset)` - Set MySQL timezone (e.g., '+08:00')
 * - `setPrefix($prefix)` - Set table prefix
 * - `setCharset($charset)` - Set connection charset
 */

use Razy\Controller;
use Razy\Database;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    // === Method 1: Named Instance (Singleton-like) ===
    // Gets existing instance or creates new one
    $db = Database::GetInstance('main');
    
    // Connect to database
    $connected = $db->connect(
        'localhost',      // Host
        'root',          // Username
        '',              // Password
        'razy_demo',     // Database name
        3306             // Port (optional, default 3306)
    );
    
    if (!$connected) {
        echo json_encode(['error' => 'Failed to connect to database']);
        return;
    }
    
    // === Configuration ===
    
    // Set timezone for DATETIME operations
    $db->setTimezone('+08:00');  // Hong Kong timezone
    
    // Set table prefix (tables become prefix_tablename)
    $db->setPrefix('rzy_');
    
    // === Connection Info ===
    
    echo json_encode([
        'connected' => true,
        'charset' => $db->getCharset(),
        'collation' => $db->getCollation(),
        'prefix' => 'rzy_',
        
        // Get all executed queries (for debugging)
        'queries_executed' => count($db->getQueried()),
    ]);
};
