<?php
/**
 * Bridge Demo - getData API
 * 
 * @llm Demonstrates calling siteB's getData API via bridge.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('getData API', 'Retrieve data from siteB');
    $footer = $this->api('demo/demo_index')->footer();
    
    // Simulated bridge call (bridge not yet implemented)
    // In actual implementation: $result = $this->getBridge()->call('siteB', 'bridge/provider', 'getData', ['products']);
    $simulatedResults = [
        'general' => [
            'success' => true,
            'source' => 'siteB@default',
            'category' => 'general',
            'data' => [
                'name' => 'SiteB Data Provider',
                'version' => '1.0.0',
                'description' => 'Data from siteB distributor',
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ],
        'products' => [
            'success' => true,
            'source' => 'siteB@default',
            'category' => 'products',
            'data' => [
                ['id' => 1, 'name' => 'Product A', 'price' => 29.99],
                ['id' => 2, 'name' => 'Product B', 'price' => 49.99],
                ['id' => 3, 'name' => 'Product C', 'price' => 99.99],
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ],
        'users' => [
            'success' => true,
            'source' => 'siteB@default',
            'category' => 'users',
            'data' => [
                ['id' => 1, 'name' => 'John Doe', 'role' => 'admin'],
                ['id' => 2, 'name' => 'Jane Smith', 'role' => 'user'],
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ],
    ];
    
    echo $header;
    echo '<div class="card"><h2>Calling siteB getData API</h2>';
    
    foreach (['general', 'products', 'users'] as $category) {
        $result = $simulatedResults[$category];
        $resultJson = htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT));
        
        echo <<<HTML
        <h3>Category: {$category}</h3>
        <pre>\$bridge->call('siteB', 'bridge/provider', 'getData', ['{$category}']);</pre>
        <h4>Response (simulated)</h4>
        <pre>{$resultJson}</pre>
HTML;
    }
    
    echo '</div>';
    
    echo <<<HTML
    <div class="card">
        <h2>Note</h2>
        <p>The responses above are <strong>simulated</strong>. When the bridge is fully implemented:</p>
        <ul>
            <li>Actual HTTP request will be made to siteB's <code>/__internal/bridge</code></li>
            <li>siteB runs in a separate process with its own autoloader</li>
            <li>No class conflicts even with different package versions</li>
        </ul>
    </div>
HTML;
    
    echo $footer;
};
