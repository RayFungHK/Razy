{# Advanced Features Template #}
    <div class="card">
        <h2>Overview</h2>
        <p>This module demonstrates <strong>advanced Agent methods</strong> for module dependency management, API command binding, complex routing, and route proxying.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>Agent</code></td>
                <td><code>Razy</code></td>
                <td>Module lifecycle manager with routing and API</td>
            </tr>
            <tr>
                <td><code>Controller</code></td>
                <td><code>Razy</code></td>
                <td>Base controller with internal method binding</td>
            </tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>1. await() - Module Dependency</h3>
            <p>Execute callback after another module completes <code>__onLoad</code>.</p>
            <pre>$agent->await('system/helper_module', function() {
    // This runs AFTER helper_module loads
    $this->helperReady = true;
    
    // Safe to use helper's API
    $this->api('system/helper_module')
        ->addClient('advanced_features');
});</pre>
            <p><span class="tag tag-success">Use Case</span> Module-to-module setup dependencies</p>
        </div>
        
        <div class="card">
            <h3>2. addAPICommand() with '#' Prefix</h3>
            <p>Create public API + internal <code>$this->method()</code> binding.</p>
            <pre>// Creates BOTH:
// - Public: $this->api('module')->validateInput()
// - Internal: $this->validateInput()

$agent->addAPICommand('#validateInput', 'internal/validate');
$agent->addAPICommand('#formatOutput', 'internal/format');
$agent->addAPICommand('#logAction', 'internal/logger');

// Regular (external only)
$agent->addAPICommand('getStatus', 'api/status');</pre>
            <p><span class="tag">Use Case</span> Reusable logic as both API and internal method</p>
        </div>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>3. Complex addLazyRoute() with @self</h3>
            <p>Nested array structure for hierarchical routes.</p>
            <pre>$agent->addLazyRoute([
    'dashboard' => [
        '@self' => 'index',    // /dashboard
        'stats' => 'stats',    // /dashboard/stats
    ],
    'api' => [
        'users' => [
            '@self' => 'list', // /api/users
            '(:d)' => 'get',   // /api/users/123
        ],
    ],
]);</pre>
            <p><span class="tag tag-warning">@self</span> Handles the parent path itself</p>
        </div>
        
        <div class="card">
            <h3>4. addShadowRoute() - Route Proxy</h3>
            <p>Route in THIS module that proxies to ANOTHER module's closure.</p>
            <pre>// /advanced_features/helper
// ??system/helper_module :: shared/handler
$agent->addShadowRoute(
    'helper',
    'system/helper_module',
    'shared/handler'
);

// With route pattern proxy
$agent->addShadowRoute(
    'common',
    'system/helper_module',
    '/advanced_features/common'
);</pre>
            <p><span class="tag">Use Case</span> URL aliasing, route forwarding, module composition</p>
        </div>
    </div>
    
    <div class="card">
        <h2>Companion Module</h2>
        <p>This demo works with the <a href="../helper_module/"><code>helper_module</code></a> which serves as:</p>
        <ul>
            <li><strong>Target for await()</strong> - advanced_features waits for helper_module to load</li>
            <li><strong>Target for addShadowRoute()</strong> - advanced_features proxies routes to helper_module</li>
        </ul>
    </div>