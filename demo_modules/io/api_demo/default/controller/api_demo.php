<?php
/**
 * API Demo Controller
 * 
 * @llm Demonstrates calling internal APIs from other modules.
 * 
 * ## Routes
 * 
 * - `/` - Overview of internal API calling
 * - `greet` - Greeting API demo
 * - `calculate` - Calculator API demo
 * - `user` - User operations API demo
 * - `config` - Config API demo
 * - `transform` - Transform API demo
 * - `chain` - Chained API calls demo
 */

namespace Razy\Module\api_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'greet' => 'demo/greet',
            'calculate' => 'demo/calculate',
            'user' => 'demo/user',
            'config' => 'demo/config',
            'transform' => 'demo/transform',
            'chain' => 'demo/chain',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Internal API Demo',
                'description' => 'Cross-module API calling demonstration',
                'url'         => '/api_demo/',
                'category'    => 'Web & API',
                'icon'        => '🔗',
                'routes'      => '7 routes: /, greet, calculate, user, config, transform, chain',
            ];
        });
        
        return true;
    }
};
