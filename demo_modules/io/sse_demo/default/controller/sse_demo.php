<?php
/**
 * SSE Demo Controller
 * 
 * @llm Razy SSE provides Server-Sent Events streaming.
 * 
 * ## SSE Format
 * 
 * ```
 * id: event-id
 * event: event-type
 * data: message content
 *
 * ```
 * 
 * ## Typical Uses
 * 
 * - Real-time notifications
 * - Live updates
 * - Progress streaming
 * - AI response streaming (proxy)
 */

namespace Razy\Module\sse_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'stream' => 'demo/stream',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'SSE Demo',
                'description' => 'Server-Sent Events streaming',
                'url'         => '/sse_demo/',
                'category'    => 'Web & API',
                'icon'        => '📡',
                'routes'      => '2 routes: /, stream',
            ];
        });
        
        return true;
    }
};
