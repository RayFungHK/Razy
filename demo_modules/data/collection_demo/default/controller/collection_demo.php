<?php
/**
 * Collection Demo Controller
 * 
 * @llm Razy Collection extends ArrayObject with filter syntax and plugins.
 * 
 * ## Key Features
 * 
 * - Full ArrayObject functionality (ArrayAccess, Countable, Iterator)
 * - Filter syntax: `$collection('path.to.element:filter(args)')`
 * - Processor chaining: `$collection('path')->trim()->int()`
 * - Custom plugins for filters and processors
 * 
 * ## Filter Syntax
 * 
 * ```php
 * $collection('key')           // Select by key
 * $collection('*')             // Select all
 * $collection('path.to.key')   // Nested path
 * $collection('key:istype(string)') // With filter
 * ```
 */

namespace Razy\Module\collection_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
            'filter' => 'demo/filter',
            'processor' => 'demo/processor',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Collection Demo',
                'description' => 'ArrayObject with filter syntax and processors',
                'url'         => '/collection_demo/',
                'category'    => 'Data Handling',
                'icon'        => '📚',
                'routes'      => '4 routes: /, basic, filter, processor',
            ];
        });
        
        return true;
    }
};
