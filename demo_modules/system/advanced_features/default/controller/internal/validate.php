<?php
/**
 * Internal Validate Closure
 * 
 * @llm Bound internally via addAPICommand('#validateInput', 'internal/validate')
 * Accessible as:
 * - $this->validateInput($data) - Internal binding
 * - $api->validateInput($data) - Public API
 */

use Razy\Controller;

return function (array $data): bool {
    /** @var Controller $this */
    
    // Basic validation example
    if (empty($data)) {
        return false;
    }
    
    // Check required fields
    $required = ['name', 'type'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return false;
        }
    }
    
    return true;
};
