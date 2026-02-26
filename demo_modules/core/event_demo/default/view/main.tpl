{# Event Demo Template - Event System Overview #}
    <div class="card">
        <h2>Overview</h2>
        <p>This module demonstrates the <strong>Event System</strong> in Razy Framework.</p>
        <p>Events enable <em>decoupled communication</em> between modules.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>Agent</code></td>
                <td><code>Razy</code></td>
                <td>Module agent providing trigger() method to fire events</td>
            </tr>
            <tr>
                <td><code>Emitter</code></td>
                <td><code>Razy</code></td>
                <td>Event emitter returned by trigger(), handles resolve() and getAllResponse()</td>
            </tr>
            <tr>
                <td><code>Application</code></td>
                <td><code>Razy</code></td>
                <td>Event bus that dispatches events to registered listeners</td>
            </tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>1. Fire Events (This Module)</h3>
            <pre>$emitter = $this->trigger('user_registered');
$emitter->resolve($userData);
$responses = $emitter->getAllResponse();</pre>
        </div>
        
        <div class="card">
            <h3>2. Listen to Events (Other Modules)</h3>
            <pre>$agent->listen('core/event_demo:user_registered', 
    function($userData) {
        return ['handled' => true];
    }
);</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demo Events</h2>
        <table>
            <tr><th>Event</th><th>Description</th><th>Test</th></tr>
            <tr>
                <td><code>core/event_demo:user_registered</code></td>
                <td>Fired when a user registers</td>
                <td><button class="btn" onclick="testEvent('register', {name:'John',email:'john@example.com'})">Test Registration</button></td>
            </tr>
            <tr>
                <td><code>core/event_demo:order_placed</code></td>
                <td>Fired when an order is placed</td>
                <td><button class="btn" onclick="testEvent('order', {product:'Widget',quantity:5})">Test Order</button></td>
            </tr>
            <tr>
                <td><code>core/event_demo:data_changed</code></td>
                <td>Fired when data is modified</td>
                <td><button class="btn" onclick="testEvent('update', {entity:'user',id:1})">Test Update</button></td>
            </tr>
        </table>
        <div id="result" style="background: #1e293b; color: #e2e8f0; padding: 16px; border-radius: var(--radius); font-family: monospace; white-space: pre-wrap; min-height: 100px; margin-top: 16px;">// Click a button to test an event and see listener responses</div>
    </div>
    
    <script>
    async function testEvent(action, params) {
        const resultEl = document.getElementById('result');
        resultEl.textContent = 'Firing event...';
        try {
            const url = '{$module_url}/' + action + '?' + new URLSearchParams(params);
            const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await response.json();
            resultEl.textContent = JSON.stringify(data, null, 2);
        } catch (e) {
            resultEl.textContent = 'Error: ' + e.message;
        }
    }
    </script>
    
    <div class="card">
        <h2>Event Listener Module</h2>
        <p>Check the <a href="/event_receiver/"><code>core/event_receiver</code></a> module to see how events are listened to.</p>
    </div>
