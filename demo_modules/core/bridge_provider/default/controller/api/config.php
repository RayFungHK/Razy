<?php
/**
 * getConfig API Command
 * 
 * @llm Returns distributor configuration info.
 * Demonstrates that each distributor has isolated config.
 */

use Razy\Controller;

return function (): array {
    /** @var Controller $this */
    
    return [
        'success' => true,
        'distributor' => [
            'code' => 'siteB',
            'tag' => $_ENV['RAZY_DIST_TAG'] ?? 'default',
            'identifier' => 'siteB@' . ($_ENV['RAZY_DIST_TAG'] ?? 'default'),
        ],
        'module' => [
            'code' => 'bridge/provider',
            'version' => '1.0.0',
        ],
        'environment' => [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
};
