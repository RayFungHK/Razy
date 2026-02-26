{# Yaml Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>YAML</strong> class provides native YAML 1.2 parsing and dumping without external PHP extensions or dependencies.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>YAML</code></td><td><code>Razy</code></td><td>YAML 1.2 parser and dumper</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Parse YAML</h3>
            <pre>use Razy\YAML;

// Parse YAML string
$data = YAML::parse('
name: John
age: 30
tags:
  - php
  - razy
');

// Parse YAML file
$config = YAML::parseFile('/path/to/config.yaml');</pre>
        </div>
        <div class="card">
            <h3>Dump to YAML</h3>
            <pre>use Razy\YAML;

$data = [
    'name' => 'John',
    'age' => 30,
    'tags' => ['php', 'razy']
];

// Dump to string
$yaml = YAML::dump($data, 2, 3);

// Dump to file
YAML::dumpFile('output.yaml', $data);</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-2">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/parse',this)">Parse YAML</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/dump',this)">Dump to YAML</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Async XHR Usage Pattern</h2>
        <p>Load YAML demo results asynchronously using XHR:</p>
        <pre>// Async fetch demo results
var xhr = new XMLHttpRequest();
xhr.open('GET', '/yaml_demo/parse');
xhr.onload = function() {
    var data = JSON.parse(xhr.responseText);
    // Each key contains: yaml (source), parsed (result), description
    for (var key in data) {
        console.log(key, data[key].parsed);
    }
};
xhr.send();</pre>
    </div>
    
    <div class="card">
        <h2>Supported YAML Features</h2>
        <div class="grid grid-2">
            <div>
                <h3>Data Structures</h3>
                <ul>
                    <li>Key-value mappings</li>
                    <li>Lists/sequences</li>
                    <li>Nested structures</li>
                    <li>Comments (#)</li>
                </ul>
            </div>
            <div>
                <h3>Advanced Features</h3>
                <ul>
                    <li>Multi-line: literal <code>|</code> and folded <code>&gt;</code></li>
                    <li>Inline flow: <code>[array]</code> and <code>{object}</code></li>
                    <li>Anchors <code>&amp;</code> and aliases <code>*</code></li>
                    <li>Type casting (int, float, bool, null)</li>
                </ul>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>API Reference</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>YAML::parse($yaml)</code></td><td>Parse YAML string to PHP array</td></tr>
            <tr><td><code>YAML::parseFile($path)</code></td><td>Parse YAML file to PHP array</td></tr>
            <tr><td><code>YAML::dump($data, $indent, $inline)</code></td><td>Dump PHP array to YAML string</td></tr>
            <tr><td><code>YAML::dumpFile($path, $data)</code></td><td>Dump PHP array to YAML file</td></tr>
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
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtYAML(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:var(--danger)">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    function fmtYAML(data){
        var h='';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var item=data[key];
            h+='<div class="card" style="margin-bottom:16px"><h3>'+esc(key)+'</h3>';
            if(item&&typeof item==='object'&&!Array.isArray(item)){
                if(item.description)h+='<p style="color:var(--text-muted);margin-bottom:8px">'+esc(String(item.description))+'</p>';
                if(item.yaml)h+='<div style="margin:8px 0"><strong>YAML Source:</strong><pre>'+esc(item.yaml)+'</pre></div>';
                if(item.code)h+='<div style="margin:8px 0"><strong>Sample Code:</strong><pre>'+esc(item.code)+'</pre></div>';
                for(var k in item){
                    if(!item.hasOwnProperty(k)||k==='description'||k==='yaml'||k==='code')continue;
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