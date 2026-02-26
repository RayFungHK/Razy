{# Template Engine Demo - Main Page #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>Template Engine</strong> is Razy's built-in view system for rendering dynamic HTML. It provides variable tags, modifiers, function tags, and a powerful block system — all parsed at runtime with a clean, designer-friendly syntax.</p>
        <p>Templates are loaded via <code>$this->loadTemplate('name')</code> in controllers and rendered with <code>$source->output()</code>.</p>
    </div>

    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>Template</code></td><td><code>Razy</code></td><td>Engine manager: loads sources, plugins, global params, queued output</td></tr>
            <tr><td><code>Source</code></td><td><code>Razy\Template</code></td><td>Represents one loaded .tpl file with its own parameters</td></tr>
            <tr><td><code>Block</code></td><td><code>Razy\Template</code></td><td>Parsed block definition (START, WRAPPER, TEMPLATE, RECURSION)</td></tr>
            <tr><td><code>Entity</code></td><td><code>Razy\Template</code></td><td>Runtime instance of a Block — holds data, renders content</td></tr>
        </table>
    </div>

    <div class="grid grid-2">
        <div class="card">
            <h3>Template Syntax</h3>
            <pre>{# Variable tags #}
{$variable}
{$path.to.nested.value}
{$name|upper}
{$url|"#"}

{# Function tags #}
{@if $active}class="active"{/if}
{@each source=$items as="item"}...{/each}
{@def "greeting" "Hello World"}
{@template:CardLayout title=$name}</pre>
        </div>
        <div class="card">
            <h3>PHP Controller Usage</h3>
            <pre>// Load template from view/ folder
$source = $this->loadTemplate('main');

// Assign variables
$source->assign(['title' => 'Hello']);

// Dynamic blocks via Entity API
$root = $source->getRoot();
$block = $root->newBlock('item');
$block->assign(['name' => 'Test']);

// Render output
echo $source->output();</pre>
        </div>
    </div>

    <div class="card">
        <h2>Parameter Resolution Chain</h2>
        <p>When a <code>{$variable}</code> tag is rendered, the engine resolves values through a hierarchical chain:</p>
        <pre>Entity (block instance)
  ↓ not found
Block (parsed definition)
  ↓ not found
Source (loaded .tpl file)
  ↓ not found
Template (engine manager / global params)</pre>
        <p>This means you can set global defaults at the Source level and override them per-block at the Entity level.</p>
    </div>

    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/variables',this)">Variables &amp; Modifiers</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/blocks',this)">Block System</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/functions',this)">Function Tags</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/entity',this)">Entity API</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/advanced',this)">Advanced Patterns</button>
        </div>
    </div>

    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>

    <div class="card">
        <h2>Built-in Modifiers</h2>
        <table>
            <tr><th>Modifier</th><th>Syntax</th><th>Description</th></tr>
            <tr><td><code>upper</code></td><td><code>{$var|upper}</code></td><td>Convert to UPPERCASE</td></tr>
            <tr><td><code>lower</code></td><td><code>{$var|lower}</code></td><td>Convert to lowercase</td></tr>
            <tr><td><code>capitalize</code></td><td><code>{$var|capitalize}</code></td><td>Capitalize Each Word</td></tr>
            <tr><td><code>trim</code></td><td><code>{$var|trim}</code></td><td>Trim whitespace</td></tr>
            <tr><td><code>join</code></td><td><code>{$arr|join:", "}</code></td><td>Join array with separator</td></tr>
            <tr><td><code>nl2br</code></td><td><code>{$var|nl2br}</code></td><td>Newlines to &lt;br&gt;</td></tr>
            <tr><td><code>addslashes</code></td><td><code>{$var|addslashes}</code></td><td>Escape with addslashes()</td></tr>
            <tr><td><code>alphabet</code></td><td><code>{$var|alphabet:"-"}</code></td><td>Slugify (alphanumeric + separator)</td></tr>
            <tr><td><code>gettype</code></td><td><code>{$var|gettype}</code></td><td>Returns PHP type name</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Built-in Function Tags</h2>
        <table>
            <tr><th>Function</th><th>Syntax</th><th>Description</th></tr>
            <tr><td><code>if</code></td><td><code>{@if $var="value"}...{@else}...{/if}</code></td><td>Conditional rendering</td></tr>
            <tr><td><code>each</code></td><td><code>{@each source=$arr as="item"}...{/each}</code></td><td>Iterate over arrays</td></tr>
            <tr><td><code>repeat</code></td><td><code>{@repeat length=3}...{/repeat}</code></td><td>Repeat content N times</td></tr>
            <tr><td><code>def</code></td><td><code>{@def "name" "value"}</code></td><td>Define a template variable</td></tr>
            <tr><td><code>template</code></td><td><code>{@template:Name param=$var}</code></td><td>Render a named template block</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Block Types</h2>
        <table>
            <tr><th>Type</th><th>Syntax</th><th>Description</th></tr>
            <tr><td><code>START/END</code></td><td><code>&lt;!-- START BLOCK: name --&gt;...&lt;!-- END BLOCK: name --&gt;</code></td><td>Repeatable block — renders once per <code>newBlock()</code> call</td></tr>
            <tr><td><code>WRAPPER</code></td><td><code>&lt;!-- WRAPPER BLOCK: name --&gt;...&lt;!-- END BLOCK: name --&gt;</code></td><td>Container wrapping all instances of a block</td></tr>
            <tr><td><code>TEMPLATE</code></td><td><code>&lt;!-- TEMPLATE BLOCK: name --&gt;...&lt;!-- END BLOCK: name --&gt;</code></td><td>Reusable readonly template block</td></tr>
            <tr><td><code>USE</code></td><td><code>&lt;!-- USE templatename BLOCK: instance --&gt;</code></td><td>Reference a TEMPLATE block within another block</td></tr>
            <tr><td><code>INCLUDE</code></td><td><code>&lt;!-- INCLUDE BLOCK: path/file.tpl --&gt;</code></td><td>Include external template file inline</td></tr>
            <tr><td><code>RECURSION</code></td><td><code>&lt;!-- RECURSION BLOCK: name --&gt;</code></td><td>Self-referencing for nested tree structures</td></tr>
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
                    if(k==='code')
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">'+esc(v)+'</pre></div>';
                    else if(k==='template'||k==='tpl_code')
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre style="background:#1a1a2e;color:#e0e0ff;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">'+esc(v)+'</pre></div>';
                    else if(k==='output'||k==='rendered')
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><div style="background:var(--bg-light);padding:12px;border-radius:6px;border:1px solid var(--border)">'+v+'</div></div>';
                    else if(typeof v==='string'&&v.length>80)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">'+esc(v)+'</pre></div>';
                    else if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code style="background:#1e1e2e;color:#cdd6f4;padding:2px 6px;border-radius:4px">'+esc(String(v))+'</code></div>';
                }
            }else h+='<pre style="background:#1e1e2e;color:#cdd6f4;padding:12px;border-radius:6px">'+esc(JSON.stringify(item,null,2))+'</pre>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>
