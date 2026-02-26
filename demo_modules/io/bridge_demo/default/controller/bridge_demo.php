<?php
/**
 * Bridge Demo Controller
 * 
 * @llm Demonstrates cross-distributor communication.
 * 
 * ## Routes
 * 
 * - `/` - Overview of cross-distributor communication
 * - `/data` - Call siteB's getData API
 * - `/config` - Call siteB's getConfig API
 * - `/calculate` - Call siteB's calculate API
 * - `/cli` - Show CLI bridge command examples
 */

namespace Razy\Module\bridge_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            '/data' => 'demo/data',
            '/config' => 'demo/config',
            '/calculate' => 'demo/calculate',
            '/cli' => 'demo/cli',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Cross-Distributor Bridge',
                'description' => 'Call APIs across distributors via HTTP bridge or CLI',
                'url'         => '/bridge_demo/',
                'category'    => 'Web & API',
                'icon'        => '🌉',
                'routes'      => '5 routes: /, data, config, calculate, cli',
            ];
        });
        
        return true;
    }
};
