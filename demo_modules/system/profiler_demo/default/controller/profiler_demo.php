<?php
/**
 * Profiler Demo Controller
 */

namespace Razy\Module\profiler_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
            'checkpoints' => 'demo/checkpoints',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Profiler Demo',
                'description' => 'Performance profiling and checkpoints',
                'url'         => '/profiler_demo/',
                'category'    => 'Advanced',
                'icon'        => '⏱️',
                'routes'      => '3 routes: /, basic, checkpoints',
            ];
        });
        
        return true;
    }
};
