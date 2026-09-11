# deploy/ — production deployment infrastructure for Razy

Runtime extension of the framework for one objective: **sustain ~15,000 TPS**
(read-dominant) with an auditable, repeatable, non-root stack. Nothing in this
directory modifies framework code; it only packages and operates it.

```
deploy/
├── README.md                    ← you are here
├── Dockerfile.worker            ← multi-stage, NON-ROOT FrankenPHP worker image
├── Caddyfile                    ← worker-mode edge config (health passthrough, gzip, JSON logs)
├── php-opcache.ini              ← OPcache + tracing JIT drop-in (the per-pod capacity knob)
├── k8s/                         ← namespace-scoped fleet: probes, HPA, PDB, Ingress, kustomize
│   ├── README.md                ← ★ 15k sizing math, prerequisites, honest caveats
│   └── {namespace,secret,configmap,deployment,service,hpa,pdb,ingress,kustomization}.yaml
└── benchmark-distributed/       ← k6 driver + 6-shard Job for an aggregate 15,000 RPS
    ├── README.md                ← how to run, pinned toolchain, thresholds decision tree
    ├── load-test.js
    └── k6-distributed-job.yaml
```

## The three things that make 15k reachable

1. **Worker mode, not `php -S`.** The existing production image
   ([`.docker/Dockerfile:58`](../.docker/Dockerfile)) runs the PHP dev server: one
   process, boot-per-request. [`Dockerfile.worker`](Dockerfile.worker) launches
   FrankenPHP with `frankenphp { worker /app/site/index.php }`
   ([`Caddyfile`](Caddyfile)), reusing the launch pattern proven in
   [`benchmark/docker/Dockerfile.razy`](../benchmark/docker/Dockerfile.razy) +
   [`benchmark/docker/Caddyfile.razy`](../benchmark/docker/Caddyfile.razy), which is
   how Razy measured **3,763–6,331 RPS per 2 vCPU container**
   ([`benchmark/results/COMPARISON-REPORT.md:140-147`](../benchmark/results/COMPARISON-REPORT.md)).
2. **A committed OPcache/JIT profile.** [`php-opcache.ini`](php-opcache.ini) keeps
   `validate_timestamps=0`, JIT tracing (`opcache.jit = 1255`, the measured value),
   and documented memory budgeting — the difference between 4.5k and 45 RPS/pod.
3. **A fleet that can say what it is doing.** Three probes on
   `GET /_razy/health` ([`src/library/Razy/Health.php`](../src/library/Razy/Health.php)),
   HPA on CPU + per-pod request rate, PDB, topology spread, and a distributed load
   test that proves the number instead of asserting it.

## Fastest path

```bash
docker build -f deploy/Dockerfile.worker -t registry.example.com/razy/worker:1.0.3-beta .
docker run --rm -p 8080:8080 registry.example.com/razy/worker:1.0.3-beta
curl -s localhost:8080/_razy/health          # {"status":"ok","uptime_seconds":…,"version":"…"}

# then the cluster (read k8s/README.md §2-§4 first — the prerequisites are mandatory)
kubectl apply -k deploy/k8s
kubectl -n razy-prod create configmap razy-loadtest-script \
  --from-file=load-test.js=deploy/benchmark-distributed/load-test.js \
  --dry-run=client -o yaml | kubectl apply -f -
kubectl -n razy-prod apply -f deploy/benchmark-distributed/k6-distributed-job.yaml
```

## Sizing, in one line

Discounted (×0.6) mixed read-dominant capacity is **≈ 2,745 RPS per 2 vCPU pod** ⇒
**6 pods** is the floor for 15,000 TPS, ~10 pods is the HPA steady state, 20 pods
(`maxReplicas`) is ~3.7× headroom. Derivation, sources, and the correction of a
circulating "db-read 8.8k RPS" figure (the measured value is **3,763**):
[`k8s/README.md` §3](k8s/README.md).

## Scope notes

* Health contract used everywhere here: `GET /_razy/health` →
  `{"status":"ok","uptime_seconds":N,"timestamp":ts}` (+`version`/`php`/`mode` with
  `RAZY_HEALTH_VERBOSE=1`). It is **liveness**, never `degraded`, and never reflects
  DB/cache state — readiness for dependencies is yours to add
  ([`k8s/README.md` §4.3](k8s/README.md)).
* Env vars referenced are real ones only: `RAZY_BRIDGE_SECRET` (+`_OLD` rotation
  window), `RAZY_ALLOW_INSECURE_TRANSPORT`, `RAZY_HEALTH_VERBOSE`,
  `RAZY_HEALTH_TOKEN`, `WORKER_MAX_REQUESTS`, `WORKER_GC_INTERVAL`, `RAZY_DEBUG`,
  `RAZY_TIMEZONE`, `RAZY_MULTIPLE_SITE`-compatible config keys. DB/Redis host vars in
  `k8s/secret.yaml` are **application-level conventions** for your `config.inc.php`,
  not framework APIs, and are labelled as such.
* Framework discipline still applies at the edges: routes stay in
  `addRoute`/`addLazyRoute` + `php Razy.phar rewrite` (RZ-013) — the Ingress only
  forwards `/` and `/_razy/health`; the bridge HMAC gate stays on
  (`RAZY_BRIDGE_SECRET`, RZ-002); module installs must not opt into insecure
  transport (`RAZY_ALLOW_INSECURE_TRANSPORT=0`).
