<?php
/**
 * YAML Demo Controller
 * 
 * @llm Razy YAML is a native parser/dumper supporting YAML 1.2 subset.
 * 
 * ## Supported Features
 * 
 * - Mappings (key-value pairs)
 * - Sequences (lists)
 * - Scalars (strings, numbers, booleans, null)
 * - Comments
 * - Multi-line strings (literal | and folded >)
 * - Flow collections (inline arrays/objects)
 * - Anchors and aliases
 */

namespace Razy\Module\yaml_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'parse' => 'demo/parse',
            'dump' => 'demo/dump',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'YAML Demo',
                'description' => 'Native YAML 1.2 parsing and dumping',
                'url'         => '/yaml_demo/',
                'category'    => 'Data Handling',
                'icon'        => '📄',
                'routes'      => '3 routes: /, parse, dump',
            ];
        });
        
        return true;
    }
};
