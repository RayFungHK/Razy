{# Dom Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>DOM</strong> class provides a fluent interface for building HTML elements programmatically, avoiding string concatenation and raw HTML.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>DOM</code></td><td><code>Razy</code></td><td>Main DOM element builder</td></tr>
            <tr><td><code>Select</code></td><td><code>Razy\DOM</code></td><td>Specialized builder for &lt;select&gt; dropdowns</td></tr>
            <tr><td><code>Input</code></td><td><code>Razy\DOM</code></td><td>Specialized builder for &lt;input&gt; elements</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Basic Usage</h3>
            <pre>use Razy\DOM;

$div = DOM::create('div')
    ->addClass('container', 'main')
    ->attr('id', 'app')
    ->dataset('page', 'home');

echo $div;  // &lt;div class="container main" id="app" data-page="home"&gt;&lt;/div&gt;</pre>
        </div>
        <div class="card">
            <h3>Nested Elements</h3>
            <pre>$nav = DOM::create('nav')
    ->append(
        DOM::create('a')->attr('href', '/')->text('Home'),
        DOM::create('a')->attr('href', '/about')->text('About')
    );</pre>
        </div>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/basic',this)">Basic Elements</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/select',this)">Select Builder</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/input',this)">Input Builder</button>
            <button class="btn btn-secondary demo-btn" onclick="loadDemo('{$module_url}/nested',this)">Nested Structures</button>
            <button class="btn btn-secondary demo-btn" onclick="loadDemo('{$module_url}/form',this)">Complete Form</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Key Methods</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>DOM::create($tag)</code></td><td>Create element with tag name</td></tr>
            <tr><td><code>->addClass(...$classes)</code></td><td>Add CSS classes</td></tr>
            <tr><td><code>->attr($name, $value)</code></td><td>Set attribute</td></tr>
            <tr><td><code>->dataset($key, $value)</code></td><td>Set data-* attribute</td></tr>
            <tr><td><code>->append(...$children)</code></td><td>Append child elements</td></tr>
            <tr><td><code>->text($content)</code></td><td>Set text content (auto-escaped)</td></tr>
            <tr><td><code>->html($content)</code></td><td>Set inner HTML</td></tr>
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
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtDOM(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:var(--danger)">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    function fmtDOM(data){
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
                    if(k==='html'||k==='br'||k==='img'){
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+' (source):</strong><pre>'+esc(typeof v==='string'?v:JSON.stringify(v))+'</pre></div>';
                        if(typeof v==='string'){
                            h+='<div style="margin:8px 0;padding:12px;background:#f1f5f9;border-radius:var(--radius);border:1px solid var(--border)"><strong>Rendered:</strong><div style="margin-top:8px">'+v+'</div></div>';
                        }
                    }else if(typeof v==='string'&&v.length>60)
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