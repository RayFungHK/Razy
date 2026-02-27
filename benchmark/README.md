# Performance Benchmark: Razy (FrankenPHP Worker) vs Laravel (Octane/Swoole)

Standardised benchmark suite for comparing **Razy PHAR + FrankenPHP/Caddy worker mode** against **Laravel 11 + Octane (Swoole)** in throughput, latency, and resource usage.

## Architecture

```
┌────────────────┐
│  k6 Client     │   (host machine)
│  6 scenarios   │
└──┬─────────┬───┘
   │         │
   ▼         ▼
┌──────────┐ ┌──────────┐
│  Razy    │ │ Laravel  │
│  :8081   │ │  :8082   │
│ FrankenPHP│ │ Octane   │
│  Worker  │ │ Swoole   │
└────┬─────┘ └────┬─────┘
     │             │
     ▼             ▼
   ┌─────────────────┐
   │   MySQL 8.0     │
   │   :13306        │
   │  (shared DB)    │
   └─────────────────┘
```

## Quick Start

### Prerequisites

- Docker & Docker Compose v2
- [k6](https://k6.io/docs/getting-started/installation/) (load testing tool)
- Python 3.10+ (for report generation)
- Bash shell (WSL2 or Git Bash on Windows)

### 1. Start Infrastructure

```bash
cd benchmark

# Start Razy + MySQL
docker compose up -d

# Optionally start Laravel too
docker compose --profile laravel up -d
```

### 2. Verify Endpoints

```bash
# Razy
curl http://localhost:8081/benchmark/static
# Should return: ok

# Laravel (if started)
curl http://localhost:8082/benchmark/static
# Should return: ok
```

### 3. Run Benchmarks

```bash
# Full suite (3 runs per scenario)
./scripts/run-all.sh razy localhost:8081 3
./scripts/run-all.sh laravel localhost:8082 3

# Single scenario (quick test)
./scripts/run-single.sh 01_static_route razy localhost:8081
```

### 4. Generate Report

```bash
python3 scripts/generate-report.py --output REPORT.md
```

## Test Scenarios

| # | Scenario | Endpoint | Purpose |
|---|----------|----------|---------|
| 1 | **Static Route** | `GET /benchmark/static` | Baseline framework overhead (returns "ok") |
| 2 | **Template Render** | `GET /benchmark/template` | Template engine cost (10 variables) |
| 3 | **DB Read** | `GET /benchmark/db-read?id=N` | Single-row SELECT via query builder |
| 4 | **DB Write** | `POST /benchmark/db-write` | Single INSERT operation |
| 5 | **Composite** | `GET /benchmark/composite?id=N` | Realistic: DB read + template render |
| 6 | **Heavy CPU** | `GET /benchmark/heavy?iterations=N` | Worker pool management under CPU stress |

## Metrics Collected

### Per Scenario (via k6)

| Metric | Description |
|--------|-------------|
| **RPS** | Requests per second (throughput) |
| **p50, p90, p95, p99** | Latency percentiles (ms) |
| **Success Rate** | HTTP 2xx percentage |
| **Total Requests** | Volume processed in test duration |

### Server-Side (via Docker stats)

| Metric | Description |
|--------|-------------|
| **CPU %** | Container CPU usage |
| **Memory** | RSS / limit |
| **PIDs** | Process/thread count |
| **Open FDs** | File descriptor count |

## Test Configuration

Both frameworks run with:

- **CPU limit:** 2 cores
- **Memory limit:** 4 GB
- **PHP:** 8.3 with OPcache + JIT enabled
- **OPcache settings:** 256MB, validate_timestamps=0, JIT 1255
- **Database:** MySQL 8.0, max_connections=500, InnoDB buffer pool 512MB
- **Persistent connections:** Enabled on both sides

### Load Profile

Each scenario uses a ramp-up pattern:

```
Warmup:  10 VUs × 30s (stabilise)
Ramp:    10 → 50 → 100 → 200 VUs over 3 minutes
Sustain: 200 VUs × 30s
Ramp-down: 200 → 0 VUs over 30s
```

Scenario 6 (Heavy CPU) uses a different pattern with mixed fast/slow requests.

## Directory Structure

```
benchmark/
├── docker-compose.yml          # Orchestration (MySQL + Razy + Laravel)
├── docker/
│   ├── Caddyfile.razy          # FrankenPHP worker mode config
│   ├── Dockerfile.razy         # Razy image
│   ├── Dockerfile.laravel      # Laravel + Octane image
│   └── db/
│       └── init.sql            # Shared schema + seed data
├── k6/
│   └── scenarios/
│       ├── 01_static_route.js
│       ├── 02_template_render.js
│       ├── 03_db_read.js
│       ├── 04_db_write.js
│       ├── 05_composite.js
│       └── 06_heavy_cpu.js
├── laravel/                    # Laravel endpoint reference code
│   ├── routes/benchmark.php
│   └── views/benchmark/
├── razy/                       # Razy benchmark module
│   └── modules/benchmark/
├── results/                    # Generated (gitignored)
│   ├── razy/
│   └── laravel/
├── scripts/
│   ├── run-all.sh              # Full benchmark orchestrator
│   ├── run-single.sh           # Single-scenario runner
│   ├── collect-metrics.sh      # Server-side metrics
│   └── generate-report.py      # Report aggregator
└── README.md                   # This file
```

## Setting Up Laravel

To create a fair comparison, set up a fresh Laravel project:

```bash
# Create Laravel project
composer create-project laravel/laravel benchmark-laravel
cd benchmark-laravel

# Install Octane with Swoole
composer require laravel/octane
php artisan octane:install --server=swoole

# Copy benchmark routes
cp ../benchmark/laravel/routes/benchmark.php routes/benchmark.php

# Add to routes/web.php:
#   require __DIR__.'/benchmark.php';

# Copy views
cp -r ../benchmark/laravel/views/benchmark resources/views/benchmark

# Configure .env
# DB_CONNECTION=mysql
# DB_HOST=mysql (or 127.0.0.1)
# DB_DATABASE=benchmark
# DB_USERNAME=benchmark
# DB_PASSWORD=benchmark

# Test locally
php artisan octane:start --server=swoole --host=0.0.0.0 --port=8082
```

## Interpreting Results

### What to Look For

1. **Static route RPS gap** — Reveals pure framework dispatch overhead. If Razy is significantly higher, its boot/routing path is lighter.

2. **p95/p99 stability** — Consistent p95/p99 ratios indicate predictable latency. Wild spikes suggest GC pauses or worker starvation.

3. **DB-heavy p99** — If Laravel's p99 is lower for DB scenarios, its connection pooling or ORM pipeline may be more mature.

4. **Memory under load** — High RPS with proportionally high memory isn't sustainable. Check `results/metrics/` CSV files.

5. **Heavy CPU isolation** — In Scenario 6, fast request latency should NOT degrade when heavy requests run concurrently. Degradation indicates poor worker isolation.

### Fairness Checklist

- [ ] Same VM/hardware or identical Docker resource limits
- [ ] Same PHP version and OPcache/JIT settings
- [ ] Same MySQL instance and dataset
- [ ] Warmup phase completed before measurement
- [ ] 3+ runs averaged with standard deviation
- [ ] No other significant load on the host machine

## Cleanup

```bash
docker compose down -v   # Stop & remove volumes
rm -rf results/          # Clear results
```
