{# Database Demo Template #}
    <style>
        .syntax-section { margin-bottom: 20px; }
        .syntax-section h4 { color: #2563eb; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0; font-size: 1em; }
        .syntax-grid { display: grid; gap: 10px; }
        .syntax-item { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .syntax-item:hover { border-color: #2563eb; box-shadow: 0 2px 8px rgba(37,99,235,0.15); }
        .syntax-item.active { border-color: #2563eb; box-shadow: 0 4px 12px rgba(37,99,235,0.2); }
        .syntax-header { padding: 12px 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: #f8fafc; }
        .syntax-label { color: #64748b; font-size: 0.8em; margin-bottom: 4px; font-weight: 500; }
        .syntax-code { font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 1em; color: #059669; background: #ecfdf5; padding: 6px 10px; border-radius: 4px; display: inline-block; border: 1px solid #d1fae5; }
        .syntax-arrow { color: #94a3b8; transition: transform 0.2s; font-size: 0.8em; }
        .syntax-item.active .syntax-arrow { transform: rotate(180deg); color: #2563eb; }
        .syntax-result { display: none; padding: 16px; background: #f1f5f9; border-top: 1px solid #e2e8f0; }
        .syntax-item.active .syntax-result { display: block; }
        .result-label { color: #64748b; font-size: 0.75em; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .result-sql { font-family: 'Consolas', 'Monaco', 'Courier New', monospace; padding: 12px; background: #1e293b; color: #e2e8f0; border-radius: 6px; line-height: 1.6; overflow-x: auto; margin-bottom: 10px; font-size: 0.9em; }
        .result-sql:last-child { margin-bottom: 0; }
        .sql-keyword { color: #f472b6; font-weight: bold; }
        .sql-string { color: #7dd3fc; }
        .sql-number { color: #fbbf24; }
        .loading-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid #cbd5e1; border-top-color: #2563eb; border-radius: 50%; animation: spin 0.8s linear infinite; margin-right: 8px; vertical-align: middle; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .syntax-desc { color: #64748b; font-size: 0.85em; margin-top: 4px; }
        .viewer-tabs { display: flex; gap: 4px; margin-bottom: 16px; background: #f1f5f9; padding: 4px; border-radius: 8px; }
        .viewer-tab { flex: 1; padding: 10px 16px; text-align: center; background: transparent; border: none; color: #64748b; cursor: pointer; border-radius: 6px; transition: all 0.2s; font-weight: 500; font-size: 0.9em; }
        .viewer-tab:hover { color: #1e293b; background: #e2e8f0; }
        .viewer-tab.active { background: #2563eb; color: white; }
        .viewer-content { display: none; }
        .viewer-content.active { display: block; }
        .demo-links { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
        .demo-links a { font-size: 0.85em; padding: 6px 12px; }
        .demo-btn.active { background: #1e40af !important; color: #fff !important; box-shadow: inset 0 2px 4px rgba(0,0,0,0.2); }
    </style>
    
    <div class="card">
        <h2>Interactive Syntax Parser</h2>
        <p style="color:#64748b;margin-bottom:16px;">Click any syntax example below to see the generated SQL statement.</p>
        
        <div class="viewer-tabs">
            <button class="viewer-tab active" data-tab="where">WhereSyntax</button>
            <button class="viewer-tab" data-tab="joins">TableJoinSyntax</button>
            <button class="viewer-tab" data-tab="order">Order & Limit</button>
        </div>
        
        <!-- WHERE SYNTAX TAB -->
        <div class="viewer-content active" id="tab-where">
            <div class="syntax-section">
                <h4>Basic Comparisons</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="id=1">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Equals</div><code class="syntax-code">id=1</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="age>18">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Greater Than</div><code class="syntax-code">age&gt;18</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="price<=100">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Less or Equal</div><code class="syntax-code">price&lt;=100</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>String Matching (LIKE)</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="name$=john">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Starts With ($=)</div><code class="syntax-code">name$=john</code><div class="syntax-desc">→ LIKE 'john%'</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="email^=@gmail.com">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Ends With (^=)</div><code class="syntax-code">email^=@gmail.com</code><div class="syntax-desc">→ LIKE '%@gmail.com'</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="title*=php">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Contains (*=)</div><code class="syntax-code">title*=php</code><div class="syntax-desc">→ LIKE '%php%'</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>NULL & IN Operators</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="deleted_at=NULL">
                        <div class="syntax-header">
                            <div><div class="syntax-label">IS NULL</div><code class="syntax-code">deleted_at=NULL</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="status|=[&quot;active&quot;,&quot;pending&quot;]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">IN list (|=)</div><code class="syntax-code">status|=["active","pending"]</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="price><[10,100]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">BETWEEN (&gt;&lt;)</div><code class="syntax-code">price&gt;&lt;[10,100]</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="category!=[&quot;spam&quot;,&quot;deleted&quot;]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">NOT IN (!=array)</div><code class="syntax-code">category!=["spam","deleted"]</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>JSON Operators</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="data:=&quot;$.name&quot;">
                        <div class="syntax-header">
                            <div><div class="syntax-label">JSON Extract (:=)</div><code class="syntax-code">data:="$.name"</code><div class="syntax-desc">Check if JSON path exists</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="tags~=&quot;php&quot;">
                        <div class="syntax-header">
                            <div><div class="syntax-label">JSON Contains (~=)</div><code class="syntax-code">tags~="php"</code><div class="syntax-desc">Search value in JSON array</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="meta&amp;=&quot;keyword&quot;">
                        <div class="syntax-header">
                            <div><div class="syntax-label">JSON Search (&amp;=)</div><code class="syntax-code">meta&amp;="keyword"</code><div class="syntax-desc">Search string anywhere in JSON</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="config@=[&quot;host&quot;,&quot;port&quot;]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">JSON Keys (@=)</div><code class="syntax-code">config@=["host","port"]</code><div class="syntax-desc">Match multiple JSON keys</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>Logical Operators</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="active=1,verified=1">
                        <div class="syntax-header">
                            <div><div class="syntax-label">AND (comma)</div><code class="syntax-code">active=1,verified=1</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="role=&quot;admin&quot;|role=&quot;mod&quot;">
                        <div class="syntax-header">
                            <div><div class="syntax-label">OR (pipe)</div><code class="syntax-code">role="admin"|role="mod"</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="active=1,(role=&quot;admin&quot;|role=&quot;mod&quot;)">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Grouped</div><code class="syntax-code">active=1,(role="admin"|role="mod")</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>Negation (! prefix)</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="where" data-syntax="!deleted=1">
                        <div class="syntax-header">
                            <div><div class="syntax-label">NOT</div><code class="syntax-code">!deleted=1</code><div class="syntax-desc">Negate any comparison</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="!name*=test">
                        <div class="syntax-header">
                            <div><div class="syntax-label">NOT LIKE</div><code class="syntax-code">!name*=test</code><div class="syntax-desc">NOT LIKE '%test%'</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="where" data-syntax="!tags~=&quot;spam&quot;">
                        <div class="syntax-header">
                            <div><div class="syntax-label">NOT JSON Contains</div><code class="syntax-code">!tags~="spam"</code><div class="syntax-desc">JSON array must not contain 'spam'</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- JOINS TAB -->
        <div class="viewer-content" id="tab-joins">
            <div class="syntax-section">
                <h4>Join Types</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="join" data-syntax="u.users<p.posts[user_id]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">LEFT JOIN (&lt;)</div><code class="syntax-code">u.users&lt;p.posts[user_id]</code><div class="syntax-desc">All users, with posts if any</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="join" data-syntax="u.users-p.posts[user_id]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">INNER JOIN (-)</div><code class="syntax-code">u.users-p.posts[user_id]</code><div class="syntax-desc">Only users with posts</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="join" data-syntax="u.users>p.posts[user_id]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">RIGHT JOIN (&gt;)</div><code class="syntax-code">u.users&gt;p.posts[user_id]</code><div class="syntax-desc">All posts, with user if exists</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>Multi-Table Joins</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="join" data-syntax="u.users<p.posts[user_id]<c.comments[post_id]">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Chained JOINs</div><code class="syntax-code">u.users&lt;p.posts[user_id]&lt;c.comments[post_id]</code><div class="syntax-desc">Users → Posts → Comments</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="join" data-syntax="a.users">
                        <div class="syntax-header">
                            <div><div class="syntax-label">Table Alias</div><code class="syntax-code">a.users</code><div class="syntax-desc">users AS a</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ORDER & LIMIT TAB -->
        <div class="viewer-content" id="tab-order">
            <div class="syntax-section">
                <h4>ORDER BY</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="order" data-syntax="<created_at">
                        <div class="syntax-header">
                            <div><div class="syntax-label">ASC (&lt;)</div><code class="syntax-code">&lt;created_at</code><div class="syntax-desc">ORDER BY created_at ASC</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="order" data-syntax=">created_at">
                        <div class="syntax-header">
                            <div><div class="syntax-label">DESC (&gt;)</div><code class="syntax-code">&gt;created_at</code><div class="syntax-desc">ORDER BY created_at DESC</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
            
            <div class="syntax-section">
                <h4>LIMIT & GROUP</h4>
                <div class="syntax-grid">
                    <div class="syntax-item" data-type="limit" data-syntax="10">
                        <div class="syntax-header">
                            <div><div class="syntax-label">LIMIT</div><code class="syntax-code">limit(10)</code><div class="syntax-desc">First 10 rows</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="limit" data-syntax="10,20">
                        <div class="syntax-header">
                            <div><div class="syntax-label">LIMIT + OFFSET</div><code class="syntax-code">limit(10, 20)</code><div class="syntax-desc">10 rows, skip first 20</div></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                    <div class="syntax-item" data-type="group" data-syntax="status">
                        <div class="syntax-header">
                            <div><div class="syntax-label">GROUP BY</div><code class="syntax-code">group('status')</code></div>
                            <span class="syntax-arrow">▼</span>
                        </div>
                        <div class="syntax-result"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="demo-links" style="border-top:none;padding-top:0;">
            <span style="color:#64748b;font-size:0.85em;margin-right:8px;display:block;margin-bottom:8px;">Click to preview JSON results inline:</span>
        </div>
    </div>

    <div class="card">
        <h2>Available Demos</h2>
        <p style="color:#64748b;margin-bottom:12px;">Each demo generates SQL statements and shows the query builder code.</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/connect',this)" style="cursor:pointer;border:none;">Connect</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/drivers',this)" style="cursor:pointer;border:none;">Drivers</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/select',this)" style="cursor:pointer;border:none;">SELECT</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/insert',this)" style="cursor:pointer;border:none;">INSERT</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/update',this)" style="cursor:pointer;border:none;">UPDATE</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/delete',this)" style="cursor:pointer;border:none;">DELETE</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/joins',this)" style="cursor:pointer;border:none;">Joins</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/where',this)" style="cursor:pointer;border:none;">Where</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/transaction',this)" style="cursor:pointer;border:none;">Transaction</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/advanced',this)" style="cursor:pointer;border:none;">Advanced</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/table_helper',this)" style="cursor:pointer;border:none;">TableHelper</button>
            <button class="btn demo-btn" onclick="loadDemo('{$module_url}/column_helper',this)" style="cursor:pointer;border:none;">ColumnHelper</button>
        </div>
    </div>

    <div id="demo-result" class="card" style="display:none;margin-top:20px"></div>

    <div class="grid grid-2">
        <div class="card">
            <h3>Connection Example</h3>
            <pre>use Razy\Database;

$db = new Database([
    'host' => 'localhost',
    'database' => 'mydb',
    'username' => 'user',
    'password' => 'pass'
]);

// Or via DSN
$db = new Database('mysql:host=localhost;dbname=mydb', 'user', 'pass');</pre>
        </div>
        
        <div class="card">
            <h3>Query Builder</h3>
            <pre>// SELECT with conditions
$stmt = $db->prepare()
    ->select('users', ['id', 'name', 'email'])
    ->where('status="active"')
    ->order('>created_at')  // > for DESC, < for ASC
    ->limit(10);

$users = $stmt->query()->fetchAll();</pre>
        </div>
    </div>
    
    <script>
    (function() {
        const tabs = document.querySelectorAll('.viewer-tab');
        const contents = document.querySelectorAll('.viewer-content');
        const items = document.querySelectorAll('.syntax-item');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
            });
        });
        
        function highlightSQL(sql) {
            if (!sql) return '';
            let s = sql.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            ['SELECT','FROM','WHERE','JOIN','LEFT','RIGHT','INNER','ON','AND','OR','NOT','IN','LIKE','BETWEEN','IS','NULL','AS','ORDER','BY','ASC','DESC','GROUP','LIMIT','OFFSET','JSON_EXTRACT','JSON_CONTAINS','JSON_SEARCH','JSON_KEYS','JSON_OVERLAPS'].forEach(k => {
                s = s.replace(new RegExp('\\b('+k+')\\b','gi'), '<span class="sql-keyword">$1</span>');
            });
            s = s.replace(/('([^']*)')/g, '<span class="sql-string">$1</span>');
            s = s.replace(/\b(\d+)\b/g, '<span class="sql-number">$1</span>');
            return s;
        }
        
        items.forEach(item => {
            item.addEventListener('click', async function() {
                const wasActive = this.classList.contains('active');
                items.forEach(i => i.classList.remove('active'));
                if (wasActive) return;
                
                this.classList.add('active');
                const resultDiv = this.querySelector('.syntax-result');
                const type = this.dataset.type;
                const syntax = this.dataset.syntax;
                
                resultDiv.innerHTML = '<span class="loading-spinner"></span> Parsing...';
                
                try {
                    const response = await fetch('{$module_url}/parse', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ type, syntax })
                    });
                    const data = await response.json();
                    
                    if (data.error) {
                        resultDiv.innerHTML = '<div style="color:#dc2626;background:#fef2f2;padding:10px;border-radius:6px;">Error: ' + data.error + '</div>';
                    } else {
                        let html = '<div class="result-label">Generated SQL</div><div class="result-sql">' + highlightSQL(data.sql) + '</div>';
                        if (data.from_clause) html += '<div class="result-label">FROM Clause</div><div class="result-sql">' + highlightSQL(data.from_clause) + '</div>';
                        if (data.where_clause) html += '<div class="result-label">WHERE Clause</div><div class="result-sql">' + highlightSQL(data.where_clause) + '</div>';
                        resultDiv.innerHTML = html;
                    }
                } catch (err) {
                    resultDiv.innerHTML = '<div style="color:#dc2626">Request failed: ' + err.message + '</div>';
                }
            });
        });
    })();

    function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML;}
    function loadDemo(url,btn){
        var c=document.getElementById('demo-result');
        c.style.display='block';
        c.innerHTML='<p style="color:#64748b"><span class="loading-spinner"></span> Loading...</p>';
        document.querySelectorAll('.demo-btn').forEach(function(b){b.classList.remove('active');b.style.background='';});
        if(btn){btn.classList.add('active');btn.style.background='#1e40af';btn.style.color='#fff';}
        var x=new XMLHttpRequest();
        x.open('GET',url);
        x.onload=function(){
            if(x.status===200){
                try{var d=JSON.parse(x.responseText);c.innerHTML=fmtDB(d);}
                catch(e){c.innerHTML='<pre>'+esc(x.responseText)+'</pre>';}
            }else c.innerHTML='<p style="color:#dc2626">Error: '+x.status+'</p>';
        };
        x.onerror=function(){c.innerHTML='<p style="color:#dc2626">Network error</p>';};
        x.send();
    }
    function fmtDB(data){
        if(data.error)return '<p style="color:#dc2626">'+esc(data.error)+'</p>';
        var h='';
        for(var key in data){
            if(!data.hasOwnProperty(key))continue;
            var item=data[key];
            h+='<div style="margin-bottom:16px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">';
            h+='<h4 style="color:#2563eb;margin:0 0 8px">'+esc(key.replace(/_/g,' '))+'</h4>';
            if(item&&typeof item==='object'&&!Array.isArray(item)){
                if(item.description)h+='<p style="color:#64748b;font-size:0.9em;margin-bottom:8px">'+esc(String(item.description))+'</p>';
                if(item.sql)h+='<div class="result-label">SQL</div><div class="result-sql">'+esc(item.sql)+'</div>';
                if(item.code)h+='<div style="margin:8px 0"><strong>Code:</strong><pre>'+esc(item.code)+'</pre></div>';
                for(var k in item){
                    if(!item.hasOwnProperty(k)||k==='description'||k==='sql'||k==='code')continue;
                    var v=item[k];
                    if(typeof v==='object'&&v!==null)
                        h+='<div style="margin:6px 0"><strong>'+esc(k)+':</strong><pre>'+esc(JSON.stringify(v,null,2))+'</pre></div>';
                    else if(typeof v==='string'&&v.length>60)
                        h+='<div style="margin:6px 0"><strong>'+esc(k)+':</strong><pre>'+esc(v)+'</pre></div>';
                    else if(v!==null)
                        h+='<div style="margin:4px 0"><strong>'+esc(k)+':</strong> <code style="background:#ecfdf5;padding:2px 6px;border-radius:4px;color:#059669">'+esc(String(v))+'</code></div>';
                }
            }else if(typeof item==='string')
                h+='<p>'+esc(item)+'</p>';
            else h+='<pre>'+esc(JSON.stringify(item,null,2))+'</pre>';
            h+='</div>';
        }
        return h||'<p>No results</p>';
    }
    </script>