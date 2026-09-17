# Performance Benchmark Report — 2026-09 symmetric epoch

Generated: 2026-09-17 08:41 · k6 2.0.0 (Docker) · 2 vCPU / 4 GB per stack, shared MySQL 8.0.43, 3 runs per scenario per stack, warmup + cooldown between all.

Symmetry law (absorbing the 2026-02 audit): real template engines both sides, query-builder layer both sides, persistent worker connections both sides, identical FrankenPHP runtime for the main comparison, and a plain-FPM baseline pass for both stacks. Raw k6 JSON under results/ is the record of truth.

```
epoch=2026-09
k6=grafana/k6:2.0.0

== bench-razy ==
php=8.3.28
frankenphp=FrankenPHP v1.10.1 PHP 8.3.28 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
razy_phar_sha256=033b6953a81c492c81a31658f4a94eb86ed3495c1558311d5ddbf1a128e049f5
pdo=mysql
== bench-laravel ==
laravel=v13.32.0
octane=v2.19.1
php=8.3.28
frankenphp=FrankenPHP v1.10.1 PHP 8.3.28 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
pdo=mysql
== bench-razy-fpm ==
php=8.3.33
runtime=php-fpm (no worker)
razy_phar_sha256=033b6953a81c492c81a31658f4a94eb86ed3495c1558311d5ddbf1a128e049f5
pdo=mysql
== bench-laravel-fpm ==
laravel=v13.32.0
php=8.3.33
runtime=php-fpm (octane NOT used)
pdo=mysql
== bench-mysql ==
OCI runtime exec failed: exec failed: unable to start container process: exec: "php": executable file not found in $PATH
mysql_version=mysqladmin  Ver 8.0.43 for Linux on x86_64 (MySQL Community Server - GPL)
```

## The framework table (identical runtime: FrankenPHP 1.10 on both sides)

| Scenario | Razy RPS | Laravel RPS | RPS ratio | Razy p95 | Laravel p95 | Razy ok% | Laravel ok% |
|---|---:|---:|:--:|---:|---:|---:|---:|
| 1. Static Route (framework dispatch) | 6,336 | 2,146 | 2.95x | 19.01 ms | 86.90 ms | 99.79% | 88.43% |
| 2. Template Render (engine, 10 vars) | 4,950 | 1,961 | 2.52x | 35.07 ms | 91.16 ms | 99.82% | 99.50% |
| 3. DB Read (single SELECT) | 4,327 | 1,456 | 2.97x | 38.28 ms | 141.80 ms | 99.89% | 94.68% |
| 4. DB Write (single INSERT) | 807.77 | 778.75 | 1.04x | 170.57 ms | 171.58 ms | 99.94% | 99.93% |
| 5. Composite (DB + template) | 3,600 | 1,464 | 2.46x | 107.05 ms | 266.78 ms | 100.00% | 100.00% |
| 6. Heavy CPU (md5 loop) | 144.30 | 121.09 | 1.19x | 590.42 ms | 690.90 ms | 65.85% | 66.44% |

## The FPM baseline table (identical runtime: php:8.3-fpm on both sides)

| Scenario | Razy RPS | Laravel RPS | RPS ratio | Razy p95 | Laravel p95 | Razy ok% | Laravel ok% |
|---|---:|---:|:--:|---:|---:|---:|---:|
| 1. Static Route (framework dispatch) | 294.57 | 921.28 | 0.32x | 832.54 ms | 190.37 ms | 75.62% | 75.90% |
| 2. Template Render (engine, 10 vars) | 166.02 | 899.90 | 0.18x | 1494.45 ms | 190.93 ms | 83.41% | 92.00% |
| 3. DB Read (single SELECT) | 164.14 | 473.05 | 0.35x | 1371.77 ms | 522.34 ms | 75.92% | 80.34% |
| 4. DB Write (single INSERT) | 182.20 | 443.99 | 0.41x | 905.02 ms | 392.21 ms | 80.89% | 89.38% |
| 5. Composite (DB + template) | 169.95 | 482.26 | 0.35x | 3071.75 ms | 1116.07 ms | 81.12% | 84.86% |
| 6. Heavy CPU (md5 loop) | 79.26 | 239.95 | 0.33x | 994.93 ms | 995.89 ms | 65.41% | 56.01% |
## What worker mode buys each stack

| Scenario | Razy worker RPS | Razy fpm RPS | gain |
|---|---:|---:|:--:|
| 1. Static Route (framework dispatch) | 6,336 | 294.57 | 21.51x |
| 2. Template Render (engine, 10 vars) | 4,950 | 166.02 | 29.81x |
| 3. DB Read (single SELECT) | 4,327 | 164.14 | 26.36x |
| 4. DB Write (single INSERT) | 807.77 | 182.20 | 4.43x |
| 5. Composite (DB + template) | 3,600 | 169.95 | 21.18x |
| 6. Heavy CPU (md5 loop) | 144.30 | 79.26 | 1.82x |

| Scenario | Laravel worker RPS | Laravel fpm RPS | gain |
|---|---:|---:|:--:|
| 1. Static Route (framework dispatch) | 2,146 | 921.28 | 2.33x |
| 2. Template Render (engine, 10 vars) | 1,961 | 899.90 | 2.18x |
| 3. DB Read (single SELECT) | 1,456 | 473.05 | 3.08x |
| 4. DB Write (single INSERT) | 778.75 | 443.99 | 1.75x |
| 5. Composite (DB + template) | 1,464 | 482.26 | 3.04x |
| 6. Heavy CPU (md5 loop) | 121.09 | 239.95 | 0.50x |

## Detailed Results (mean ± stddev over runs)

### razy

**1. Static Route (framework dispatch)** (3 runs): RPS 6,336 ±11.18, p50 2.17 ms, p95 19.01 ms, success 99.79%

**2. Template Render (engine, 10 vars)** (3 runs): RPS 4,950 ±7.15, p50 3.85 ms, p95 35.07 ms, success 99.82%

**3. DB Read (single SELECT)** (3 runs): RPS 4,327 ±13.92, p50 8.17 ms, p95 38.28 ms, success 99.89%

**4. DB Write (single INSERT)** (3 runs): RPS 807.77 ±1.87, p50 94.96 ms, p95 170.57 ms, success 99.94%

**5. Composite (DB + template)** (3 runs): RPS 3,600 ±32.37, p50 47.17 ms, p95 107.05 ms, success 100.00%

**6. Heavy CPU (md5 loop)** (3 runs): RPS 144.30 ±0.10, p50 499.90 ms, p95 590.42 ms, success 65.85%

### laravel

**1. Static Route (framework dispatch)** (3 runs): RPS 2,146 ±3.33, p50 17.91 ms, p95 86.90 ms, success 88.43%

**2. Template Render (engine, 10 vars)** (3 runs): RPS 1,961 ±4.65, p50 19.84 ms, p95 91.16 ms, success 99.50%

**3. DB Read (single SELECT)** (3 runs): RPS 1,456 ±5.62, p50 64.50 ms, p95 141.80 ms, success 94.68%

**4. DB Write (single INSERT)** (3 runs): RPS 778.75 ±6.43, p50 98.35 ms, p95 171.58 ms, success 99.93%

**5. Composite (DB + template)** (3 runs): RPS 1,464 ±2.44, p50 146.50 ms, p95 266.78 ms, success 100.00%

**6. Heavy CPU (md5 loop)** (3 runs): RPS 121.09 ±0.22, p50 602.15 ms, p95 690.90 ms, success 66.44%

### razy-fpm

**1. Static Route (framework dispatch)** (3 runs): RPS 294.57 ±84.40, p50 164.03 ms, p95 832.54 ms, success 75.62%

**2. Template Render (engine, 10 vars)** (3 runs): RPS 166.02 ±14.55, p50 254.69 ms, p95 1494.45 ms, success 83.41%

**3. DB Read (single SELECT)** (3 runs): RPS 164.14 ±23.66, p50 332.98 ms, p95 1371.77 ms, success 75.92%

**4. DB Write (single INSERT)** (3 runs): RPS 182.20 ±11.05, p50 285.98 ms, p95 905.02 ms, success 80.89%

**5. Composite (DB + template)** (3 runs): RPS 169.95 ±4.52, p50 985.41 ms, p95 3071.75 ms, success 81.12%

**6. Heavy CPU (md5 loop)** (3 runs): RPS 79.26 ±2.43, p50 672.18 ms, p95 994.93 ms, success 65.41%

### laravel-fpm

**1. Static Route (framework dispatch)** (3 runs): RPS 921.28 ±21.76, p50 89.32 ms, p95 190.37 ms, success 75.90%

**2. Template Render (engine, 10 vars)** (3 runs): RPS 899.90 ±1.32, p50 89.47 ms, p95 190.93 ms, success 92.00%

**3. DB Read (single SELECT)** (3 runs): RPS 473.05 ±9.12, p50 188.86 ms, p95 522.34 ms, success 80.34%

**4. DB Write (single INSERT)** (3 runs): RPS 443.99 ±1.42, p50 186.11 ms, p95 392.21 ms, success 89.38%

**5. Composite (DB + template)** (3 runs): RPS 482.26 ±12.51, p50 452.30 ms, p95 1116.07 ms, success 84.86%

**6. Heavy CPU (md5 loop)** (3 runs): RPS 239.95 ±0.90, p50 192.58 ms, p95 995.89 ms, success 56.01%

## Reproduction

```bash
cd benchmark
docker compose up -d                                # mysql + razy worker
docker compose --profile laravel up -d              # laravel worker (frankenphp)
docker compose --profile fpm up -d                  # both fpm baselines + caddy front
./scripts/full-run-2026-09.ps1                      # serial 4-pass run, receipts included
python3 scripts/generate-report.py
```