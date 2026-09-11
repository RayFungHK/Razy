# deploy/benchmark-distributed — driving an aggregate 15,000 RPS at Razy

[`load-test.js`](load-test.js) is the 15k-TPS extension of
[`benchmark/k6/scenarios/`](../../benchmark/k6/scenarios): same routes, same checks
style, but **arrival-rate** executors (requests/second instead of VUs) and a
shard-friendly design, because a single k6 process driving VU ramps cannot deliver
15k RPS of *verified* requests.

Server side of the same objective: [`../k8s/README.md`](../k8s/README.md).

---

## 1. Pinned toolchain (the result is only reproducible if the generator is fixed)

| Component | Pin | Why it matters at 15k |
|---|---|---|
| k6 | `grafana/k6:0.57.0` (or any 0.57+/1.x you have verified) | arrival-rate executors, `check(val,sets,tags)`, `setResponseCallback`, per-metric tags — older k6s reject parts of `load-test.js` |
| jslib | `https://jslib.k6.io/k6-summary/0.0.3/index.js` | same pinned version the existing scenarios import (`benchmark/k6/scenarios/01_static_route.js:75`) so summaries stay comparable |
| Docker Engine | 24+ / Compose v2.20+ | used for the local stack and the k6 container |
| kubectl | v1.30.x (min v1.23) | `batch/v1` Indexed Job used here |
| kustomize | v5.x (bundled in kubectl) | applying the fleet under test |
| Target image | `deploy/Dockerfile.worker` @ PHP 8.3 / `dunglas/frankenphp:latest-php8.3-alpine` | OPcache+JIT defaults change in PHP 8.4 — do not compare runs across a PHP bump |
| Runner CPUs | 2 vCPU per shard, ≥ 6 shards | the generator's own p95 contaminates the measurement |

Check the pin before a run: `docker run --rm grafana/k6:0.57.0 version`.
`thresholds.abortOnFail` / `abortGracePeriod` are supported only on newer k6
releases — they are left commented in `load-test.js`; verify with `k6 version` and
the release notes before enabling.

---

## 2. How the 15k is shaped

`TOTAL_RPS` (default **15000**) is split across the routes of one **PROFILE**; each
route becomes its own `ramping-arrival-rate` executor, so the plateau sums to
exactly `TOTAL_RPS`:

```
PROFILE=read-dominant (default), TOTAL_RPS=15000
  composite  45% →  6,750 RPS    (pre-alloc 507 VUs, max 2,025)
  db_read    30% →  4,500 RPS    (pre-alloc 338 VUs, max 1,350)
  static     20% →  3,000 RPS    (pre-alloc 225 VUs, max   900)
  health      5% →    750 RPS    (pre-alloc  57 VUs, max   225)
  ─────────────────────────────
  plateau aggregate    15,000 RPS      threshold gate: http_reqs rate >= 14,250
```

Shape per executor: 60 s × 25 % → 60 s × 50 % → 60 s × 75 % → 60 s × 100 % →
`SUSTAIN` (default **5m**) plateau → 60 s ramp-down. The staircase exists because a
fleet that jumps to 15k instantly lands on pods whose JIT/OPcache traces are still
cold — that measures warm-up, not steady state.

### Profiles ↔ the existing six scenarios

| Existing scenario | Route | Used by profiles |
|---|---|---|
| `01_static_route.js` | `GET /benchmark/static` | `read-dominant` (20%), `static-dominant` (50%), `cpu-mixed` (19%) |
| `02_template_render.js` | `GET /benchmark/template` | `static-dominant` (45%) |
| `03_db_read.js` | `GET /benchmark/db-read?id=1..1000` | `read-dominant` (30%), `read-write-mix` (25%), `cpu-mixed` (29%) |
| `04_db_write.js` | `POST /benchmark/db-write` (same JSON body) | `read-write-mix` (35%) |
| `05_composite.js` | `GET /benchmark/composite?id=1..1000` | `read-dominant` (45%), `read-write-mix` (35%), `cpu-mixed` (49%) |
| `06_heavy_cpu.js` | `GET /benchmark/heavy?iterations=500000` | `cpu-mixed` (1%) only |
| *(new)* framework probe | `GET /_razy/health` | every profile (2–5%) |

Why `heavy` is capped at 1%: it measured **144 RPS per container**
(`benchmark/results/COMPARISON-REPORT.md:147`). At 10 pods the ceiling is ~1.4k
RPS, so giving it 10 % of 15k would fail `http_reqs` for arithmetic reasons, not
framework reasons. Likewise `read-write-mix` is a ceiling-finder — db-write
measured 754 RPS and the report attributes it to MySQL, not Razy
(`benchmark/results/COMPARISON-REPORT.md:98,163`).

---

## 3. Run it

### 3.0 Smoke test against the existing compose stack (single instance)

One `bench-razy` container is a 2 vCPU anchor ≈ 4.5k composite RPS — it **cannot**
serve 15k. Use a small `TOTAL_RPS` here to validate the script and the routes:

```bash
docker compose -f benchmark/docker-compose.yml up -d          # MySQL + bench-razy

docker run --rm --network benchmark_default \
  -e TARGET_HOST=bench-razy:8080 -e TOTAL_RPS=500 -e SUSTAIN=1m \
  -v "$PWD/deploy/benchmark-distributed:/scripts" \
  grafana/k6:0.57.0 run /scripts/load-test.js
```

### 3.1 Distributed against the Kubernetes fleet (the 15k run)

```bash
# 1. publish the script the Job mounts
kubectl -n razy-prod create configmap razy-loadtest-script \
  --from-file=load-test.js=deploy/benchmark-distributed/load-test.js \
  --dry-run=client -o yaml | kubectl apply -f -

# 2. 6 shards × 2,500 RPS = 15,000 RPS aggregate
kubectl -n razy-prod apply -f deploy/benchmark-distributed/k6-distributed-job.yaml
kubectl -n razy-prod wait --for=condition=complete job/razy-load-15k --timeout=25m \
  || kubectl -n razy-prod describe job razy-load-15k

# 3. read the summaries
kubectl -n razy-prod logs -l job-name=razy-load-15k --prefix --tail=200
for p in $(kubectl -n razy-prod get pods -l job-name=razy-load-15k -o name); do
  kubectl -n razy-prod cp "$p" k6 /results "./results/${p##*/}"
done
```

Each shard asserts its **own** `http_reqs rate >= 2,375` (95 % of 2,500). All six
green ⇒ the aggregate delivered ≥ 14,250 RPS. One red shard ⇒ the objective was not
met; `backoffLimit: 0` keeps that visible instead of hiding it behind a retry.

### 3.2 Distributed on N plain hosts (no Kubernetes)

```bash
# per host i of N=6:
TARGET_BASE_URL=http://razy-worker.example.internal \
TOTAL_RPS=2500 PROFILE=read-dominant SUSTAIN=5m INSTANCE_INDEX=$i \
RESULTS_DIR=/tmp/k6 \
  k6 run deploy/benchmark-distributed/load-test.js
```

`TOTAL_RPS` is *per process* — divide it yourself (`15000/N`), and gate each host on
its own share. Aggregate afterwards:

```bash
python - <<'PY'
import json, glob
tot = 0.0
for f in glob.glob('/tmp/k6/load-test_*.json'):
    m = json.load(open(f))['metrics']
    r = m.get('http_reqs', {}).get('points', {}).get('rate') or m['http_reqs'].get('rate', 0)
    tot += r
print('aggregate RPS across shards:', round(tot, 1), '/ 15000 =', f'{tot/150:.1f}%')
PY
```

### 3.3 k6-operator / k6 Cloud alternative

`grafana/k6-operator` (`K6` CRD with `parallelism: 6`) does the same split
automatically, and k6 Cloud aggregates shards natively. Not required: §3.1 uses
plain `batch/v1` so it runs on any cluster with no CRDs. If you do use the
operator, keep `TOTAL_RPS` at the *per-instance* value it computes and re-check the
threshold semantics, or you will gate each shard on the whole 15k.

---

## 4. Environment variables

| Var | Default | Meaning |
|---|---|---|
| `TARGET_BASE_URL` | `http://$TARGET_HOST` (fallback `localhost:8081`) | full base URL; prefer this |
| `TARGET_HOST` | `localhost:8081` | host:port, same var name the existing scenarios use |
| `TOTAL_RPS` | `15000` | aggregate target **per k6 process** (divide by shard count yourself) |
| `PROFILE` | `read-dominant` | `read-dominant` \| `static-dominant` \| `read-write-mix` \| `cpu-mixed` \| `health-only` |
| `SUSTAIN` | `5m` | plateau duration at the target rate |
| `VU_FACTOR` | `0.15` | expected max latency (s) used to size `preAllocatedVUs`/`maxVUs`; raise it if the fleet is slower than 150 ms p95 |
| `INSTANCE_INDEX` | `0` (the Job sets it to the shard index) | metric tag only, for correlation |
| `RESULTS_DIR` | `.` | where the summary JSON lands (Job mounts `/results`) |
| `K6_INSECURE_TLS` | unset | `1` = skip TLS verification (self-signed test edge only) |

---

## 5. Thresholds and what a red line means

| Gate | Value | If it fails, check in this order |
|---|---|---|
| `http_reqs` | `rate >= 95% of TOTAL_RPS` | generator CPU saturation (`maxVUs` hit → k6 logs "Insufficient VUs") → HPA lag / pod count → worker queue overflow (`FRANKENPHP_MAX_QUEUE_SIZE`) |
| `http_req_failed` | `rate < 1%` | 503 = queue rejection (`max_wait_time`/`max_queue_size` in [`../Caddyfile`](../Caddyfile)); 502/504 = Ingress timeouts vs Caddy `write 60s`; pod restarts = probes (`kubectl describe pod`) |
| `ok_rate{kind:probe}` | `> 99.9%` | probe latency means the worker threads are fully occupied — pods will flake in Kubernetes before this test even finishes (§ "surge behaviour" in [`../k8s/README.md`](../k8s/README.md)) |
| `http_req_duration{kind:probe}` | `p95<25ms, p99<50ms` | probe path is pre-routing ([`src/library/Razy/Health.php:33-54`](../../src/library/Razy/Health.php)) — if it is slow, the edge or the network is, not Razy |
| `http_req_duration{kind:business}` | `p95<150ms, p99<400ms` | per-container p95 anchors were 18–72 ms; a fleet 2× that is TLS+hop+queue, 5× that is DB/pooler |
| `…{scenario:db_read}` / `db_write` | `p95<120/300ms` | MySQL + pooler first (db-read anchor 3,763 RPS, db-write 754 RPS) |
| `…{scenario:heavy}` | `p95<1200ms` | CPU-bound by design; only in `cpu-mixed` at 1% |
| `health_degraded` | `rate < 1%` | counts the literal string `"degraded"` in the probe body — the stock probe only ever emits `"ok"` (`Health.php:67-71`), so a non-zero rate means something *added* degraded reporting |
| `checks` | `rate > 98%` | body-shape drift (e.g. template route changed) rather than capacity |

**Generator hygiene** (otherwise you measure your laptop):
watch for `Insufficient VUs, reached N active VUs and cannot create more` — that is
`maxVUs` exhaustion, i.e. the generator, not the target. Keep shard CPUs
non-overlapping with worker pods (`k6-distributed-job.yaml` spreads generator pods
onto separate nodes), keep `checks` (they cost the generator CPU), and prefer the
cluster-internal `TARGET_BASE_URL` (`http://razy-worker.razy-prod.svc.cluster.local`)
when you want the fleet number and the HTTPS host when you want the whole path.
Response bodies are kept (needed by the checks); they are small JSON/HTML, so this
is not the memory problem it usually is.

---

## 6. What "passed" means — and what it does not

A green 15k run means: **N pods of this image, with this ConfigMap, this MySQL/Redis
topology, and this traffic mix, sustained ≥ 14,250 checked requests/s for the whole
`SUSTAIN` window, with the health probe still answering inside its own budget.**

It does **not** mean:
* 15k for a write-dominant workload (see §2, MySQL-bound at ~754 RPS/container),
* 15k with the Ingress path excluded — that depends on which `TARGET_BASE_URL` you used,
* capacity for the *production* mix, unless your mix matches `read-dominant`,
* anything at all if the anchors were crossed (PHP 8.4 defaults change the JIT;
  see [`../php-opcache.ini`](../php-opcache.ini) PHP VERSION NOTES).

Per-pod expectations for the default profile, derived from the measured anchors in
[`benchmark/results`](../../benchmark/results) (composite 4,528 / db-read 3,763 /
static 6,331 RPS per 2 vCPU container, discounted ×0.6 ⇒ ≈ 2,745 mixed RPS/pod):
6 pods is the floor, ~10 pods is the HPA's steady state at the 15k objective, 20 pods
is ~3.7× headroom. Full derivation:
[`../k8s/README.md` §3](../k8s/README.md).
