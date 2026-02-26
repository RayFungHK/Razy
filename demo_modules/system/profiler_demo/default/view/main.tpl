{# Profiler Demo Template #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>Profiler</strong> class provides performance monitoring with checkpoint-based comparison. Track memory, CPU time, execution time, and more.</p>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>Profiler</code></td><td><code>Razy</code></td><td>Performance profiler with checkpoints</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h2>Tracked Metrics</h2>
        <table>
            <tr><th>Metric</th><th>Description</th></tr>
            <tr><td><code>memory_usage</code></td><td>Current memory usage</td></tr>
            <tr><td><code>memory_allocated</code></td><td>Total memory allocated</td></tr>
            <tr><td><code>output_buffer</code></td><td>Output buffer size</td></tr>
            <tr><td><code>user_mode_time</code></td><td>CPU user mode time</td></tr>
            <tr><td><code>system_mode_time</code></td><td>CPU system mode time</td></tr>
            <tr><td><code>execution_time</code></td><td>Wall clock time</td></tr>
            <tr><td><code>defined_functions</code></td><td>User-defined functions</td></tr>
            <tr><td><code>declared_classes</code></td><td>Declared classes</td></tr>
        </table>
    </div>
    
    <div class="card">
        <h3>Basic Usage</h3>
        <pre>use Razy\Profiler;

$profiler = new Profiler();  // Records initial state

// Do some work
$data = range(1, 1000);
$sum = array_sum($data);

// Create checkpoint
$profiler->checkpoint('after_work');

// Get report comparing init to checkpoint
$report = $profiler->report(true);

// Multi-stage comparison
$profiler->checkpoint('stage_1');
// ... more work ...
$profiler->checkpoint('stage_2');
$report = $profiler->report(true, 'stage_1', 'stage_2');</pre>
    </div>
    
    <div class="card">
        <h2>Available Demos</h2>
        <div class="grid grid-2">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/basic',this)">Basic Profiling</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/checkpoints',this)">Checkpoint Comparison</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>Production Pattern</h2>
        <pre>$profiler = new Profiler();

$this->loadConfig();
$profiler->checkpoint('config_loaded');

$this->initDatabase();
$profiler->checkpoint('database_ready');

$this->handleRequest();
$profiler->checkpoint('request_handled');

$report = $profiler->report(true);
foreach ($report as $stage => $metrics) {
    $time = round($metrics['execution_time'] * 1000, 2);
    $memory = round($metrics['memory_usage'] / 1024, 2);
    error_log("[Profile] {$stage}: {$time}ms, {$memory}KB");
}</pre>
    </div>
    
    <div class="card">
        <h2>API Reference</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>new Profiler()</code></td><td>Create profiler (captures initial snapshot)</td></tr>
            <tr><td><code>checkpoint($name)</code></td><td>Record named checkpoint</td></tr>
            <tr><td><code>report($compare)</code></td><td>Generate report (true = diff from init)</td></tr>
            <tr><td><code>report($compare, $from, $to)</code></td><td>Compare specific checkpoints</td></tr>
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
                if(item.code)h+='<div style="margin:8px 0"><strong>Code:</strong><pre>'+esc(item.code)+'</pre></div>';
                for(var k in item){
                    if(!item.hasOwnProperty(k)||k==='description'||k==='code')continue;
                    var v=item[k];
                    if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else if(typeof v==='string'&&v.length>60)
                        h+='<div style="margin:8px 0"><strong>'+esc(k)+':</strong><pre>'+esc(v)+'</pre></div>';
                    else if(v!==null)
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code>'+esc(String(v))+'</code></div>';
                }
            }else if(typeof item==='string'&&item.length>60)
                h+='<pre>'+esc(item)+'</pre>';
            else if(typeof item==='object')
                h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            else h+='<p>'+esc(String(item))+'</p>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>