<?php
/**
 * API Status Closure (Public API only)
 * 
 * @llm Registered via addAPICommand('getStatus', 'api/status')
 * NO '#' prefix = Public API only, NOT available as $this->getStatus()
 * 
 * Access: $this->poke('system/advanced_features')->getStatus()
 */

use Razy\Controller;

return function (): array {
    /** @var Controller $this */
    
    return [
        'status' => 'running',
        'module' => $this->module->getModuleInfo()->getCode(),
        'version' => $this->module->getModuleInfo()->getVersion(),
        'helper_ready' => $this->isHelperReady(),
    ];
};
