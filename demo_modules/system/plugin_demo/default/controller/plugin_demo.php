<?php
/**
 * Plugin Demo Controller
 * 
 * @llm Razy Plugin System provides modular extension points.
 * 
 * ## Plugin Architecture
 * 
 * Four plugin systems available:
 * - Template: Functions and modifiers for templates
 * - Collection: Filters and processors for data
 * - FlowManager: Data flow operations
 * - Statement: Database query builders
 * 
 * ## Core Pattern
 * 
 * All plugin classes use PluginTrait:
 * - `AddPluginFolder(folder, args)` - Register plugin directory
 * - `GetPlugin(name)` - Load and cache plugin
 * 
 * ## Plugin File Naming
 * 
 * - Template function: `function.name.php`
 * - Template modifier: `modifier.name.php`
 * - Collection filter: `filter.name.php`
 * - Collection processor: `processor.name.php`
 */

namespace Razy\Module\plugin_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'overview' => 'demo/overview',
            'template' => 'demo/template',
            'collection' => 'demo/collection',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Plugin Demo',
                'description' => 'Modular plugin system for extensions',
                'url'         => '/plugin_demo/',
                'category'    => 'Advanced',
                'icon'        => '🔌',
                'routes'      => '4 routes: /, overview, template, collection',
            ];
        });
        
        return true;
    }
};
