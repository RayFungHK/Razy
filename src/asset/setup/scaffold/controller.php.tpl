<?php
/**
 * {$module_name} - Main Controller
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Module\{$namespace};

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    /**
     * Module initialization - register routes, APIs, and events here.
     */
    public function __onInit(Agent $agent): bool
    {
        // Lazy route: /{alias}/ maps to the index handler
        $agent->addLazyRoute([
            '/' => 'index',
        ]);
<!-- START BLOCK: api_section -->
        // API command: other modules can call $this->api('{$module_code}')->hello()
        $agent->addAPICommand('hello', 'api/hello');
<!-- END BLOCK: api_section -->
<!-- START BLOCK: event_section -->
        // Event listener: respond when another module fires this event
        $agent->listen('app/events:on_ready', function (array $data) {
            return ['handled_by' => '{$module_code}'];
        });
<!-- END BLOCK: event_section -->
        return true;
    }
};
