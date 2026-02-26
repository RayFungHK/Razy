<?php
/**
 * YAML Dump Demo
 * 
 * @llm Demonstrates dumping PHP data to YAML format.
 */

use Razy\Controller;
use Razy\YAML;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Simple Array ===
    $data = [
        'name' => 'MyApp',
        'version' => '1.0.0',
        'debug' => true,
    ];
    
    $results['simple'] = [
        'data' => $data,
        'yaml' => YAML::dump($data),
        'description' => 'Simple array to YAML',
    ];
    
    // === Nested Structure ===
    $data = [
        'database' => [
            'host' => 'localhost',
            'port' => 3306,
            'credentials' => [
                'username' => 'root',
                'password' => 'secret',
            ],
        ],
    ];
    
    $results['nested'] = [
        'data' => $data,
        'yaml' => YAML::dump($data),
        'description' => 'Nested structure',
    ];
    
    // === Lists ===
    $data = [
        'features' => ['auth', 'api', 'admin'],
        'ports' => [80, 443, 8080],
    ];
    
    $results['lists'] = [
        'data' => $data,
        'yaml' => YAML::dump($data),
        'description' => 'Arrays as sequences',
    ];
    
    // === Custom Indentation ===
    $data = [
        'level1' => [
            'level2' => [
                'value' => 'deep',
            ],
        ],
    ];
    
    $results['indent'] = [
        'indent_2' => YAML::dump($data, 2),
        'indent_4' => YAML::dump($data, 4),
        'description' => 'Custom indent spaces (2 vs 4)',
    ];
    
    // === Inline Level ===
    $data = [
        'simple' => ['a', 'b', 'c'],
        'nested' => [
            'deep' => ['x', 'y', 'z'],
        ],
    ];
    
    $results['inline'] = [
        'inline_2' => YAML::dump($data, 2, 2),
        'inline_4' => YAML::dump($data, 2, 4),
        'description' => 'Inline level: arrays at depth N+ become inline',
    ];
    
    // === Mixed Types ===
    $data = [
        'string' => 'hello',
        'integer' => 42,
        'float' => 3.14,
        'bool_true' => true,
        'bool_false' => false,
        'null_val' => null,
        'array' => [1, 2, 3],
    ];
    
    $results['types'] = [
        'data' => $data,
        'yaml' => YAML::dump($data),
        'description' => 'Various PHP types',
    ];
    
    // === File Operations ===
    $results['file_ops'] = [
        'description' => 'File read/write operations',
        'parse_file' => <<<'PHP'
// Parse YAML file
$config = YAML::parseFile('/path/to/config.yaml');
PHP,
        'dump_file' => <<<'PHP'
// Dump to YAML file
$data = ['name' => 'MyApp', 'version' => '1.0'];
YAML::dumpFile('/path/to/output.yaml', $data);
PHP,
    ];
    
    // === Real World: Module Config ===
    $moduleConfig = [
        'module_code' => 'my_module',
        'name' => 'My Module',
        'version' => '1.0.0',
        'author' => 'Developer',
        'dependencies' => [
            'core' => '^1.0',
            'database' => '^2.0',
        ],
        'routes' => [
            '/' => 'main',
            '/api/*' => 'api',
        ],
    ];
    
    $results['real_world'] = [
        'data' => $moduleConfig,
        'yaml' => YAML::dump($moduleConfig),
        'description' => 'Module configuration example',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
