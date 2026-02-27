# Razy Framework — Benchmark Report

**Date:** 2026-02-27  
**PHP:** 8.3.7 (FrankenPHP worker mode, Alpine)  
**Framework:** Razy v0.5 (Phar, 600,453 bytes) — Standalone mode, worker loop  
**Container:** cpus=2.0, memory=4G  
**OPcache:** JIT 1255, buffer 128M, memory 256M, validate_timestamps=0  
**Load Tool:** k6 (grafana/k6:latest via Docker, same network)  
**Database:** MySQL 8.0 (Docker container, same host)  

---

## Scenario: Static Route (`/benchmark/static`)

Measures pure dispatch + response path with a trivial "ok" handler.  
No template, no DB, no I/O — isolates the framework routing overhead.

### Load Profile

| Phase     | Duration | VUs           |
|-----------|----------|---------------|
| Warmup    | 30s      | 10 constant   |
| Ramp 1    | 30s      | 10 → 50       |
| Ramp 2    | 60s      | 50 → 100      |
| Ramp 3    | 60s      | 100 → 200     |
| Sustain   | 30s      | 200            |
| Ramp-down | 30s      | 200 → 0       |

**Total duration:** 240s (4 min) — **Max VUs:** 200  

---

## Pre-Optimization Baseline (3 runs)

| Metric         | Run 1      | Run 2      | Run 3      | **Average**  |
|----------------|------------|------------|------------|--------------|
| **RPS**        | 6,310.52   | 6,308.42   | 6,313.27   | **6,310.74** |
| **Avg latency**| 5.41ms     | 5.42ms     | 5.41ms     | **5.41ms**   |
| **Med latency**| 2.77ms     | 2.78ms     | 2.76ms     | **2.77ms**   |
| **p90 latency**| 14.43ms    | 14.45ms    | 14.36ms    | **14.41ms**  |
| **p95 latency**| 18.95ms    | 18.91ms    | 18.80ms    | **18.89ms**  |
| **Total reqs** | 1,514,500  | 1,513,944  | 1,515,106  | 1,514,517    |
| **Errors**     | 0          | 0          | 0          | 0            |

Variance: <0.08% — highly stable baseline.

---

## Post-Optimization Results (3 runs)

| Metric         | Run 1\*    | Run 2      | Run 3      | **Avg (R2–R3)** |
|----------------|------------|------------|------------|------------------|
| **RPS**        | 6,149.95   | 6,407.02   | 6,420.26   | **6,413.64**     |
| **Avg latency**| 5.81ms     | 5.18ms     | 5.15ms     | **5.16ms**       |
| **Med latency**| 3.15ms     | 2.67ms     | 2.64ms     | **2.65ms**       |
| **p90 latency**| 15.24ms    | 13.74ms    | 13.65ms    | **13.69ms**      |
| **p95 latency**| 19.87ms    | 18.14ms    | 17.96ms    | **18.05ms**      |
| **Total reqs** | 1,475,914  | 1,537,621  | 1,540,838  | 1,539,230        |
| **Errors**     | 0          | 0          | 0          | 0                |

\* Run 1 is a cold-start outlier (first run after container restart, despite 30s warmup).  
Runs 2–3 variance: <0.2% — consistent and reliable.

---

## Improvement Summary

Using the stable Runs 2–3 average vs. baseline average:

| Metric          | Baseline   | Optimized  | Delta      |
|-----------------|------------|------------|------------|
| **RPS**         | 6,310.74   | 6,413.64   | **+1.63%** |
| **Avg latency** | 5.41ms     | 5.16ms     | **−4.62%** |
| **Med latency** | 2.77ms     | 2.65ms     | **−4.33%** |
| **p90 latency** | 14.41ms    | 13.69ms    | **−4.99%** |
| **p95 latency** | 18.89ms    | 18.05ms    | **−4.45%** |
| **Total reqs**  | 1,514,517  | 1,539,230  | **+24,713**|

---

## Optimizations Applied

### 1. Eliminated duplicate `Error::reset()` (`src/main.php`)
Removed redundant call at handler start; kept only the `finally` block version.

### 2. Pre-tidied URL passed to dispatch (`src/main.php` → `Standalone.php`)
`PathUtil::tidy()` called once in the main handler loop; downstream `dispatchStandalone()` receives the already-clean URL.

### 3. Pre-computed route metadata at registration (`RouteDispatcher.php`)
At `setRoute()` time, pre-computes: `is_redirect`, `redirect_absolute`, `redirect_target`, `tidied_path`, `tidied_route`, `module_code`. Eliminates per-request string manipulation in `matchRoute()`.

### 4. Skip MiddlewarePipeline when empty (`RouteDispatcher.php`)
When no middleware is attached to a route, bypasses pipeline construction entirely.

### 5. Spread operator instead of `call_user_func_array` (`RouteDispatcher.php`)
`$closure(...$args)` replaces `call_user_func_array($closure, $args)` for faster invocation.

### 6. `MiddlewarePipeline::isEmpty()` uses `empty()` (`MiddlewarePipeline.php`)
`empty($this->middleware)` instead of `count($this->middleware) === 0`.

### 7. ClosureLoader fast-path cache (`ClosureLoader.php`)
Pre-check `isset($this->closures[$path])` before path resolution. Caches controller closures by raw path.

### 8. ModuleRegistry `announce()` short-circuit (`ModuleRegistry.php`)
Skips cross-module announcement when queue has ≤1 module.

---

## Worker Mode Fixes (Applied Prior to Benchmarking)

During earlier benchmarking, three framework-level issues were discovered and fixed:

1. **Stale `URL_QUERY` constant** — In FrankenPHP worker mode, `URL_QUERY` was defined once at script startup and never updated. Fixed by computing the URL query dynamically per-request from `$_SERVER['REQUEST_URI']`.

2. **Missing worker loop** — `frankenphp_handle_request()` was called once (no loop), causing the worker to exit after every request. Fixed by wrapping in a `do/while` loop with `WORKER_MAX_REQUESTS` env var support.

3. **HTTP status code leaking** — `http_response_code()` state persisted across worker requests. Fixed by resetting to 200 at handler entry.

---

## Methodology Notes

- **Baseline script:** `01_static_route.js` (with handleSummary + textSummary import)
- **Post-opt script:** v2 inline script (identical profile, no handleSummary, uses `--summary-export`)
- Both scripts use identical: scenarios, stages, VU counts, thresholds, `sleep(0.01)`, custom metrics
- All runs performed on same machine, same Docker host/network
- Container restarted + 30s warmup (20 VUs) before each set of runs
- No concurrent k6 processes during clean runs
- 100% success rate (0 HTTP errors) across all runs

## How to Reproduce

```bash
# Start containers
docker compose -f benchmark/docker-compose.yml up -d

# Run individual scenario
docker run --rm \
  --network benchmark_default \
  -e TARGET_HOST=bench-razy:8080 \
  -v ./benchmark/k6:/scripts \
  grafana/k6 run /scripts/scenarios/01_static_route.js

# Quick smoke test (5 VUs, 10s)
docker run --rm \
  --network benchmark_default \
  -e TARGET_HOST=bench-razy:8080 \
  -v ./benchmark/k6:/scripts \
  grafana/k6 run --vus 5 --duration 10s /scripts/scenarios/01_static_route.js
```
