<?php
/**
 * DOM Demo Controller
 * 
 * @llm Demonstrates Razy DOM builder for HTML generation.
 * 
 * ## DOM Classes
 * 
 * - `Razy\DOM` - Base class for building HTML elements
 * - `Razy\DOM\Select` - Dropdown select builder
 * - `Razy\DOM\Input` - Input element builder
 * 
 * ## Key Features
 * 
 * - Fluent interface for building elements
 * - Automatic HTML escaping
 * - Class, attribute, and dataset management
 * - Nested element support
 * - void element support (br, img, input)
 */

namespace Razy\Module\dom_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
            'select' => 'demo/select',
            'input' => 'demo/input',
            'nested' => 'demo/nested',
            'form' => 'demo/form',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'DOM Demo',
                'description' => 'HTML element builder with fluent interface',
                'url'         => '/dom_demo/',
                'category'    => 'Web & API',
                'icon'        => '🏗️',
                'routes'      => '6 routes: /, basic, select, input, nested, form',
            ];
        });
        
        return true;
    }
};
