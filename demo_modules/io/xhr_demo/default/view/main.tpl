{# Xhr Demo Template #}
    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>XHR</strong> class provides standardized JSON API responses with built-in CORS support and consistent response formatting.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>XHR</code></td>
                <td><code>Razy</code></td>
                <td>JSON API response handler with CORS</td>
            </tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Basic Usage</h3>
            <pre>use Razy\XHR;

$xhr = new XHR();
$xhr->data(['users' => $users]);
$xhr->set('pagination', [
    'page' => 1,
    'total' => 100
]);
$xhr->send(true, 'Success');</pre>
        </div>
        
        <div class="card">
            <h3>Controller Shortcut</h3>
            <pre>// In controller method:
return $this->xhr()
    ->data(['users' => $users])
    ->allowOrigin('*')
    ->send(true, 'Users loaded');</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Live API Demo</h2>
        <p>Click the buttons below to test the API endpoints using XHR fetch:</p>
        <div style="margin: 16px 0;">
            <button class="btn" onclick="testGetUsers()">GET /api/users</button>
            <button class="btn btn-success" onclick="testSubmit()">POST /api/submit</button>
        </div>
        <div id="result" style="background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: var(--radius); font-family: monospace; white-space: pre-wrap; min-height: 100px;">// Click a button to see the API response</div>
    </div>
    
    <script>
    async function testGetUsers() {
        const resultEl = document.getElementById('result');
        resultEl.textContent = 'Loading...';
        try {
            const response = await fetch('{$module_url}/api/users');
            const data = await response.json();
            resultEl.textContent = JSON.stringify(data, null, 2);
        } catch (e) {
            resultEl.textContent = 'Error: ' + e.message;
        }
    }
    
    async function testSubmit() {
        const resultEl = document.getElementById('result');
        resultEl.textContent = 'Submitting...';
        try {
            const response = await fetch('{$module_url}/api/submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: 'Test User', email: 'test@example.com' })
            });
            const data = await response.json();
            resultEl.textContent = JSON.stringify(data, null, 2);
        } catch (e) {
            resultEl.textContent = 'Error: ' + e.message;
        }
    }
    </script>
    
    <div class="card">
        <h2>Response Format</h2>
        <pre>{
    "result": true,
    "hash": "abc123",
    "timestamp": 1699999999,
    "response": { ... your data ... },
    "message": "Success message",
    "params": { ... additional params ... }
}</pre>
    </div>
    
    <div class="card">
        <h2>Key Methods</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>data($response)</code></td><td>Set response data array</td></tr>
            <tr><td><code>set($key, $value)</code></td><td>Set additional parameter</td></tr>
            <tr><td><code>allowOrigin($origin)</code></td><td>Set CORS allowed origin</td></tr>
            <tr><td><code>corp($policy)</code></td><td>Set Cross-Origin Resource Policy</td></tr>
            <tr><td><code>send($result, $message)</code></td><td>Send JSON response and exit</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h2>CORS Constants</h2>
        <table>
            <tr><th>Constant</th><th>Value</th><th>Use Case</th></tr>
            <tr><td><code>XHR::CORP_SAME_ORIGIN</code></td><td>same-origin</td><td>Same domain only</td></tr>
            <tr><td><code>XHR::CORP_SAME_SITE</code></td><td>same-site</td><td>Same site (subdomains OK)</td></tr>
            <tr><td><code>XHR::CORP_CROSS_ORIGIN</code></td><td>cross-origin</td><td>Any origin allowed</td></tr>
        </table>
    </div>