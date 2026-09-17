import json, glob, statistics as st

scen = {}
for f in sorted(glob.glob('benchmark/results/gate/*_run*.json')):
    d = json.load(open(f))
    m = d.get('metrics', d)

    def g(name, key):
        s = m.get(name, {}) or {}
        s = s.get('values') or s
        return s.get(key)

    name = f.replace('\\', '/').split('/')[-1].rsplit('_run', 1)[0]
    rec = dict(
        rps=g('http_reqs', 'rate'),
        p95=g('http_req_duration', 'p(95)'),
        avg=g('http_req_duration', 'avg'),
        min=g('http_req_duration', 'min'),
        max=g('http_req_duration', 'max'),
    )
    ck = d.get('root_group', {}).get('checks', {})
    if ck:
        k = list(ck)[0]
        c = ck[k]
        val = c.get('value')
        if val is None and c.get('passes') is not None:
            tot = c['passes'] + c['fails']
            val = c['passes'] / tot if tot else 0
        rec['checks'] = val
    scen.setdefault(name, []).append(rec)

print('%-24s %10s %8s %8s %8s %8s' % ('scenario', 'rps', 'p95ms', 'avg', 'min', 'checks'))
for name, runs in sorted(scen.items()):
    med = lambda k: (st.median([r[k] for r in runs if r.get(k) is not None])
                     if any(r.get(k) is not None for r in runs) else None)
    fmt = lambda v, w, p: ('%*.*f' % (w, p, v)) if v is not None else ' n/a'.rjust(w)
    print('%-24s %10.1f %8.2f %8.2f %8.2f %8s' % (
        name, med('rps'), med('p95'), med('avg'), med('min'),
        ('%.4f' % med('checks')) if med('checks') is not None else 'n/a'))
    print('   runs rps: %s' % ['%.0f' % r['rps'] for r in runs])
