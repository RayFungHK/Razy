{# Plugin Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>Razy's <strong>Plugin System</strong> provides modular extension points across four subsystems: Template, Collection, FlowManager, and Statement.</p>
        <p>All plugin classes use <code>PluginTrait</code> with <code>AddPluginFolder()</code> and <code>GetPlugin()</code> methods.</p>
    </div>
    
    <div class="card">
        <h2>Plugin Systems</h2>
        <table>
            <tr><th>System</th><th>Plugin Folder</th><th>Types</th></tr>
            <tr><td>Template</td><td><code>src/plugins/Template/</code></td><td>function.NAME.php, modifier.NAME.php</td></tr>
            <tr><td>Collection</td><td><code>src/plugins/Collection/</code></td><td>filter.NAME.php, processor.NAME.php</td></tr>
            <tr><td>FlowManager</td><td><code>src/plugins/FlowManager/</code></td><td>NAME.php</td></tr>
            <tr><td>Statement</td><td><code>src/plugins/Statement/</code></td><td>NAME.php</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Template – Function Plugin</h3>
            <pre>// function.greeting.php
use Razy\Template\Plugin\InlineFunction;

return function($controller) {
    return new class extends InlineFunction {
        public function render(string $param): string
        {
            $p = $this->parseParameter($param);
            $name = $p['name'] ?? 'World';
            return "Hello, &#123;$name}!";
        }
    };
};

// Usage: {greeting name="Alice"}</pre>
        </div>
        <div class="card">
            <h3>Template – Modifier Plugin</h3>
            <pre>// modifier.slug.php
return function($value) {
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    return preg_replace('/-+/', '-', $slug);
};

// Usage: &#123;$title|slug}</pre>
        </div>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Collection – Filter Plugin</h3>
            <pre>// filter.notempty.php
return function ($value) {
    return !empty($value);
};

// Usage: $collection('*:notempty')</pre>
        </div>
        <div class="card">
            <h3>Collection – Processor Plugin</h3>
            <pre>// processor.uppercase.php
return function ($value) {
    return is_string($value)
        ? strtoupper($value)
        : $value;
};

// Usage: $collection('*')->uppercase()</pre>
        </div>
    </div>
    
    <div class="card">
        <h3>Registering Plugin Folders</h3>
        <pre>use Razy\Template;
use Razy\Collection;

// In your module controller __onInit():
Template::AddPluginFolder(__DIR__ . '/plugins/Template');
Collection::AddPluginFolder(__DIR__ . '/plugins/Collection');</pre>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/overview',this)">Plugin Architecture</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/template',this)">Template Plugins</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/collection',this)">Collection Plugins</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Built-in Template Modifiers</h2>
        <table>
            <tr><th>Modifier</th><th>Usage</th><th>Description</th></tr>
            <tr><td><code>upper</code></td><td><code>&#123;$name|upper}</code></td><td>Uppercase</td></tr>
            <tr><td><code>lower</code></td><td><code>&#123;$name|lower}</code></td><td>Lowercase</td></tr>
            <tr><td><code>escape</code></td><td><code>&#123;$html|escape}</code></td><td>HTML escape</td></tr>
            <tr><td><code>json</code></td><td><code>&#123;$data|json}</code></td><td>JSON encode</td></tr>
            <tr><td><code>default</code></td><td><code>&#123;$val|default:"N/A"}</code></td><td>Default value</td></tr>
            <tr><td><code>count</code></td><td><code>&#123;$arr|count}</code></td><td>Array count</td></tr>
            <tr><td><code>join</code></td><td><code>&#123;$arr|join:","}</code></td><td>Join array</td></tr>
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
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtPlugin(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:var(--danger)">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    function fmtPlugin(data){
        var h='';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var item=data[key];
            h+='<div class="card" style="margin-bottom:16px"><h3>'+esc(key.replace(/_/g,' '))+'</h3>';
            if(item&&typeof item==='object'&&!Array.isArray(item)){
                if(item.description)h+='<p style="color:var(--text-muted);margin-bottom:8px">'+esc(String(item.description))+'</p>';
                if(item.code)h+='<div style="margin:8px 0"><strong>Code:</strong><pre>'+esc(item.code)+'</pre></div>';
                if(item.file)h+='<div style="margin:4px 0"><strong>File:</strong> <code>'+esc(item.file)+'</code></div>';
                if(item.usage)h+='<div style="margin:4px 0"><strong>Usage:</strong> <code>'+esc(item.usage)+'</code></div>';
                if(item.output)h+='<div style="margin:4px 0"><strong>Output:</strong> <code>'+esc(item.output)+'</code></div>';
                for(var k in item){
                    if(!item.hasOwnProperty(k)||['description','code','file','usage','output'].indexOf(k)>=0)continue;
                    var v=item[k];
                    if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else if(typeof v==='string'&&v.length>60)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(v)+'</pre></div>';
                    else if(v!==null)
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code>'+esc(String(v))+'</code></div>';
                }
            }else if(Array.isArray(item)){
                h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            }else h+='<p>'+esc(String(item))+'</p>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>