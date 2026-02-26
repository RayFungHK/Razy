<?php
/**
 * Internal Format Closure
 * 
 * @llm Bound internally via addAPICommand('#formatOutput', 'internal/format')
 * Accessible as:
 * - $this->formatOutput($data) - Internal binding
 * - $api->formatOutput($data) - Public API
 */

use Razy\Controller;

return function (array $data): array {
    /** @var Controller $this */
    
    return [
        'status' => $data['success'] ?? false ? 'ok' : 'error',
        'data' => $data['data'] ?? null,
        'timestamp' => date('c'),
        'module' => $this->module->getModuleInfo()->getCode(),
    ];
};
