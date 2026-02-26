<?php
/**
 * Helper Module Controller
 * 
 * @llm Companion module demonstrating:
 * 
 * ## Target for await()
 * advanced_features uses await('system/helper_module', ...) to wait for
 * this module to complete __onLoad before executing its callback.
 * 
 * ## Target for addShadowRoute()
 * advanced_features creates shadow routes that proxy to this module:
 * - /advanced_features/helper ??this module's 'shared/handler'
 * - /advanced_features/common ??this module's '/advanced_features/common'
 */

namespace Razy\Module\helper_module;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    private array $registeredClients = [];
    
    public function __onInit(Agent $agent): bool
    {
        // Provide API for other modules to interact with
        $agent->addAPICommand('registerClient', 'api/register-client');
        $agent->addAPICommand('getClients', 'api/get-clients');
        $agent->addAPICommand('isReady', 'api/is-ready');
        
        // Standard lazy routes for this module
        $agent->addLazyRoute([
            '/' => 'main',
        ]);
        
        // This closure will be called via shadow route from advanced_features
        // Shadow route: /advanced_features/helper ??shared/handler
        $agent->addLazyRoute('shared/handler', 'shared/handler');
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Helper Module',
                'description' => 'Companion module for advanced features demo',
                'url'         => '/helper_module/',
                'category'    => 'Advanced',
                'icon'        => '🧰',
                'routes'      => '1 route + shadow route target',
            ];
        });
        
        return true;
    }
    
    public function __onLoad(Agent $agent): bool
    {
        // After this completes, any await() callbacks targeting this module execute
        return true;
    }
    
    /**
     * Register a client module
     */
    public function addClient(string $moduleCode): void
    {
        $this->registeredClients[$moduleCode] = time();
    }
    
    /**
     * Get registered clients
     */
    public function getRegisteredClients(): array
    {
        return $this->registeredClients;
    }
};
