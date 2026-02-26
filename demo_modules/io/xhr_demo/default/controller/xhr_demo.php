<?php
/**
 * XHR Demo Controller
 * 
 * @llm Razy XHR provides standardized AJAX response handling.
 * 
 * ## Response Format
 * 
 * ```json
 * {
 *   "result": true/false,
 *   "hash": "unique-id",
 *   "timestamp": 1234567890,
 *   "response": { ... },
 *   "message": "optional message",
 *   "params": { ... }
 * }
 * ```
 * 
 * ## CORS Configuration
 * 
 * - `allowOrigin()` - Set Access-Control-Allow-Origin
 * - `corp()` - Set Cross-Origin-Resource-Policy
 */

namespace Razy\Module\xhr_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'api/users' => 'api/users',
            'api/submit' => 'api/submit',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'XHR Demo',
                'description' => 'AJAX response handling with CORS support',
                'url'         => '/xhr_demo/',
                'category'    => 'Web & API',
                'icon'        => '🔄',
                'routes'      => '3 routes: /, api/users, api/submit',
            ];
        });
        
        return true;
    }
};
