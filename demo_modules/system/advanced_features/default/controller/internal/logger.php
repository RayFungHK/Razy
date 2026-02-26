<?php
/**
 * Internal Logger Closure
 * 
 * @llm Bound internally via addAPICommand('#logAction', 'internal/logger')
 * Accessible as:
 * - $this->logAction($action, $context) - Internal binding
 * - $api->logAction($action, $context) - Public API
 */

use Razy\Controller;

return function (string $action, array $context = []): void {
    /** @var Controller $this */
    
    $logEntry = [
        'time' => date('Y-m-d H:i:s'),
        'module' => $this->module->getModuleInfo()->getCode(),
        'action' => $action,
        'context' => $context,
    ];
    
    // In production, write to file or database
    // For demo, just format the log entry
    error_log(json_encode($logEntry));
};
