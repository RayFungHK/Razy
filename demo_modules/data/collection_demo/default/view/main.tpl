{# Collection Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>Collection</strong> class extends PHP's <code>ArrayObject</code> with powerful filter syntax and processor plugins for data manipulation.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>Collection</code></td><td><code>Razy</code></td><td>Extended ArrayObject with filter syntax via <code>__invoke()</code></td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Filter Syntax</h3>
            <pre>$collection('key')                 // Single key
$collection('*')                   // All elements
$collection('path.to.key')         // Nested path
$collection('key:istype(string)')  // With filter
$collection('a, b, c')             // Multiple selectors</pre>
        </div>
        <div class="card">
            <h3>Quick Example</h3>
            <pre>use Razy\Collection;

$data = new Collection([
    'name' => 'John',
    'age' => 30,
    'tags' => ['php', 'razy']
]);

// Filter access
$name = $data('name');
$allStrings = $data('*:istype(string)');</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/basic',this)">Basic Operations</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/filter',this)">Filter Syntax</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/processor',this)">Processors</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Built-in Plugins</h2>
        <div class="grid grid-2">
            <div>
                <h3>Filters</h3>
                <table>
                    <tr><th>Filter</th><th>Description</th></tr>
                    <tr><td><code>:istype(type)</code></td><td>Filter by PHP type</td></tr>
                </table>
            </div>
            <div>
                <h3>Processors</h3>
                <table>
                    <tr><th>Processor</th><th>Description</th></tr>
                    <tr><td><code>trim()</code></td><td>Trim whitespace</td></tr>
                    <tr><td><code>int()</code></td><td>Convert to integer</td></tr>
                    <tr><td><code>float()</code></td><td>Convert to float</td></tr>
                </table>
            </div>
        </div>
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