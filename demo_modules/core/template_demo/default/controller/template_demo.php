<?php
/**
 * Template Engine Demo Controller
 * 
 * @llm Comprehensive demo of the Razy Template Engine featuring:
 * 
 * ## Key Features Demonstrated
 * 
 * - Variable tags: {$variable}, {$path.to.value}
 * - Modifiers: {$var|upper}, {$var|lower}, {$var|capitalize}, {$var|trim}, {$var|join:","}
 * - Function tags: {@if}, {@each}, {@repeat}, {@def}, {@template:Name}
 * - Block system: START/END, WRAPPER, TEMPLATE, USE, INCLUDE
 * - Dynamic blocks: Entity API with newBlock(), assign(), process()
 * - Parameter resolution: Entity -> Block -> Source -> Template chain
 */

namespace Razy\Module\template_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'variables' => 'demo/variables',
            'blocks' => 'demo/blocks',
            'functions' => 'demo/functions',
            'entity' => 'demo/entity',
            'advanced' => 'demo/advanced',
        ]);

        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Template Engine Demo',
                'description' => 'Variables, modifiers, blocks, function tags & Entity API',
                'url'         => '/template_demo/',
                'category'    => 'Core Features',
                'icon'        => '📝',
                'routes'      => '6 routes: /, variables, blocks, functions, entity, advanced',
            ];
        });

        return true;
    }
};
