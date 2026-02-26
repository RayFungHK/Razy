<?php

/**
 * Service Info Demo
 * 
 * Shows information about the markdown service being used.
 * 
 * URL: /demo/markdown_consumer/info
 */

return function (): void {
    // Get service info via API
    $info = $this->api('markdown')->getInfo();
    
    $infoJson = json_encode($info, JSON_PRETTY_PRINT);
    
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Service Info - Markdown Consumer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 20px; border-radius: 8px; overflow-x: auto; }
        h1 { border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
        .card { background: #f9f9f9; border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin: 20px 0; }
        .card h3 { margin-top: 0; color: #0066cc; }
        .features { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
        .feature { background: white; border: 1px solid #ddd; padding: 10px; border-radius: 5px; text-align: center; }
        .feature.enabled { border-color: #22c55e; background: #f0fdf4; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #ddd; }
        th { background: #f4f4f4; }
    </style>
</head>
<body>
    <h1>🔍 Markdown Service Info</h1>
    
    <div class="card">
        <h3>Service Details</h3>
        <table>
            <tr><th>Service Name</th><td>{$info['service']}</td></tr>
            <tr><th>Library</th><td>{$info['library']}</td></tr>
            <tr><th>Library Version</th><td>{$info['library_version']}</td></tr>
            <tr><th>Description</th><td>{$info['description']}</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h3>Supported Features</h3>
        <div class="features">
HTML;

    foreach ($info['features'] as $feature => $enabled) {
        $class = $enabled ? 'enabled' : '';
        $icon = $enabled ? '✅' : '❌';
        echo "<div class=\"feature {$class}\">{$icon} {$feature}</div>";
    }

    echo <<<HTML
        </div>
    </div>
    
    <div class="card">
        <h3>API Methods</h3>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
HTML;

    foreach ($info['api_methods'] as $method => $description) {
        echo "<tr><td><code>{$method}</code></td><td>{$description}</td></tr>";
    }

    echo <<<HTML
        </table>
    </div>
    
    <div class="card">
        <h3>Version Conflict Solution</h3>
        <table>
            <tr><th>Pattern</th><td>{$info['version_conflict_solution']['pattern']}</td></tr>
            <tr><th>Description</th><td>{$info['version_conflict_solution']['description']}</td></tr>
            <tr><th>Usage</th><td><code>{$info['version_conflict_solution']['usage']}</code></td></tr>
        </table>
    </div>
    
    <div class="card">
        <h3>Raw JSON Response</h3>
        <pre>{$infoJson}</pre>
    </div>
</body>
</html>
HTML;
};
