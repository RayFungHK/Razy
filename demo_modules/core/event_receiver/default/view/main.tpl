{# Event Receiver Template - Event Listener Overview #}
    <div class="card">
        <h2>Overview</h2>
        <p>This module <strong>listens to events</strong> fired by the <code>event_demo</code> module.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr>
                <td><code>Agent</code></td>
                <td><code>Razy</code></td>
                <td>Module controller that provides listen() method for event subscription</td>
            </tr>
            <tr>
                <td><code>Application</code></td>
                <td><code>Razy</code></td>
                <td>Event bus that dispatches events to registered listeners</td>
            </tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Registered Listeners</h2>
        
        <div style="background: #dcfce7; padding: 16px; margin: 12px 0; border-radius: var(--radius); border-left: 4px solid var(--success);">
            <h3 style="margin-top: 0; color: #166534;">event_demo:user_registered</h3>
            <p>Handles new user registrations by queueing welcome emails.</p>
            <p><strong>Handler:</strong> <span class="tag tag-success">Inline Closure</span></p>
        </div>
        
        <div style="background: #dcfce7; padding: 16px; margin: 12px 0; border-radius: var(--radius); border-left: 4px solid var(--success);">
            <h3 style="margin-top: 0; color: #166534;">event_demo:order_placed</h3>
            <p>Processes new orders by updating inventory.</p>
            <p><strong>Handler:</strong> <span class="tag">File-based</span> <code>events/order_placed.php</code></p>
        </div>
        
        <div style="background: #dcfce7; padding: 16px; margin: 12px 0; border-radius: var(--radius); border-left: 4px solid var(--success);">
            <h3 style="margin-top: 0; color: #166534;">event_demo:data_changed</h3>
            <p>Records all data changes in the audit log.</p>
            <p><strong>Handler:</strong> <span class="tag tag-success">Inline Closure</span></p>
        </div>
    </div>
    
    <div class="card">
        <h2>Test the Events</h2>
        <p>Visit the Event Demo Module to fire events and see this module's responses.</p>
        <a href="../event_demo/" class="btn">Go to Event Demo</a>
    </div>
