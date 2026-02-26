<?php

use Razy\Controller;
use Razy\Agent;

/**
 * Markdown Service Controller
 * 
 * Exposes markdown parsing as a cross-module API.
 * 
 * USAGE (from any other module in same distributor):
 *   $html = $this->api('markdown')->parse($markdownText);
 *   $html = $this->api('system/markdown_service')->parse($markdownText);
 * 
 * WHY THIS PATTERN:
 * This "Shared Service" pattern solves Composer version conflicts by:
 * 1. Centralizing the dependency in ONE module
 * 2. Exposing a stable API that doesn't change with library versions
 * 3. Allowing library upgrades without touching consumer modules
 */

return new class () extends Controller {
    /**
     * Initialize the module - register API commands
     */
    public function __onInit(Agent $agent): bool
    {
        // Register API commands for cross-module access
        $agent->addAPICommand('parse', 'api/parse.php');
        $agent->addAPICommand('parseFile', 'api/parse_file.php');
        $agent->addAPICommand('getInfo', 'api/get_info.php');
        
        // Register routes for web demo
        $agent->addLazyRoute([
            'demo' => 'demo',
        ]);
        
        return true;
    }
    
    /**
     * Allow all modules to access the markdown API
     * 
     * @param \Razy\ModuleInfo $module The requesting module's info
     * @param string $method The API method being called
     * @param string $fqdn Optional FQDN for cross-distributor calls
     */
    public function __onAPICall(\Razy\ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        // Open API - any module can call this service
        return true;
    }
};
