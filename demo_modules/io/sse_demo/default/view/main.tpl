{# Sse Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>SSE</strong> class enables Server-Sent Events for real-time, server-to-client streaming over HTTP.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>SSE</code></td><td><code>Razy</code></td><td>Server-Sent Events handler</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Server-Side Example</h3>
            <pre>use Razy\SSE;

$sse = new SSE(3000);  // retry ms
$sse->start();

for ($i = 0; $i < 10; $i++) {
    $sse->send(
        "Count: $i",    // data
        'update',        // event type
        "msg-$i"        // event ID
    );
    sleep(1);
}</pre>
        </div>
        <div class="card">
            <h3>Client-Side JavaScript</h3>
            <pre>const source = new EventSource('/api/stream');

source.addEventListener('update', (e) => {
    console.log('Data:', e.data);
    console.log('ID:', e.lastEventId);
});

source.onerror = (e) => {
    console.error('Connection lost');
    source.close();
};</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Live Demo — Code Examples</h2>
        <p>Click to load SSE code examples via XHR:</p>
        <div style="margin-top:12px">
            <button class="btn btn-success demo-btn" onclick="loadDemo('{$module_url}/stream',this)">Load SSE Examples</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>SSE Class Methods</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>__construct($retry)</code></td><td>Set retry interval in milliseconds</td></tr>
            <tr><td><code>start()</code></td><td>Send SSE headers and begin stream</td></tr>
            <tr><td><code>send($data, $event, $id)</code></td><td>Send data with optional event type and ID</td></tr>
            <tr><td><code>comment($text)</code></td><td>Send comment (heartbeat)</td></tr>
            <tr><td><code>proxy($url, ...)</code></td><td>Proxy another SSE endpoint</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Use Cases</h2>
        <ul>
            <li>Live notifications and alerts</li>
            <li>Real-time dashboard updates</li>
            <li>Progress tracking for long operations</li>
            <li>Chat message streaming</li>
            <li>AI response streaming (proxy mode)</li>
        </ul>
    </div>

    <script>
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function loadDemo(url,btn){
        var c=document.getElementById('demo-result');
        c.style.display='block';
        c.innerHTML='<p style="color:var(--text-muted)">Loading...</p>';
        document.querySelectorAll('.demo-btn').forEach(function(b){b.classList.remove('active');});
        if(btn)btn.classList.add('active');
        var x=new XMLHttpRequest();
        x.open('GET',url);
        x.onload=function(){
            if(x.status===200){
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtSSE(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:var(--danger)">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    function fmtSSE(data){
        var h='';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var item=data[key];
            h+='<div class="card" style="margin-bottom:16px"><h3>'+esc(key)+'</h3>';
            if(item&&typeof item==='object'){
                if(item.description)h+='<p style="color:var(--text-muted);margin-bottom:8px">'+esc(String(item.description))+'</p>';
                if(item.code)h+='<pre>'+esc(item.code)+'</pre>';
            }else h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>