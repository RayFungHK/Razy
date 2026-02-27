# Benchmark Comparison: Razy vs Laravel (with Optimization)

**Date:** 2026-02-26  
**Load Tool:** k6 (Grafana) via Docker  
**Database:** MySQL 8.0 (shared container, same host)  
**Host:** Docker Desktop on Windows (single machine)  
**Resource Limits:** 2 CPUs, 4 GB RAM per container  

## Framework / Runtime Configuration

| | Razy (Optimized) | Laravel |
|---|---|---|
| **Framework** | Razy v0.5 (Phar, standalone mode) | Laravel 12.53.0 |
| **Runtime** | FrankenPHP (Caddy-based, PHP 8.3.7-alpine) | PHP 8.3-cli + Swoole (Octane) |
| **Worker Mode** | FrankenPHP persistent worker (boot-once dispatch) | Octane Swoole (`--workers=auto`) |
| **OPcache** | Enabled (JIT 1255, 128 MB buffer) | Enabled (JIT 1255, 128 MB buffer) |
| **Config Cache** | N/A (standalone Phar) | `config:cache`, `route:cache`, `view:cache` |
| **Session/Cache** | None | `array` driver (in-memory) |

## Worker Mode Optimization Applied

The original Razy worker loop **rebuilt the entire object graph per request** (Application -> Container -> Standalone -> Module -> RouteDispatcher + session_start + gc_collect_cycles). The optimization moves all initialization to a one-time boot phase:

| Per-Request Work | Before | After |
|---|---|---|
| `new Application()` + DI Container | Every request | **Once at boot** |
| `new Standalone()` + sub-components | Every request | **Once at boot** |
| `Module::initialize()` + `__onInit()` | Every request | **Once at boot** |
| Route registration + regex compile | Every request | **Once at boot** |
| `session_start()` | Every request | **Skipped** |
| `Database::resetInstances()` | Every request | **Skipped** (connections persist) |
| `PluginManager::resetAll()` + 8x re-register | Every request | **Skipped** |
| `CompiledTemplate::clearCache()` | Every request | **Skipped** |
| `gc_collect_cycles()` | Every request | **Every 500 requests** |
| Per-request cost | ~6ms overhead | ~0.05ms overhead |

---

## Before vs After: Razy Optimization Impact

| Scenario | Before RPS | After RPS | Speedup | Before p50 | After p50 |
|---|---:|---:|---:|---:|---:|
| Static Route | 171 | **6,331** | **37.0x** | 171ms | **2.8ms** |
| Template Render | 141 | **6,264** | **44.4x** | 322ms | **2.9ms** |
| DB Read | 105 | **3,763** | **35.8x** | 303ms | **14.3ms** |
| DB Write | 101 | **754** | **7.5x** | 493ms | **102ms** |
| Composite | 131 | **4,528** | **34.6x** | 1,530ms | **37.6ms** |
| Heavy CPU (combined) | 55 | **144** | **2.6x** | ~900ms | **495ms** |

---

## Head-to-Head: Razy (Optimized) vs Laravel

### Scenario 1: Static Route - Pure Framework Overhead

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Total Requests** | 1,519,465 | 301,006 | **Razy (5.0x)** |
| **Throughput (RPS)** | 6,331 | 1,254 | **Razy (5.0x)** |
| **p50 Latency** | 2.8ms | 81ms | **Razy (29x)** |
| **p90 Latency** | 14.1ms | 95ms | **Razy (6.7x)** |
| **p95 Latency** | 18.6ms | 186ms | **Razy (10x)** |
| **Success Rate** | 100% | 100% | Tie |

---

### Scenario 2: Template Render - String Templating

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Total Requests** | 1,503,267 | 272,813 | **Razy (5.5x)** |
| **Throughput (RPS)** | 6,264 | 1,137 | **Razy (5.5x)** |
| **p50 Latency** | 2.9ms | 87ms | **Razy (30x)** |
| **p90 Latency** | 14.5ms | 185ms | **Razy (12.8x)** |
| **p95 Latency** | 19.0ms | 189ms | **Razy (9.9x)** |
| **Success Rate** | 100% | 100% | Tie |

---

### Scenario 3: DB Read - SELECT Query

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Total Requests** | 903,079 | 228,548 | **Razy (3.9x)** |
| **Throughput (RPS)** | 3,763 | 952 | **Razy (4.0x)** |
| **p50 Latency** | 14.3ms | 89ms | **Razy (6.2x)** |
| **p90 Latency** | 34.0ms | 188ms | **Razy (5.5x)** |
| **p95 Latency** | 38.5ms | 191ms | **Razy (5.0x)** |
| **Success Rate** | 100% | 100% | Tie |

---

### Scenario 4: DB Write - INSERT Query

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Total Requests** | 181,027 | 201,991 | Laravel (1.1x) |
| **Throughput (RPS)** | 754 | 842 | Laravel (1.1x) |
| **p50 Latency** | 102ms | 89ms | Laravel (1.1x) |
| **p90 Latency** | 178ms | 181ms | **Razy (1.02x)** |
| **p95 Latency** | 182ms | 186ms | **Razy (1.02x)** |
| **Success Rate** | 100% | 100% | Tie |

---

### Scenario 5: Composite - DB Read + Template Render

*400 peak VUs, 4m30s ramp.*

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Total Requests** | 1,358,416 | 287,283 | **Razy (4.7x)** |
| **Throughput (RPS)** | 4,528 | 958 | **Razy (4.7x)** |
| **p50 Latency** | 37.6ms | 191ms | **Razy (5.1x)** |
| **p90 Latency** | 68.1ms | 389ms | **Razy (5.7x)** |
| **p95 Latency** | 72.4ms | 395ms | **Razy (5.5x)** |
| **Success Rate** | 100% | 100% | Tie |

---

### Scenario 6: Heavy CPU - 500K MD5 + Concurrent Fast Requests

| Metric | Razy v2 | Laravel | Winner |
|---|---:|---:|---|
| **Fast: Total Reqs** | 17,765 | 52,983 | Laravel (3.0x) |
| **Fast: p50** | 495ms | 89ms | Laravel (5.6x) |
| **Fast: p95** | 553ms | 689ms | **Razy (1.2x)** |
| **Fast: Success** | 100% | 100% | Tie |
| **Heavy: Total Reqs** | 8,228 | 5,665 | **Razy (1.5x)** |
| **Heavy: p50** | 571ms | 701ms | **Razy (1.2x)** |
| **Heavy: p95** | 595ms | 1,590ms | **Razy (2.7x)** |
| **Heavy: Success** | 100% | 100% | Tie |

Under CPU-bound workloads, Razy now processes **45% more heavy requests** with **63% lower tail latency** (p95: 595ms vs 1,590ms). Laravel wins on fast-request throughput due to Swoole's coroutine-based concurrency isolating I/O-bound from CPU-bound workers.

---

## Summary Table

| Scenario | Razy v1 RPS | Razy v2 RPS | Laravel RPS | Razy v2 vs Laravel |
|---|---:|---:|---:|---:|
| Static Route | 171 | **6,331** | 1,254 | **Razy 5.0x** |
| Template Render | 141 | **6,264** | 1,137 | **Razy 5.5x** |
| DB Read | 105 | **3,763** | 952 | **Razy 4.0x** |
| DB Write | 101 | **754** | 842 | Laravel 1.1x |
| Composite | 131 | **4,528** | 958 | **Razy 4.7x** |
| Heavy CPU | 55 | **144** | 325 | Laravel 2.3x |

**Razy now outperforms Laravel Octane (Swoole) in 4 of 6 scenarios.** The remaining gaps are in write-heavy DB workloads (MySQL INSERT bottleneck, not framework overhead) and CPU-bound scenarios (Swoole's coroutine isolation vs FrankenPHP's thread pool).

---

## Analysis

### What Changed

The single biggest optimization was **eliminating per-request object reconstruction**. The original worker loop treated each request as if starting the framework from scratch - creating Application, Container, Standalone, Module, RouteDispatcher objects, running the full module lifecycle (`__onInit` -> `__onLoad` -> `__onRequire`), starting sessions, and tearing everything down afterward.

The optimized loop boots the framework once and dispatches each request directly through the pre-built route table. This reduced per-request framework overhead from ~6ms to ~0.05ms - a 100x reduction.

### Why DB Write Is Close

DB Write (scenario 4) shows near-parity because the bottleneck is MySQL INSERT throughput, not framework dispatch. Both frameworks are limited by the MySQL container's write I/O, so framework overhead is negligible relative to the ~100ms DB round-trip.

### Why Heavy CPU Favors Laravel on Fast Requests

Swoole uses coroutines to service fast requests even when worker threads are blocked on CPU-bound work. FrankenPHP's thread pool model means a CPU-bound request blocks one thread entirely - fast requests must queue behind it. However, Razy's lower overall framework overhead means its heavy requests complete faster, resulting in better p95 tail latency.

### Remaining Optimization Opportunities

1. **Swoole/RoadRunner runtime** - Would give Razy access to coroutine-based concurrency, eliminating the CPU-bound isolation gap.
2. **FrankenPHP worker count tuning** - Increasing `FRANKENPHP_NUM_THREADS` beyond the default (2x CPU) could help under mixed workloads.
3. **Connection pooling** - The benchmark controller already uses a static PDO, but the framework's Database class could offer built-in persistent connections.

---

## Test Reproducibility

```bash
# Start all containers
docker compose -f benchmark/docker-compose.yml --profile laravel up -d

# Run against Razy
docker run --rm --network benchmark_default \
  -e TARGET_HOST=bench-razy:8080 \
  -v ./benchmark/k6:/scripts \
  grafana/k6 run /scripts/scenarios/01_static_route.js

# Run against Laravel
docker run --rm --network benchmark_default \
  -e TARGET_HOST=bench-laravel:8080 \
  -v ./benchmark/k6:/scripts \
  grafana/k6 run /scripts/scenarios/01_static_route.js
```

---

## Disclaimer

Benchmarks ran on a **single Windows machine** with all containers sharing CPU, memory, and I/O. Results reflect relative performance under identical conditions - absolute numbers would differ on dedicated hardware.
