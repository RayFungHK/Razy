# Razy v1.0 — Enterprise-Grade Multi-Tenant Architecture

> Version: 3.0-draft | Date: 2026-02-27 | Author: Razy Core Team
> Supersedes: v1.0-draft (container-only isolation model)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Model: Core → Tenant → Dist → Module](#2-architecture-model-core--tenant--dist--module)
3. [Application Lifecycle Analysis](#3-application-lifecycle-analysis)
4. [Worker Mode vs Non-Worker Mode Flow](#4-worker-mode-vs-non-worker-mode-flow)
5. [Threat Model](#5-threat-model)
6. [Isolation Strategy: Three Layers](#6-isolation-strategy-three-layers)
7. [Hotplug Architecture (Worker Mode)](#7-hotplug-architecture-worker-mode)
8. [Four-Layer Communication Model](#8-four-layer-communication-model)
9. [Docker Compose Implementation](#9-docker-compose-implementation)
10. [Kubernetes Implementation](#10-kubernetes-implementation)
11. [Razy Core Code Modifications](#11-razy-core-code-modifications)
12. [Performance Analysis](#12-performance-analysis)
13. [Security Analysis](#13-security-analysis)
14. [Tenant Crash & Failure Handling](#14-tenant-crash--failure-handling)
15. [Limitations & Constraints](#15-limitations--constraints)
16. [Development Roadmap](#16-development-roadmap)
17. [Migration Path](#17-migration-path)
18. [Testing Strategy](#18-testing-strategy)

---

## 1. Executive Summary

### Goal

> **Every Razy Application environment is a Tenant — including the local host.**
> Tenant containers each run a complete Razy environment (multi-domain, multi-dist, multi-module).
> Communication between tenants uses a unified protocol regardless of locality.
> The `sites.inc.php` file is NEVER overwritten for tenant merging — in-memory hotplug is used instead.

### Key Architecture Decisions (v3)

| Decision | Rationale |
|----------|-----------|
| **1 Tenant = 1 Full Environment** | A tenant container wraps multiple distributors, sites, and modules — it IS a complete `Application` instance, not just one dist. |
| **Local = Host Tenant** | The local `Application` running the Razy process is also a tenant (the "Host Tenant"). Container tenants communicate with it using the same protocol as container↔container. All entities are peers. |
| **Memory Hotplug (not file overwrite)** | Tenant sites are merged into the routing table via in-memory overlay (`$tenantOverlays`). The base `sites.inc.php` is never modified. Hotplug/unplug via `RestartSignal` extension in worker mode. |
| **Persistent Tenant Registry** | `tenants.json` stores registered tenants, auto-replayed on worker boot. CLI commands manage the registry. |
| **Core-Omniscient** | A dedicated Core/Admin container has full filesystem visibility for cross-tenant operations, backups, and whitelist-mediated data sharing. |
| **Defense-in-Depth** | OS-level isolation (Docker/K8s) + PHP-level (`open_basedir`) + Framework-level (opaque paths, blocked constants, DI blocklist). |
| **Zero Module Changes** | Existing module code works without modification. Only framework internals change. |

### Hierarchy

```
Core (Orchestration Layer)
└── Tenant (= 1 complete Razy Application environment)
    ├── Domain A (example.com)
    │   ├── /       → Distributor "main"
    │   │   ├── Module: auth
    │   │   ├── Module: cms
    │   │   └── Module: api
    │   └── /admin  → Distributor "admin"
    │       └── Module: dashboard
    └── Domain B (api.example.com)
        └── /       → Distributor "api-gateway"
            ├── Module: rest
            └── Module: webhook
```

---

## 2. Architecture Model: Core → Tenant → Dist → Module

### 2.1 Entity Relationship

```
┌─────────────────────────────────────────────────────────────────────┐
│                           Core (Orchestrator)                       │
│  Manages tenant registry, whitelist, cross-tenant routing           │
│  Runs in its own container (or is the Host Tenant in dev mode)      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────────────┐   ┌──────────────────────┐               │
│  │  Host Tenant (Local) │   │  Container Tenant B   │              │
│  │  ═══════════════════ │   │  ═══════════════════  │              │
│  │  Application instance │   │  Application instance │              │
│  │    ├─ Domain: *       │   │    ├─ Domain: b.com   │              │
│  │    │   └─ Dist: main  │   │    │   └─ Dist: shop  │              │
│  │    │       ├─ Mod: A  │   │    │       ├─ Mod: X  │              │
│  │    │       └─ Mod: B  │   │    │       └─ Mod: Y  │              │
│  │    └─ Domain: api.*   │   │    └─ Domain: admin.* │              │
│  │        └─ Dist: api   │   │        └─ Dist: mgmt  │              │
│  │            └─ Mod: C  │   │            └─ Mod: Z  │              │
│  │                       │   │                       │              │
│  │  sites.inc.php (base) │   │  sites.inc.php (own)  │              │
│  │  + tenantOverlays[]   │   │  (standalone, scoped) │              │
│  └──────────────────────┘   └──────────────────────┘               │
│                                                                     │
│  ┌──────────────────────┐                                          │
│  │  Container Tenant C   │                                          │
│  │  ═══════════════════  │                                          │
│  │  ...same structure... │                                          │
│  └──────────────────────┘                                          │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 Previous vs Corrected Model

| Aspect | v1 (Old) | v3 (Current) |
|--------|----------|-------------|
| Tenant definition | 1 Tenant = 1 Distributor | 1 Tenant = 1 full Application environment |
| Local Application | Not a tenant; special entity | Host Tenant — a peer tenant |
| Tenant merging | Overwrite `sites.inc.php` | In-memory overlay, never touch file |
| Communication: Container↔Host | Different protocol | Same protocol, different transport |
| Addressing | `(tenantId, moduleCode)` | `(tenantId, distCode, moduleCode)` — 3 params |
| sites.inc.php | Written by Core | Read-only; overlays in memory |

### 2.3 What a Tenant Contains

Each tenant IS a complete Razy environment:

```
Tenant Container (or Local Host):
├── Razy.phar                    ← Framework binary
├── sites.inc.php                ← OWN routing table (read-only)
├── config.inc.php               ← App config
├── sites/
│   ├── distA/                   ← Multiple distributors
│   │   ├── dist.php
│   │   └── vendor/pkg/...
│   └── distB/
├── data/
│   ├── cache/
│   ├── domainA-distA/           ← Per-dist runtime data
│   └── domainA-distB/
├── config/                      ← Per-module configs
├── shared/
│   └── module/                  ← Global shared modules
└── plugins/
```

---

## 3. Application Lifecycle Analysis

### 3.1 Will Tenant Hotplug Make the Cycle Chaotic?

**Short answer: No.** The design carefully preserves the existing lifecycle by restricting tenant operations to a narrow, well-defined window.

**Key design constraints that prevent chaos:**

| Constraint | How It Prevents Chaos |
|-----------|----------------------|
| **Hotplug only between requests** | `plugTenant()` / `unplugTenant()` execute between worker loop iterations — never during a request dispatch. No mid-request state mutation. |
| **Overlay is additive, never destructive** | `$tenantOverlays` merges entries on top of the base `$multisite`. Existing base entries are never removed or modified by tenant operations. |
| **Rebuild is atomic** | `rebuildMultisite()` reconstructs the full routing table in one pass: base config → overlay → sort. No partial states are observable by request handlers. |
| **Lock/Unlock discipline** | `Application::$locked` prevents config mutations. `UnlockForWorker()` is called only in the signal-check window (between requests). Lock is restored before the next `frankenphp_handle_request()`. |
| **Signal file is the single mutation trigger** | No in-process timer, no inotify, no shared memory. Only the filesystem signal file (atomic write via `rename()`) triggers state changes. |

### 3.2 Application Boot Sequence (Unchanged Core, Extended)

The tenant hotplug mechanism does NOT alter the core boot sequence:

```
Constructor()
  ├── Validate not locked
  ├── Generate GUID (spl_object_hash)
  ├── Create Container + register core bindings
  └── Register PluginManager singleton

host(fqdn)
  ├── Validate FQDN format
  ├── ensureInitialized()
  │   ├── loadSiteConfig()        ← reads sites.inc.php + checksum
  │   ├── updateSites()           ← parse domains, validate dists, build $multisite
  │   └── [NEW] replayTenantOverlays()  ← replay tenants.json into $tenantOverlays
  ├── Register SPL autoloader
  ├── matchDomain(fqdn)           ← cascade: exact → domain → alias → wildcard → '*'
  └── Return Domain instance

Lock()                             ← freeze state — no further config changes
```

### 3.3 Per-Request Flow (Unchanged)

The request-handling path remains identical — tenant overlays have already been merged into `$multisite` during boot:

```
Request arrives
  ├── [Standard] query(urlQuery)
  │   └── Domain::matchQuery() → new Distributor → initialize() → matchRoute()
  │
  └── [Worker]   dispatch(urlQuery)
      └── Domain::dispatchQuery() → cache lookup → Distributor::dispatch()
```

### 3.4 State Persistence in Worker Mode

| State Variable | Persists? | Tenant Impact |
|----------------|-----------|---------------|
| `Application::$locked` | Yes (static) | Temporarily unlocked during hotplug window only |
| `$multisite` | Yes | Rebuilt atomically when overlays change |
| `$alias` | Yes | Rebuilt atomically when overlays change |
| `$config` | Yes | Base config from `sites.inc.php` — never mutated |
| `$tenantOverlays` | Yes (NEW) | Added/removed via signal; merged into `$multisite` |
| `$domain` (Domain instance) | Yes | May be re-matched if overlays add matching wildcard |
| `Domain::$distributorCache` | Yes | Accumulates across requests; periodic fingerprint check |
| Module instances | Yes (in cache) | Full object graph persists in cached distributors |
| SPL autoloader | Yes | Registered once in boot |

### 3.5 Lifecycle State Machine

```
                              ┌─────────────────────────┐
                              │                         │
    ┌────────┐   host()     ┌─▼──────┐   Lock()     ┌──┴─────┐
    │ Created ├────────────►│ Booted  ├─────────────►│ Locked  │
    └────────┘              └─────────┘              └──┬──────┘
                                                        │
                         ┌──────────────────────────────┤
                         │                              │
                    ┌────▼──────┐                  ┌────▼──────┐
                    │ Request 1 │                  │ Request N │
                    │ (full     │                  │ (fast     │
                    │  query)   │                  │  dispatch)│
                    └────┬──────┘                  └────┬──────┘
                         │                              │
                         └──────────┬───────────────────┘
                                    │
                              ┌─────▼──────────┐   [Worker only: between requests]
                              │ Signal Check   │
                              │ ─────────────  │
                              │ plug/unplug?   │
                              │ → Unlock       │
                              │ → mutate       │
                              │ → rebuild      │
                              │ → Lock         │
                              └─────┬──────────┘
                                    │
                              ┌─────▼──────────┐
                              │ Next Request   │
                              │ (uses updated  │
                              │  $multisite)   │
                              └────────────────┘
```

### 3.6 Complexity Assessment

| Concern | Verdict | Explanation |
|---------|---------|-------------|
| Boot complexity increase | **Minimal (+1 step)** | `replayTenantOverlays()` is a single JSON parse + array merge at the end of `ensureInitialized()` |
| Request-path complexity increase | **None** | Request handling code is completely unmodified; it reads `$multisite` which already contains merged data |
| State mutation risk | **Low** | Mutations only happen in a tightly controlled window (between requests, under lock/unlock) |
| Error propagation risk | **Low** | `plugTenant()`/`unplugTenant()` are non-critical — a JSON parse failure in `tenants.json` falls back to empty overlays |
| Debugging difficulty | **Medium** | Developers need to understand the overlay concept. `razy tenant list` CLI command provides observability. |

---

## 4. Worker Mode vs Non-Worker Mode Flow

### 4.1 Non-Worker Mode (Standard PHP — Apache/FPM/CGI)

```
── Per Request (one process, one lifecycle) ──────────────────────────

HTTP Request → SAPI (Apache/FPM/CGI) → index.php → main.php

  1. new Application()                        ← fresh instance every request
  2. host($fqdn)                              ← load config, parse sites, match domain
     └── ensureInitialized()
         ├── loadSiteConfig()                  ← read sites.inc.php from disk
         ├── updateSites()                     ← parse all domains, validate all dists
         └── [NEW] replayTenantOverlays()      ← read tenants.json, merge overlays
  3. Lock()                                   ← freeze state
  4. register_shutdown_function(validation)    ← tamper-detect sites.inc.php
  5. query($urlQuery)                         ← FULL lifecycle:
     ├── Domain::matchQuery()
     │   └── new Distributor()
     │       ├── Load dist.php config               ← disk I/O
     │       ├── Scan modules (filesystem)           ← disk I/O
     │       ├── Resolve dependencies                ← CPU
     │       ├── __onInit → __onLoad → __onRequire   ← module lifecycle hooks
     │       └── return Distributor
     └── Distributor::matchRoute()
         ├── setSession()                      ← session_start()
         ├── processAwaits()                   ← inter-module coordination
         ├── notifyReady()                     ← __onReady callbacks
         └── router->matchRoute()              ← URL → Controller method
  6. dispose()                                 ← cleanup: Module::__onDispose(), unlock
  7. validation()                              ← shutdown: verify checksums
  8. Process exits                             ← ALL memory released

Cost: Every request pays full boot + scan + init + session overhead (~15-30ms)
```

### 4.2 Worker Mode (FrankenPHP)

```
── Boot Once (per worker process lifetime) ───────────────────────────

FrankenPHP Go runtime → spawns PHP worker → main.php

  1. new Application()
  2. host($fqdn)
     └── ensureInitialized()
         ├── loadSiteConfig()                  ← read sites.inc.php once
         ├── updateSites()                     ← parse all domains once
         └── replayTenantOverlays()            ← read tenants.json once
  3. Lock()                                    ← state frozen for entire lifetime
  4. Build $handler closure                    ← captures $app by reference

── Request 1 (full lifecycle, inside $handler) ───────────────────────

  frankenphp_handle_request($handler)
  try:
    http_response_code(200) + header_remove()   ← reset response state
    Drain leftover output buffers               ← while (ob_get_level()) ob_end_clean()
    Recompute $urlQuery from $_SERVER           ← fresh per-request URL
    query($urlQuery)                            ← SAME full lifecycle as non-worker
    $firstRequestHandled = true
  catch HttpException:                          ← swallow (404, redirect — response sent)
  catch Throwable:                              ← Error::showException() or echo $e
  finally:
    Error::reset()                              ← clear debug console for next request
    session_write_close()                       ← release session lock

── Request 2..N (fast dispatch) ──────────────────────────────────────

  frankenphp_handle_request($handler)
  try:
    Reset headers + output buffers
    Recompute $urlQuery
    dispatch($urlQuery)                         ← FAST PATH:
    ├── Domain::dispatchQuery()
    │   ├── Cache hit (normal case)
    │   │   ├── Periodic fingerprint check (every configCheckInterval)
    │   │   │   └── If changed → evict + rebuild (rare)
    │   │   └── Distributor::dispatch()
    │   │       └── router->matchRoute()         ← ONLY routing, skip all init
    │   └── Cache miss → full lifecycle + cache
  catch/finally: (same as Request 1)

── Between Requests (worker loop tail) ───────────────────────────────

  ++$requestCount

  // Periodic GC
  if ($requestCount % $gcInterval === 0):
    gc_collect_cycles()
    CompiledTemplate::clearCache()

  // [NEW] Tenant Hotplug Signal Check
  $signal = RestartSignal::check($signalPath)
  if ($signal !== null && !isStale($signal)):
    match ($signal['action']):
      'plug'      → UnlockForWorker() → plugTenant() → Lock()
      'unplug'    → UnlockForWorker() → unplugTenant() → Lock()
      'restart'   → break loop (FrankenPHP restarts)
      'terminate' → break loop (worker exits)
    RestartSignal::clear($signalPath)

── Loop Exit ─────────────────────────────────────────────────────────

  Loop exits when:
    - frankenphp_handle_request() returns false (SIGTERM / server shutdown)
    - $maxRequests reached (memory leak protection)
  FrankenPHP Go runtime → detects worker exit → auto-restarts new worker
  New worker boots → replayTenantOverlays() restores tenant routes from tenants.json
```

### 4.3 Comparative Analysis

| Aspect | Non-Worker | Worker Mode |
|--------|------------|-------------|
| **Boot cost** | Every request (~15-30ms) | Once per process lifetime |
| **Module filesystem scan** | Every request | Once (first request); cached after |
| **`__onInit/__onLoad/__onRequire`** | Every request | Once (first request) |
| **Session handling** | Every request (`session_start`) | Only in `query()` (first req); skipped in `dispatch()` |
| **Distributor creation** | Every request (destroyed after) | Cached in `Domain::$distributorCache` |
| **Config fingerprint check** | N/A (fresh config every time) | Every `$configCheckInterval` requests (default 100) |
| **Memory lifetime** | Per-request (auto-freed) | Per-process (needs `WORKER_MAX_REQUESTS` + periodic GC) |
| **Error recovery** | Process dies → next request clean | try/catch in handler; fatal → FrankenPHP auto-restart |
| **Tenant hotplug** | Overlay replayed per-boot, no dynamic hotplug | Full hotplug via signal file between requests |
| **GC strategy** | Automatic (process exit) | Manual: every `$gcInterval` via `gc_collect_cycles()` |
| **Template cache** | Per-request (no leak risk) | Cleared every `$gcInterval` via `CompiledTemplate::clearCache()` |
| **Tamper detection** | `validation()` shutdown function | N/A (state locked, file not writable) |

### 4.4 Tenant Behavior Differences by Mode

| Capability | Non-Worker | Worker Mode |
|-----------|------------|-------------|
| **Base routing from sites.inc.php** | ✅ Read every request | ✅ Read once at boot |
| **Tenant overlay replay from tenants.json** | ✅ Replayed every request (fresh boot) | ✅ Replayed once at boot, then persistent |
| **Dynamic hotplug (plug/unplug)** | ❌ No persistent state to modify | ✅ Via RestartSignal between requests |
| **CLI `razy tenant plug`** | Updates tenants.json only (takes effect on next request's fresh boot) | Updates tenants.json + sends signal → immediate effect |
| **Container tenant isolation** | ✅ Same Docker/K8s isolation works | ✅ Same |
| **L4 TenantEmitter** | ✅ HTTP calls work the same | ✅ Same |

### 4.5 Why Non-Worker Still Benefits

Even without dynamic hotplug, non-worker mode gains from the tenant architecture:

1. **`tenants.json` as declarative config**: CLI manages the tenant registry; each fresh request replays it automatically
2. **Container isolation**: `RAZY_TENANT_ISOLATED=true` works identically in both modes
3. **L4 communication**: `TenantEmitter` uses HTTP, which is mode-agnostic
4. **Path opaqueness**: `getDataPath()` isolation guard works in both modes

The only feature exclusive to worker mode is **real-time hotplug** (0-request-delay route changes without restart).

---

## 5. Threat Model

### Attack Vectors (Current, Single-Container)

| # | Vector | Severity | Exploitable By |
|---|--------|----------|----------------|
| T1 | `scandir(DATA_FOLDER)` lists all `{domain}-{distCode}` dirs | **CRITICAL** | Any module PHP code |
| T2 | `file_get_contents(DATA_FOLDER.'/{otherIdentity}/{module}/secret.json')` | **CRITICAL** | Any module knowing path pattern |
| T3 | `scandir(SITES_FOLDER)` enumerates all distributor folder names | **HIGH** | Any module PHP code |
| T4 | `glob(SYSTEM_ROOT.'/config/*/')` lists all per-tenant config dirs | **HIGH** | Any module PHP code |
| T5 | PHP constants `DATA_FOLDER`, `SITES_FOLDER`, `SHARED_FOLDER` readable | **HIGH** | Any module; constants are global |
| T6 | `data_mapping` in `dist.php` allows direct cross-tenant filesystem ref | **MEDIUM** | Admin-configured, but no ACL |
| T7 | Reflection on Controller → Module → Distributor backtrace | **MEDIUM** | Blocked by DI container security (v1.0.1-beta) |

### Post-Isolation Status

| Vector | Docker Isolation | K8s Isolation | Notes |
|--------|-----------------|---------------|-------|
| T1 | **ELIMINATED** | **ELIMINATED** | Volume mount = only own data |
| T2 | **ELIMINATED** | **ELIMINATED** | Other tenant dirs don't exist |
| T3 | **ELIMINATED** | **ELIMINATED** | Only own site dir mounted |
| T4 | **ELIMINATED** | **ELIMINATED** | Only own config dir mounted |
| T5 | **MITIGATED** | **MITIGATED** | Constants point to isolated mounts; `open_basedir` restricts |
| T6 | **ELIMINATED** | **ELIMINATED** | Cross-tenant = cross-container; must use TenantEmitter |
| T7 | **ELIMINATED** | **ELIMINATED** | Already blocked + no inter-container backtrace |

---

## 6. Isolation Strategy: Three Layers

```
┌────────────────────────────────────────────────────────────────┐
│                  LAYER 1: OS / Container Isolation             │
│  Docker volume mounts / K8s PVC per tenant                     │
│  Each tenant process can ONLY see its own filesystem slice     │
├────────────────────────────────────────────────────────────────┤
│                  LAYER 2: PHP Runtime Restriction              │
│  open_basedir = /app/site (tenant-scoped mount)                │
│  disable_functions = exec,system,passthru,proc_open,...        │
│  Prevents escape from mounted filesystem                       │
├────────────────────────────────────────────────────────────────┤
│                  LAYER 3: Framework Opaque Paths + DI Blocklist│
│  RAZY_TENANT_ISOLATED mode:                                    │
│    getDataPath() → DATA_FOLDER/{module}  (no identity prefix)  │
│    Constants point to pre-mounted, isolated directories        │
│    data_mapping disabled (cross-tenant via TenantEmitter only) │
│  DI Container Blocklist:                                       │
│    14 blocked system classes (SecurityException on resolve)    │
└────────────────────────────────────────────────────────────────┘
```

---

## 7. Hotplug Architecture (Worker Mode)

### 7.1 Memory Overlay Design

The base `sites.inc.php` configuration is loaded once during boot and stored in `$config`. Tenant overlays are stored separately in `$tenantOverlays` and merged into `$multisite` during `rebuildMultisite()`.

```php
// Application.php — new properties
private array $tenantOverlays = [];  // tenantId → {domains: {...}, alias: {...}}

// The merge order:
// 1. Parse base $config (from sites.inc.php) → $multisite, $alias
// 2. For each $tenantOverlays entry → merge domains + aliases additively
// 3. sortPathLevel() on all merged domains
// Result: $multisite contains base + all tenant routes
```

**Conflict resolution**: If a tenant overlay declares a domain/path that already exists in the base config or another overlay, the **later** overlay wins (last-write-wins). CLI warnings are emitted during plug.

### 7.2 RestartSignal Extension

Two new signal actions for tenant management:

| Constant | Value | Payload |
|----------|-------|---------|
| `ACTION_PLUG` | `'plug'` | `{"tenantId": "abc123", "config": {"domains": {...}, "alias": {...}}}` |
| `ACTION_UNPLUG` | `'unplug'` | `{"tenantId": "abc123"}` |

Signal file format (extended):
```json
{
    "action": "plug",
    "timestamp": 1708700000,
    "tenantId": "abc123",
    "config": {
        "domains": {
            "shop.example.com": {
                "/": "shop-main"
            }
        },
        "alias": {}
    },
    "reason": "New tenant registered"
}
```

### 7.3 Worker Loop Integration Point

```php
// main.php — worker loop tail (between requests)
do {
    $running = frankenphp_handle_request($handler);
    ++$requestCount;

    // Periodic GC (existing)
    if ($gcInterval > 0 && $requestCount % $gcInterval === 0) {
        gc_collect_cycles();
        CompiledTemplate::clearCache();
    }

    // [NEW] Tenant Hotplug Signal Check
    $signal = RestartSignal::check($signalPath);
    if ($signal !== null && !RestartSignal::isStale($signal)) {
        match ($signal['action']) {
            'plug' => (function () use ($app, $signal) {
                Application::UnlockForWorker();
                $app->plugTenant($signal['tenantId'], $signal['config']);
                Application::Lock();
            })(),
            'unplug' => (function () use ($app, $signal) {
                Application::UnlockForWorker();
                $app->unplugTenant($signal['tenantId']);
                Application::Lock();
            })(),
            'restart', 'swap', 'terminate' => /* existing RestartSignal behavior */,
            default => null,
        };
        RestartSignal::clear($signalPath);
    }

} while ($running && ($maxRequests <= 0 || $requestCount < $maxRequests));
```

### 7.4 Persistent Tenant Registry (`tenants.json`)

```json
{
    "version": 1,
    "tenants": {
        "abc123": {
            "domains": {
                "shop.example.com": { "/": "shop-main" }
            },
            "alias": {},
            "pluggedAt": "2026-02-27T10:00:00Z",
            "endpoint": "http://tenant-shop:8080"
        },
        "def456": {
            "domains": {
                "blog.example.com": { "/": "blog" }
            },
            "alias": {},
            "pluggedAt": "2026-02-27T11:00:00Z",
            "endpoint": "http://tenant-blog:8080"
        }
    }
}
```

On worker boot, `replayTenantOverlays()` reads `tenants.json` and replays all entries into `$tenantOverlays`, ensuring tenant routes survive worker restarts.

### 7.5 CLI Commands

```bash
# Plug a new tenant
php Razy.phar tenant plug --id=abc123 \
    --domain=shop.example.com --path=/ --dist=shop-main \
    --endpoint=http://tenant-shop:8080

# Unplug a tenant
php Razy.phar tenant unplug --id=abc123

# List all registered tenants
php Razy.phar tenant list

# Install tenant from remote package
php Razy.phar tenant install --package=vendor/shop --id=abc123

# Show tenant status
php Razy.phar tenant status --id=abc123
```

Each CLI command:
1. Updates `tenants.json` (persistent store)
2. Sends RestartSignal with `plug`/`unplug` action (triggers worker hotplug)
3. Worker picks up signal between requests → merges/removes overlay → rebuilds `$multisite`

---

## 8. Four-Layer Communication Model

### 8.1 Overview

```
┌─────────────────────────────────────────────────────────────┐
│ L4: Tenant ↔ Tenant                                        │
│     TenantEmitter(tenantId, distCode, moduleCode)           │
│     Transport: HTTP (container↔container or container↔host) │
│     Endpoint: /_razy/internal/bridge                        │
│     Envelope: {ok, data} (transparent unwrap)               │
├─────────────────────────────────────────────────────────────┤
│ L3: Domain Registration / Sites Merging                     │
│     Application::plugTenant() / unplugTenant()              │
│     Memory overlay into $multisite                          │
│     No new classes — built into Application                  │
├─────────────────────────────────────────────────────────────┤
│ L2: Dist ↔ Dist (Bridge CLI)                                │
│     Distributor::executeInternalAPI()                       │
│     Transport: proc_open (same machine, different process)  │
│     Envelope: {ok, data} / {ok:false, error, code}         │
│     Permission: __onBridgeCall(sourceDist, command)         │
├─────────────────────────────────────────────────────────────┤
│ L1: Module ↔ Module (Emitter)                               │
│     Controller::api('module') → Emitter → Module::execute() │
│     Transport: In-process function call                     │
│     Permission: __onAPICall(ModuleInfo, method)             │
│     Returns: mixed (zero serialization)                     │
└─────────────────────────────────────────────────────────────┘
```

### 8.2 Layer Details

#### L1 — Module ↔ Module (Existing)

```php
$emitter = $this->api('moduleB');
$result = $emitter->someMethod($arg1, $arg2);
// Returns: mixed — direct function call, zero serialization
```

- **Transport**: In-process PHP function call
- **Permission gate**: `__onAPICall(ModuleInfo $caller, string $method): bool`
- **Envelope**: None — returns raw `mixed`
- **Performance**: < 0.01ms

#### L2 — Dist ↔ Dist (Existing)

```php
$result = $distributor->executeInternalAPI('moduleCode', 'command', [...args]);
// Spawns: php Razy.phar bridge {jsonPayload}
```

- **Transport**: `proc_open()` — spawns separate PHP process
- **Permission gate**: `__onBridgeCall(string $sourceDist, string $command): bool`
- **Envelope**: `{"ok": true, "data": ...}` / `{"ok": false, "error": "...", "code": 0}`
- **Performance**: ~50-100ms (process spawn + JSON encode/decode)
- **Note**: NOT available in isolated tenant containers (`proc_open` disabled)

#### L3 — Domain Registration (New — built into Application)

Not a communication layer per se — this is the tenant route merging mechanism via `$tenantOverlays`. See [Section 7](#7-hotplug-architecture-worker-mode).

#### L4 — Tenant ↔ Tenant (New)

```php
$tenantApi = $this->tenantApi('abc123', 'shop-main', 'orderModule');
$result = $tenantApi->getRecentOrders($limit);
// HTTP POST to: http://tenant-shop:8080/_razy/internal/bridge
```

- **Transport**: HTTP (internal Docker network)
- **Permission gate**: `__onTenantCall(string $tenantId, string $distCode, string $command): bool`
- **Envelope**: Same as L2 `{"ok": true, "data": ...}` — transparent unwrap for caller
- **Performance**: ~5-20ms (HTTP over Docker network, no TLS internally)
- **Addressing**: `(tenantId, distCode, moduleCode)` — 3 parameters required

### 8.3 DataRequest / DataResponse (File I/O Cross-Concern)

For file-level data access (separate from code-level API calls):

```php
$request = $this->data('moduleB');
$response = $request->read('uploads/photo.jpg');
// Returns: DataResponse {content, mimeType, size, lastModified}
```

- **DataRequest**: wraps `(targetModule, path)` with permission via `__onDataRequest`
- **DataResponse**: carries `content`, `mimeType`, `size`, `lastModified`, `exists()`, `isReadable()`
- Separate from API Emitters because file operations carry metadata that function calls don't

---

## 9. Docker Compose Implementation

### 9.1 Host Directory Structure

```
/opt/razy-platform/
├── phar/
│   └── Razy.phar              ← Framework binary (shared, read-only)
├── shared/
│   └── module/                ← Global shared modules (read-only)
├── sites/
│   ├── tenantA/               ← Tenant A site config + modules
│   │   ├── dist.php
│   │   └── vendor/pkg/...
│   └── tenantB/
├── tenants/
│   ├── a1b2c3d4/              ← Tenant A (opaque ID)
│   │   ├── data/
│   │   ├── config/
│   │   └── sites.inc.php      ← Per-tenant routing (auto-generated, read-only)
│   ├── e5f6g7h8/              ← Tenant B (opaque ID)
│   │   ├── data/
│   │   └── config/
│   ├── tenants.json           ← Persistent tenant registry (Host Tenant)
│   └── registry.json          ← Core-only: tenant ID → metadata map
└── core/
    ├── sites.inc.php          ← Host Tenant base routing table
    ├── config.inc.php         ← Core admin config
    └── whitelist.json         ← Cross-tenant ACL
```

### 9.2 Docker Compose — Production Multi-Tenant

```yaml
# docker-compose.tenant.yml

x-razy-tenant: &razy-tenant-base
  image: razy-tenant:1.0
  build:
    context: .
    dockerfile: docker/Dockerfile.tenant
  restart: unless-stopped
  networks:
    - razy-internal
  environment: &tenant-env-base
    RAZY_TENANT_ISOLATED: "true"
    RAZY_DEBUG: "false"
    RAZY_TIMEZONE: "UTC"

services:
  # ── Reverse Proxy ───────────────────────────────────────────
  proxy:
    image: caddy:2-alpine
    container_name: razy-proxy
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/Caddyfile.multi:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
    networks:
      - razy-internal
    depends_on:
      - host-tenant
      - tenant-a
      - tenant-b
    restart: unless-stopped

  # ── Host Tenant (Core + Local Application) ──────────────────
  host-tenant:
    image: razy-host:1.0
    build:
      context: .
      dockerfile: docker/Dockerfile.host
    container_name: razy-host
    hostname: host-tenant
    environment:
      RAZY_TENANT_ISOLATED: "false"
      RAZY_HOST_TENANT: "true"
      WORKER_MODE: "true"
      WORKER_MAX_REQUESTS: "10000"
      WORKER_GC_INTERVAL: "500"
      WORKER_CONFIG_CHECK_INTERVAL: "100"
    volumes:
      - ./phar/Razy.phar:/app/site/Razy.phar:ro
      - ./core/sites.inc.php:/app/site/sites.inc.php:ro
      - ./tenants/tenants.json:/app/site/tenants.json:rw
      - ./shared:/app/site/shared:ro
    expose:
      - "8080"
    networks:
      - razy-internal
    restart: unless-stopped

  # ── Tenant A ────────────────────────────────────────────────
  tenant-a:
    <<: *razy-tenant-base
    container_name: razy-tenant-a
    hostname: tenant-a
    environment:
      <<: *tenant-env-base
      RAZY_TENANT_ID: "a1b2c3d4"
      RAZY_TENANT_DOMAIN: "tenantA.example.com"
    volumes:
      - ./phar/Razy.phar:/app/site/Razy.phar:ro
      - ./sites/tenantA:/app/site/sites/tenantA:ro
      - ./tenants/a1b2c3d4/data:/app/site/data:rw
      - ./tenants/a1b2c3d4/config:/app/site/config:rw
      - ./shared:/app/site/shared:ro
      - ./tenants/a1b2c3d4/sites.inc.php:/app/site/sites.inc.php:ro
    expose:
      - "8080"

  # ── Tenant B ────────────────────────────────────────────────
  tenant-b:
    <<: *razy-tenant-base
    container_name: razy-tenant-b
    hostname: tenant-b
    environment:
      <<: *tenant-env-base
      RAZY_TENANT_ID: "e5f6g7h8"
      RAZY_TENANT_DOMAIN: "tenantB.example.com"
    volumes:
      - ./phar/Razy.phar:/app/site/Razy.phar:ro
      - ./sites/tenantB:/app/site/sites/tenantB:ro
      - ./tenants/e5f6g7h8/data:/app/site/data:rw
      - ./tenants/e5f6g7h8/config:/app/site/config:rw
      - ./shared:/app/site/shared:ro
      - ./tenants/e5f6g7h8/sites.inc.php:/app/site/sites.inc.php:ro
    expose:
      - "8080"

networks:
  razy-internal:
    driver: bridge

volumes:
  caddy_data:
  caddy_config:
```

### 9.3 Per-Tenant `sites.inc.php` (Auto-Generated, Read-Only)

```php
<?php
// Auto-generated for tenant a1b2c3d4 — READ-ONLY
return [
    'domains' => [
        'tenantA.example.com' => [
            '/' => 'tenantA',
        ],
    ],
    'alias' => [],
];
```

### 9.4 Caddyfile — Multi-Tenant Reverse Proxy

```caddyfile
{
    auto_https off
}

# Per-tenant domain routing
tenantA.example.com:80 {
    reverse_proxy tenant-a:8080
}

tenantB.example.com:80 {
    reverse_proxy tenant-b:8080
}

# Host Tenant (catch-all + admin + internal bridge)
:80 {
    reverse_proxy host-tenant:8080
}

# Internal bridge (container-to-host only, not exposed)
:9090 {
    reverse_proxy host-tenant:8080
}
```

### 9.5 What Each Container Sees

```
Tenant A container filesystem:
/app/site/
├── Razy.phar          (ro)
├── sites.inc.php      (ro) ONLY tenantA routing
├── sites/
│   └── tenantA/       (ro) ONLY own dist
├── data/              (rw) = tenants/a1b2c3d4/data  (flat, no identity prefix)
├── config/            (rw) = tenants/a1b2c3d4/config
└── shared/            (ro) global modules

# scandir('/app/site/data/')  → ['vendor']  (only own data)
# scandir('/app/site/sites/') → ['tenantA'] (cannot see tenantB)

Host Tenant container filesystem:
/app/site/
├── Razy.phar          (ro)
├── sites.inc.php      (ro) base routing table — NEVER overwritten
├── tenants.json       (rw) persistent tenant registry
├── sites/             (depends on setup)
├── data/              (rw)
└── shared/            (ro)
  + $tenantOverlays in memory (from tenants.json replay)
  + $multisite = base config + all overlays merged
```

### 9.6 Dockerfile.tenant — Hardened Tenant Image

```dockerfile
FROM dunglas/frankenphp:latest-php8.3-alpine

RUN install-php-extensions pdo_mysql opcache pcntl mbstring

RUN cat > /usr/local/etc/php/conf.d/tenant-security.ini <<'EOF'
open_basedir = /app/site:/tmp
disable_functions = exec,system,passthru,proc_open,popen,shell_exec,pcntl_exec,dl
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=8000
opcache.validate_timestamps=0
opcache.jit_buffer_size=64M
opcache.jit=1255
memory_limit=128M
EOF

WORKDIR /app/site
RUN mkdir -p /app/site/data /app/site/config /app/site/sites /app/site/shared /app/site/plugins
EXPOSE 8080
```

---

## 10. Kubernetes Implementation

### 10.1 Architecture Overview

```
                    ┌─────────────────────────────────────────┐
                    │           Ingress Controller             │
                    │  Host-based routing per tenant            │
                    └────┬────────────┬────────────┬──────────┘
                         │            │            │
                    ┌────▼────┐ ┌────▼────┐ ┌────▼────┐
                    │Host+Core│ │Tenant A │ │Tenant B │
                    │  Pod    │ │  Pod    │ │  Pod    │
                    │ (ns:    │ │ (ns:    │ │ (ns:    │
                    │ system) │ │ razy-a) │ │ razy-b) │
                    └────┬────┘ └────┬────┘ └────┬────┘
                         │            │            │
                    ┌────▼────┐ ┌────▼────┐ ┌────▼────┐
                    │  PVC    │ │  PVC-A  │ │  PVC-B  │
                    │  host   │ │  data   │ │  data   │
                    │  data   │ │  config │ │  config │
                    └─────────┘ └─────────┘ └─────────┘
```

### 10.2 Namespace-Per-Tenant + NetworkPolicy

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: razy-tenant-a
  labels:
    razy.io/role: tenant
    razy.io/tenant-id: "a1b2c3d4"
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: deny-inter-tenant
  namespace: razy-tenant-a
spec:
  podSelector: {}
  policyTypes: [Ingress, Egress]
  ingress:
    - from:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: ingress-nginx
        - namespaceSelector:
            matchLabels:
              razy.io/role: system
  egress:
    - to:
        - namespaceSelector:
            matchLabels:
              kubernetes.io/metadata.name: kube-system
          podSelector:
            matchLabels:
              k8s-app: kube-dns
        - namespaceSelector:
            matchLabels:
              razy.io/role: system
    - ports:
        - { port: 53, protocol: UDP }
        - { port: 53, protocol: TCP }
        - { port: 3306, protocol: TCP }
```

### 10.3 Tenant Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: razy-tenant
  namespace: razy-tenant-a
spec:
  replicas: 2
  selector:
    matchLabels: { app: razy }
  template:
    metadata:
      labels:
        app: razy
        razy.io/tenant-id: "a1b2c3d4"
    spec:
      securityContext:
        runAsNonRoot: true
        runAsUser: 1000
        fsGroup: 1000
      containers:
        - name: razy
          image: registry.example.com/razy-tenant:1.0
          ports: [{ containerPort: 8080 }]
          env:
            - { name: RAZY_TENANT_ISOLATED, value: "true" }
            - { name: RAZY_TENANT_ID, value: "a1b2c3d4" }
          volumeMounts:
            - { name: tenant-data, mountPath: /app/site/data }
            - { name: tenant-config, mountPath: /app/site/config }
            - { name: site-config, mountPath: /app/site/sites/tenantA, readOnly: true }
            - { name: sites-inc, mountPath: /app/site/sites.inc.php, subPath: sites.inc.php, readOnly: true }
            - { name: shared-modules, mountPath: /app/site/shared, readOnly: true }
          resources:
            requests: { cpu: 100m, memory: 128Mi }
            limits: { cpu: "1", memory: 512Mi }
          livenessProbe:
            httpGet: { path: /_razy/health, port: 8080 }
            initialDelaySeconds: 10
            periodSeconds: 30
          readinessProbe:
            httpGet: { path: /_razy/health, port: 8080 }
            initialDelaySeconds: 5
            periodSeconds: 10
      volumes:
        - { name: tenant-data, persistentVolumeClaim: { claimName: tenant-data } }
        - { name: tenant-config, persistentVolumeClaim: { claimName: tenant-config } }
        - { name: site-config, configMap: { name: tenant-a-site } }
        - { name: sites-inc, configMap: { name: tenant-a-sites-inc } }
        - { name: shared-modules, persistentVolumeClaim: { claimName: shared-modules } }
```

### 10.4 K8s vs Docker Feature Comparison

| Feature | Docker Compose | Kubernetes |
|---------|---------------|------------|
| **Isolation Unit** | Container + named volume | Namespace + PVC + NetworkPolicy |
| **Horizontal Scaling** | Manual replicas | HPA auto-scaling per tenant |
| **Network Isolation** | Docker network (basic) | NetworkPolicy (fine-grained) |
| **Secret Management** | `.env` files / Docker secrets | K8s Secrets + external vault |
| **Rolling Updates** | `docker compose up -d` | `kubectl rollout` (zero-downtime) |
| **Resource Limits** | `deploy.resources` (soft) | `resources.limits` (hard cgroup) |
| **Best For** | Single-node, < 20 tenants | Multi-node, 20+ tenants |

---

## 11. Razy Core Code Modifications

### 11.1 Modification Map

```
Files requiring changes:       8 files
New files:                      6 files
Total lines changed (est.):   ~515 lines
Test impact:                   ~60 new tests, 0 existing test changes
Module code changes:           ZERO
```

### 11.2 File-by-File Breakdown

#### `src/system/bootstrap.inc.php` — Tenant Detection Constants

**Lines changed:** ~15 | **Complexity:** LOW

```php
// ADD after existing constants:
\define('RAZY_TENANT_ISOLATED', \filter_var(
    \getenv('RAZY_TENANT_ISOLATED') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
));
\define('RAZY_TENANT_ID', \getenv('RAZY_TENANT_ID') ?: '');
\define('RAZY_HOST_TENANT', \filter_var(
    \getenv('RAZY_HOST_TENANT') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
));
```

#### `src/library/Razy/Application.php` — Hotplug Engine

**Lines changed:** ~75 | **Complexity:** MEDIUM

```php
// New properties:
private array $tenantOverlays = [];

// New methods:
public function plugTenant(string $tenantId, array $config): void
{
    // Validate config, store overlay, persist to tenants.json, rebuildMultisite()
}

public function unplugTenant(string $tenantId): void
{
    // Remove overlay, evict distributor cache, persist, rebuildMultisite()
}

private function rebuildMultisite(): void
{
    // Reset → parse base → merge overlays → sort
}

private function replayTenantOverlays(): void
{
    // Read tenants.json → replay into $tenantOverlays → rebuildMultisite()
}
```

#### `src/library/Razy/Distributor.php` — Opaque Data Path

**Lines changed:** ~15 | **Complexity:** LOW

```php
public function getDataPath(string $module = '', bool $isURL = false): string
{
    if ($isURL) {
        return PathUtil::append($this->getSiteURL(), 'data', $module);
    }
    if (RAZY_TENANT_ISOLATED) {
        return PathUtil::append(DATA_FOLDER, $module);  // No identity prefix
    }
    return PathUtil::append(DATA_FOLDER, $this->getIdentity(), $module);
}

private function parseDataMappings(array $config): void
{
    if (RAZY_TENANT_ISOLATED) { return; }  // Disabled in isolation mode
    // ...existing code...
}
```

#### `src/library/Razy/Module.php` — Config Path Isolation

**Lines changed:** ~8 | **Complexity:** LOW

```php
public function loadConfig(): Configuration
{
    if (RAZY_TENANT_ISOLATED) {
        $path = PathUtil::append(SYSTEM_ROOT, 'config',
            $this->moduleInfo->getClassName() . '.php');
    } else {
        $path = PathUtil::append(SYSTEM_ROOT, 'config',
            $this->distributor->getCode(),
            $this->moduleInfo->getClassName() . '.php');
    }
    return new Configuration($path);
}
```

#### `src/library/Razy/Worker/RestartSignal.php` — New Actions

**Lines changed:** ~5 | **Complexity:** LOW

```php
public const ACTION_PLUG = 'plug';
public const ACTION_UNPLUG = 'unplug';
```

#### `src/main.php` — Worker Loop Signal Check

**Lines changed:** ~15 | **Complexity:** LOW

Add hotplug signal check between requests in the worker `do/while` loop.

#### NEW: `src/system/terminal/tenant.inc.php` — CLI Commands

**Lines:** ~80 | **Complexity:** MEDIUM

#### NEW: `src/library/Razy/TenantEmitter.php` — L4 Communication

**Lines:** ~50 | **Complexity:** MEDIUM

#### NEW: `src/library/Razy/DataRequest.php` + `DataResponse.php`

**Lines:** ~220 combined | **Complexity:** MEDIUM

#### NEW: `src/library/Razy/TenantAccessPolicy.php` — Whitelist Engine

**Lines:** ~80 | **Complexity:** MEDIUM

### 11.3 Backward Compatibility

| Scenario | Behavior |
|----------|----------|
| **Existing single-container** | `RAZY_TENANT_ISOLATED=false` (default). All paths identical. No hotplug. **Zero change.** |
| **Existing playground dev** | No change. `$tenantOverlays` empty. `sites.inc.php` read normally. |
| **Docker multi-tenant** | `RAZY_TENANT_ISOLATED=true`. Flat data paths, `data_mapping` disabled. |
| **Host Tenant (worker)** | `RAZY_HOST_TENANT=true`. Hotplug enabled. Memory overlays active. |

---

## 12. Performance Analysis

### 12.1 Hotplug Operation Costs

| Operation | Latency | When | Request Impact |
|-----------|---------|------|----------------|
| `RestartSignal::check()` | ~0.01ms | Every request (between iterations) | **Negligible** — 1x `file_exists` + `file_get_contents` + `json_decode`; filesystem cache = memory speed |
| `plugTenant()` | ~1-5ms | Rare (CLI-triggered only) | **Zero** — runs between requests |
| `unplugTenant()` | ~1-3ms | Rare | **Zero** — runs between requests |
| `rebuildMultisite()` | ~0.5-2ms | Only on plug/unplug | **Zero** — not in request path |
| `replayTenantOverlays()` | ~1-5ms | Once per worker boot | **Negligible** — amortized across process lifetime |

### 12.2 Request-Path Performance (No Degradation)

The tenant architecture adds **zero overhead to the request-handling hot path**:

1. **Signal check is NOT in the request path** — it runs in the worker loop tail, between `frankenphp_handle_request()` calls
2. **`$multisite` is a flat array** — domain matching uses the same code path as before
3. **`Domain::$distributorCache`** continues to work — overlaid routes use the same cache mechanism
4. **No additional function calls during dispatch** — the overlay is merged during plug, not looked up per-request

### 12.3 Memory Impact

| Component | Per-Tenant Cost | 10 Tenants | 100 Tenants |
|-----------|----------------|------------|-------------|
| `$tenantOverlays` entry | ~200 bytes | ~2 KB | ~20 KB |
| `$multisite` additional entries | ~500 bytes | ~5 KB | ~50 KB |
| `$alias` additional entries | ~100 bytes | ~1 KB | ~10 KB |
| `Domain::$distributorCache` | ~50 KB per cached dist | ~500 KB | ~5 MB |
| **Total additional memory** | | **~508 KB** | **~5.08 MB** |

At 128MB `memory_limit`, 100 tenants consume < 4% of available memory for routing metadata. The distributor cache is the heaviest component and already exists in the current codebase.

### 12.4 Worker vs Non-Worker Performance Baseline

Based on benchmark data (v1.0.1-beta, k6 static route):

| Metric | Non-Worker (FPM est.) | Worker Mode | Factor |
|--------|----------------------|-------------|--------|
| Static route RPS | ~2,800 | ~9,500 | ~3.4× |
| Dynamic route RPS | ~1,500 | ~7,200 | ~4.8× |
| p95 latency (static) | ~12ms | ~3.2ms | ~3.8× |
| Boot overhead per req | ~15-30ms | ~0ms (amortized) | Eliminated |

Tenant hotplug does NOT affect these numbers because all mutation happens between request iterations.

### 12.5 Cross-Tenant Communication Latency

| Layer | Latency | Serialization | Best For |
|-------|---------|---------------|----------|
| L1 (Emitter) | < 0.01ms | None | Same-dist module calls |
| L2 (Bridge CLI) | ~50-100ms | JSON | Same-machine cross-dist (not available in isolated containers) |
| L4 (TenantEmitter, Docker network) | ~5-20ms | JSON | Cross-container |
| L4 (TenantEmitter, loopback) | ~2-5ms | JSON | Host↔container on same node |

L4 is **faster** than L2 for cross-dist communication when dists are in different containers, because HTTP over Docker network avoids the `proc_open` process-spawn overhead.

### 12.6 Scaling Projections

| Tenant Count | Routing Table Memory | Signal Check Cost | Acceptable? |
|-------------|---------------------|-------------------|-------------|
| 1-10 | < 1 MB | 0.01ms | ✅ Trivial |
| 10-50 | ~2.5 MB | 0.01ms | ✅ Comfortable |
| 50-200 | ~10 MB | 0.01ms | ✅ Fine at 128MB limit |
| 200-1000 | ~50 MB | 0.01ms | ⚠️ Increase memory_limit to 256MB+ |
| 1000+ | ~500 MB | 0.01ms | ❌ Use K8s with multiple host pods |

---

## 13. Security Analysis

### 13.1 Existing Security Mechanisms

| Mechanism | Layer | Status |
|-----------|-------|--------|
| **DI Container Blocklist** | Framework | ✅ 14 blocked system classes, `SecurityException` |
| **`__onAPICall` permission gate** | L1 (Module) | ✅ Per-module callback |
| **`__onBridgeCall` permission gate** | L2 (Dist) | ✅ Per-module callback |
| **`Application::$locked`** | Framework | ✅ Prevents config writes in worker mode |
| **`$coreInitialized` guard** | Distributor | ✅ Prevents dispatch before init |
| **WORKER_MODE dispatch guards** | Domain/Application | ✅ v1.0.1-beta security patch |
| **Config fingerprint check** | Worker | ✅ Periodic MD5 of dist.php mtime |

### 13.2 New Security Surfaces

| Surface | Risk | Mitigation |
|---------|------|-----------|
| **`/_razy/internal/bridge` endpoint** | L4 calls visible to HTTP | Docker network isolation (internal only); HMAC-signed requests; Caddy does NOT expose to internet |
| **Signal file write** | Malicious signal injection | OS file perms (`0640`); path not guessable; `proc_open` disabled in isolated containers |
| **`tenants.json` manipulation** | Attacker adds malicious routes | File owned by Host Tenant; isolated tenants have no access; CLI requires shell |
| **Tenant ID spoofing** | Container claims wrong ID | ID via env var, controlled by orchestrator, not tenant code |
| **Overlay domain conflict** | Overlaid routes hijack existing domains | Conflict detection + configurable reject policy in `plugTenant()` |
| **Cross-tenant data leaks** | TenantEmitter reveals unauthorized data | `__onTenantCall` permission gate + Core whitelist |

### 13.3 Bridge Endpoint Authentication

```
POST /_razy/internal/bridge HTTP/1.1
Host: tenant-a:8080
X-Razy-Tenant-Id: host-tenant
X-Razy-Timestamp: 1708700000
X-Razy-Signature: hmac-sha256(secret, "tenantId:timestamp:body")
Content-Type: application/json

{"dist":"shop-main","module":"orderModule","command":"getRecentOrders","args":[10]}
```

- **HMAC signing** prevents tampering and replay (60s timestamp window)
- **Shared secret** via env var or K8s secret per tenant pair
- **Network isolation** ensures only internal traffic reaches bridge

### 13.4 Signal File Security

```
File:        /app/site/.worker-signal
Permissions: 0640 (rw-r-----)
Owner:       root:razy

Write access:
  ✅ CLI (razy tenant plug) — runs as same user
  ✅ Core Admin container — has volume mount
  ❌ Isolated tenant modules — proc_open disabled, no shell
  ❌ External HTTP requests — no filesystem access
```

### 13.5 Attack Scenario Analysis

| Scenario | Result |
|----------|--------|
| **Malicious module calls `plugTenant()`** | `Application::$locked = true` during request → throws. Even if unlocked, module can't write `.worker-signal` (no `proc_open` in isolated containers). |
| **Container impersonates another tenant** | Bridge HMAC validation fails (wrong secret). NetworkPolicy blocks direct container↔container traffic. |
| **Attacker modifies `tenants.json`** | Only Host Tenant + CLI have write access. Isolated containers have no mount. Worker reads only on boot. |
| **Replay attack on bridge** | HMAC includes timestamp; 60s window prevents replay. |
| **Path traversal in DataRequest** | `realpath()` check + base path prefix validation. Isolated container has no paths to traverse to. |

---

## 14. Tenant Crash & Failure Handling

### 14.1 Failure Modes

| Failure Mode | Detection | Recovery | Impact Scope |
|-------------|-----------|----------|--------------|
| **Tenant container crash** | Proxy health check / K8s liveness | Docker `restart: unless-stopped` / K8s auto-restart | That tenant only; 502 for ~200ms |
| **Tenant PHP fatal error** | FrankenPHP worker process dies | FrankenPHP Go runtime auto-restarts worker | That tenant only; ~200ms gap |
| **Tenant memory exhaustion** | PHP fatal error → process dies | Auto-restart + `WORKER_MAX_REQUESTS` prevents accumulation | That tenant only |
| **Tenant infinite loop** | Caddy `request_timeout` | Proxy returns 504; worker continues | Current request; other tenants unaffected |
| **Host Tenant crash** | Proxy can't reach host | Docker/K8s auto-restart | L4 calls fail; direct tenant serving continues |
| **Signal file corruption** | `RestartSignal::check()` returns null | Ignored; CLI re-sends | Zero — hotplug waits for valid signal |
| **`tenants.json` corruption** | JSON parse failure in `replayTenantOverlays()` | Caught → empty overlays + CLI warning | Tenant routes lost until manual re-plug |
| **Network partition (K8s)** | HTTP timeout on L4 calls | `HttpClient` timeout (configurable, default 5s) | Cross-tenant calls fail; local serving continues |

### 14.2 Error Recovery in Worker Mode

The worker handler has robust per-request error isolation:

```
Per-request error handling (inside $handler closure):

  try:
    Reset headers + drain output buffers       ← clean slate for this request
    Recompute $urlQuery from $_SERVER           ← fresh URL
    dispatch($urlQuery)                        ← may throw
  catch (HttpException):
    Swallowed — response already sent (404, redirect, XHR)
  catch (Throwable):
    Error::showException($e)                   ← render error page
    Falls back to echo $e if renderer fails
  finally:
    Error::reset()                             ← clear debug console
    session_write_close()                      ← release session lock
```

**Key properties:**
- Exceptions in one request do NOT crash the worker
- try/catch/finally ensures the loop continues to the next request
- `Error::reset()` prevents cross-request debug state leakage
- `header_remove()` + `ob_end_clean()` at request start prevents response leakage

### 14.3 Unrecoverable Errors

| Error Type | Caught by Handler? | Worker Impact |
|-----------|-------------------|---------------|
| `Exception` / `Error` (PHP 8) | ✅ Yes — `catch (Throwable)` | Error rendered; worker continues |
| `TypeError` / `ValueError` | ✅ Yes | Same |
| `OutOfMemoryError` | ❌ No — PHP fatal | Worker process dies; FrankenPHP restarts |
| `E_COMPILE_ERROR` | ❌ No — PHP fatal | Same |
| Segfault (PHP extension bug) | ❌ No — OS signal | Same; core dump for debugging |

FrankenPHP's Go runtime **automatically restarts worker processes** that exit for any reason. This is built into FrankenPHP — no Razy configuration needed.

### 14.4 Health Check Endpoints

```yaml
# Docker Compose
healthcheck:
  test: ["CMD", "curl", "-sf", "http://localhost:8080/_razy/health"]
  interval: 30s
  timeout: 5s
  retries: 3
  start_period: 10s

# Kubernetes
livenessProbe:
  httpGet: { path: /_razy/health, port: 8080 }
  initialDelaySeconds: 10
  periodSeconds: 30
  failureThreshold: 3

readinessProbe:
  httpGet: { path: /_razy/health, port: 8080 }
  initialDelaySeconds: 5
  periodSeconds: 10
  failureThreshold: 2
```

### 14.5 Host Tenant Crash — Detailed Impact

The Host Tenant is critical infrastructure. Its failure analysis:

| Component | Impact During Host Outage | Recovery After Restart |
|-----------|--------------------------|----------------------|
| Tenant overlay routes | Lost (in-memory only) | `replayTenantOverlays()` rebuilds from `tenants.json` |
| L4 TenantEmitter calls | Fail with connection timeout | Host restarts; callers retry |
| Direct tenant containers | **Unaffected** — self-sufficient | N/A |
| Pending signal files | Wait until host restarts | `isStale()` (5min TTL); CLI can re-send |
| `tenants.json` | Untouched (on disk) | Read on next boot |

**Tenant containers are self-sufficient** for serving their own domains. They only depend on the Host Tenant for L4 cross-tenant communication and hotplug management.

### 14.6 Graceful Shutdown Flow

```
SIGTERM → FrankenPHP Go runtime
  → Sets internal shutdown flag
  → Current request completes normally
  → frankenphp_handle_request() returns false
  → Worker loop exits
  → PHP shutdown functions run (if any)
  → Process exits cleanly
  → Docker/K8s detects exit
  → Container restarts (restart policy)
  → New worker boots
  → replayTenantOverlays() restores tenant routes from tenants.json
  → First request: full lifecycle (query/queryStandalone)
  → Subsequent: fast dispatch
```

**Note**: Razy does NOT register `pcntl_signal()` handlers. All signal handling is delegated to FrankenPHP's Go runtime. This is by design — PHP signal handling is unreliable in worker mode.

### 14.7 Tenant Failure Isolation Matrix

```
Tenant A (crashing)           Tenant B (healthy)          Host Tenant
─────────────────            ───────────────────         ─────────────
PHP fatal error              Normal operation             Normal operation
  → Worker dies              Request arrives              Hotplug works
  → FrankenPHP restarts      dispatch() → cached          L4 to B works
  → 502 for ~200ms           → Response 200               *L4 to A fails*
  → Worker boots             Normal operation             (timeout → error)
  → Full lifecycle (slow)    Normal operation             L4 to A resumes
  → Normal operation         Normal operation             Normal operation
```

- Docker/K8s network isolation ensures no crosstalk
- Each container has its own PHP process, memory, and filesystem
- Reverse proxy routes independently per hostname
- A crashing tenant causes **zero impact** on other tenants' request serving

---

## 15. Limitations & Constraints

### 15.1 Architectural Limitations

| Limitation | Description | Workaround |
|-----------|-------------|-----------|
| **Hotplug requires worker mode** | Non-worker mode creates fresh `Application` every request — no persistent state to overlay. Dynamic route changes need a worker process. | Container-per-tenant with separate `sites.inc.php` still works. Non-worker replays `tenants.json` per-boot (per-request in non-worker = effective). |
| **No real-time route propagation** | Hotplug relies on signal file polling between requests. There is a 0-1 request delay before new routes take effect. | Acceptable for tenant provisioning (not latency-sensitive). |
| **Host Tenant is SPOF for L4** | All cross-tenant HTTP calls originate from or relay through the Host. | Deploy Host with `replicas: 2+` behind load balancer. K8s for HA. |
| **No partial tenant restart** | Unplugging evicts all cached distributors for that tenant. Next request triggers full lifecycle. | By design — ensures clean state. |
| **`proc_open` disabled in isolated containers** | L2 Bridge CLI (`executeInternalAPI`) cannot work inside isolated tenants. | Use L4 HTTP bridge instead. L2 is for same-machine dists only. |
| **No inotify / shared memory / FIFO** | Signal check is filesystem-based with no kernel notification. | Filesystem signal is simple, portable, works across all SAPIs. |
| **One pending signal at a time** | Two signals before worker checks → only last processed. | `tenants.json` is source of truth; signals are transient triggers. |

### 15.2 Scaling Limits

| Metric | Docker Compose | Kubernetes | Hard Limit |
|--------|---------------|------------|-----------|
| **Max tenants (routing)** | ~200 | ~200 | `$multisite` array; 200 domains × 5 paths ≈ 200 KB |
| **Max tenants (containers)** | ~20-30 (single node) | 1000+ (multi-node) | Host CPU/memory |
| **Distributor cache** | ~50 per worker | ~50 per pod | 50 × ~50 KB = 2.5 MB |
| **Cross-tenant RPS (L4)** | ~500-1000/s | ~2000-5000/s | HTTP + JSON overhead |

### 15.3 Compatibility Requirements

| Requirement | Details |
|-----------|---------|
| PHP 8.2+ | `readonly` properties, `enum`, `match` expression |
| FrankenPHP | Required for worker mode hotplug |
| Alpine Linux | Recommended; `open_basedir` verified |
| No Windows containers | FrankenPHP worker mode = Linux-only |
| `opcache.validate_timestamps=0` | Required in production; file changes need worker restart |
| No `exec/proc_open` in tenant containers | Disabled for security → L2 Bridge unavailable |

### 15.4 What This Architecture Does NOT Solve

| Problem | Reason | Alternative |
|---------|--------|------------|
| Database isolation | Out of scope (DB external to Razy) | Separate DB schemas/instances per tenant |
| Per-tenant rate limiting | Not a framework concern | Caddy rate_limit or app-level middleware |
| Tenant billing / metering | Business logic | Metering middleware or K8s resource quotas |
| Zero-downtime tenant migration | Requires stateful session draining | WorkerLifecycleManager Strategy B (future) |
| Multi-region failover | Requires DNS-level routing | Cloudflare Workers / AWS Route53 |
| Real-time pub/sub between tenants | HTTP is request/response | Add Redis/RabbitMQ for event-driven patterns |

---

## 16. Development Roadmap

### 16.1 Phase Overview

```
Phase 0 ─── Foundation (DONE)                        [v1.0.1-beta]
Phase 1 ─── Tenant Isolation Core                     [v1.1.0-beta]
Phase 2 ─── Docker Multi-Tenant                       [v1.1.0]
Phase 3 ─── Communication Layers (L4 + DataRequest)   [v1.2.0]
Phase 4 ─── Kubernetes + Lifecycle Integration         [v1.3.0]
Phase 5 ─── Cross-Tenant Whitelist + Admin UI          [v2.0.0]
```

### 16.2 Phase 0 — Foundation (DONE) ✅

| Task | Status |
|------|--------|
| DI Container security blocklist (14 classes, SecurityException) | ✅ v1.0.1-beta |
| Worker dispatch security guards (WORKER_MODE + $locked + $coreInitialized) | ✅ v1.0.1-beta |
| Boot-once optimization (37× RPS improvement) | ✅ v1.0.0-beta |
| Distributor caching in Domain::dispatchQuery() | ✅ v1.0.0-beta |
| RestartSignal + WorkerState + ChangeType (library) | ✅ v1.0.0-beta |
| WorkerLifecycleManager (4 strategies, not integrated) | ✅ v1.0.0-beta |
| ModuleChangeDetector (PHP tokenizer) | ✅ v1.0.0-beta |
| Benchmark suite (22 files, k6) | ✅ v1.0.0-beta |
| All 4,794 tests passing | ✅ |

### 16.3 Phase 1 — Tenant Isolation Core (16h)

**Target:** Framework-level tenant isolation, backward compatible. No Docker dependency.

| # | Task | Files | Lines | Hours |
|---|------|-------|-------|-------|
| 1.1 | Bootstrap constants (`RAZY_TENANT_ISOLATED`, `RAZY_TENANT_ID`, `RAZY_HOST_TENANT`) | bootstrap.inc.php | ~15 | 1h |
| 1.2 | `Distributor::getDataPath()` isolation guard | Distributor.php | ~10 | 2h |
| 1.3 | `Distributor::parseDataMappings()` isolation guard | Distributor.php | ~5 | 1h |
| 1.4 | `Module::loadConfig()` isolation guard | Module.php | ~8 | 2h |
| 1.5 | `Application::$tenantOverlays` + `plugTenant()` + `unplugTenant()` | Application.php | ~75 | 4h |
| 1.6 | `Application::rebuildMultisite()` + `replayTenantOverlays()` | Application.php | ~40 | 2h |
| 1.7 | `RestartSignal::ACTION_PLUG` + `ACTION_UNPLUG` constants | RestartSignal.php | ~5 | 0.5h |
| 1.8 | Worker loop hotplug signal check | main.php | ~15 | 1h |
| 1.9 | Unit tests for isolation + hotplug | tests/ | ~100 | 4h |
| | **Phase 1 Total** | | **~273** | **16h** |

**Exit criteria:** `RAZY_TENANT_ISOLATED=true` makes data paths opaque. Hotplug works in worker mode. All existing tests pass + 30 new tests.

**Dependencies:** None (extends existing code only).

### 16.4 Phase 2 — Docker Multi-Tenant (14h)

**Target:** Production-ready Docker Compose multi-tenant deployment.

| # | Task | Deliverable | Hours |
|---|------|-------------|-------|
| 2.1 | Dockerfile.tenant (hardened, `open_basedir` + `disable_functions`) | docker/Dockerfile.tenant | 2h |
| 2.2 | Dockerfile.host (Host Tenant image) | docker/Dockerfile.host | 2h |
| 2.3 | Docker Compose template | docker-compose.tenant.yml | 3h |
| 2.4 | Caddyfile.multi template | docker/Caddyfile.multi | 1h |
| 2.5 | Per-tenant `sites.inc.php` generator script | scripts/generate-tenant-config.sh | 2h |
| 2.6 | Integration tests (Docker isolation verification) | tests/docker/ | 4h |
| | **Phase 2 Total** | | **14h** |

**Exit criteria:** `docker compose up` starts Host + 2 tenant containers. Each tenant fully isolated (verified by scandir tests). Health checks pass.

**Dependencies:** Phase 1

### 16.5 Phase 3 — Communication Layers L4 + DataRequest (20h)

**Target:** Cross-tenant API calls and file-level data access.

| # | Task | Files | Lines | Hours |
|---|------|-------|-------|-------|
| 3.1 | `TenantEmitter` class (HTTP bridge client) | TenantEmitter.php | ~50 | 3h |
| 3.2 | Internal bridge route handler (`/_razy/internal/bridge`) | bridge-handler | ~40 | 3h |
| 3.3 | HMAC authentication for bridge endpoint | TenantEmitter.php | ~30 | 2h |
| 3.4 | `Controller::tenantApi()` convenience method | Controller.php | ~10 | 1h |
| 3.5 | `DataRequest` class (file I/O wrapper) | DataRequest.php | ~120 | 3h |
| 3.6 | `DataResponse` class (file metadata carrier) | DataResponse.php | ~100 | 2h |
| 3.7 | `Controller::data()` convenience method | Controller.php | ~10 | 1h |
| 3.8 | `__onTenantCall` + `__onDataRequest` permission gates | Module.php | ~30 | 2h |
| 3.9 | CLI `razy tenant` commands (plug/unplug/list/status) | terminal/tenant.inc.php | ~80 | 2h |
| 3.10 | Unit + integration tests | tests/ | ~150 | 4h |
| | **Phase 3 Total** | | **~620** | **20h** |

**Exit criteria:** Module in Host can call module in container via `$this->tenantApi()`. DataRequest returns DataResponse with metadata. HMAC prevents unauthorized bridge calls.

**Dependencies:** Phase 1. Can run in parallel with Phase 2.

### 16.6 Phase 4 — Kubernetes + Lifecycle Integration (25h)

**Target:** K8s deployment templates. WorkerLifecycleManager integration.

| # | Task | Deliverable | Hours |
|---|------|-------------|-------|
| 4.1 | K8s namespace + PVC templates | k8s/templates/ | 4h |
| 4.2 | Deployment + Service + Ingress YAMLs | k8s/templates/ | 4h |
| 4.3 | NetworkPolicy per tenant | k8s/templates/ | 3h |
| 4.4 | Helm chart (parameterized) | charts/razy-tenant/ | 8h |
| 4.5 | WorkerLifecycleManager integration into main.php | main.php | 3h |
| 4.6 | Integration tests (K8s via minikube) | tests/k8s/ | 3h |
| | **Phase 4 Total** | | **25h** |

**Dependencies:** Phase 1 + Phase 2

### 16.7 Phase 5 — Cross-Tenant Whitelist + Admin UI (30h)

**Target:** Fine-grained cross-tenant data sharing with admin interface.

| # | Task | Deliverable | Hours |
|---|------|-------------|-------|
| 5.1 | `TenantAccessPolicy` class | TenantAccessPolicy.php | 4h |
| 5.2 | Core Admin API endpoints | admin controller | 8h |
| 5.3 | Whitelist config management UI | admin frontend | 12h |
| 5.4 | End-to-end cross-tenant tests | tests/ | 6h |
| | **Phase 5 Total** | | **30h** |

**Dependencies:** Phase 1 + Phase 2 or Phase 3

### 16.8 Total Effort Summary

| Phase | Hours | Complexity | Ships Independently |
|-------|-------|------------|---------------------|
| Phase 0 (Foundation) | Done | — | ✅ v1.0.1-beta |
| Phase 1 (Isolation Core) | 16h | LOW-MED | ✅ Backward compatible |
| Phase 2 (Docker) | 14h | LOW-MED | ✅ Requires P1 |
| Phase 3 (Communication) | 20h | MEDIUM | ✅ Requires P1 |
| Phase 4 (K8s + Lifecycle) | 25h | MEDIUM | ✅ Requires P1+P2 |
| Phase 5 (Whitelist + Admin) | 30h | MED-HIGH | ✅ Requires P1+P2/P3 |
| **GRAND TOTAL** | **105h** | | |

### 16.9 Implementation Graph

```
Phase 1 (isolation core) ─────┐
                               ├──► Phase 2 (Docker)  ──► Phase 4 (K8s)
                               │                              │
                               └──► Phase 3 (L4 + Data) ─────┘──► Phase 5 (Whitelist)
```

Phases 2 and 3 can develop in parallel after Phase 1.

### 16.10 Risk Matrix

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|-----------|
| Existing modules break | **Very Low** | HIGH | Gated by `RAZY_TENANT_ISOLATED`; default `false` |
| Performance overhead | **None** | N/A | Signal check between requests; zero in-request cost |
| Worker mode incompatible | **Low** | MEDIUM | Each tenant = own FrankenPHP worker |
| Volume permission issues | **Medium** | LOW | Documented `fsGroup` / `chown` requirements |
| Whitelist config drift | **Medium** | MEDIUM | Version field + hot-reload + admin UI |
| Host Tenant SPOF | **Low** | HIGH | Deploy with replicas=2+; K8s HA |
| Signal race condition | **Very Low** | LOW | Atomic write via rename(); last-write-wins |
| `tenants.json` grows large | **Very Low** | LOW | 1000 tenants < 500KB; periodic compaction |

---

## 17. Migration Path

### 17.1 From Single-Container to Docker Multi-Tenant

```
Step 1: Update Razy.phar (Phase 1 code changes)
        ↓
Step 2: Create host directory structure
        mkdir -p /opt/razy-platform/{phar,shared,sites,tenants,core}
        ↓
Step 3: For each existing tenant distributor:
        a. Generate opaque ID:   openssl rand -hex 4 → "a1b2c3d4"
        b. Move data:            mv data/{identity}/* tenants/{id}/data/
        c. Move config:          mv config/{distCode}/* tenants/{id}/config/
        d. Copy site:            cp -r sites/{distCode} sites/{distCode}
        e. Generate per-tenant sites.inc.php
        ↓
Step 4: Deploy docker-compose.tenant.yml
        ↓
Step 5: Update DNS / reverse proxy
        ↓
Step 6: Verify: curl https://tenantA.example.com/_razy/health
```

### 17.2 Migration Script

```bash
#!/bin/bash
set -euo pipefail

PLATFORM_ROOT="/opt/razy-platform"
OLD_ROOT="/app/site"

mkdir -p "$PLATFORM_ROOT"/{phar,shared/module,sites,tenants,core}
cp "$OLD_ROOT/Razy.phar" "$PLATFORM_ROOT/phar/"
cp -r "$OLD_ROOT/shared/module/"* "$PLATFORM_ROOT/shared/module/" 2>/dev/null || true

php -r "
  \$c = require '$OLD_ROOT/sites.inc.php';
  foreach (\$c['domains'] as \$domain => \$routes) {
    foreach (\$routes as \$path => \$dist) {
      echo \"\$domain|\$path|\$dist\n\";
    }
  }
" | while IFS='|' read -r domain path dist; do
  TENANT_ID=$(openssl rand -hex 4)
  IDENTITY="$domain-$dist"
  TENANT_DIR="$PLATFORM_ROOT/tenants/$TENANT_ID"

  mkdir -p "$TENANT_DIR"/{data,config}

  [ -d "$OLD_ROOT/data/$IDENTITY" ] && cp -r "$OLD_ROOT/data/$IDENTITY/"* "$TENANT_DIR/data/"
  [ -d "$OLD_ROOT/config/$dist" ] && cp -r "$OLD_ROOT/config/$dist/"* "$TENANT_DIR/config/"
  cp -r "$OLD_ROOT/sites/$dist" "$PLATFORM_ROOT/sites/$dist"

  cat > "$TENANT_DIR/sites.inc.php" <<PHP
<?php
return [
    'domains' => ['$domain' => ['$path' => '$dist']],
    'alias' => [],
];
PHP

  echo "{\"id\":\"$TENANT_ID\",\"dist\":\"$dist\",\"domain\":\"$domain\"}" >> "$PLATFORM_ROOT/tenants/registry.jsonl"
done

echo "Migration complete."
```

---

## 18. Testing Strategy

### 18.1 Unit Tests — Framework Isolation + Hotplug

| # | Test Case | Category |
|---|-----------|----------|
| 1 | `getDataPath()` omits identity when `RAZY_TENANT_ISOLATED=true` | Path isolation |
| 2 | `getDataPath()` includes identity when `RAZY_TENANT_ISOLATED=false` | Backward compat |
| 3 | `parseDataMappings()` is no-op when isolated | Data mapping |
| 4 | `loadConfig()` omits distCode when isolated | Config path |
| 5 | `plugTenant()` adds overlay to `$multisite` | Hotplug |
| 6 | `unplugTenant()` removes overlay from `$multisite` | Hotplug |
| 7 | `rebuildMultisite()` preserves base config | Hotplug |
| 8 | `rebuildMultisite()` merges overlays additively | Hotplug |
| 9 | `replayTenantOverlays()` reads `tenants.json` correctly | Persistence |
| 10 | Domain conflict detection during plug | Safety |
| 11 | Signal file plug/unplug round-trip | Signal |
| 12 | `TenantAccessPolicy` grants/denies based on whitelist | Security |
| 13 | `TenantAccessPolicy` expires grants correctly | Security |
| 14 | `TenantAccessPolicy` blocks path traversal | Security |
| 15 | CLI setup blocked when `RAZY_TENANT_ISOLATED=true` | Safety |
| 16 | Bridge endpoint validates HMAC signature | Security |
| 17 | Bridge endpoint rejects expired timestamp | Security |
| 18 | `DataResponse` carries correct metadata | Data |
| 19 | `__onTenantCall` gate blocks unauthorized calls | Security |
| 20 | Lock/Unlock discipline in hotplug window | State safety |

### 18.2 Integration Tests — Docker

```bash
# 1. Filesystem isolation
docker exec razy-tenant-a php -r "var_dump(scandir('/app/site/data/'));"
# Expected: ['.', '..', 'vendor'] — only own data

docker exec razy-tenant-a php -r "var_dump(scandir('/app/site/sites/'));"
# Expected: ['.', '..', 'tenantA'] — only own site

# 2. open_basedir enforcement
docker exec razy-tenant-a php -r "scandir('/opt/');"
# Expected: Warning: open_basedir restriction

# 3. Hotplug: plug → route reachable
php Razy.phar tenant plug --id=test123 --domain=test.local --path=/ --dist=testdist
curl -H "Host: test.local" http://localhost:8080/
# Expected: routes to testdist

# 4. Hotplug: unplug → route gone
php Razy.phar tenant unplug --id=test123
curl -H "Host: test.local" http://localhost:8080/
# Expected: 404

# 5. Cross-tenant API
# (requires Phase 3)
```

### 18.3 Chaos Tests

| Scenario | Expected Result |
|----------|----------------|
| Kill tenant container mid-request | Proxy 502; auto-restart; next request OK |
| Kill Host Tenant | Direct tenant requests unaffected; L4 fails; host restarts; overlays restored |
| Corrupt `tenants.json` | Boot with empty overlays; CLI warning; manual re-plug |
| Invalid signal file | `check()` returns null; worker continues |
| Plug 100 tenants rapidly | ~2-5ms each; < 5MB memory; zero request impact |
| Fill tenant disk | Tenant writes fail; other tenants unaffected |
| OOM kill tenant worker | FrankenPHP restarts; clean boot |

---

## Appendix A: Decision Matrix — Docker vs Kubernetes

| Criteria | Docker Compose | Kubernetes | Recommendation |
|----------|---------------|------------|----------------|
| **Startup Cost** | Low ($5 VPS) | Medium (~$75/mo managed) | Docker for < 20 tenants |
| **Auto-Scaling** | Manual | HPA per tenant | K8s if traffic varies |
| **Network Isolation** | Basic (bridge) | Strong (NetworkPolicy) | K8s for compliance |
| **Tenant Provisioning** | Script + compose | Helm chart / Operator | K8s for > 50 tenants |
| **Multi-Node** | Swarm (limited) | Native | K8s for HA |
| **Monitoring** | DIY | Prometheus + Grafana | K8s for observability |

**Recommendation:** Start with Docker Compose (Phase 1+2), migrate to K8s (Phase 4) when tenant count exceeds 20.

## Appendix B: Glossary

| Term | Definition |
|------|-----------|
| **Tenant** | A complete Razy Application environment (multi-domain, multi-dist, multi-module) running in a container or as the local host |
| **Host Tenant** | The local Application instance running the Razy worker process. Also a tenant — communicates with container tenants as a peer |
| **Distributor** | Razy's per-site routing + module management unit. One tenant may contain multiple distributors |
| **Opaque ID** | Random hex string (e.g., `a1b2c3d4`) identifying a tenant without revealing domain/distCode |
| **Hotplug** | In-memory addition/removal of tenant routes to the Host Tenant's `$multisite` without modifying `sites.inc.php` |
| **Overlay** | A tenant's domain/alias configuration stored in `$tenantOverlays`, merged on top of the base config |
| **Core Container** | Admin container with full filesystem access; may be the Host Tenant itself in simple deployments |
| **Whitelist** | JSON-based ACL controlling cross-tenant data access via Core API |
| **Identity** | Current Razy format: `{domain}-{distCode}`, eliminated in isolated mode (opaque path) |
| **Signal File** | `.worker-signal` — filesystem-based message passing between CLI/admin and the worker process |
| **L1-L4** | Communication layers: L1 Emitter (in-process), L2 Bridge (proc_open), L3 Registration, L4 TenantEmitter (HTTP) |
