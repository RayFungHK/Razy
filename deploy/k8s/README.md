# deploy/k8s — Razy worker fleet on Kubernetes (15,000 TPS sizing)

Manifests for running Razy in **FrankenPHP worker mode** as a stateless, autoscaled,
non-root fleet. Everything here is namespace-scoped to `razy-prod`.

* Image: [`../Dockerfile.worker`](../Dockerfile.worker) · Edge/worker config:
  [`../Caddyfile`](../Caddyfile), [`../php-opcache.ini`](../php-opcache.ini)
* Load side of the same objective: [`../benchmark-distributed/README.md`](../benchmark-distributed/README.md)

---

## 1. File map

| File | What it gives you | 15k-TPS relevance |
|---|---|---|
| [`namespace.yaml`](namespace.yaml) | `razy-prod` boundary + Pod Security Admission (`restricted`) | quota/blast-radius scope |
| [`secret.yaml`](secret.yaml) | Placeholders for `RAZY_BRIDGE_SECRET`, `RAZY_ALLOW_INSECURE_TRANSPORT`, DB/Redis | makes the fleet *deployable*, not just fast |
| [`configmap.yaml`](configmap.yaml) | `Caddyfile` + `php-opcache.ini` mounted per pod | `num_threads`, `opcache.jit*` = per-pod capacity |
| [`deployment.yaml`](deployment.yaml) | 2 vCPU / 4 Gi Guaranteed pods, 3 probes on `/_razy/health`, read-only rootfs, topology spread | `replicas: 6` = read-dominant floor |
| [`service.yaml`](service.yaml) | ClusterIP `:80 → :8080`, no session affinity | dataplane under 15k conn/s |
| [`hpa.yaml`](hpa.yaml) | CPU 65% + `http_requests_per_second` 1,500/pod, min 4 / max 20 | 15,000 ÷ 1,500 ⇒ ~10 pods steady |
| [`pdb.yaml`](pdb.yaml) | `minAvailable: 70%` | maintenance ≠ capacity cliff |
| [`ingress.yaml`](ingress.yaml) | TLS termination + per-Ingress timeouts (`proxy-http-version`, `service-upstream` option) | the edge can cap you below 15k — keep-alive/`ssl-protocols` are controller **ConfigMap** keys, not annotations |
| [`kustomization.yaml`](kustomization.yaml) | one `apply -k`, common labels, image pin | atomic capacity changes |

---

## 2. Bring-up

```bash
# 0. Prerequisites (see §4 before you bother with 15k):
#    - registry access, kubectl >= v1.23 (labels: block), kustomize v5 recommended
#    - Redis (sessions/cache), ProxySQL or RDS Proxy, MySQL sized for ~11k QPS
#    - metrics adapter (prometheus-adapter) if you want the request-rate HPA signal
#    - TLS cert for the public host

# 1. Build + push the worker image (context = repo root)
docker build -f deploy/Dockerfile.worker -t registry.example.com/razy/worker:1.0.3-beta .
docker push registry.example.com/razy/worker:1.0.3-beta
#    Prefer digest pinning: images: → digest: in kustomization.yaml

# 2. Replace the placeholders in secret.yaml (or swap the file for a
#    SealedSecret / ExternalSecret with the SAME key names)
#      openssl rand -hex 32   # RAZY_BRIDGE_SECRET

# 3. Apply the whole stack
kubectl apply -k deploy/k8s
kubectl -n razy-prod rollout status deployment/razy-worker

# 4. Verify the probe, from inside and outside
kubectl -n razy-prod exec deploy/razy-worker -- \
  wget -qO- http://127.0.0.1:8080/_razy/health
kubectl -n razy-prod get pods,hpa,pdb,ingress -o wide
curl -sk https://raz.example.com/_razy/health
```

Expected probe body (worker mode, `RAZY_HEALTH_VERBOSE=1`):

```json
{"status":"ok","uptime_seconds":42,"timestamp":1760000000,"version":"1.0.3-beta","php":"8.3.x","mode":"worker"}
```

`version`/`php`/`mode` only appear with `RAZY_HEALTH_VERBOSE=1`
([`src/library/Razy/Health.php:73-77`](../../src/library/Razy/Health.php));
`?token=...` adds `checks` when `RAZY_HEALTH_TOKEN` is set
([`Health.php:79-111`](../../src/library/Razy/Health.php)).

---

## 3. The 15,000-TPS sizing math

### 3.1 Measured anchors (no extrapolation — these are the runs in `benchmark/results`)

Container: **2 vCPU / 4 GB**, PHP 8.3, FrankenPHP **worker mode**, OPcache+JIT 1255
(`benchmark/results/BENCHMARK-REPORT.md:4-9`).

| Route | Per-container RPS | p95 | Source |
|---|---:|---:|---|
| `/benchmark/static` (dispatch only) | **6,331** | 18.6 ms | `results/COMPARISON-REPORT.md:59,62` |
| `/benchmark/template` | **6,264** | 19.0 ms | `results/COMPARISON-REPORT.md:72,75` |
| `/benchmark/db-read` | **3,763** | 38.5 ms | `results/COMPARISON-REPORT.md:85,88` |
| `/benchmark/composite` (DB read + render) | **4,528** | 72.4 ms | `results/COMPARISON-REPORT.md:113,116` |
| `/benchmark/db-write` (INSERT) | **754** | 182 ms | `results/COMPARISON-REPORT.md:98,101` |
| `/benchmark/heavy` (CPU-bound) | **144** | 595 ms | `results/COMPARISON-REPORT.md:129-131,147` |

> ⚠️ **Correction to a circulating draft figure.** An earlier sizing note quoted
> "db-read ≈ 8.8k RPS". No such number exists in `benchmark/results`: the measured
> db-read is **3,763 RPS** (`results/03_razy_v2.txt:994`,
> `COMPARISON-REPORT.md:85`), and the "4.7k" figure is the composite
> *Razy-vs-Laravel ratio* (`Razy (4.7x)`), whose RPS is **4,528**. The fleet sizing
> below uses the measured values, which is why it lands at 6 pods, not 3.

### 3.2 Discount, then division

Production discount **×0.6** on every anchor: mixed traffic instead of one route,
Ingress + TLS hop, log pipeline, JSON access logging (`deploy/Caddyfile`), GC
(`WORKER_GC_INTERVAL`), node neighbours, plus the HPA-lag window while a new pod
still has cold JIT traces.

| Route | Measured | ×0.6 ⇒ per-pod budget | Pods for 15,000 |
|---|---:|---:|---:|
| static/template only | 6,331 / 6,264 | 3,799 / 3,758 | **4** |
| composite (read+render) | 4,528 | 2,717 | 5.5 ⇒ **6** |
| db-read dominant | 3,763 | 2,258 | 6.6 ⇒ **7** |

**Mixed read-dominant traffic** — the `read-dominant` profile in
`deploy/benchmark-distributed/load-test.js` (45% composite, 30% db-read, 20% static,
5% probe) — must be summed by *service demand*, not by averaging rates:

```
per-pod mixed RPS = 1 / Σ(weight_i / discounted_rps_i)
                  = 1 / (0.45/2717 + 0.30/2258 + 0.20/3799 + 0.05/3799)
                  ≈ 2,745 mixed requests/s per 2 vCPU pod

15,000 / 2,745 = 5.47  ⇒  6 pods  (deployment.yaml ships replicas: 6)
```

Without the discount the same mix is ≈ 4,575 RPS/pod ⇒ 3.3 ⇒ 4 pods. **That 4-vs-6
gap is the whole point of the discount**: it is what you pay for a node loss, a
JIT-cold pod during scale-out, and p99 instead of mean.

Fleet envelope at `maxReplicas: 20` ⇒ 20 × 2,745 ≈ **54.9k RPS** discounted
composite-equivalent ⇒ ~3.7× headroom over the objective (HPA sizing in
[`hpa.yaml`](hpa.yaml): 15,000 ÷ 1,500 RPS/pod target ⇒ ~10 pods steady).

### 3.3 What the sizing is NOT

* **Write-heavy 15k TPS is not on the menu.** `/benchmark/db-write` measured
  **754 RPS** and the report attributes it to MySQL INSERT throughput, not the
  framework (`results/COMPARISON-REPORT.md:163`). More pods do not add MySQL write
  IOPS. For write-dominant load you need: an async write path (queue + worker),
  primary/replica split, and realistically table sharding — and you should re-run
  `PROFILE=read-write-mix` before promising anything.
* **CPU-bound traffic caps far below 15k** (heavy: 144 RPS/pod,
  `COMPARISON-REPORT.md:147`). `PROFILE=cpu-mixed` keeps it at 1% for that reason.
* **Anchors are single-container numbers.** Multi-pod adds per-pod Redis/MySQL
  connection demand and one more network hop; re-measure with
  `deploy/benchmark-distributed/` before you trust a bigger number than 2,745/pod.

---

## 4. Mandatory prerequisites before claiming 15k

These four are the actual gaps. The framework is not one of them (§5).

### 4.1 Sessions → Redis (or stateless tokens)

`/_razy/health` needs no session, but business routes do: the worker calls
`session_write_close()` per request (`src/main.php:225-228`) and the pre-worker
baseline did a `session_start()` per request
(`results/COMPARISON-REPORT.md:30`). With default `files` handler, sessions live on
each pod's ephemeral `/tmp` — 20 pods ⇒ 20 disconnected session namespaces, and
every restart wipes them. Put the handler in Redis (`session.save_handler=redis` +
`session.save_path` via a second conf.d drop-in, or Razy's own session store in
`config.inc.php`) or drop cookie-sessions for tokens.
`deploy/k8s/secret.yaml` already reserves `RAZY_REDIS_SESSION_HOST/PORT` for your
`config.inc.php` to read.

### 4.2 Cache → Redis (and the local disk cache is per-pod)

`CACHE_FOLDER = SYSTEM_ROOT/data` and `PLUGIN_FOLDER = SYSTEM_ROOT/plugins`
([`src/system/bootstrap.inc.php:41,44-46`](../../src/system/bootstrap.inc.php)).
Because the root filesystem is read-only, `deployment.yaml` mounts an `emptyDir` at
`/app/site/data` — so compiled templates and framework cache are **per pod and per
lifetime**: every deploy/restart recompiles templates (a latency spike right when
the HPA also wants warm pods), and pods never share cache. Options, in order of
preference:

1. Move shared cache to Redis (`RAZY_REDIS_CACHE_HOST/PORT` reserved in the Secret).
2. Keep the disk cache but make it survive restarts: `ReadWriteMany` PVC (or a
   per-node `ReadWriteOnce` via a DaemonSet-local path) at `/app/site/data`.
3. Accept recompiles and pre-warm each pod (`startupProbe` budget is 180 s for
   exactly this; optionally curl a few business routes from an initContainer).

### 4.3 Readiness is *not* dependency readiness

`/_razy/health` is documented and implemented as **liveness, not readiness**: it is
answered before Application boot/routing and always returns
`{"status":"ok","uptime_seconds":N}` with HTTP 200 —
[`Health.php:5-24`](../../src/library/Razy/Health.php) ("Liveness, not
dependency-readiness: DB/cache round-trips need distributor/module context this
endpoint deliberately does not boot"). Consequences:

* All three probes in `deployment.yaml` gate *"the worker answers HTTP"*, which is
  the correct, cheap thing for liveness — and a false comfort for readiness.
* A pod whose MySQL/Redis is down still passes readiness and receives traffic. Fix
  it outside this file set: an app-level readiness route (module/controller that
  pings DB+Redis) for `readinessProbe`, or an initContainer that TCP-checks the
  dependencies, or Envoy/Service-entry checks. Keep **liveness** on `/_razy/health`
  (a dependency blip must not restart 20 pods).

### 4.4 DB connection pooling in front of MySQL

Worker mode makes this *worse-looking but better-behaved*: connections persist
across requests (`results/COMPARISON-REPORT.md:31`, `Database::resetInstances()`
skipped), so demand is bounded at `pods × FRANKENPHP_NUM_THREADS` ≈ 20 × 2 = **40**
persistent connections — trivial for `max_connections=500`
(`benchmark/docker-compose.yml:37`). The real needs are different:

* ~**11k queries/s** at 15k read-dominant TPS (75% of requests touch the DB) on
  whatever MySQL is behind it — that host, not Razy, is the ceiling.
* A **pooler/proxy** (ProxySQL / RDS Proxy) for failover, query routing
  (read vs write hostgroups), and connection re-use across *deploys* — because
  pods are ephemeral and 40 reconnects every rolling update is a thundering herd
  on a primary that is also doing 11k QPS.
* Point `RAZY_DB_HOST` at the pooler (already the placeholder value), never at a
  pod-IP-resolved MySQL Service.

---

## 5. Honest caveats

1. **The framework is not the bottleneck.** Razy worker mode beats Laravel
   Octane/Swoole on 4 of 6 scenarios (`results/COMPARISON-REPORT.md:140-147`) and
   its per-request overhead is ~0.05 ms (`results/COMPARISON-REPORT.md:35`). The
   remaining gaps to a 15k claim are the four items in §4 — K8s wiring, health
   semantics, connection pooling, state externalisation.
2. **`/_razy/health` will never tell you about B.** It cannot report DB/Redis
   health and never returns `degraded` by itself (§4.3). Anything downstream that
   expects `status:"degraded"` semantics must implement them.
3. **The load generator is a real limit.** 15k RPS *with checks* needs ~1,100
   pre-allocated VUs — a single laptop tops out a few thousand RPS. Use
   [`../benchmark-distributed/k6-distributed-job.yaml`](../benchmark-distributed/k6-distributed-job.yaml).
4. **Shared memory is not per-thread.** `opcache.memory_consumption` (256 M, which
   contains the 32 M interned-string pool) **plus** `opcache.jit_buffer_size`
   (128 M) ≈ 384 M of shm per container, shared by all worker threads
   (`deploy/php-opcache.ini` MEMORY BUDGET). `memory_limit=512M` is per worker
   thread. 4 Gi is roomy at 2 threads; check the math before raising threads or
   `memory_limit`, and watch `dmesg`/`OOMKilled` (exit 137) as the failure mode.
5. **PHP ≥ 8.4 changes the JIT defaults.** From 8.4.0, `opcache.jit` defaults to
   *disable* and JIT init failure is a fatal startup error — the explicit values in
   `deploy/php-opcache.ini` are what keep throughput across a base-image bump, and
   `jit_buffer_size` must actually fit
   ([`deploy/php-opcache.ini`](../php-opcache.ini) PHP VERSION NOTES).
6. **`--adapter=frankenphp` is version-sensitive.** Older FrankenPHP builds lack the
   flag; worker mode comes from the Caddyfile `frankenphp { worker … }` block, so
   deleting that one token is safe if your pinned base image rejects it
   ([`deploy/Dockerfile.worker`](../Dockerfile.worker) ENTRYPOINT note).
7. **No pod-level metrics by default.** `admin off` in the Caddyfile means no
   Caddy `/metrics`, and the HPA's request-rate signal usually comes from the
   Ingress controller's metrics instead. Without an adapter, only CPU acts — it
   works, it just lags. Enable `admin localhost:2019` + PodMonitor if you want
   Caddy's own counters.
8. **ConfigMap edits are not hot-reloaded** (subPath mounts). Edit →
   `kubectl -n razy-prod rollout restart deployment/razy-worker`, or bump the
   `razy.example.com/config-version` annotation so the rollout is auditable.
9. **Topology spread is `ScheduleAnyway`.** Lopsided placement is allowed during an
   emergency scale-out; only zone spread + PDB bound the blast radius. Change to
   `DoNotSchedule` if you have ≥ `maxReplicas`-worthy node count and want strictness.
10. **Placeholders everywhere**: `registry.example.com`, `raz.example.com`,
    `razy.example.com/config-version`, `nginx` ingress class, and every Secret value
    must be replaced before this is a real environment.
11. **Razy-level discipline still applies**: routes belong to
    `addRoute`/`addLazyRoute` + `php Razy.phar rewrite` (RZ-013) — the Ingress only
    forwards `/` and `/_razy/health`; it never re-implements app routing. Cross-distributor calls need
    `RAZY_BRIDGE_SECRET` for the HMAC gate (RZ-002, `secret.yaml`).
12. **ingress-nginx ignores unknown annotations silently.** The per-Ingress
    annotations in [`ingress.yaml`](ingress.yaml) are the verified ones
    (`backend-protocol`, `proxy-http-version`, `proxy-*-timeout`, `proxy-body-size`,
    `proxy-next-upstream*`, `ssl-prefer-server-ciphers`, optionally
    `service-upstream`). The 15k-critical keep-alive and TLS-protocol knobs
    (`upstream-keepalive-connections/-requests/-timeout`, `ssl-protocols`,
    `worker-connections`, HSTS) are **controller ConfigMap** keys and are documented
    as comments there rather than pasted in as no-ops — and
    `upstream-keepalive-timeout` must be shorter than Caddy's `idle 75s`, or the
    edge hands requests to sockets the pods already closed.

---

## 6. Operational runbook

```bash
# Where is capacity right now?
kubectl -n razy-prod describe hpa razy-worker           # desired/desired-by-resource
kubectl -n razy-prod top pods | grep razy-worker         # CPU per pod vs 2 CPU request
kubectl -n razy-prod get events --sort-by=.lastTimestamp | tail -30

# Worker-level state (JIT/OPcache complaints, queue rejections)
kubectl -n razy-prod logs deploy/razy-worker --tail=200   # JSON access log on stderr

# Config change (Caddyfile / opcache ini) — remember it is NOT hot-reloaded:
kubectl -n razy-prod apply -f deploy/k8s/configmap.yaml
kubectl -n razy-prod rollout restart deployment/razy-worker
kubectl -n razy-prod rollout status deployment/razy-worker

# Capacity override during an incident (HPA reclaims it later)
kubectl -n razy-prod scale deployment/razy-worker --replicas=12

# Rollback to the previous image
kubectl -n razy-prod rollout undo deployment/razy-worker
```

**Expected surge behaviour** (15k from cold): CPU crosses 65% → HPA adds pods →
each new pod sits in `startupProbe` for its boot + JIT warm-up (up to 180 s) →
existing pods queue (`FRANKENPHP_MAX_QUEUE_SIZE=1024`, then fast 503 rather than
unbounded latency) → `http_req_duration{kind:business}` p99 spikes and, if the
queue overflows, `http_req_failed` crosses 1%. That is the signal to raise
`minReplicas` (or `scaleUp` policies in [`hpa.yaml`](hpa.yaml)), not the signal that
Razy is slow.

---

## 7. Optional hardening (deliberately not shipped)

* **ResourceQuota / LimitRange** in `razy-prod` so a runaway HPA cannot eat the
  cluster: cap `requests.cpu: "40"`, `requests.memory: 80Gi` (matches
  `maxReplicas: 20` × 2 vCPU / 4 Gi).
* **NetworkPolicy** allowing ingress to `:8080` only from the Ingress controller
  namespace, and egress only to Redis/MySQL/pooler.
* **Image verification**: pin `images: → digest:` in
  [`kustomization.yaml`](kustomization.yaml); `IfNotPresent` + digest is the
  cheapest supply-chain win here.
* **Warm-up Job / initContainer** hitting a few business routes before readiness,
  so new pods stop paying cold-JIT cost on real traffic.
* **Argo Rollouts / Flagger** if you want canary-by-metric on `p95` instead of
  plain rolling updates (maxUnavailable 0 already protects availability).
