{# Hashmap Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>HashMap</strong> class provides a hash-based key-value storage that supports using <em>objects as keys</em>, unlike PHP's native arrays.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>HashMap</code></td><td><code>Razy</code></td><td>Hash-based key-value storage with object key support</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Basic Usage</h3>
            <pre>use Razy\HashMap;

$map = new HashMap();

// Push with custom key
$map->push('key1', 'value1');

// Push auto-generated key
$key = $map->push(null, 'value2');

// Check and retrieve
if ($map->has('key1')) {
    $value = $map->get('key1');
}</pre>
        </div>
        <div class="card">
            <h3>Object as Key</h3>
            <pre>$obj = new stdClass();
$obj->id = 1;

// Use object as key
$map->push($obj, 'associated data');

// Retrieve by same object
$data = $map->get($obj);</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/basic',this)">Basic Operations</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/objects',this)">Object Keys</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/iteration',this)">Iteration</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Key Prefixes</h2>
        <p>HashMap uses prefixes to identify key types:</p>
        <table>
            <tr><th>Prefix</th><th>Type</th><th>Description</th></tr>
            <tr><td><code>c:</code></td><td>Custom</td><td>User-provided string key</td></tr>
            <tr><td><code>o:</code></td><td>Object</td><td>Object hash (spl_object_hash)</td></tr>
            <tr><td><code>i:</code></td><td>Internal</td><td>Auto-generated integer key</td></tr>
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
                    if(typeof v==='string'&&v.length>60)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(v)+'</pre></div>';
                    else if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code>'+esc(String(v))+'</code></div>';
                }
            }else h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>