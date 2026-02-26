{# Helper Module Template #}
    <div class="card">
        <h2>Overview</h2>
        <p>This <strong>Helper Module</strong> is a companion for the <a href="../advanced_features/">Advanced Features Demo</a>. It serves as the target for <code>await()</code> and <code>addShadowRoute()</code> demonstrations.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>Agent</code></td>
                <td><code>Razy</code></td>
                <td>Module lifecycle and API commands</td>
            </tr>
            <tr>
                <td><code>Controller</code></td>
                <td><code>Razy</code></td>
                <td>Base controller class</td>
            </tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Target for await()</h3>
            <p>The <code>advanced_features</code> module uses:</p>
            <pre>$agent->await('system/helper_module', function() {
    // Executes after this module's
    // __onLoad() completes
    $this->helperReady = true;
});</pre>
            <p>This ensures dependent code runs only after the helper is ready.</p>
        </div>
        
        <div class="card">
            <h3>Target for addShadowRoute()</h3>
            <p>Shadow routes proxy to this module:</p>
            <pre>// In advanced_features:
$agent->addShadowRoute(
    'helper',
    'system/helper_module',
    'shared/handler'
);

// URL: /advanced_features/helper
// Executes: helper_module::shared/handler</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Provided API Commands</h2>
        <table>
            <tr><th>Command</th><th>Handler</th><th>Description</th></tr>
            <tr>
                <td><code>registerClient</code></td>
                <td><code>api/register-client</code></td>
                <td>Register a client module</td>
            </tr>
            <tr>
                <td><code>getClients</code></td>
                <td><code>api/get-clients</code></td>
                <td>Get registered clients list</td>
            </tr>
            <tr>
                <td><code>isReady</code></td>
                <td><code>api/is-ready</code></td>
                <td>Check if module is ready</td>
            </tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Related Demo</h2>
        <p>Visit the <a href="../advanced_features/" class="btn">Advanced Features Demo</a> to see how this module is used.</p>
    </div>