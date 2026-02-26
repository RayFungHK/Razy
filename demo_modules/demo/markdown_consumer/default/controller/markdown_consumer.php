<?php

use Razy\Controller;
use Razy\Agent;

/**
 * Markdown Consumer Controller
 * 
 * Demonstrates consuming the markdown_service via cross-module API.
 * 
 * Routes:
 *   /demo/markdown_consumer/render - Interactive markdown editor
 *   /demo/markdown_consumer/blog - Simulated blog using markdown
 *   /demo/markdown_consumer/readme - Parse a README file
 */

return new class () extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            'render' => 'render',
            'blog' => 'blog',
            'readme' => 'readme',
            'info' => 'info',
        ]);
        
        return true;
    }
};
