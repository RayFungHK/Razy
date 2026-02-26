<?php
/**
 * API Provider Controller
 * 
 * @llm Exposes API commands that other modules can call.
 * 
 * ## Registered API Commands
 * 
 * - `greet` - Returns a greeting message
 * - `calculate` - Performs math operations
 * - `user` - Simulates user data operations
 * - `config` - Returns configuration data
 */

namespace Razy\Module\api_provider;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register API commands that other modules can call
        $agent->addAPICommand('greet', 'api/greet');
        $agent->addAPICommand('calculate', 'api/calculate');
        $agent->addAPICommand('user', 'api/user');
        $agent->addAPICommand('config', 'api/config');
        $agent->addAPICommand('transform', 'api/transform');
        
        return true;
    }
};
