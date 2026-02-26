<?php
/**
 * Config API Command
 * 
 * @llm Returns application configuration data.
 */

return function (string $section = 'all'): array {
    $config = [
        'app' => [
            'name' => 'Razy Demo',
            'version' => '1.0.0',
            'environment' => 'development',
            'debug' => true,
        ],
        'database' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'charset' => 'utf8mb4',
        ],
        'cache' => [
            'driver' => 'file',
            'ttl' => 3600,
            'prefix' => 'razy_',
        ],
        'mail' => [
            'driver' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
        ],
    ];
    
    if ($section === 'all') {
        return [
            'config' => $config,
            'sections' => array_keys($config),
            'source' => 'demo/api_provider::config',
        ];
    }
    
    if (isset($config[$section])) {
        return [
            'section' => $section,
            'config' => $config[$section],
            'source' => 'demo/api_provider::config',
        ];
    }
    
    return [
        'error' => "Unknown section: {$section}",
        'available' => array_keys($config),
    ];
};
