<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="qa-csrf" content="{$csrf_token->escape}">
<title>Queue Admin</title>
<style>
:root { color-scheme: dark; }
body { font: 14px/1.5 system-ui, sans-serif; background:#111; color:#ddd; margin:2rem; }
h1 { font-size:1.2rem; } h2 { font-size:1rem; color:#9cf; margin-top:1.5rem; }
table { border-collapse:collapse; margin:.5rem 0; }
th, td { border:1px solid #333; padding:.3rem .7rem; text-align:left; }
th { color:#8fa; font-weight:600; }
input, button, select { font:inherit; background:#1b1b1b; color:#ddd; border:1px solid #444; padding:.3rem .5rem; }
button { cursor:pointer; } button.danger { color:#faa; }
#jobs { background:#181818; border:1px solid #333; padding:.7rem; white-space:pre-wrap; font-family:monospace; font-size:12px; max-height:40vh; overflow:auto; }
.err { color:#faa; } .ok { color:#8fa; }
</style>
</head>
<body>
<h1>Queue Admin <small style="color:#666">razymod/queue-admin</small></h1>

<h2>Queues</h2>
<p>
  <label>queue names (comma-separated)
    <input id="queues" size="30">
  </label>
  <button id="refresh">Refresh</button>
  <button id="purge" class="danger">Purge first queue…</button>
</p>
<div id="status" class="err">loading…</div>

<h2>Job lookup</h2>
<p>
  <label>job id <input id="jobid" size="24"></label>
  <button id="find">Find</button>
</p>
<div id="jobs">—</div>

<h2>Actions</h2>
<p>
  <select id="kind">
    <option value="release">release</option>
    <option value="bury">bury</option>
    <option value="delete" class="danger">delete</option>
  </select>
  <label>retry delay (s) <input id="delay" size="4" value="0"></label>
  <button id="act">Apply to job id above</button>
</p>
<div id="result">—</div>

<script>
(function () {
    'use strict';
    // value is a controller-side json_encode(JSON_HEX_TAG|…) string literal: pre-encoded for JS
    // context, </script> injection impossible, provenance is getModuleURL() (no user input).
    // lint-allow: RZ-004
    var BASE = {$module_url_json};
    var CSRF = document.querySelector('meta[name="qa-csrf"]').content;
    function escInto(el, text) { el.textContent = String(text); } // textContent only: no HTML sink anywhere
    function qs() {
        var names = document.getElementById('queues').value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        return names.length ? names : ['default'];
    }
    function query(names) { return names.map(function (n) { return 'queues[]=' + encodeURIComponent(n); }).join('&'); }
    function post(path, body) {
        var form = new URLSearchParams(body);
        return fetch(BASE + '/' + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            body: form.toString(),
        }).then(function (r) { return r.json(); });
    }
    function renderStatus(data) {
        var box = document.getElementById('status');
        box.className = '';
        if (!data.ok) { box.className = 'err'; escInto(box, data.error || 'failed'); return; }
        var table = document.createElement('table');
        var queues = Object.keys(data.status);
        if (!queues.length) { escInto(box, 'no queues'); return; }
        var statuses = Object.keys(data.status[queues[0]] || {});
        var head = table.insertRow();
        head.insertCell().textContent = 'queue';
        statuses.forEach(function (s) { var c = head.insertCell(); c.textContent = s; });
        queues.forEach(function (q) {
            var row = table.insertRow();
            row.insertCell().textContent = q;
            statuses.forEach(function (s) { row.insertCell().textContent = data.status[q][s]; });
        });
        box.replaceChildren(table);
    }
    function refresh() {
        fetch(BASE + '/status?' + query(qs())).then(function (r) { return r.json(); })
            .then(renderStatus)
            .catch(function (e) { var b = document.getElementById('status'); b.className = 'err'; escInto(b, 'fetch failed: ' + e); });
    }
    document.getElementById('refresh').addEventListener('click', refresh);
    document.getElementById('find').addEventListener('click', function () {
        var id = document.getElementById('jobid').value.trim();
        fetch(BASE + '/job?id=' + encodeURIComponent(id)).then(function (r) { return r.json(); })
            .then(function (d) { var j = document.getElementById('jobs'); escInto(j, d.ok ? JSON.stringify(d.job || null, null, 2) : d.error); })
            .catch(function (e) { escInto(document.getElementById('jobs'), 'fetch failed: ' + e); });
    });
    document.getElementById('act').addEventListener('click', function () {
        if (!window.confirm('Apply this action?')) { return; }
        post('act', {
            id: document.getElementById('jobid').value.trim(),
            kind: document.getElementById('kind').value,
            retry_delay: document.getElementById('delay').value || '0',
        }).then(function (d) { var r = document.getElementById('result'); r.className = d.ok ? 'ok' : 'err'; escInto(r, JSON.stringify(d, null, 2)); refresh(); })
          .catch(function (e) { escInto(document.getElementById('result'), 'request failed: ' + e); });
    });
    document.getElementById('purge').addEventListener('click', function () {
        var q = qs()[0];
        if (!window.confirm('Purge finished/buried jobs of "' + q + '"?')) { return; }
        post('purge', { queue: q })
            .then(function (d) { var r = document.getElementById('result'); r.className = d.ok ? 'ok' : 'err'; escInto(r, JSON.stringify(d, null, 2)); refresh(); })
            .catch(function (e) { escInto(document.getElementById('result'), 'request failed: ' + e); });
    });
    refresh();
}());
</script>
</body>
</html>
