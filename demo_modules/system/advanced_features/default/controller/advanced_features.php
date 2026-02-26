<?php
/**
 * Advanced Features Controller
 * 
 * @llm Demonstrates advanced Razy features in __onInit:
 * 
 * ## 1. await() - Module Dependency Execution
 * Defers callable until specified module(s) have completed __onLoad.
 * Use when you need to interact with another module's API at startup.
 * 
 * ## 2. addAPICommand() with '#' Prefix - Internal Binding
 * The '#' prefix creates BOTH:
 * - Public API command (accessible via $this->poke('module')->command())
 * - Internal method binding (accessible via $this->command() in Controller)
 * 
 * ## 3. Complex addLazyRoute() with @self
 * Nested array structures where:
 * - Keys = URL path segments
 * - Values = Closure file paths (relative to controller/)
 * - '@self' = Handler for the current path level
 * 
 * ## 4. addShadowRoute() - Route Proxy
 * Creates a route in THIS module that proxies to ANOTHER module's closure.
 * Useful for:
 * - URL aliasing
 * - Route forwarding
 * - Module composition
 */

namespace Razy\Module\advanced_features;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    private bool $helperReady = false;
    
    public function __onInit(Agent $agent): bool
    {
        // =====================================================
        // FEATURE 1: await() - Wait for Module Dependencies
        // =====================================================
        // The callback executes AFTER 'system/helper_module' has completed
        // its __onLoad phase. If helper_module never loads, callback still runs.
        
        $agent->await('system/helper_module', function() {
            // Mark helper as ready - await() guarantees it loaded if we reach here
            $this->helperReady = true;
            
            // Use the API to interact with the helper module
            // $this->api('system/helper_module') returns an Emitter to call its APIs
            // Example: $this->api('system/helper_module')->addClient('advanced_features');
        });
        
        // =====================================================
        // FEATURE 2: addAPICommand() with '#' Prefix
        // =====================================================
        // '#' prefix = Internal binding + Public API
        // These become $this->methodName() callable in this controller
        
        $agent->addAPICommand('#validateInput', 'internal/validate');
        $agent->addAPICommand('#formatOutput', 'internal/format');
        $agent->addAPICommand('#logAction', 'internal/logger');
        
        // Regular API command (external only, no $this-> binding)
        $agent->addAPICommand('getStatus', 'api/status');
        // Note: processRequest commented out as closure file not provided
        // $agent->addAPICommand('processRequest', 'api/process');
        
        // =====================================================
        // FEATURE 3: Complex addLazyRoute() with @self
        // =====================================================
        // The nested structure maps URL paths to controller closures.
        // NOTE: Paths shown below demonstrate the PATTERN - in real usage,
        // you would create corresponding closure files.
        // 
        // Example pattern (not registered to avoid validation errors):
        // $agent->addLazyRoute([
        //     'dashboard' => [
        //         '@self' => 'index',           // /advanced_features/dashboard ??dashboard/index.php
        //         'stats' => 'stats',           // /advanced_features/dashboard/stats ??dashboard/stats.php
        //     ],
        //     'api' => [
        //         'users' => [
        //             '@self' => 'list',        // /advanced_features/api/users ??api/users/list.php
        //             '(:d)' => 'get',          // /advanced_features/api/users/123 ??api/users/get.php
        //         ],
        //     ],
        // ]);
        
        // Simplified route for demo - just the main page
        $agent->addLazyRoute([
            '/' => 'main',
        ]);
        
        // =====================================================
        // FEATURE 4: addShadowRoute() - Route Proxy
        // =====================================================
        // Creates route in THIS module that proxies to ANOTHER module
        // 
        // Syntax: addShadowRoute($route, $targetModuleCode, $targetPath)
        // - $route: URL path (like addRoute, needs leading slash for absolute)
        // - $targetModuleCode: The module to proxy to
        // - $targetPath: The closure path in the target module
        //
        // Use cases:
        // - URL aliasing: /short ??calls long/module/path handler
        // - Route forwarding: /v2/api ??calls v1 module handler
        // - Module composition: aggregate routes from multiple modules
        
        // Shadow route: /advanced_features/helper proxies to helper_module's 'shared/handler'
        $agent->addShadowRoute('/advanced_features/helper', 'system/helper_module', 'shared/handler');
        
        // Shadow route without explicit path: uses same route path in target
        // /advanced_features/common ??helper_module's '/advanced_features/common'
        $agent->addShadowRoute('/advanced_features/common', 'system/helper_module');
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Advanced Features',
                'description' => 'await(), shadow routes, internal APIs',
                'url'         => '/advanced_features/',
                'category'    => 'Advanced',
                'icon'        => '⚙️',
                'routes'      => '1 route + shadow routes',
            ];
        });
        
        return true;
    }
    
    public function __onLoad(Agent $agent): bool
    {
        // After routes are set up, this is called
        return true;
    }
    
    /**
     * Example route handler showing internal binding usage
     */
    public function handleRequest(array $data): array
    {
        // Using internal bindings created by '#' prefix
        // These call the closure files directly via $this->methodName()
        
        if (!$this->validateInput($data)) {
            return ['error' => 'Invalid input'];
        }
        
        $this->logAction('request_processed', $data);
        
        return $this->formatOutput(['success' => true, 'data' => $data]);
    }
    
    /**
     * Check if helper module is ready
     */
    public function isHelperReady(): bool
    {
        return $this->helperReady;
    }
};
