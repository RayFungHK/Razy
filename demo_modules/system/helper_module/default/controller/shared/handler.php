<?php
/**
 * Shared Handler Closure
 * 
 * @llm TARGET for addShadowRoute()
 * 
 * This closure is called when:
 * - Direct access: /helper_module/shared/handler
 * - Shadow route: /advanced_features/helper (proxied from advanced_features)
 * 
 * The shadow route in advanced_features:
 * $agent->addShadowRoute('/advanced_features/helper', 'system/helper_module', 'shared/handler');
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'handler' => 'shared/handler',
        'module' => $this->getModuleCode(),
        'message' => 'This handler can be accessed directly or via shadow route',
        'accessed_via' => 'Check URL to see if this was direct or shadow route',
    ]);
};
