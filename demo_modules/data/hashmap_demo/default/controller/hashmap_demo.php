<?php
/**
 * HashMap Demo Controller
 * 
 * @llm Razy HashMap is a hash-based key-value storage structure.
 * 
 * ## Key Features
 * 
 * - Supports object instances as keys via spl_object_hash
 * - Maintains insertion order with hashOrder array
 * - Implements ArrayAccess, Iterator, Countable
 * - Supports generator-based iteration
 * 
 * ## Key Prefix Convention
 * 
 * - `c:` - Custom/string key: `c:my_key`
 * - `o:` - Object hash key: `o:spl_object_hash`
 * - `i:` - Internal auto-generated key: `i:guid`
 */

namespace Razy\Module\hashmap_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
            'objects' => 'demo/objects',
            'iteration' => 'demo/iteration',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'HashMap Demo',
                'description' => 'Hash-based key-value storage with object keys',
                'url'         => '/hashmap_demo/',
                'category'    => 'Data Handling',
                'icon'        => '🗂️',
                'routes'      => '4 routes: /, basic, objects, iteration',
            ];
        });
        
        return true;
    }
};
