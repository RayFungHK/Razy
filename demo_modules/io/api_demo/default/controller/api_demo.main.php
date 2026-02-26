<?php
/**
 * API Demo Main Page
 * 
 * @llm Overview of internal API calling features.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');
    
    $moduleUrl = rtrim($this->getModuleURL(), '/');
    
    $header = $this->api('demo/demo_index')->header('Internal API Demo', 'Cross-module API calling demonstration');
    $footer = $this->api('demo/demo_index')->footer();
    
    echo $header;
    echo <<<HTML
    <div class="card">
        <h2>What is Internal API?</h2>
        <p>Razy modules can expose API commands that other modules can call using <code>\$this->api()</code>.</p>
        
        <h4>Provider Module (api_provider)</h4>
        <pre>// In controller __onInit():
\$agent->addAPICommand('greet', 'api/greet.php');

// In api/greet.php:
return function (string \$name = 'Guest'): array {
    return ['message' => "Hello, {\$name}!"];
};</pre>
        
        <h4>Consumer Module (api_demo)</h4>
        <pre>// Call another module's API:
\$result = \$this->api('io/api_provider')->greet('John');
// Result: ['message' => 'Hello, John!']</pre>
    </div>
    
    <div class="card">
        <h2>Demo Pages</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <a href="{$moduleUrl}/greet" class="btn btn-primary">Greeting API</a>
            <a href="{$moduleUrl}/calculate" class="btn btn-secondary">Calculator API</a>
            <a href="{$moduleUrl}/user" class="btn btn-primary">User API</a>
            <a href="{$moduleUrl}/config" class="btn btn-secondary">Config API</a>
            <a href="{$moduleUrl}/transform" class="btn btn-primary">Transform API</a>
            <a href="{$moduleUrl}/chain" class="btn btn-secondary">Chained Calls</a>
        </div>
    </div>
    
    <div class="card">
        <h2>API Provider Commands</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Command</th>
                    <th>Description</th>
                    <th>Parameters</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>greet</code></td>
                    <td>Returns a greeting message</td>
                    <td>name (string), language (string)</td>
                </tr>
                <tr>
                    <td><code>calculate</code></td>
                    <td>Math operations</td>
                    <td>operation, a, b</td>
                </tr>
                <tr>
                    <td><code>user</code></td>
                    <td>User CRUD operations</td>
                    <td>action, id, data</td>
                </tr>
                <tr>
                    <td><code>config</code></td>
                    <td>Get configuration</td>
                    <td>section</td>
                </tr>
                <tr>
                    <td><code>transform</code></td>
                    <td>String transformations</td>
                    <td>type, input</td>
                </tr>
            </tbody>
        </table>
    </div>
HTML;
    echo $footer;
};
