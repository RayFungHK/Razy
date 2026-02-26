<?php
/**
 * Bridge Demo - getConfig API
 * 
 * @llm Demonstrates calling siteB's getConfig API via bridge.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('getConfig API', 'Get distributor config from siteB');
    $footer = $this->api('demo/demo_index')->footer();
    
    // Simulated bridge calls to different siteB tags
    $simulatedResults = [
        'siteB' => [
            'success' => true,
            'distributor' => [
                'code' => 'siteB',
                'tag' => 'default',
                'identifier' => 'siteB@default',
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
        ],
        'siteB@1.0.0' => [
            'success' => true,
            'distributor' => [
                'code' => 'siteB',
                'tag' => '1.0.0',
                'identifier' => 'siteB@1.0.0',
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
        ],
    ];
    
    echo $header;
    echo '<div class="card"><h2>Calling siteB getConfig API</h2>';
    echo '<p>Notice how different tags return different <code>identifier</code> values:</p>';
    
    foreach ($simulatedResults as $target => $result) {
        $resultJson = htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT));
        
        echo <<<HTML
        <h3>Target: {$target}</h3>
        <pre>\$bridge->call('{$target}', 'bridge/provider', 'getConfig');</pre>
        <h4>Response (simulated)</h4>
        <pre>{$resultJson}</pre>
HTML;
    }
    
    echo '</div>';
    
    echo <<<HTML
    <div class="card">
        <h2>Why This Matters</h2>
        <p>Each target (<code>siteB</code> vs <code>siteB@1.0.0</code>) can have:</p>
        <ul>
            <li><strong>Different Composer packages</strong> - v1 vs v2 of the same library</li>
            <li><strong>Different configurations</strong> - dev vs prod settings</li>
            <li><strong>Complete isolation</strong> - no shared state or class conflicts</li>
        </ul>
    </div>
HTML;
    
    echo $footer;
};
