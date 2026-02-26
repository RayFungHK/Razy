<?php
/**
 * Database Demo Controller
 * 
 * @llm Comprehensive demonstration of Razy Database features.
 * 
 * ## Database Overview
 * 
 * Razy Database provides:
 * - PDO wrapper with named instances
 * - Fluent Statement builder
 * - TableJoinSyntax for joins
 * - WhereSyntax for conditions
 * - Query result handling
 * - Transaction support
 * 
 * ## Connection Methods
 * 
 * 1. Static instance (recommended for modules):
 *    `Database::GetInstance('main')` - Gets/creates named instance
 * 
 * 2. Controller method:
 *    `$this->getDatabase()` - Gets module's configured database
 * 
 * ## Statement Builder
 * 
 * `$db->prepare()` returns a Statement for building queries:
 * - `->select('columns')` - SELECT columns
 * - `->from('table')` - FROM with optional joins
 * - `->where('conditions')` - WHERE clause
 * - `->order('>column')` - ORDER BY (> = DESC, < = ASC)
 * - `->limit(10, 0)` - LIMIT with offset
 * - `->group('column')` - GROUP BY
 * - `->having('condition')` - HAVING clause
 */

namespace Razy\Module\database_demo;

use Razy\Agent;
use Razy\Controller;
use Razy\Database;

return new class extends Controller {
    private ?Database $db = null;
    
    public function __onInit(Agent $agent): bool
    {
        // Routes for demonstrating database features
        $agent->addLazyRoute([
            '/' => 'main',
            'connect' => 'demo/connect',
            'drivers' => 'demo/drivers',
            'select' => 'demo/select',
            'insert' => 'demo/insert',
            'update' => 'demo/update',
            'delete' => 'demo/delete',
            'joins' => 'demo/joins',
            'where' => 'demo/where',
            'transaction' => 'demo/transaction',
            'advanced' => 'demo/advanced',
            'table_helper' => 'demo/table_helper',
            'column_helper' => 'demo/column_helper',
        ]);
        
        // API commands for programmatic access (closure files would be needed)
        // Commented out as demo API closures are not provided
        // $agent->addAPICommand('fetchUsers', 'api/fetch-users');
        // $agent->addAPICommand('createUser', 'api/create-user');
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Database Demo',
                'description' => 'PDO wrapper, Statement builder, queries, table/column helpers',
                'url'         => '/database_demo/',
                'category'    => 'Data Handling',
                'icon'        => '🗄️',
                'routes'      => '12 routes: /, connect, select, insert, table_helper, column_helper, etc.',
            ];
        });
        
        return true;
    }
    
    /**
     * Get or create database connection
     */
    public function getDB(): ?Database
    {
        if (!$this->db) {
            // Get named instance (shared across modules)
            $this->db = Database::GetInstance('main');
        }
        return $this->db;
    }
};
