<?php
/**
 * SimplifiedMessage Demo Controller
 * 
 * @llm SimplifiedMessage implements STOMP-like message protocol.
 * 
 * ## Message Format
 * 
 * ```
 * COMMAND\r\n
 * header1:value1\r\n
 * header2:value2\r\n
 * \r\n
 * body content\0\r\n
 * ```
 * 
 * ## Use Cases
 * 
 * - WebSocket messaging
 * - Inter-process communication
 * - Message queue protocols
 */

namespace Razy\Module\message_demo;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'basic' => 'demo/basic',
        ]);
        
        // Register with demo index
        $agent->listen('demo/demo_index:register_demo', function () {
            return [
                'name'        => 'Message Demo',
                'description' => 'STOMP-like message protocol implementation',
                'url'         => '/message_demo/',
                'category'    => 'Web & API',
                'icon'        => '💬',
                'routes'      => '1 route: /',
            ];
        });
        
        return true;
    }
};
