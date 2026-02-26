{# Route Demo Template - Route Parameter Capture #}
    <style>
        .pattern { font-family: 'SF Mono', Monaco, monospace; background: #fef3c7; padding: 4px 8px; border-radius: var(--radius); color: #92400e; }
        .note { background: #dbeafe; padding: 16px; border-left: 4px solid var(--primary); margin: 16px 0; border-radius: 0 var(--radius) var(--radius) 0; }
        .test-btn{cursor:pointer;border:none;font-size:0.8rem;padding:6px 14px;}
        .test-btn.active{background:var(--primary-dark);box-shadow:inset 0 2px 4px rgba(0,0,0,0.2);}
    </style>
    
    <div class="note">
        <strong>Note:</strong> addRoute() requires a <strong>leading slash</strong> and uses <strong>absolute paths</strong> from site root.
        Use parentheses <code>()</code> around tokens to capture values passed to handler functions.
    </div>

    <div class="card">
        <h2>Classes Used</h2>
        <table>
            <tr><th>Class</th><th>Namespace</th><th>Description</th></tr>
            <tr><td><code>Agent</code></td><td><code>Razy</code></td><td>Module controller that provides addRoute() and addLazyRoute() methods</td></tr>
            <tr><td><code>Application</code></td><td><code>Razy</code></td><td>Central router that dispatches requests to matching routes</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Pattern Syntax Reference</h2>
        <table>
            <tr><th>Pattern</th><th>Matches</th><th>Example</th></tr>
            <tr><td><code>:a</code></td><td>Any non-slash characters</td><td><code>hello-world_123</code></td></tr>
            <tr><td><code>:d</code></td><td>Digits only (0-9)</td><td><code>12345</code></td></tr>
            <tr><td><code>:D</code></td><td>Non-digits</td><td><code>abc-xyz</code></td></tr>
            <tr><td><code>:w</code></td><td>Alphabets (a-zA-Z)</td><td><code>HelloWorld</code></td></tr>
            <tr><td><code>:W</code></td><td>Non-alphabets</td><td><code>123-456</code></td></tr>
            <tr><td><code>:[regex]</code></td><td>Custom regex char class</td><td><code>:[a-z0-9-]</code></td></tr>
            <tr><td><code>{n}</code></td><td>Exactly n characters</td><td><code>:a{6}</code></td></tr>
            <tr><td><code>{min,max}</code></td><td>Length range</td><td><code>:a{3,10}</code></td></tr>
            <tr><td><code>()</code></td><td>Capture group</td><td>Passes captured value to handler</td></tr>
        </table>
    </div>

    <div class="card">
        <h2>Test Routes</h2>
        <p>Click <strong>Test</strong> to send the request via XHR and view the response inline:</p>
        <table>
            <tr><th>Route Pattern</th><th>Test URL</th><th>Action</th></tr>
            <tr>
                <td class="pattern">/route_demo/user/(:d)</td>
                <td><code>user/123</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('user/123',this)">Test</button></td>
            </tr>
            <tr>
                <td class="pattern">/route_demo/article/(:a)</td>
                <td><code>article/hello-world</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('article/hello-world',this)">Test</button></td>
            </tr>
            <tr>
                <td class="pattern">/route_demo/product/(:w)</td>
                <td><code>product/Widget</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('product/Widget',this)">Test</button></td>
            </tr>
            <tr>
                <td class="pattern">/route_demo/code/(:a{6})</td>
                <td><code>code/ABC123</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('code/ABC123',this)">Test</button></td>
            </tr>
            <tr>
                <td class="pattern">/route_demo/search/(:a{3,10})</td>
                <td><code>search/hello</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('search/hello',this)">Test</button></td>
            </tr>
            <tr>
                <td class="pattern">/route_demo/tag/(:[a-z0-9-]{1,30})</td>
                <td><code>tag/web-dev</code></td>
                <td><button class="btn btn-success test-btn" onclick="testRoute('tag/web-dev',this)">Test</button></td>
            </tr>
        </table>
    </div>
    
    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>

    <div class="card">
        <h2>Code Example</h2>
        <pre>// In controller __onInit()
$agent->addRoute('/route_demo/user/(:d)', 'user');
$agent->addRoute('/route_demo/tag/(:[a-z0-9-]{1,30})', 'tag');

// Handler file (route_demo.user.php)
return function (string $id): void {
    header('Content-Type: application/json');
    echo json_encode(['user_id' => $id]);
};</pre>
    </div>

    <div class="card">
        <h2>addRoute vs addLazyRoute</h2>
        <table>
            <tr><th>Feature</th><th>addLazyRoute</th><th>addRoute</th></tr>
            <tr><td>Path Type</td><td>Relative to module alias</td><td>Absolute from site root</td></tr>
            <tr><td>URL Parameters</td><td>No capture</td><td>Regex capture groups</td></tr>
            <tr><td>Pattern Matching</td><td>Prefix match + segments</td><td>Regex patterns</td></tr>
            <tr><td>Use Case</td><td>Static routes, nested paths</td><td>Dynamic routes with IDs/slugs</td></tr>
        </table>
    </div>

    <script>
    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function testRoute(path,btn){
        var c=document.getElementById('demo-result');
        var fullUrl=path;
        c.style.display='block';
        c.innerHTML='<h3>Request</h3><p><code>GET '+esc(fullUrl)+'</code></p><p style="color:var(--text-muted)">Loading...</p>';
        document.querySelectorAll('.test-btn').forEach(function(b){b.classList.remove('active');});
        if(btn)btn.classList.add('active');
        var x=new XMLHttpRequest();
        x.open('GET',fullUrl);
        x.onload=function(){
            var h='<h3>Request</h3><p><strong>URL:</strong> <code>'+esc(fullUrl)+'</code></p>';
            h+='<h3>Response <span class="tag'+(x.status===200?' tag-success':'')+'">'+x.status+'</span></h3>';
            try{
                var d=JSON.parse(x.responseText);
                h+='<pre>'+esc(JSON.stringify(d,null,2))+'</pre>';
            }catch(e){
                h+='<pre>'+esc(x.responseText)+'</pre>';
            }
            c.innerHTML=h;
        };
        x.onerror=function(){c.innerHTML='<p style="color:var(--danger)">Network error</p>';};
        x.send();
    }
    </script>
