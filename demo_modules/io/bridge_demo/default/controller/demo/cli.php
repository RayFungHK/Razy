<?php
/**
 * Bridge Demo - CLI Examples
 * 
 * @llm Shows how to use CLI bridge for non-web distributors.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $header = $this->api('demo/demo_index')->header('CLI Bridge', 'For non-web distributors');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    echo <<<HTML
    <div class="card">
        <h2>Why CLI Bridge?</h2>
        <p>Some distributors don't have web endpoints (CLI-only tools). The CLI bridge provides:</p>
        <ul>
            <li><strong>Complete isolation</strong> - Subprocess with separate autoloader</li>
            <li><strong>No HTTP overhead</strong> - Direct process execution</li>
            <li><strong>Works everywhere</strong> - No web server required</li>
        </ul>
    </div>
    
    <div class="card">
        <h2>CLI Bridge Command</h2>
        <pre>php Razy.phar bridge &lt;target&gt; &lt;module&gt; &lt;command&gt; [args_json]</pre>
        
        <h3>Examples</h3>
        
        <h4>Get Data</h4>
        <pre># Call siteB@default
php Razy.phar bridge siteB bridge/provider getData '["products"]'

# Call siteB@1.0.0
php Razy.phar bridge siteB@1.0.0 bridge/provider getData '["users"]'</pre>
        
        <h4>Get Config</h4>
        <pre>php Razy.phar bridge siteB bridge/provider getConfig</pre>
        
        <h4>Calculate</h4>
        <pre>php Razy.phar bridge siteB bridge/provider calculate '["multiply", 15, 8]'</pre>
    </div>
    
    <div class="card">
        <h2>Output Format</h2>
        <p>CLI bridge returns JSON to stdout:</p>
        <pre>$ php Razy.phar bridge siteB bridge/provider calculate '["add", 10, 20]'
{
    "success": true,
    "source": "siteB@default",
    "operation": "add",
    "operands": {"a": 10, "b": 20},
    "result": 30,
    "error": null,
    "timestamp": "2026-02-10 15:30:00"
}</pre>
    </div>
    
    <div class="card">
        <h2>Error Handling</h2>
        <pre>$ php Razy.phar bridge siteB unknown/module getData
{
    "success": false,
    "error": "Module not found: unknown/module",
    "code": "MODULE_NOT_FOUND"
}

$ php Razy.phar bridge siteB bridge/provider unknownCommand
{
    "success": false,
    "error": "API command not found: unknownCommand",
    "code": "COMMAND_NOT_FOUND"
}

$ php Razy.phar bridge siteC bridge/provider getData
{
    "success": false,
    "error": "Caller 'testsite@default' not in allowlist",
    "code": "ACCESS_DENIED"
}</pre>
    </div>
    
    <div class="card">
        <h2>Using in PHP Code</h2>
        <pre>// For non-web targets, bridge automatically uses CLI
\$bridge = \$this->getBridge();

// This spawns: php Razy.phar bridge siteB bridge/provider getData '["products"]'
\$result = \$bridge->call('siteB', 'bridge/provider', 'getData', ['products']);

// Parse JSON response
if (\$result['success']) {
    \$products = \$result['data'];
}</pre>
    </div>
    
    <div class="card">
        <h2>Bridge Detection</h2>
        <p>The bridge client automatically chooses the transport:</p>
        <table>
            <tr>
                <th>Target Has</th>
                <th>Transport Used</th>
            </tr>
            <tr>
                <td>Web endpoint (domain mapped)</td>
                <td>HTTP POST to <code>/__internal/bridge</code></td>
            </tr>
            <tr>
                <td>No web endpoint (CLI only)</td>
                <td>CLI subprocess</td>
            </tr>
        </table>
    </div>
HTML;
    
    echo $footer;
};
