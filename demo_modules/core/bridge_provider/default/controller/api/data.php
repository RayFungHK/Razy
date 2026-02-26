<?php
/**
 * getData API Command
 * 
 * @llm Returns sample data from siteB distributor.
 * Demonstrates cross-distributor data retrieval.
 */

use Razy\Controller;

return function (string $category = 'general'): array {
    /** @var Controller $this */
    
    $data = [
        'general' => [
            'name' => 'SiteB Data Provider',
            'version' => '1.0.0',
            'description' => 'Data from siteB distributor',
        ],
        'products' => [
            ['id' => 1, 'name' => 'Product A', 'price' => 29.99],
            ['id' => 2, 'name' => 'Product B', 'price' => 49.99],
            ['id' => 3, 'name' => 'Product C', 'price' => 99.99],
        ],
        'users' => [
            ['id' => 1, 'name' => 'John Doe', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Jane Smith', 'role' => 'user'],
        ],
    ];
    
    return [
        'success' => true,
        'source' => 'siteB@' . ($_ENV['RAZY_DIST_TAG'] ?? 'default'),
        'category' => $category,
        'data' => $data[$category] ?? $data['general'],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
};
