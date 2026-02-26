<?php
/**
 * Bridge Demo - Main Page
 * 
 * @llm Overview of cross-distributor communication.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $moduleUrl = rtrim($this->getModuleURL(), '/');
    
    $header = $this->api('demo/demo_index')->header('Cross-Distributor Bridge', 'Communication across isolated distributors');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    echo <<<HTML
    <div class="card">
        <h2>Why Cross-Distributor Communication?</h2>
        <p>In PHP, once a class is loaded, it cannot be redeclared. This causes problems when:</p>
        <ul>
            <li><strong>Different versions</strong>: siteA uses <code>vendor/package v1.0</code>, siteB uses <code>vendor/package v2.0</code></li>
            <li><strong>Same process</strong>: Both distributors run in the same PHP process</li>
            <li><strong>Class conflict</strong>: First loaded wins, causing version mismatch or fatal errors</li>
        </ul>
    </div>
    
    <div class="card">
        <h2>The Solution: Internal Bridge</h2>
        <p>Instead of in-process API calls, use HTTP or CLI bridge for complete isolation:</p>
        
        <table>
            <tr>
                <th>Method</th>
                <th>Use Case</th>
                <th>How It Works</th>
            </tr>
            <tr>
                <td><strong>HTTP Bridge</strong></td>
                <td>Web mode distributors</td>
                <td>POST to <code>/__internal/bridge</code> endpoint</td>
            </tr>
            <tr>
                <td><strong>CLI Bridge</strong></td>
                <td>Non-web distributors</td>
                <td>Subprocess: <code>php Razy.phar bridge target module command args</code></td>
            </tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Distributor Identification</h2>
        <p>Distributors are identified by <code>code@tag</code> format:</p>
        <pre>siteB           // Shorthand for siteB@default
siteB@default   // Explicit default tag
siteB@dev       // Dev tag (different packages)
siteB@1.0.0     // Version as tag
siteB@*         // Wildcard (any tag)</pre>
    </div>
    
    <div class="card">
        <h2>Configuration</h2>
        <h4>Target Distributor (siteB/dist.php)</h4>
        <pre>return [
    'dist' => 'siteB',
    'internal_bridge' => [
        'enabled' => true,
        'allow' => [
            'testsite' => true,       // Allow testsite@default
            'testsite@*' => true,     // Allow testsite with any tag
        ],
        'secret' => 'shared-secret',
        'path' => '/__internal/bridge',
    ],
];</pre>
    </div>
    
    <div class="card">
        <h2>Demo Pages</h2>
        <p>These demos show the bridge API (currently using simulated responses since bridge is planned):</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <a href="{$moduleUrl}/data" class="btn btn-primary">getData API</a>
            <a href="{$moduleUrl}/config" class="btn btn-secondary">getConfig API</a>
            <a href="{$moduleUrl}/calculate" class="btn btn-primary">calculate API</a>
            <a href="{$moduleUrl}/cli" class="btn btn-secondary">CLI Examples</a>
        </div>
    </div>
    
    <div class="card">
        <h2>API Usage (Planned)</h2>
        <pre>// Get the bridge client
\$bridge = \$this->getBridge();

// Call siteB's API (HTTP bridge)
\$result = \$bridge->call('siteB', 'bridge/provider', 'getData', ['products']);

// Call specific version
\$result = \$bridge->call('siteB@1.0.0', 'bridge/provider', 'calculate', ['add', 10, 20]);

// The call is isolated - no class conflicts!</pre>
    </div>
HTML;
    echo $footer;
};
