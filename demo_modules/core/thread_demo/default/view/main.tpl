{# Thread Demo Template - ThreadManager for async task execution #}
    <style>.demo-btn{cursor:pointer;border:none;} .demo-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}</style>

    <div class="card">
        <h2>Overview</h2>
        <p>The <strong>ThreadManager</strong> class provides async task execution with configurable concurrency control. It supports two modes:</p>
        <ul>
            <li><strong>Inline mode</strong> – Execute PHP callable synchronously (blocking)</li>
            <li><strong>Process mode</strong> – Execute shell commands asynchronously (non-blocking)</li>
        </ul>
    </div>
    
    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>ThreadManager</code></td><td><code>Razy</code></td><td>Thread pool with concurrency control</td></tr>
            <tr><td><code>Thread</code></td><td><code>Razy</code></td><td>Individual thread with status and result</td></tr>
        </table>
    </div>
    
    <div class="grid grid-2">
        <div class="card">
            <h3>Inline Mode</h3>
            <pre>use Razy\ThreadManager;

$tm = new ThreadManager();

$thread = $tm->spawn(function() {
    $result = 0;
    for ($i = 1; $i <= 100; $i++) {
        $result += $i;
    }
    return ['sum' => $result];
});

// Result available immediately
echo $thread->getResult();</pre>
        </div>
        <div class="card">
            <h3>Process Mode</h3>
            <pre>$tm = new ThreadManager();

// Spawn subprocess
$thread = $tm->spawn(fn() => null, [
    'command' => 'php',
    'args' => ['-r', 'echo getmypid();']
]);

// Wait for completion
$tm->await($thread->getId());

echo $thread->getStdout();
echo $thread->getExitCode();</pre>
        </div>
    </div>
    
    <div class="card">
        <h3>spawnPHPCode – Complex PHP Safely</h3>
        <pre>// Base64-encoded internally to avoid shell escaping issues
$phpCode = &lt;&lt;&lt;'PHP'
$data = [
    "message" =&gt; "Hello from subprocess",
    "pid" =&gt; getmypid(),
    "features" =&gt; ["nested", "quotes", "work"]
];
echo json_encode($data);
PHP;

$thread = $tm->spawnPHPCode($phpCode);
$tm->await($thread->getId());
echo $thread->getStdout();</pre>
    </div>
    
    <div class="card">
        <h3>Parallel Processing with Concurrency Control</h3>
        <pre>$tm = new ThreadManager();
$tm->setMaxConcurrency(3);

$threads = [];
for ($i = 0; $i < 5; $i++) {
    $threads[] = $tm->spawn(fn() => null, [
        'command' => 'php',
        'args' => ['-r', 'usleep(50000); echo getmypid();']
    ]);
}

// Wait for all tasks
$tm->joinAll($threads);</pre>
    </div>

    <div class="card">
        <h2>Run Demos</h2>
        <div class="grid grid-3">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/inline',this)">▶ Inline Thread</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/process',this)">▶ Process Thread</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/complex',this)">▶ spawnPHPCode</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/multi',this)">▶ Multi-Task</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/parallel',this)">▶ Parallel</button>
        </div>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>
    
    <div class="card">
        <h2>API Reference</h2>
        <table>
            <tr><th>Method</th><th>Description</th></tr>
            <tr><td><code>new ThreadManager()</code></td><td>Create thread manager</td></tr>
            <tr><td><code>setMaxConcurrency(n)</code></td><td>Set max concurrent processes</td></tr>
            <tr><td><code>spawn(callable, config)</code></td><td>Spawn inline or process thread</td></tr>
            <tr><td><code>spawnPHPCode(code)</code></td><td>Spawn PHP code via base64 encoding</td></tr>
            <tr><td><code>await(threadId)</code></td><td>Wait for single thread</td></tr>
            <tr><td><code>joinAll(threads)</code></td><td>Wait for all threads</td></tr>
            <tr><td><code>Thread::getStatus()</code></td><td>Get thread status</td></tr>
            <tr><td><code>Thread::getResult()</code></td><td>Get inline thread result</td></tr>
            <tr><td><code>Thread::getStdout()</code></td><td>Get process stdout</td></tr>
            <tr><td><code>Thread::getStderr()</code></td><td>Get process stderr</td></tr>
            <tr><td><code>Thread::getExitCode()</code></td><td>Get process exit code</td></tr>
        </table>
    </div>

    <script>
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function loadDemo(url,btn){
        var c=document.getElementById('demo-result');
        c.style.display='block';
        c.innerHTML='<p style="color:var(--text-muted)">Running thread demo... (may take a moment)</p>';
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
        var h='<h3>'+esc(data.demo||'Result')+'</h3>';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var v=data[key];
            if(key==='demo')continue;
            if(key==='tasks'||key==='results'){
                h+='<div style="margin:12px 0"><strong>'+esc(key)+':</strong>';
                if(Array.isArray(v)){
                    v.forEach(function(item,i){
                        h+='<div class="card" style="margin:8px 0;padding:8px 12px"><strong>#'+(i+1)+'</strong>';
                        for(var ik in item){
                            if(!item.hasOwnProperty(ik))continue;
                            var iv=item[ik];
                            if(iv===null)continue;
                            if(typeof iv==='object')
                                h+='<div><strong>'+esc(ik)+':</strong> <code>'+esc(JSON.stringify(iv))+'</code></div>';
                            else
                                h+='<div><strong>'+esc(ik)+':</strong> <code>'+esc(String(iv))+'</code></div>';
                        }
                        h+='</div>';
                    });
                }
                h+='</div>';
            }else if(typeof v==='object'&&v!==null){
                h+='<div style="margin:8px 0"><strong>'+esc(key)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
            }else if(v!==null){
                var cls=key==='status'?(v==='completed'?'color:var(--success)':'color:var(--warning)'):'';
                h+='<div style="margin:4px 0"><strong>'+esc(key)+':</strong> <code style="'+cls+'">'+esc(String(v))+'</code></div>';
            }
        }
        return h;
    }
    </script>
