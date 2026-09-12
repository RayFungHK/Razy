# Coexistence samples — one host, Razy + a sibling app

Worked, copy-pasteable configs for running a Razy distributor next to a non-Razy app
(a Python API below; substitute your own) on the same host. The failure these prevent —
and the mechanism — is explained in the manual:
[`manual/08-coexistence.md`](../../manual/08-coexistence.md), with evidence in
[`architecture/ROUTE-COEXISTENCE.md`](../../architecture/ROUTE-COEXISTENCE.md).

## Topology A — edge proxy splits the path (recommended)

The proxy you already own makes the routing decision; Razy's generated rewrite files
never see the sibling's prefixes at all.

```
                      example.com:443
                             │
              ┌──────────────┴───────────────┐
              │        EDGE PROXY            │   nginx-edge.conf / Caddyfile-edge.sample
              │  (nginx, Caddy, LB, CDN…)    │
              └───┬──────────────────┬───────┘
      /api-py/…   │                  │   everything else
                  ▼                  ▼
        ┌──────────────────┐  ┌───────────────────────────────┐
        │ python :8000     │  │ RAZY APP (Apache or FrankenPHP)│
        │ (sibling upstream│  │ generated .htaccess / Caddyfile│
        │  or its own      │  │ claim ONLY what the edge       │
        │  subdomain)      │  │ forwards to them               │
        └──────────────────┘  └───────────────────────────────┘
```

Files: `nginx-edge.conf` (nginx edge), `Caddyfile-edge.sample` (Caddy edge).
No Razy-side config change is required in this topology — the edge decides.
(Optional hardening: still declare `exclude_paths` so the Razy-generated file is
correct *in depth*, i.e. also if the edge ever forwards too broadly.)

## Topology B — subdomain per app (default model)

```
  pyapp.example.com  ──►  vhost / site block A  ──►  python :8000
   example.com     ──►  vhost / site block B  ──►  Razy (docroot + generated files)
```

No path splitting anywhere: `sites.inc.php` binds `example.com` only, the sibling never
shares Razy's claim. This is the model the framework's per-domain bindings are built
around (dossier §3(e)); wildcard DNS + ACME covers the TLS cost. No files in this
directory are needed — this is the absence of the problem.

## Topology C — one Apache web server, declared exclusions

```
        example.com (one Apache vhost, docroot = Razy install)
                             │
                     .htaccess (machine-owned)
              ┌──────────────┴──────────────┐
   /api-py/…  │  exclusion rule [L]         │   ← from 'exclude_paths' in sites.inc.php
              ▼                             ▼
     vhost ProxyPass → python :8000   Razy rules → index.php
```

Files: `apache-vhost-exinclude.conf` (the operator-owned vhost piece) +
`sites.exclude.php.example` (the Razy-side exclusion config). Razy refuses the claim;
`ProxyPass` serves the sibling. Regenerate after every change:
`php Razy.phar rewrite <dist>` — never hand-edit `.htaccess` (RZ-013).

## Under FrankenPHP/Caddy

Same split, but note the generated Caddyfile only *declines* the claim
(`@not_excluded not path …` + `php_server @not_excluded`, manual/08 §3): the sibling's
handler must live in the edge (Topology A, `Caddyfile-edge.sample`). By decision (Q3) the
generator never emits `reverse_proxy` for sibling upstreams.
