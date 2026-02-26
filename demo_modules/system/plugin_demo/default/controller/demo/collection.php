<?php
/**
 * Collection Plugin Demo
 * 
 * @llm Demonstrates creating Collection filter and processor plugins.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'creating_filter_plugin' => [
            'file' => 'filter.notempty.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Collection/filter.notempty.php
return function ($value) {
    // Return true to keep, false to remove
    return !empty($value);
};
PHP,
            'usage' => "\$collection('*:notempty')",
            'description' => 'Filter out empty values',
        ],
        
        'creating_filter_with_args' => [
            'file' => 'filter.minlength.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Collection/filter.minlength.php
return function ($value, int $min = 0) {
    if (is_string($value)) {
        return strlen($value) >= $min;
    }
    if (is_array($value)) {
        return count($value) >= $min;
    }
    return false;
};
PHP,
            'usage' => "\$collection('*:minlength(5)')",
            'description' => 'Filter by minimum length',
        ],
        
        'creating_processor_plugin' => [
            'file' => 'processor.uppercase.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Collection/processor.uppercase.php
return function ($value) {
    if (is_string($value)) {
        return strtoupper($value);
    }
    echo json_encode($value, JSON_PRETTY_PRINT);
};
PHP,
            'usage' => "\$collection('*')->uppercase()",
            'description' => 'Convert strings to uppercase',
        ],
        
        'creating_processor_with_args' => [
            'file' => 'processor.prefix.php',
            'code' => <<<'PHP'
<?php
// src/plugins/Collection/processor.prefix.php
return function ($value, string $prefix = '') {
    if (is_string($value)) {
        return $prefix . $value;
    }
    echo json_encode($value, JSON_PRETTY_PRINT);
};
PHP,
            'usage' => "\$collection('*')->prefix('ID:')",
            'description' => 'Add prefix to strings',
        ],
        
        'registering_plugin_folder' => [
            'code' => <<<'PHP'
<?php
use Razy\Collection;

// In your module:
Collection::AddPluginFolder(__DIR__ . '/plugins/Collection');
PHP,
        ],
        
        'built_in_filter' => [
            'istype' => [
                'usage' => "\$collection('*:istype(string)')",
                'types' => ['string', 'integer', 'double', 'boolean', 'array', 'object', 'null'],
            ],
        ],
        
        'built_in_processors' => [
            'trim' => "\$collection('*')->trim()->getArray()",
            'int' => "\$collection('*')->int()->getArray()",
            'float' => "\$collection('*')->float()->getArray()",
        ],
    ], JSON_PRETTY_PRINT);
};
