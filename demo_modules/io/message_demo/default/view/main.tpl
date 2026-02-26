{# Message Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>SimplifiedMessage</strong> class implements a STOMP-like message protocol for structured communication over WebSockets, IPC, or message queues.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>SimplifiedMessage</code></td><td><code>Razy</code></td><td>STOMP-like message builder and parser</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Message Format</h2>
        <pre>COMMAND\r\n
header1:value1\r\n
header2:value2\r\n
\r\n
body content\0\r\n</pre>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Create Message</h3>
            <pre>use Razy\SimplifiedMessage;

$msg = new SimplifiedMessage('SEND');
$msg->setHeader('destination', '/queue/orders');
$msg->setHeader('content-type', 'application/json');
$msg->setBody(json_encode(['order_id' => 123]));

echo $msg->getMessage();</pre>
        </div>
        <div class="card">
            <h3>Parse Message</h3>
            <pre>$raw = "MESSAGE\r\nid:msg-001\r\n\r\nHello!\0\r\n";
$parsed = SimplifiedMessage::Fetch($raw);

echo $parsed->getCommand();  // MESSAGE
echo $parsed->getHeader('id');  // msg-001
echo $parsed->getBody();  // Hello!</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/basic',this)">Message Operations</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>STOMP Commands</h2>
        <table>
            <tr><th>Command</th><th>Direction</th><th>Description</th></tr>
            <tr><td><code>CONNECT</code></td><td>Client → Server</td><td>Establish connection</td></tr>
            <tr><td><code>CONNECTED</code></td><td>Server → Client</td><td>Connection acknowledged</td></tr>
            <tr><td><code>SEND</code></td><td>Client → Server</td><td>Send message to destination</td></tr>
            <tr><td><code>SUBSCRIBE</code></td><td>Client → Server</td><td>Subscribe to destination</td></tr>
            <tr><td><code>UNSUBSCRIBE</code></td><td>Client → Server</td><td>Unsubscribe from destination</td></tr>
            <tr><td><code>MESSAGE</code></td><td>Server → Client</td><td>Message from server</td></tr>
            <tr><td><code>ACK</code></td><td>Client → Server</td><td>Acknowledge message</td></tr>
            <tr><td><code>NACK</code></td><td>Client → Server</td><td>Negative acknowledge</td></tr>
            <tr><td><code>DISCONNECT</code></td><td>Client → Server</td><td>Close connection</td></tr>
            <tr><td><code>ERROR</code></td><td>Server → Client</td><td>Error response</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Key Methods</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>new SimplifiedMessage($cmd)</code></td><td>Create message with command</td></tr>
            <tr><td><code>setHeader($key, $val)</code></td><td>Set a header</td></tr>
            <tr><td><code>getHeader($key)</code></td><td>Get a header value</td></tr>
            <tr><td><code>setBody($body)</code></td><td>Set message body</td></tr>
            <tr><td><code>getBody()</code></td><td>Get message body</td></tr>
            <tr><td><code>getMessage()</code></td><td>Serialize to wire format</td></tr>
            <tr><td><code>SimplifiedMessage::Fetch($raw)</code></td><td>Parse raw message string</td></tr>
            <tr><td><code>SimplifiedMessage::Encode($str)</code></td><td>Encode special chars</td></tr>
            <tr><td><code>SimplifiedMessage::Decode($str)</code></td><td>Decode special chars</td></tr>
        </table>
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
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtResult(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:var(--danger)">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    function fmtResult(data){
        var h='';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var item=data[key];
            h+='<div class="card" style="margin-bottom:16px"><h3>'+esc(key)+'</h3>';
            if(item&&typeof item==='object'&&!Array.isArray(item)){
                if(item.description)h+='<p style="color:var(--text-muted);margin-bottom:8px">'+esc(String(item.description))+'</p>';
                for(var k in item){
                    if(!item.hasOwnProperty(k)||k==='description')continue;
                    var v=item[k];
                    if(typeof v==='string'&&(v.length>60||k==='raw_message'||k==='code'))
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(v)+'</pre></div>';
                    else if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code>'+esc(String(v))+'</code></div>';
                }
            }else if(typeof item==='string')
                h+='<p>'+esc(item)+'</p>';
            else h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>