# Cross-Tenant Process Flow, Injection Analysis & FPM Pool Evaluation

> **Version:** 2.0-draft  
> **Date:** 2026-02-27  
> **Source:** Enterprise Tenant Isolation Architecture v3.0-draft  
> **Prerequisite:** UPGRADE-ROADMAP.md Phase 1 (Isolation Core) + Phase 3 (L4 Comms)

---

## Table of Contents

1. [Four-Layer Communication Summary](#1-four-layer-communication-summary)
2. [L4 Cross-Tenant Request Process Flow](#2-l4-cross-tenant-request-process-flow)
3. [Injection Threat Analysis](#3-injection-threat-analysis)
4. [FPM Pool Evaluation for Library Conflict Resolution](#4-fpm-pool-evaluation-for-library-conflict-resolution)
5. [Recommendation Summary](#5-recommendation-summary)
6. [PKI (Public/Private Key) Payload Encryption Evaluation](#6-pki-publicprivate-key-payload-encryption-evaluation)
7. [Latency Assessment & Optimization Directions](#7-latency-assessment--optimization-directions)
8. [htaccess / Caddy Rewrite for Multi-Tenant Routing](#8-htaccess--caddy-rewrite-for-multi-tenant-routing)
9. [Frontend Access to Tenant Assets](#9-frontend-access-to-tenant-assets)
10. [Caddy API + PHP Reverse Static Proxy, Container Mesh & Market Comparison](#10-caddy-api--php-reverse-static-proxycontainer-mesh--market-comparison)
11. [Core-Delegated Volume + Static File External Access Feasibility](#11-core-delegated-volume--static-file-external-access-feasibility)
12. [Data Access Rewrite (Module-Controlled) + Webassets Under Load Balancing](#12-data-access-rewrite-module-controlled--webassets-under-load-balancing)
13. [Webasset Pack — Build-Time Asset Extraction & External Storage](#13-webasset-pack--build-time-asset-extraction--external-storage)
14. [Best Solution & Unified Upgrade Roadmap](#14-best-solution--unified-upgrade-roadmap-Best Solution--Unified Upgrade Roadmap)

---

## 1. Four-Layer Communication Summary

```
┌─────────────────────────────────────────────────────────────────────┐
│ L4  Tenant ↔ Tenant    TenantEmitter → HTTP POST (Docker network)  │
│     Latency: ~5-20ms   Serialization: JSON   Auth: HMAC-SHA256     │
├─────────────────────────────────────────────────────────────────────┤
│ L3  Domain Registration   Application::plugTenant() / unplugTenant()│
│     Memory overlay into $multisite — no transport, no serialization │
├─────────────────────────────────────────────────────────────────────┤
│ L2  Dist ↔ Dist (Bridge CLI)   Distributor::executeInternalAPI()   │
│     Latency: ~50-100ms  Transport: proc_open   Auth: __onBridgeCall│
├─────────────────────────────────────────────────────────────────────┤
│ L1  Module ↔ Module (Emitter)   Controller::api() → Emitter        │
│     Latency: <0.01ms   Transport: In-process   Auth: __onAPICall   │
└─────────────────────────────────────────────────────────────────────┘
```

### Current Implementation Status

| Layer | Implemented | Permission Gate | Transport |
|-------|------------|-----------------|-----------|
| L1 | ✅ `Emitter.php` | `__onAPICall(ModuleInfo, method)` → default `true` | In-process |
| L2 | ✅ `Distributor::executeInternalAPI()` | `__onBridgeCall(sourceDist, command)` → default `true` | `proc_open` CLI |
| L3 | ⬜ Planned | N/A (admin operation) | N/A |
| L4 | ⬜ Planned | `__onTenantCall(tenantId, distCode, command)` | HTTP POST |

### Key Code Locations (Existing)

| Component | File | Method |
|-----------|------|--------|
| L1 Emitter proxy | `src/library/Razy/Emitter.php` | `__call()` → `Module::execute()` |
| L1 permission gate | `src/library/Razy/Controller.php` | `__onAPICall()` |
| L2 bridge client | `src/library/Razy/Distributor.php` | `executeInternalAPI()` |
| L2 permission gate | `src/library/Razy/Controller.php` | `__onBridgeCall()` |
| L1 command dispatch | `src/library/Razy/Module/CommandRegistry.php` | `execute()` |
| L2 command dispatch | `src/library/Razy/Module/CommandRegistry.php` | `executeBridgeCommand()` |
| Internal dispatch (no perm) | `src/library/Razy/Module/CommandRegistry.php` | `executeInternalCommand()` |
| Autoloader (per-dist) | `src/library/Razy/Distributor/ModuleScanner.php` | `autoload()` |
| Package extraction | `src/library/Razy/PackageManager.php` | `validate()` → extracts to `autoload/{distCode}/` |
| SPL registration | `src/library/Razy/Application.php` | `host()` → `spl_autoload_register()` |

---

## 2. L4 Cross-Tenant Request Process Flow

### 2.1 Architecture Overview

```
┌──────────────────────────────┐    HTTP POST     ┌──────────────────────────────┐
│   Tenant A (Caller)          │  ────────────►   │   Tenant B (Callee)          │
│                              │  Docker Network  │                              │
│  Module → Controller         │                  │  Bridge Handler              │
│     ↓                        │                  │     ↓                        │
│  tenantApi(B, dist, module)  │                  │  HMAC Verify                 │
│     ↓                        │                  │     ↓                        │
│  TenantEmitter.__call()      │                  │  __onTenantCall gate         │
│     ↓                        │                  │     ↓                        │
│  HttpClient.post()           │                  │  CommandRegistry.execute()   │
│     ↓                        │                  │     ↓                        │
│  HMAC Sign + JSON body  ─────┼──────────────────┼► Parse + Dispatch            │
│                              │                  │     ↓                        │
│  JSON decode ← ─ ─ ─ ─ ─ ─ ─┼──────────────────┼── JSON envelope response     │
└──────────────────────────────┘                  └──────────────────────────────┘
```

### 2.2 Detailed Process Flow (Sequence)

```
Caller Module         Controller        TenantEmitter        HttpClient        Network        Bridge Handler        Permission Gate        CommandRegistry        Callee Module
    │                    │                   │                    │                │                │                      │                      │                    │
    │ getRecentOrders()  │                   │                    │                │                │                      │                      │                    │
    ├───────────────────►│                   │                    │                │                │                      │                      │                    │
    │                    │ tenantApi(B,d,m)  │                    │                │                │                      │                      │                    │
    │                    ├──────────────────►│                    │                │                │                      │                      │                    │
    │                    │                   │ __call('getRecentOrders', [10])     │                │                      │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │ [1] Build JSON body               │                │                      │                      │                    │
    │                    │                   │ {"dist":"d","module":"m",          │                │                      │                      │                    │
    │                    │                   │  "command":"getRecentOrders",      │                │                      │                      │                    │
    │                    │                   │  "args":[10]}                      │                │                      │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │ [2] Compute HMAC                   │                │                      │                      │                    │
    │                    │                   │ sig = hmac-sha256(secret,          │                │                      │                      │                    │
    │                    │                   │   tenantId:timestamp:body)         │                │                      │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │ [3] POST request   │                │                │                      │                      │                    │
    │                    │                   ├───────────────────►│                │                │                      │                      │                    │
    │                    │                   │                    │ HTTP POST      │                │                      │                      │                    │
    │                    │                   │                    │ /_razy/internal/bridge          │                      │                      │                    │
    │                    │                   │                    ├───────────────►│                │                      │                      │                    │
    │                    │                   │                    │                │ [4] Receive    │                      │                      │                    │
    │                    │                   │                    │                ├───────────────►│                      │                      │                    │
    │                    │                   │                    │                │                │ [5] Verify HMAC      │                      │                    │
    │                    │                   │                    │                │                │  - Check timestamp   │                      │                    │
    │                    │                   │                    │                │                │    (±60s window)     │                      │                    │
    │                    │                   │                    │                │                │  - Recompute sig     │                      │                    │
    │                    │                   │                    │                │                │  - hash_equals()     │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │                    │                │                │ [6] Permission gate  │                      │                    │
    │                    │                   │                    │                │                ├─────────────────────►│                      │                    │
    │                    │                   │                    │                │                │   __onTenantCall(    │                      │                    │
    │                    │                   │                    │                │                │     tenantId,        │                      │                    │
    │                    │                   │                    │                │                │     distCode,        │                      │                    │
    │                    │                   │                    │                │                │     command)         │                      │                    │
    │                    │                   │                    │                │                │◄─────────────────────┤ return true/false     │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │                    │                │                │ [7] Dispatch command │                      │                    │
    │                    │                   │                    │                │                ├─────────────────────────────────────────────►│                    │
    │                    │                   │                    │                │                │                      │  executeBridgeCommand │                    │
    │                    │                   │                    │                │                │                      │  (moduleCode,command, │                    │
    │                    │                   │                    │                │                │                      │   args)               │                    │
    │                    │                   │                    │                │                │                      │                      ├───────────────────►│
    │                    │                   │                    │                │                │                      │                      │  execute(args)     │
    │                    │                   │                    │                │                │                      │                      │◄───────────────────┤
    │                    │                   │                    │                │                │                      │                      │  return $result    │
    │                    │                   │                    │                │                │◄─────────────────────────────────────────────┤                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │                    │                │ [8] Wrap response                     │                      │                    │
    │                    │                   │                    │                │ {"ok":true,"data":$result}            │                      │                    │
    │                    │                   │                    │◄───────────────┤                │                      │                      │                    │
    │                    │                   │◄───────────────────┤ HTTP 200       │                │                      │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
    │                    │                   │ [9] Unwrap envelope                │                │                      │                      │                    │
    │                    │                   │ return $response['data']           │                │                      │                      │                    │
    │                    │◄──────────────────┤                    │                │                │                      │                      │                    │
    │◄───────────────────┤ return $result    │                    │                │                │                      │                      │                    │
    │                    │                   │                    │                │                │                      │                      │                    │
```

### 2.3 Step-by-Step Walkthrough

#### Step 1 — Caller Module Initiates API Call

```php
// In Tenant A's module controller
class OrderManager extends Controller
{
    public function getExternalOrders(): array
    {
        // Create a TenantEmitter targeting Tenant B
        $tenantApi = $this->tenantApi('tenant-b-id', 'shop-main', 'orderModule');
        
        // Magic __call dispatches to L4 transport
        return $tenantApi->getRecentOrders(10);
    }
}
```

- `Controller::tenantApi()` returns a `TenantEmitter` instance
- `TenantEmitter` is a `__call` proxy (same pattern as L1 `Emitter`)

#### Step 2 — TenantEmitter Builds Request

```php
class TenantEmitter
{
    public function __call(string $method, array $args): mixed
    {
        // 1. Build JSON body
        $body = json_encode([
            'dist'    => $this->distCode,
            'module'  => $this->moduleCode,
            'command' => $method,
            'args'    => $args,
        ]);
        
        // 2. Compute HMAC signature
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            "{$this->callerTenantId}:{$timestamp}:{$body}",
            $this->sharedSecret  // from env or K8s secret
        );
        
        // 3. Send HTTP POST
        $response = $this->httpClient
            ->withHeaders([
                'X-Razy-Tenant-Id'  => $this->callerTenantId,
                'X-Razy-Timestamp'  => $timestamp,
                'X-Razy-Signature'  => $signature,
                'Content-Type'      => 'application/json',
            ])
            ->post($this->resolveEndpoint(), $body);
        
        // 4. Unwrap envelope (same as L2)
        $envelope = $response->json();
        if (!($envelope['ok'] ?? false)) {
            throw new TenantCommunicationException(
                $envelope['error'] ?? 'Unknown error',
                $envelope['code'] ?? 0
            );
        }
        
        return $envelope['data'] ?? null;
    }
    
    private function resolveEndpoint(): string
    {
        // Docker: http://tenant-{id}:8080/_razy/internal/bridge
        // K8s:    http://{service}.{namespace}:8080/_razy/internal/bridge
        return "http://{$this->targetHost}:8080/_razy/internal/bridge";
    }
}
```

#### Step 3 — Network Transport

```
POST /_razy/internal/bridge HTTP/1.1
Host: tenant-b:8080
X-Razy-Tenant-Id: tenant-a-id
X-Razy-Timestamp: 1708700000
X-Razy-Signature: a1b2c3d4e5f6...
Content-Type: application/json

{"dist":"shop-main","module":"orderModule","command":"getRecentOrders","args":[10]}
```

- Docker internal network: `tenant-a` → `tenant-b` (no public exposure)
- Caddy does NOT expose `/_razy/internal/*` routes to the internet
- Connection reuse via persistent HTTP client (keep-alive)

#### Step 4 — Bridge Handler Receives Request

The bridge handler is a special internal route registered by the framework (not by user modules):

```php
// Registered internally by Application during initialization
// Route: /_razy/internal/bridge (POST only)

function handleBridgeRequest(): void
{
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        return;
    }
    
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        return;
    }
    
    // ... continues to Step 5
}
```

#### Step 5 — HMAC Verification

```php
// Extract auth headers
$tenantId  = $_SERVER['HTTP_X_RAZY_TENANT_ID'] ?? '';
$timestamp = (int) ($_SERVER['HTTP_X_RAZY_TIMESTAMP'] ?? 0);
$signature = $_SERVER['HTTP_X_RAZY_SIGNATURE'] ?? '';

// [5a] Timestamp window check (prevent replay)
if (abs(time() - $timestamp) > 60) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Request expired']);
    return;
}

// [5b] Recompute HMAC
$expected = hash_hmac(
    'sha256',
    "{$tenantId}:{$timestamp}:{$rawBody}",
    getenv('RAZY_BRIDGE_SECRET')  // shared secret from env
);

// [5c] Constant-time comparison (prevents timing attacks)
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    return;
}
```

#### Step 6 — Permission Gate (`__onTenantCall`)

```php
// Resolve the target module
$distCode   = $payload['dist']    ?? '';
$moduleCode = $payload['module']  ?? '';
$command    = $payload['command']  ?? '';
$args       = $payload['args']    ?? [];

// Find the distributor and module
$distributor = $application->getDistributorByCode($distCode);
$module      = $distributor->getRegistry()->get($moduleCode);

// Call the module's permission gate
$controller = $module->getController();
if (!$controller->__onTenantCall($tenantId, $distCode, $command)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied by module']);
    return;
}
```

Module controllers can override:
```php
class OrderModule extends Controller
{
    // Whitelist approach: only allow specific tenants and commands
    public function __onTenantCall(string $tenantId, string $distCode, string $command): bool
    {
        // Only host tenant and trusted tenants can call us
        $allowed = ['host-tenant', 'billing-tenant'];
        if (!in_array($tenantId, $allowed, true)) {
            return false;
        }
        
        // Only allow read operations
        return in_array($command, ['getRecentOrders', 'getOrderStatus'], true);
    }
}
```

#### Step 7 — Command Dispatch

```php
// Use existing CommandRegistry to dispatch (same as L2)
$registry = $module->getCommandRegistry();
$result   = $registry->executeBridgeCommand($moduleCode, $command, $args);
```

This reuses the existing L2 dispatch path, with `__onBridgeCall` as an additional inner permission gate. The L4 flow adds an **outer** gate (`__onTenantCall`) before reaching `executeBridgeCommand`.

**Double gate architecture:**
```
L4 request → HMAC verify → __onTenantCall (outer) → executeBridgeCommand → __onBridgeCall (inner) → executeCommand → controller method
```

#### Step 8 — Response Envelope

```php
// Wrap result in standard envelope (same format as L2)
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'ok'   => true,
    'data' => $result,
]);
```

Error case:
```php
echo json_encode([
    'ok'    => false,
    'error' => $exception->getMessage(),
    'code'  => $exception->getCode(),
]);
```

#### Step 9 — Caller Unwraps Response

`TenantEmitter::__call()` transparently unwraps the envelope and returns `$response['data']`. The caller module sees a plain return value — identical UX to L1 `Emitter`.

### 2.4 Error Flow

```
Caller Module                  TenantEmitter                 Network            Bridge Handler
    │                               │                           │                     │
    │ tenantApi->method()           │                           │                     │
    ├──────────────────────────────►│                           │                     │
    │                               │ HTTP POST ───────────────►│                     │
    │                               │                           │ ─────────────────── ►│
    │                               │                           │                     │
    │                               │         Possible failures:                      │
    │                               │         ┌──────────────────────────────────┐     │
    │                               │         │ [A] Network timeout (5s default) │     │
    │                               │         │ [B] HMAC rejected (403)          │     │
    │                               │         │ [C] Module not found (404)       │     │
    │                               │         │ [D] Permission denied (403)      │     │
    │                               │         │ [E] Command execution error(500) │     │
    │                               │         │ [F] Timestamp expired (401)      │     │
    │                               │         └──────────────────────────────────┘     │
    │                               │                           │                     │
    │  TenantCommunicationException │                           │                     │
    │◄──────────────────────────────┤                           │                     │
```

| Error Code | Condition | Caller Receives |
|------------|-----------|-----------------|
| Timeout | Container down / unresponsive | `TenantCommunicationException('Connection timeout')` |
| 401 | Timestamp outside ±60s window | `TenantCommunicationException('Request expired')` |
| 403 (HMAC) | Wrong shared secret | `TenantCommunicationException('Invalid signature')` |
| 403 (gate) | `__onTenantCall` returns false | `TenantCommunicationException('Permission denied')` |
| 404 | Module/command not found | `TenantCommunicationException('Module not found')` |
| 500 | Exception in callee module | `TenantCommunicationException($message, $code)` |

### 2.5 Endpoint Resolution Strategy

| Deployment | Host Resolution | Example |
|------------|----------------|---------|
| Docker Compose | Container service name | `http://tenant-abc:8080` |
| Kubernetes | Service DNS in namespace | `http://razy.tenant-abc:8080` |
| Same-host (dev) | `localhost` + port offset | `http://localhost:8081` |

Resolution is configured per-tenant in `tenants.json`:
```json
{
    "abc123": {
        "id": "abc123",
        "host": "tenant-abc",
        "port": 8080,
        "secret": "env:RAZY_BRIDGE_SECRET_abc123",
        "status": "active"
    }
}
```

---

## 3. Injection Threat Analysis

### 3.1 Attack Surface Map

```
                         Internet
                            │
                    ┌───────┴────────┐
                    │ Caddy Reverse  │
                    │ Proxy          │
                    │ (blocks /_razy │
                    │  /internal/*)  │
                    └───────┬────────┘
                            │
              ┌─────────────┼─────────────┐
              │             │             │
         ┌────┴────┐  ┌────┴────┐  ┌────┴────┐
         │Tenant A │  │Tenant B │  │  Host   │
         │Container│  │Container│  │ Tenant  │
         └────┬────┘  └────┬────┘  └────┬────┘
              │             │             │
              └─────────────┼─────────────┘
                     Docker Internal
                       Network
```

**Attack vectors identified:**

| # | Vector | Entry Point | Transport |
|---|--------|-------------|-----------|
| V1 | JSON payload injection | HTTP body to `/_razy/internal/bridge` | L4 HTTP |
| V2 | Command injection via moduleCode/command | Payload fields `module`, `command` | L4 HTTP |
| V3 | HMAC bypass / forging | `X-Razy-Signature` header | L4 HTTP |
| V4 | Tenant ID spoofing | `X-Razy-Tenant-Id` header | L4 HTTP |
| V5 | Replay attack | Captured valid request re-sent | L4 HTTP |
| V6 | SSRF via endpoint manipulation | `tenants.json` host field | Configuration |
| V7 | Deserialization attack | JSON body parsed as PHP types | L4 HTTP |
| V8 | Argument injection | `args` array in payload | L4 HTTP |
| V9 | Path traversal (DataRequest) | File path in data request | L4 HTTP |
| V10 | Timing side-channel | HMAC comparison | L4 HTTP |
| V11 | Internal command bypass | `CommandRegistry::executeInternalCommand()` | L4 HTTP |

### 3.2 Detailed Threat Analysis

#### V1 — JSON Payload Injection

**Attack:** Malicious tenant sends crafted JSON body with unexpected structure.

```json
{
    "dist": "shop-main",
    "module": "../../etc/passwd",
    "command": "__construct",
    "args": ["rm -rf /"]
}
```

**Risk Level:** MEDIUM

**Mitigations:**
1. **Input validation at bridge handler** — Strict regex validation on `dist`, `module`, `command` fields:
   ```php
   // module code must match vendor/package format
   if (!preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+$/i', $payload['module'])) {
       return error(400, 'Invalid module code format');
   }
   // command must be a valid PHP method name
   if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $payload['command'])) {
       return error(400, 'Invalid command format');
   }
   // dist code must be alphanumeric + dash/underscore
   if (!preg_match('/^[a-zA-Z0-9_-]+$/', $payload['dist'])) {
       return error(400, 'Invalid dist code format');
   }
   ```
2. **Existing ModuleInfo::REGEX_MODULE_CODE** already validates module code format
3. **CommandRegistry lookup** only matches registered commands — arbitrary strings can't reach code execution
4. **`args` array** is passed as-is to the PHP method. The callee is responsible for type-checking arguments (same trust model as L1/L2)

**Residual risk:** LOW — Args are user-controlled. Modules must validate arguments like any public API.

---

#### V2 — Command Injection via Parameter Fields

**Attack:** Injecting shell metacharacters or PHP code via `module` / `command` fields.

**Risk Level:** LOW

**Analysis:**
- Unlike L2 (`proc_open` with CLI arguments), L4 uses **HTTP transport** — no shell involved
- `module` resolves to a `Module` object via `ModuleRegistry::get()` — lookup by string key in an associative array. No file path resolution, no `include`, no `eval`
- `command` resolves via `CommandRegistry` — lookup in a pre-registered command map. Unregistered commands return null → 404
- **No path is constructed from user input** at any point in the bridge handler

**Mitigation (defense-in-depth):** Input regex validation (see V1) prevents any non-alphanumeric input from reaching the resolution layer.

**Residual risk:** NEGLIGIBLE

---

#### V3 — HMAC Bypass / Forging

**Attack:** Forging a valid HMAC signature without knowing the shared secret.

**Risk Level:** CRITICAL (if bypassed) → LOW (with proper implementation)

**Mitigations:**
1. **HMAC-SHA256** — computationally infeasible to forge without the key
2. **Shared secret per tenant pair** — compromise of one secret doesn't affect others
3. **Secret rotation** — environment variable allows rotation without code changes
4. **Key length** — minimum 32 bytes enforced by framework validation at startup

**Implementation requirements:**
```php
// At startup, validate secret existence and length
$secret = getenv('RAZY_BRIDGE_SECRET');
if (!$secret || strlen($secret) < 32) {
    throw new SecurityException('RAZY_BRIDGE_SECRET must be at least 32 bytes');
}
```

**Residual risk:** LOW — Standard HMAC-SHA256 is industry battle-tested.

---

#### V4 — Tenant ID Spoofing

**Attack:** Container A sends request with `X-Razy-Tenant-Id: container-b-id` to impersonate another tenant.

**Risk Level:** MEDIUM

**Analysis:**
- The `X-Razy-Tenant-Id` header is self-asserted by the caller
- The HMAC signature includes the tenant ID, so:
  - If tenant A claims to be tenant B, they need tenant B's shared secret to produce a valid HMAC
  - Each tenant pair has a **unique secret** → spoofing requires the target's secret

**Mitigations:**
1. **HMAC binds tenant ID to signature** — `hmac(secret, "tenantId:timestamp:body")`. Changing the tenant ID invalidates the signature
2. **Per-pair secrets** — Tenant A's secret ≠ Tenant B's secret
3. **K8s NetworkPolicy** — blocks direct container-to-container traffic; all traffic routes through known service endpoints
4. **Docker internal network** — external traffic cannot reach bridge endpoints

**Enhanced mitigation (optional):**
```php
// Verify source IP against expected container address
$callerIp = $_SERVER['REMOTE_ADDR'];
$expectedIp = $tenantRegistry->getExpectedIp($tenantId);
if ($expectedIp && $callerIp !== $expectedIp) {
    return error(403, 'Source IP mismatch');
}
```

**Residual risk:** LOW — HMAC + per-pair secrets make spoofing computationally infeasible.

---

#### V5 — Replay Attack

**Attack:** Intercepting a valid L4 request and re-sending it later to trigger duplicate operations.

**Risk Level:** MEDIUM

**Mitigations:**
1. **60-second timestamp window** — requests older than 60s are rejected:
   ```php
   if (abs(time() - $timestamp) > 60) {
       return error(401, 'Request expired');
   }
   ```
2. **Nonce + deduplication (optional enhancement):**
   ```php
   // Generate unique nonce per request
   $nonce = bin2hex(random_bytes(16));
   // Include in HMAC: "tenantId:timestamp:nonce:body"
   // Bridge handler stores nonce in short-TTL cache; rejects duplicates
   ```

**Analysis without nonce:**
- 60s window means a captured request can be replayed within that window
- Docker internal network makes interception unlikely (no external access)
- For **idempotent operations** (reads), replay has no side-effects
- For **non-idempotent operations** (writes), the 60s window is a concern

**Recommendation:** Phase 1 ships with timestamp-only (60s). Phase 2 adds nonce-based deduplication for modules that register non-idempotent bridge commands.

**Residual risk:** LOW (within Docker network); MEDIUM (if internal network is compromised).

---

#### V6 — SSRF via Endpoint Manipulation

**Attack:** Manipulating `tenants.json` to point a tenant's host to an internal service (Redis, database, etc.).

**Risk Level:** MEDIUM

**Analysis:**
- `tenants.json` is writable only by the Host Tenant and CLI
- Isolated tenant containers have no access to this file
- If an attacker compromises the Host Tenant, they have broader access anyway

**Mitigations:**
1. **File permission**: `tenants.json` owned by `root:razy`, mode `0640`
2. **Host validation on load:**
   ```php
   // Only allow known container hostnames
   if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
       throw new ConfigurationException("Invalid tenant host: {$host}");
   }
   // Block private IPs that aren't Docker network
   $blocked = ['127.0.0.1', '0.0.0.0', '169.254.169.254'];
   if (in_array($host, $blocked)) {
       throw new SecurityException("Blocked host address: {$host}");
   }
   ```
3. **Port whitelist:** Only allow port 8080 (Razy's default)

**Residual risk:** LOW — requires Host Tenant compromise.

---

#### V7 — Deserialization Attack

**Attack:** Sending a JSON body that, when decoded, creates dangerous PHP objects.

**Risk Level:** NEGLIGIBLE

**Analysis:**
- PHP's `json_decode()` **never** creates objects by default (`assoc: true` returns arrays)
- Unlike `unserialize()`, JSON decode cannot instantiate arbitrary PHP classes
- Razy explicitly uses `json_decode($body, true)` → always arrays

**Residual risk:** NEGLIGIBLE

---

#### V8 — Argument Injection

**Attack:** Sending crafted `args` values to exploit module logic.

```json
{
    "dist": "shop",
    "module": "vendor/order",
    "command": "query",
    "args": ["1; DROP TABLE orders; --"]
}
```

**Risk Level:** MEDIUM (dependent on module implementation)

**Analysis:**
- This is NOT a framework-level vulnerability — it's standard API input validation
- Same risk exists in L1 (`Emitter`) and L2 (`executeInternalAPI`) today
- The framework serializes/deserializes the `args` array. The callee module's method receives them as PHP values

**Framework-level mitigations:**
1. **JSON-only transport** — args can only be JSON-safe types (string, int, float, bool, array, null). No PHP objects, no closures
2. **Type enforcement (recommended):**
   ```php
   // At bridge handler: ensure args is a flat array (no nested objects)
   if (!is_array($payload['args'])) {
       return error(400, 'args must be an array');
   }
   ```

**Module-level responsibility:**
- SQL injection → use parameterized queries (already standard practice via `Statement` class)
- Path traversal → validate path inputs
- Type coercion → declare typed parameters in command methods

**Residual risk:** MEDIUM — modules must follow input validation best practices. Framework cannot prevent all logic-level attacks.

---

#### V9 — Path Traversal (DataRequest)

**Attack:** Using `DataRequest` to access files outside the module's data directory.

```php
$request->read('../../config/database.php');
```

**Risk Level:** HIGH (if unmitigated)

**Mitigations:**
1. **`realpath()` + base path validation** in `DataResponse`:
   ```php
   $realPath = realpath($dataDir . '/' . $requestedPath);
   if ($realPath === false || !str_starts_with($realPath, $dataDir)) {
       throw new SecurityException('Path traversal blocked');
   }
   ```
2. **Docker `open_basedir`** — isolated containers have `open_basedir=/app/site:/tmp` — filesystem access is physically constrained
3. **`__onDataRequest` permission gate** — module explicitly approves each data request

**Residual risk:** LOW — defense-in-depth: path validation + `open_basedir` + permission gate.

---

#### V10 — Timing Side-Channel

**Attack:** Measuring HMAC comparison time to deduce the secret byte-by-byte.

**Risk Level:** LOW

**Mitigation:** Already addressed — use `hash_equals()` for constant-time comparison:
```php
if (!hash_equals($expected, $signature)) {
    return error(403, 'Invalid signature');
}
```

**Residual risk:** NEGLIGIBLE — `hash_equals()` is specifically designed to prevent timing attacks.

---

#### V11 — Internal Command Bypass

**Attack:** Crafting requests that reach `CommandRegistry::executeInternalCommand()` which has **no permission gate**.

**Risk Level:** HIGH (if reachable)

**Analysis of existing code:**
```php
// CommandRegistry.php
public function executeInternalCommand(...) {
    // NO permission gate — used by the internal HTTP bridge
    return $this->executeCommand(null, $moduleCode, $command, $arguments);
}
```

This method exists for L2 bridge calls where the CLI process has already been authenticated. If L4 traffic accidentally reaches `executeInternalCommand()` instead of `executeBridgeCommand()`, the `__onBridgeCall` gate would be bypassed.

**Mitigation:**
- **L4 bridge handler MUST call `executeBridgeCommand()`** (which includes `__onBridgeCall` gate)
- PLUS the outer `__onTenantCall` gate at the bridge handler level
- **Never expose `executeInternalCommand()` to HTTP requests**

**Implementation guard:**
```php
// At framework level: mark executeInternalCommand as CLI-only
public function executeInternalCommand(string $moduleCode, string $command, array $arguments = []): mixed
{
    if (!CLI_MODE) {
        throw new SecurityException('executeInternalCommand() is only available in CLI mode');
    }
    return $this->executeCommand(null, $moduleCode, $command, $arguments);
}
```

**Residual risk:** LOW (with proper routing to `executeBridgeCommand()` + CLI guard).

### 3.3 Threat Matrix Summary

| Vector | Risk (Raw) | Risk (Mitigated) | Mitigation Type |
|--------|-----------|-------------------|-----------------|
| V1 JSON payload injection | MEDIUM | LOW | Input regex + CommandRegistry lookup |
| V2 Command injection | LOW | NEGLIGIBLE | HTTP transport (no shell) + regex |
| V3 HMAC bypass | CRITICAL | LOW | HMAC-SHA256 + 32-byte key minimum |
| V4 Tenant ID spoofing | MEDIUM | LOW | HMAC binds ID + per-pair secrets |
| V5 Replay attack | MEDIUM | LOW | 60s timestamp + future nonce option |
| V6 SSRF endpoint | MEDIUM | LOW | File perms + host validation |
| V7 Deserialization | NEGLIGIBLE | NEGLIGIBLE | JSON-only (no unserialize) |
| V8 Argument injection | MEDIUM | MEDIUM | Module responsibility (standard API) |
| V9 Path traversal | HIGH | LOW | realpath() + open_basedir + gate |
| V10 Timing attack | LOW | NEGLIGIBLE | hash_equals() |
| V11 Internal cmd bypass | HIGH | LOW | CLI guard + correct dispatch routing |

### 3.4 Required Security Actions for Phase 3

| Priority | Action | Where |
|----------|--------|-------|
| P0 (Critical) | Implement HMAC-SHA256 with `hash_equals()` | Bridge handler |
| P0 (Critical) | Add CLI-only guard to `executeInternalCommand()` | `CommandRegistry.php` |
| P0 (Critical) | Route L4 requests through `executeBridgeCommand()` only | Bridge handler |
| P1 (High) | Input regex validation on `dist`, `module`, `command` | Bridge handler |
| P1 (High) | `realpath()` + base path check in DataRequest/DataResponse | New files |
| P1 (High) | 60s timestamp window on HMAC | Bridge handler |
| P2 (Medium) | Host validation on `tenants.json` load | Application.php |
| P2 (Medium) | Secret length validation (≥32 bytes) at startup | Bootstrap |
| P3 (Low) | Optional nonce-based replay prevention | Bridge handler (Phase 2) |
| P3 (Low) | Source IP validation (optional) | Bridge handler |

---

## 4. FPM Pool Evaluation for Library Conflict Resolution

### 4.1 The Library Conflict Problem

**Root cause:** PHP's class table is global per-process. Once a class is loaded, it cannot be unloaded or replaced.

```
Single PHP Process (FrankenPHP Worker)
┌────────────────────────────────────────────────┐
│  Class Table (global, immutable once loaded)    │
│  ┌──────────────────────────────────────────┐  │
│  │ Razy\Distributor          → loaded       │  │
│  │ Vendor\Package\SomeClass  → v1.0 (Dist A)│  │ ← First load wins
│  │ Vendor\Package\SomeClass  → CONFLICT!    │  │ ← Dist B wants v2.0
│  └──────────────────────────────────────────┘  │
│                                                 │
│  SPL Autoload Chain:                            │
│  1. bootstrap (Razy framework classes)          │
│  2. Application (delegates to matched Domain)   │
│     └─ Domain → Distributor → ModuleScanner     │
│           ↓                                     │
│     autoload/{distCode}/ (per-dist packages)    │
└────────────────────────────────────────────────┘
```

**Current autoloader flow:**
1. Bootstrap: `library/` (framework) → `phar/library/` (phar fallback)
2. Application: delegates to matched `Domain::autoload()` → `Distributor::autoload()`
3. ModuleScanner: tries `module/{moduleCode}/library/` → falls back to `autoload/{distCode}/`
4. `autoload/{distCode}/` contains PSR-4/PSR-0 packages extracted by `PackageManager`

**Conflict scenario:**
```
Request 1: matches Dist A → loads Vendor\Lib\Client v1.2
Request 2: matches Dist B → Vendor\Lib\Client already in class table → gets v1.2 instead of v2.0
```

In **non-worker mode** (PHP-FPM / standard CGI), this is NOT a problem because each request spawns a fresh process. In **FrankenPHP worker mode**, the class table persists across requests.

### 4.2 Current Isolation Mechanisms

| Mechanism | Isolation Level | Worker-Safe? |
|-----------|----------------|--------------|
| Per-dist `autoload/{distCode}/` directory | File-level (separate extraction paths) | ❌ No — class table shared |
| SPL autoloader delegation via Domain | Request-level (only matched dist's autoloader runs) | ⚠️ Partial — loaded classes persist |
| Docker container per tenant | Process-level | ✅ Yes — separate PHP process |
| ModuleScanner namespace convention | Namespace-level | ✅ Vendor/package format avoids collision within dist |

### 4.3 PHP-FPM Pool Architecture

```
                    Caddy / Nginx
                         │
            ┌────────────┼────────────┐
            │            │            │
     ┌──────┴──────┐ ┌──┴──────┐ ┌──┴──────────┐
     │ FPM Pool A  │ │FPM Pool B│ │FPM Pool Host│
     │ (Dist A)    │ │(Dist B)  │ │(Host Tenant)│
     │             │ │          │ │             │
     │ workers: 4  │ │workers: 4│ │workers: 2   │
     │ vendor/ v1  │ │vendor/ v2│ │vendor/ v3   │
     │ autoload/A/ │ │autoload/B│ │autoload/host│
     │             │ │          │ │             │
     │ Separate    │ │Separate  │ │Separate     │
     │ class table │ │class tbl │ │class table  │
     └─────────────┘ └─────────┘ └─────────────┘
```

**How it works:**
- Each distributor gets its own FPM pool with `N` worker processes
- Each pool has its own `php.ini` (can differ per-dist), `open_basedir`, memory limit
- The reverse proxy (Caddy/Nginx) routes by domain to the correct pool's socket
- Each pool loads ONLY its own distributor's autoload paths
- **Complete class-level isolation** — no cross-dist pollution

### 4.4 Comparison Matrix

| Dimension | FrankenPHP Worker | PHP-FPM Pools | Docker Containers |
|-----------|------------------|---------------|-------------------|
| **Class isolation** | ❌ Shared class table | ✅ Per-pool class table | ✅ Per-container process |
| **Memory overhead** | LOW: single shared process | MEDIUM: N workers × M pools | HIGH: full container per tenant |
| **Request latency** | ~0.3ms (no fork) | ~1-3ms (fork or existing worker) | ~0.3ms (FrankenPHP inside container) |
| **Cold start** | ~50ms (framework boot) | ~50ms (per pool worker) | ~200-500ms (container startup) |
| **Config complexity** | LOW: single Caddyfile | MEDIUM: N pool configs + proxy routing | HIGH: Docker Compose + network + volumes |
| **Library conflict resolution** | ❌ No | ✅ Yes (complete) | ✅ Yes (complete) |
| **Filesystem isolation** | ❌ No (shared filesystem) | ⚠️ Partial (`open_basedir` per pool) | ✅ Yes (volume mounts) |
| **Network isolation** | ❌ No (same process) | ❌ No (same host) | ✅ Yes (Docker network) |
| **Horizontal scaling** | Via FrankenPHP process count | Via pool `pm.max_children` | Via `replicas` / HPA |
| **L4 communication** | N/A (same process) | Loopback HTTP or Unix socket | Docker network HTTP |
| **Hot-reload** | Signal-based + hotplug | FPM graceful reload per pool | Container restart |
| **Resource limits** | Per-request `memory_limit` | Per-pool `memory_limit`, CPU via cgroups | Full cgroup isolation |
| **Suitable scale** | 1-50 distributors | 1-200 distributors | 1-∞ (K8s) |

### 4.5 FPM Pool — Library Conflict Resolution Analysis

#### Does FPM Pool solve cross-dist library conflicts?

**Yes, completely.** Each FPM pool is a separate set of PHP worker processes with independent class tables. Dist A's pool loads `Vendor\Lib\Client` v1.2 while Dist B's pool loads the same FQCN as v2.0 — they never interfere. This is the same isolation level as Docker containers but with much lower overhead.

#### How would it integrate with Razy?

```
Current Architecture:
  Caddy → FrankenPHP (one worker pool) → Application → Domain → Distributor

FPM Pool Architecture:
  Caddy → routing by domain → FPM Pool X → Application (standalone-like) → Distributor X
                            → FPM Pool Y → Application (standalone-like) → Distributor Y
```

Integration changes required:

| Component | Change | Effort |
|-----------|--------|--------|
| **Caddy/Nginx config** | Route by domain to pool socket | ~2h |
| **FPM pool config generator** | Script to create `pool.d/{dist}.conf` per distributor | ~3h |
| **Application bootstrap** | Pool runs in "single-dist mode" (like standalone) | ~2h |
| **autoload/ path** | Pool sets `RAZY_DIST_CODE` env → autoloader scoped | ~1h |
| **L4 TenantEmitter** | HTTP to loopback (same host, different FPM socket) | ~1h |
| **Main.php** | Detect FPM mode vs FrankenPHP mode | ~1h |

**Total additional effort:** ~10h

#### Caveats

1. **Loses FrankenPHP worker mode benefits** — FPM must fork (or reuse) a worker per request. FrankenPHP's persistent worker eliminates this overhead entirely
2. **More processes** — 4 pools × 4 workers = 16 PHP processes vs FrankenPHP's ~4 workers
3. **Memory multiplication** — each pool loads the framework independently: `4 pools × ~25MB = ~100MB` base vs `1 × ~25MB`
4. **Configuration sprawl** — N distributors = N pool config files
5. **No network isolation** — all pools share the host network. Docker containers provide true network isolation

### 4.6 Hybrid Architecture (Recommended)

Rather than choosing one isolation strategy, use a **tiered model** based on trust and conflict risk:

```
┌─────────────────────────────────────────────────┐
│ Tier 1: Trusted (Same vendor, no lib conflicts) │
│ → FrankenPHP single worker (current mode)       │
│ → Shared class table OK                         │
│ → L1/L2 communication (in-process / CLI bridge) │
│ → Best performance                              │
│                                                  │
│ Use when: Distributors share same library deps   │
│ or have non-overlapping namespaces               │
├─────────────────────────────────────────────────┤
│ Tier 2: Library-Conflicting (Same host,         │
│         different lib versions)                  │
│ → PHP-FPM pools per-dist (or FrankenPHP multi)  │
│ → Separate class tables                          │
│ → L4 communication (loopback HTTP)              │
│ → Moderate overhead                              │
│                                                  │
│ Use when: Distributors need different versions   │
│ of the same library but are on the same host     │
├─────────────────────────────────────────────────┤
│ Tier 3: Untrusted / Enterprise (Full isolation) │
│ → Docker containers per-tenant                   │
│ → Separate process + filesystem + network        │
│ → L4 communication (Docker network HTTP)        │
│ → Maximum overhead, maximum isolation            │
│                                                  │
│ Use when: Tenants are separate customers with    │
│ compliance/regulatory isolation requirements     │
└─────────────────────────────────────────────────┘
```

### 4.7 FrankenPHP Multi-Worker Alternative

FrankenPHP (Go-based) can potentially run **multiple worker pools** within the same Caddy process, each with its own PHP `SAPI` context. This would provide class-level isolation without the FPM overhead:

```
Caddy + FrankenPHP (single Go process)
├── Worker Pool A (PHP SAPI → Dist A class table)
├── Worker Pool B (PHP SAPI → Dist B class table)
└── Worker Pool Host (PHP SAPI → Host class table)
```

**Status:** FrankenPHP does NOT currently support per-route worker pools with separate PHP contexts. The `worker` directive in Caddyfile boots one pool per `worker` block, but they share the same PHP process memory.

**Future potential:** If FrankenPHP adds per-worker-pool PHP isolation, this becomes the ideal solution — combining worker-mode performance with class-table isolation.

**Action item:** Monitor [FrankenPHP issue tracker](https://github.com/dunglas/frankenphp) for multi-pool support. Until then, FPM pools or Docker containers are the available options.

### 4.8 Temporary Workaround: Namespace Prefixing

For library conflicts that can be resolved at build time:

```php
// Before: both Dist A and Dist B depend on guzzlehttp/guzzle
// Dist A needs v6, Dist B needs v7 — same namespace GuzzleHttp\Client

// Solution: Use php-scoper or similar tool at module build time
// Dist A: GuzzleHttp\Client → DistA\GuzzleHttp\Client (v6)
// Dist B: GuzzleHttp\Client → DistB\GuzzleHttp\Client (v7)
```

**Pros:** Works with current FrankenPHP worker mode; no infrastructure changes
**Cons:** Requires module authors to pre-scope their dependencies; not transparent

### 4.9 Decision Matrix

| Scenario | Solution | Isolation | Performance Impact | Effort |
|----------|----------|-----------|-------------------|--------|
| Same library versions across dists | Current FrankenPHP | None needed | +0% | 0h |
| Different lib versions, same host | FPM Pools | Class-level | -15% (fork overhead) | ~10h |
| Different lib versions, same host | Namespace prefixing | Namespace-level | +0% | Per-module |
| Full tenant isolation (enterprise) | Docker containers | Full process + network | -5% (Docker network) | Phase 2 |
| Future (FrankenPHP per-pool) | FrankenPHP multi-pool | Class-level | ~0% | Pending upstream |

---

## 5. Recommendation Summary

### 5.1 L4 Process Flow

- **Ship with Phase 3** as designed: `TenantEmitter` → HTTP POST → Bridge Handler → HMAC → `__onTenantCall` → `executeBridgeCommand()`
- **Double-gate architecture** provides defense-in-depth: outer tenant gate + inner bridge gate
- **Same envelope format** as L2 (`{ok, data}`) for consistency

### 5.2 Injection Prevention — Top Actions

1. **P0:** Add CLI guard to `CommandRegistry::executeInternalCommand()` — blocks HTTP exploitation of ungated path
2. **P0:** HMAC-SHA256 with `hash_equals()` — prevents forging + timing attacks
3. **P1:** Input regex validation on all bridge payload fields — blocks malformed input early
4. **P1:** `realpath()` + base path check in DataRequest — prevents path traversal

### 5.3 Library Conflicts — Phased Approach

| Phase | Approach | When |
|-------|----------|------|
| **Current (v1.0.x)** | Same library versions across distributors (document the constraint) | Now |
| **Phase 2 (v1.1.0)** | Docker containers per tenant (full isolation) | Enterprise deployments |
| **Phase 3 (v1.2.0)** | Optional FPM Pool mode for mid-tier use case | Same-host multi-dist |
| **Future** | FrankenPHP multi-pool (if upstream supports it) | TBD |

### 5.4 Configuration-Driven Isolation Tier

Add to `config.inc.php`:
```php
return [
    'isolation_mode' => 'frankenphp',  // 'frankenphp' | 'fpm-pool' | 'docker'
    // FPM pool settings (only when isolation_mode = 'fpm-pool')
    'fpm_pools' => [
        'workers_per_pool' => 4,
        'socket_dir'       => '/run/php-fpm/',
    ],
];
```

This enables operators to choose their isolation tier without code changes.

---

## Appendix A — Full Request/Response Examples

### A.1 Successful L4 Call

**Request:**
```http
POST /_razy/internal/bridge HTTP/1.1
Host: tenant-b:8080
X-Razy-Tenant-Id: tenant-a
X-Razy-Timestamp: 1708700000
X-Razy-Signature: 7f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2
Content-Type: application/json
Content-Length: 85

{"dist":"shop-main","module":"vendor/order","command":"getRecentOrders","args":[10]}
```

**Response:**
```http
HTTP/1.1 200 OK
Content-Type: application/json

{"ok":true,"data":[{"id":1,"total":99.50},{"id":2,"total":45.00}]}
```

### A.2 Rejected (HMAC Invalid)

**Response:**
```http
HTTP/1.1 403 Forbidden
Content-Type: application/json

{"ok":false,"error":"Invalid signature","code":4003}
```

### A.3 Rejected (Permission Gate)

**Response:**
```http
HTTP/1.1 403 Forbidden
Content-Type: application/json

{"ok":false,"error":"Permission denied by module","code":4004}
```

### A.4 Rejected (Timestamp Expired)

**Response:**
```http
HTTP/1.1 401 Unauthorized
Content-Type: application/json

{"ok":false,"error":"Request expired","code":4001}
```

---

## Appendix B — Security Checklist for Phase 3 Implementation

```
□ HMAC-SHA256 signing in TenantEmitter
□ HMAC verification in bridge handler with hash_equals()
□ 60-second timestamp window enforcement
□ Secret length validation (≥32 bytes) at bootstrap
□ Input regex on dist/module/command fields
□ Bridge handler calls executeBridgeCommand() (NOT executeInternalCommand)
□ CLI guard on executeInternalCommand()
□ __onTenantCall gate with default deny (return false)
□ __onDataRequest gate with default deny
□ realpath() + base path check in DataResponse
□ Caddy config blocks /_razy/internal/* from external traffic
□ Docker internal network only for bridge endpoints
□ tenants.json host validation (no SSRF)
□ Error responses never leak stack traces or internal paths
□ All 4,794+ existing tests still pass
□ ~20 new security-specific tests for bridge handler
```

---

## 6. PKI (Public/Private Key) Payload Encryption Evaluation

### 6.1 Current Design: HMAC-SHA256 (Symmetric)

The current L4 bridge design uses **HMAC-SHA256** with a shared secret:

```
Caller: sig = hmac-sha256(shared_secret, "tenantId:timestamp:body")
Callee: expected = hmac-sha256(shared_secret, "tenantId:timestamp:body")
        hash_equals(sig, expected)  →  pass/fail
```

**Properties:** Authentication + Integrity. Does NOT provide confidentiality — the JSON body is plaintext over HTTP.

### 6.2 PKI Option A: Asymmetric Signature (RSA/Ed25519)

Replace HMAC with digital signatures:

```
Caller: sig = sign(caller_private_key, "tenantId:timestamp:body")
Callee: verify(caller_public_key, sig, "tenantId:timestamp:body")  →  pass/fail
```

**Advantage:** No shared secret. Callee only holds the caller's public key — if the callee is compromised, the attacker cannot forge requests from the caller.

**Implementation — Ed25519 (Recommended over RSA):**
```php
// --- Caller (TenantEmitter) ---
// Ed25519 via sodium (PHP 7.2+ built-in, no extension)
$keypair   = sodium_crypto_sign_keypair();
$secretKey = sodium_crypto_sign_secretkey($keypair);  // 64 bytes
$publicKey = sodium_crypto_sign_publickey($keypair);   // 32 bytes

$message   = "{$tenantId}:{$timestamp}:{$body}";
$signature = sodium_crypto_sign_detached($message, $secretKey);  // 64 bytes

// Send: X-Razy-Signature: base64($signature)

// --- Callee (Bridge Handler) ---
$valid = sodium_crypto_sign_verify_detached(
    base64_decode($signature),
    $message,
    $callerPublicKey  // loaded from config/env
);
```

**Implementation — RSA-2048/4096:**
```php
// --- Caller ---
openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
// Send: X-Razy-Signature: base64($signature)

// --- Callee ---
$valid = openssl_verify($message, base64_decode($signature), $publicKey, OPENSSL_ALGO_SHA256);
// $valid === 1 means verified
```

### 6.3 PKI Option B: Full Payload Encryption (Confidentiality)

Encrypt the entire JSON body so it cannot be read in transit:

```
Caller: encrypted = encrypt(callee_public_key, json_body)
Callee: json_body = decrypt(callee_private_key, encrypted)
```

**Implementation — Hybrid Encryption (Recommended):**

RSA/Ed25519 cannot encrypt arbitrary-length data. Use hybrid: asymmetric key encrypts a symmetric session key, session key encrypts the payload.

```php
// --- Caller (TenantEmitter) ---
// 1. Generate random AES-256 session key
$sessionKey = random_bytes(32);  // 256-bit
$iv         = random_bytes(16);  // AES CBC IV

// 2. Encrypt payload with AES-256-CBC (fast)
$ciphertext = openssl_encrypt($jsonBody, 'AES-256-CBC', $sessionKey, OPENSSL_RAW_DATA, $iv);

// 3. Encrypt session key with callee's RSA public key
openssl_public_encrypt($sessionKey, $encryptedKey, $calleePublicKey, OPENSSL_PKCS1_OAEP_PADDING);

// 4. Send: { "key": base64($encryptedKey), "iv": base64($iv), "data": base64($ciphertext) }

// --- Callee (Bridge Handler) ---
// 1. Decrypt session key with own RSA private key
openssl_private_decrypt(base64_decode($encKey), $sessionKey, $calleePrivateKey, OPENSSL_PKCS1_OAEP_PADDING);

// 2. Decrypt payload with session key
$jsonBody = openssl_decrypt(base64_decode($cipherData), 'AES-256-CBC', $sessionKey, OPENSSL_RAW_DATA, base64_decode($iv));
```

**Implementation — Sodium Sealed Box (Simpler):**
```php
// Caller: encrypt for callee's public key (anonymous sender)
$encrypted = sodium_crypto_box_seal($jsonBody, $calleePublicKey);  // X25519

// Callee: decrypt with own keypair
$jsonBody = sodium_crypto_box_seal_open($encrypted, $calleeKeypair);
```

### 6.4 Performance Benchmark Comparison

| Operation | Algorithm | Time per call | Overhead vs HMAC | Notes |
|-----------|-----------|---------------|------------------|-------|
| **HMAC-SHA256** (current) | SHA-256 | ~2-5 µs | Baseline | Sign + verify combined |
| **Ed25519 sign** | Curve25519 | ~50-80 µs | ~15× | Constant-time, no padding |
| **Ed25519 verify** | Curve25519 | ~120-180 µs | ~40× | Slightly slower than sign |
| **RSA-2048 sign** | RSA PKCS#1 | ~500-800 µs | ~150× | Private key operation |
| **RSA-2048 verify** | RSA PKCS#1 | ~20-40 µs | ~8× | Public key (fast) |
| **RSA-4096 sign** | RSA PKCS#1 | ~2,000-4,000 µs | ~800× | **Avoid for hot path** |
| **AES-256-CBC encrypt** (1KB payload) | AES-CBC | ~3-5 µs | ~1× | Hardware-accelerated (AES-NI) |
| **RSA-2048 key encrypt** (32 bytes) | RSA OAEP | ~100-200 µs | ~40× | One-time per request |
| **Sodium sealed box** (1KB) | X25519 + XSalsa20 | ~30-60 µs | ~12× | Compact, no RSA overhead |
| **Total: HMAC only** | — | **~5 µs** | — | Auth + integrity |
| **Total: Ed25519 sign + verify** | — | **~200-260 µs** | ~50× | Auth + integrity (no shared secret) |
| **Total: Hybrid encrypt + sign** | — | **~350-600 µs** | ~100× | Auth + integrity + confidentiality |
| **Total: Sodium seal + sign** | — | **~100-150 µs** | ~25× | Best balance |

> **Context:** A single L4 HTTP round-trip is ~5,000-20,000 µs (5-20ms). Even the slowest crypto option (RSA-4096) adds <1ms — dwarfed by network latency.

### 6.5 When to Use Each Approach

| Scenario | Recommended Approach | Why |
|----------|---------------------|-----|
| Docker internal network (default) | **HMAC-SHA256** | Network already isolated; simplest; fastest |
| K8s with NetworkPolicy (trusted mesh) | **HMAC-SHA256** | Same as Docker; mTLS could be added at service mesh level |
| Cross-host / WAN / untrusted network | **Ed25519 sign + Sodium sealed box** | Full auth + confidentiality; no shared secret |
| Compliance (PCI-DSS, HIPAA, SOC2) | **Hybrid RSA + AES** or **mTLS** | Regulatory requirement for encryption at rest/transit |
| Callee compromise risk (zero-trust) | **Ed25519 sign** (minimum) | Compromised callee cannot forge caller identity |

### 6.6 Key Management Architecture

```
┌─────────────────────────────────────────────────────────┐
│                  Key Storage Options                     │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  Option 1: Environment Variables (Docker/K8s)            │
│  ┌─────────────────────────────────────────────────┐    │
│  │ RAZY_TENANT_PRIVATE_KEY=base64(ed25519_sk)      │    │
│  │ RAZY_BRIDGE_PUBKEYS="tenantA:pk1,tenantB:pk2"   │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  Option 2: K8s Secrets (recommended for K8s)             │
│  ┌─────────────────────────────────────────────────┐    │
│  │ apiVersion: v1                                   │    │
│  │ kind: Secret                                     │    │
│  │ metadata:                                        │    │
│  │   name: tenant-a-bridge-keys                     │    │
│  │ data:                                            │    │
│  │   private.key: <base64>                          │    │
│  │   tenant-b.pub: <base64>                         │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  Option 3: File-based (simple Docker Compose)            │
│  ┌─────────────────────────────────────────────────┐    │
│  │ /app/keys/                                       │    │
│  │ ├── private.key    (0600, own tenant only)       │    │
│  │ ├── tenant-a.pub   (0644, public keys of peers)  │    │
│  │ └── tenant-b.pub                                 │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  Option 4: Razy Crypt Integration                        │
│  ┌─────────────────────────────────────────────────┐    │
│  │ Existing Crypt class (AES-256-CBC + HMAC-SHA256) │    │
│  │ → Already in framework at src/library/Razy/Crypt │    │
│  │ → Symmetric only; could be extended for PKI      │    │
│  │ → Extend: Crypt::signEd25519() / verifyEd25519() │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### 6.7 CLI Key Generation

```bash
# Generate Ed25519 keypair for a tenant
php Razy.phar tenant keygen --id=abc123
# Output:
#   Private key: /app/keys/private.key (0600)
#   Public key:  /app/keys/abc123.pub  (distribute to peers)

# Or with OpenSSL for RSA:
openssl genpkey -algorithm RSA -out private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in private.pem -pubout -out public.pem
```

### 6.8 Recommendation

**Phase 3 (v1.2.0):** Ship with **HMAC-SHA256** as default (current design). Add `bridge_auth` config option:

```php
// config.inc.php
return [
    'bridge_auth' => 'hmac',  // 'hmac' | 'ed25519' | 'rsa' | 'sodium-seal'
];
```

**Phase 4+ (v1.3.0):** Add Ed25519 + optional Sodium sealed box for cross-host/compliance deployments.

**Rationale:**
- Docker internal network provides transport-level isolation (no eavesdropping possible)
- HMAC is 50× faster than Ed25519 and sufficient within isolated networks
- PKI adds value only when: (a) callee compromise is a threat model, or (b) network is untrusted
- The modular `bridge_auth` config allows upgrading without code changes

---

## 7. Latency Assessment & Optimization Directions

### 7.1 L4 Request Latency Breakdown

```
┌─────────────────────────────────────────────────────────────┐
│ L4 Round-Trip Time Breakdown (Docker internal network)       │
├────────────────────────────┬─────────────┬──────────────────┤
│ Phase                      │ Time (µs)   │ % of Total       │
├────────────────────────────┼─────────────┼──────────────────┤
│ 1. JSON encode (caller)    │ 5-20        │ 0.1%             │
│ 2. HMAC sign               │ 2-5         │ <0.1%            │
│ 3. HTTP connection setup   │ 200-500     │ 5-10%            │  ← optimizable
│ 4. TCP data transfer       │ 50-200      │ 1-4%             │
│ 5. PHP request dispatch    │ 100-300     │ 2-6%             │  ← FrankenPHP overhead
│ 6. HMAC verify             │ 2-5         │ <0.1%            │
│ 7. Input validation        │ 5-15        │ <0.1%            │
│ 8. Permission gate         │ 1-5         │ <0.1%            │
│ 9. CommandRegistry lookup  │ 5-20        │ <0.1%            │
│ 10. Command execution      │ 500-5,000   │ 10-50%           │  ← application logic
│ 11. JSON encode (response) │ 5-20        │ 0.1%             │
│ 12. HTTP response transfer │ 50-200      │ 1-4%             │
│ 13. JSON decode (caller)   │ 5-20        │ <0.1%            │
├────────────────────────────┼─────────────┼──────────────────┤
│ **Total (excluding #10)**  │ **~430-1,300** │ **~0.4-1.3ms** │
│ **Total (typical)**        │ **5,000-20,000** │ **5-20ms**  │
└────────────────────────────┴─────────────┴──────────────────┘
```

### 7.2 Comparison: L1 vs L2 vs L4 Latency

| Layer | Transport | Crypto | Serialization | Typical RT | Relative |
|-------|-----------|--------|---------------|-----------|----------|
| L1 (Emitter) | In-process call | None | None | <10 µs | 1× |
| L2 (Bridge CLI) | proc_open + pipe | None | JSON×2 | 50-100 ms | 5,000× |
| L4 HMAC (Docker) | HTTP loopback | HMAC (~5 µs) | JSON×2 | 5-20 ms | 500-2,000× |
| L4 Ed25519 (Docker) | HTTP loopback | Ed25519 (~260 µs) | JSON×2 | 5-20 ms | 500-2,000× |
| L4 HMAC (K8s cross-node) | HTTP over VXLAN | HMAC (~5 µs) | JSON×2 | 10-50 ms | 1,000-5,000× |

**Key insight:** Crypto overhead (even RSA) is negligible compared to network + PHP dispatch. The bottleneck is **HTTP connection setup** and **PHP request dispatch**.

### 7.3 Optimization Directions

#### O1 — HTTP Keep-Alive / Connection Pooling

**Problem:** Each L4 call creates a new TCP connection (~200-500 µs handshake).

**Solution:** Reuse connections with HTTP keep-alive:

```php
class TenantEmitter
{
    // Static connection pool (persists across requests in worker mode)
    private static array $connectionPool = [];
    
    private function getClient(string $targetHost): HttpClient
    {
        if (!isset(self::$connectionPool[$targetHost])) {
            self::$connectionPool[$targetHost] = HttpClient::create()
                ->baseUrl("http://{$targetHost}:8080")
                ->timeout(5)
                ->connectTimeout(2)
                ->withHeaders(['Connection' => 'keep-alive']);
        }
        return self::$connectionPool[$targetHost];
    }
}
```

**Savings:** ~200-400 µs per call after first request (eliminate TCP handshake).

**Worker mode benefit:** Connection pool persists across requests in FrankenPHP worker mode — amortized to near-zero after warmup.

#### O2 — Batch API Calls

**Problem:** N separate L4 calls = N round-trips.

**Solution:** Batch multiple commands into a single HTTP request:

```php
// Single HTTP round-trip for multiple commands
$batch = $this->tenantBatch('tenant-b', 'shop-main');
$batch->add('orderModule', 'getRecentOrders', [10]);
$batch->add('userModule', 'getUserProfile', ['abc123']);
$results = $batch->execute();
// Returns: [0 => [...orders], 1 => [...profile]]
```

Bridge handler processes batch:
```json
{
    "batch": [
        {"module": "vendor/order", "command": "getRecentOrders", "args": [10]},
        {"module": "vendor/user", "command": "getUserProfile", "args": ["abc123"]}
    ]
}
```

**Savings:** (N-1) × full-round-trip eliminated. For 3 calls: ~10-40ms saved.

#### O3 — Response Caching (Read-Heavy Patterns)

**Problem:** Repeated L4 calls for the same data (e.g., tenant config, product catalog).

**Solution:** TTL-based cache at TenantEmitter level:

```php
class TenantEmitter
{
    private static array $cache = [];
    
    public function __call(string $method, array $args): mixed
    {
        $cacheKey = "{$this->targetTenant}:{$this->moduleCode}:{$method}:" . md5(serialize($args));
        
        if (isset(self::$cache[$cacheKey]) && self::$cache[$cacheKey]['expires'] > time()) {
            return self::$cache[$cacheKey]['data'];
        }
        
        $result = $this->doHttpCall($method, $args);
        
        // Cache for configurable TTL (default 60s)
        self::$cache[$cacheKey] = [
            'data'    => $result,
            'expires' => time() + $this->cacheTtl,
        ];
        
        return $result;
    }
}
```

**Savings:** 100% of round-trip for cache hits. Must be opt-in per command (not safe for write operations).

#### O4 — Unix Domain Socket (Same-Host FPM Pools)

**Problem:** TCP loopback still has kernel overhead (~100-200 µs).

**Solution:** When caller and callee are on the same host (FPM Pool mode), use Unix domain socket:

```
# Caddy routes bridge to Unix socket
reverse_proxy unix//run/php-fpm/tenant-b.sock
```

**Savings:** ~50-100 µs per call (eliminate TCP stack overhead).

**Applicability:** Same-host only; Docker containers can share a socket via volume mount.

#### O5 — Async / Fire-and-Forget

**Problem:** Caller blocks waiting for response on non-critical calls (e.g., logging, analytics).

**Solution:** Async mode that doesn't wait for response:

```php
$tenantApi = $this->tenantApi('analytics-tenant', 'stats', 'tracker');
$tenantApi->async()->trackEvent('page_view', $eventData);
// Returns immediately — HTTP request sent but response not awaited
```

**Savings:** Eliminates blocking entirely for fire-and-forget patterns.

#### O6 — MessagePack Instead of JSON

**Problem:** JSON encode/decode adds ~10-40 µs per direction for typical payloads.

**Solution:** Use MessagePack (binary serialization, ~2-3× faster than JSON):

```php
// Requires ext-msgpack
$body = msgpack_pack($payload);    // ~5-10 µs for 1KB
$data = msgpack_unpack($response);  // ~3-8 µs for 1KB
```

**Savings:** ~10-30 µs per call. Negligible in absolute terms but adds up for batch operations.

**Trade-off:** Requires `ext-msgpack` PHP extension; JSON is universal and human-readable.

### 7.4 Optimization Priority Matrix

| # | Optimization | Savings per Call | Effort | Complexity | Priority |
|---|-------------|-----------------|--------|------------|----------|
| O1 | Connection pooling | ~200-400 µs | 2h | LOW | **P0** (ship with Phase 3) |
| O2 | Batch API calls | (N-1) × RT | 4h | MEDIUM | **P1** (Phase 3.x) |
| O5 | Async fire-and-forget | Full RT | 2h | LOW | **P1** (Phase 3.x) |
| O3 | Response caching | Up to 100% | 3h | LOW | **P2** (opt-in per command) |
| O4 | Unix domain socket | ~50-100 µs | 3h | MEDIUM | **P3** (FPM Pool mode only) |
| O6 | MessagePack | ~10-30 µs | 2h | LOW | **P3** (marginal gain) |

### 7.5 Latency Budget Summary

| Scenario | Unoptimized | With O1+O2 | With O1+O2+O3 |
|----------|------------|------------|----------------|
| Single L4 read call | 5-20 ms | 3-15 ms | <0.1 ms (cache hit) |
| 3 L4 read calls (serial) | 15-60 ms | 3-15 ms (batched) | <0.1 ms (cache hit) |
| Single L4 write call | 5-20 ms | 3-15 ms | 3-15 ms (no cache) |
| L4 with Ed25519 (vs HMAC) | +0.26 ms | +0.26 ms | +0.26 ms |
| L4 with full hybrid encrypt | +0.5 ms | +0.5 ms | +0.5 ms |

**Conclusion:** Crypto choice has minimal impact (<1ms). Connection pooling (O1) is the single biggest win and should ship with Phase 3. Batching (O2) provides the most dramatic improvement for multi-call patterns.

---

## 8. htaccess / Caddy Rewrite for Multi-Tenant Routing

### 8.1 Current Rewrite Architecture

Razy already has two rewrite compilers:

| Compiler | File | Target | CLI Command |
|----------|------|--------|-------------|
| `RewriteRuleCompiler` | `src/library/Razy/Routing/RewriteRuleCompiler.php` | Apache `.htaccess` | `php Razy.phar rewrite` |
| `CaddyfileCompiler` | `src/library/Razy/Routing/CaddyfileCompiler.php` | Caddy `Caddyfile` | `php Razy.phar rewrite --caddy` |

Both compile from the same `$multisite` data structure and produce:
1. **Domain detection** — match HTTP_HOST to a domain/distributor
2. **Webasset rules** — serve `/{route}/webassets/{alias}/{version}/{file}` from module's `webassets/` directory
3. **Data mapping** — serve `/{route}/data/{path}` from distributor's data directory
4. **Fallback** — route everything else to `index.php`

### 8.2 Current Template: htaccess

```apache
RewriteEngine on

# Base directory detection
RewriteCond $0#%{REQUEST_URI} ^([^#]*)#(.*)
1$
RewriteRule ^.*$ - [E=BASE:%2]

# Shared modules
RewriteRule ^\w+/shared/(.*)$ shared/$1 [L]

# Domain detection (per domain)
RewriteCond %{HTTP_HOST} ^example\.com(:\d+)?$ [NC]
RewriteRule ^ - [E=RAZY_DOMAIN:example.com]

# Per-distributor rules
RewriteCond %{ENV:RAZY_DOMAIN} =example.com
RewriteRule ^webassets/MyModule/(.+?)/(.+)$ modules/vendor/package/default/webassets/$2 [END]

RewriteCond %{ENV:RAZY_DOMAIN} =example.com
RewriteRule ^data/(.+)$ data/example.com-main/$1 [L]

# Fallback to PHP
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ %{ENV:BASE}index.php [L]
```

### 8.3 Current Template: Caddyfile

```caddyfile
example.com {
    root * /app/public

    # Webassets (static file serving)
    @webasset_MyModule path /webassets/MyModule/*
    handle @webasset_MyModule {
        uri strip_prefix /webassets/MyModule
        root * modules/vendor/package/default
        file_server
    }

    # Data mapping
    @data_main path /data/*
    handle @data_main {
        uri strip_prefix /data
        root * data/example.com-main
        file_server
    }

    # PHP handling (FrankenPHP worker mode)
    php_server {
        worker /app/public/index.php
    }
}
```

### 8.4 Multi-Tenant Extension Required

For isolated tenant containers, the rewrite rules need updates at **two levels**:

#### Level 1: External Proxy (Caddy front-door)

Routes internet traffic to the correct tenant container:

```caddyfile
# === External Proxy (internet-facing) ===
{
    auto_https disable_redirects
}

# Tenant A domain → Tenant A container
tenantA.example.com {
    reverse_proxy tenant-a:8080
    
    # BLOCK internal bridge from external access
    @internal path /_razy/internal/*
    respond @internal 404
}

# Tenant B domain → Tenant B container
tenantB.example.com {
    reverse_proxy tenant-b:8080
    
    @internal path /_razy/internal/*
    respond @internal 404
}

# Host Tenant (admin + catch-all)
admin.example.com {
    reverse_proxy host-tenant:8080
    
    @internal path /_razy/internal/*
    respond @internal 404
}

# Internal bridge port (Docker-only, NOT exposed to internet)
:9090 {
    # Only accessible from Docker internal network
    reverse_proxy host-tenant:8080
}
```

#### Level 2: Per-Container (FrankenPHP inside tenant)

Each tenant container runs its own FrankenPHP with a Caddyfile generated by `CaddyfileCompiler`:

```caddyfile
# Inside tenant-a container
:8080 {
    root * /app/site
    
    # Module webassets
    @webasset_Shop path /webassets/Shop/*
    handle @webasset_Shop {
        uri strip_prefix /webassets/Shop
        root * modules/vendor/shop/default
        file_server
    }
    
    # Internal bridge endpoint (receives L4 calls)
    # NOTE: The framework handles this via PHP routing, not Caddy
    # Bridge is just a normal PHP route: /_razy/internal/bridge
    
    # FrankenPHP worker
    php_server {
        worker /app/site/index.php
    }
}
```

### 8.5 htaccess for Apache-Based Tenant Routing

For Apache deployments (non-Docker), tenant isolation uses `VirtualHost` + per-tenant `.htaccess`:

```apache
# httpd-vhosts.conf
<VirtualHost *:80>
    ServerName tenantA.example.com
    DocumentRoot /var/www/tenants/a1b2c3d4/site
    <Directory /var/www/tenants/a1b2c3d4/site>
        AllowOverride All
        php_admin_value open_basedir "/var/www/tenants/a1b2c3d4:/tmp"
    </Directory>
</VirtualHost>

<VirtualHost *:80>
    ServerName tenantB.example.com
    DocumentRoot /var/www/tenants/e5f6g7h8/site
    <Directory /var/www/tenants/e5f6g7h8/site>
        AllowOverride All
        php_admin_value open_basedir "/var/www/tenants/e5f6g7h8:/tmp"
    </Directory>
</VirtualHost>
```

Each tenant has its own document root → their `.htaccess` (generated by `RewriteRuleCompiler`) only sees their own modules/data.

### 8.6 Bridge Endpoint Protection in Rewrite Rules

**Critical:** The `/_razy/internal/bridge` endpoint must NEVER be accessible from the internet.

```apache
# .htaccess (Apache) — Block external bridge access
RewriteCond %{HTTP_HOST} !^localhost$ [NC]
RewriteCond %{HTTP_HOST} !^10\. [NC]
RewriteCond %{HTTP_HOST} !^172\.(1[6-9]|2[0-9]|3[01])\. [NC]
RewriteCond %{HTTP_HOST} !^192\.168\. [NC]
RewriteRule ^_razy/internal/ - [F,L]
```

```caddyfile
# Caddyfile (Caddy) — Block external bridge access
@external_bridge {
    path /_razy/internal/*
    not remote_ip 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16
}
respond @external_bridge 403
```

### 8.7 Changes Required to Existing Compilers

| Compiler | Change | Effort | Description |
|----------|--------|--------|-------------|
| `CaddyfileCompiler` | Add bridge blocking rule | 1h | `@internal` matcher for `/_razy/internal/*` → 404 |
| `CaddyfileCompiler` | Multi-container proxy mode | 3h | New template for external proxy with per-domain `reverse_proxy` |
| `CaddyfileCompiler` | Tenant asset CDN prefix | 2h | Optional CDN URL rewrite for webassets (see Section 9) |
| `RewriteRuleCompiler` | Add bridge blocking rule | 1h | RewriteRule for `_razy/internal/` → 403 |
| `RewriteRuleCompiler` | VirtualHost-aware mode | 2h | Per-VHost document root when generating for Apache multi-tenant |
| New: CLI command | `php Razy.phar rewrite --multi-tenant` | 3h | Generates external proxy config + per-tenant internal configs |

**Total effort:** ~12h (can be partially parallelized with Phase 2 Docker work)

---

## 9. Frontend Access to Tenant Assets

### 9.1 Current Asset Serving Architecture

Razy modules serve static assets (CSS, JS, images) from the `webassets/` directory within each module:

```
modules/vendor/package/default/
├── controller/
├── library/
└── webassets/                    ← Static assets live here
    ├── css/style.css
    ├── js/app.js
    └── images/logo.png
```

**URL pattern:** `{siteURL}/webassets/{moduleAlias}/{moduleVersion}/{file}`

Example: `https://tenantA.example.com/webassets/Shop/1.0.0/css/style.css`

**How it works:**
1. `Controller::getAssetPath()` returns the URL prefix: `{siteURL}/webassets/{alias}/{version}/`
2. The Caddyfile/htaccess rewrite rule maps this URL to the module's filesystem path
3. `file_server` (Caddy) or direct file serving (Apache) handles the response
4. PHP is NOT involved in serving static assets — pure web server performance

### 9.2 Difficulty Analysis: Frontend Accessing Tenant Assets

#### Scenario A: Same-Tenant Assets (EASY ✅)

Frontend within Tenant A accessing Tenant A's own assets:

```html
<!-- In Tenant A's template -->
<link rel="stylesheet" href="/webassets/Shop/1.0.0/css/style.css">
<script src="/webassets/Shop/1.0.0/js/app.js"></script>
<img src="/webassets/Shop/1.0.0/images/logo.png">
```

**Difficulty:** NONE — this is the current architecture. Works identically in multi-tenant mode because each tenant container serves its own webassets via its own Caddy/FrankenPHP instance.

#### Scenario B: Cross-Tenant Assets (MEDIUM ⚠️)

Frontend in Tenant A needs to load an asset from Tenant B:

```html
<!-- In Tenant A's HTML, trying to load Tenant B's asset -->
<link rel="stylesheet" href="https://tenantB.example.com/webassets/Theme/2.0.0/css/shared-theme.css">
```

**Challenges:**

| Challenge | Severity | Solution |
|-----------|----------|----------|
| **CORS** | HIGH | Tenant B must send `Access-Control-Allow-Origin` header |
| **URL discovery** | MEDIUM | Tenant A needs to know Tenant B's domain + module alias + version |
| **Availability** | LOW | If Tenant B is down, Tenant A's assets fail to load |
| **Cache busting** | LOW | Version in URL path handles this naturally |

**CORS Solution:**
```caddyfile
# In Tenant B's Caddyfile — allow cross-origin for webassets
@webasset path /webassets/*
header @webasset Access-Control-Allow-Origin "https://tenantA.example.com"
header @webasset Access-Control-Allow-Methods "GET, HEAD, OPTIONS"
header @webasset Cache-Control "public, max-age=86400, immutable"
```

Or wildcard (less secure but simpler):
```caddyfile
header @webasset Access-Control-Allow-Origin "*"
```

#### Scenario C: Shared Asset Library / CDN (RECOMMENDED ✅)

Rather than cross-tenant asset fetching, provide a shared asset layer:

```
┌─────────────────────────────────────────────────────┐
│                CDN / Asset Proxy                      │
│    assets.example.com                                │
│    ┌──────────────────────────────────┐              │
│    │ /shared/theme/2.0.0/css/base.css │              │
│    │ /shared/icons/1.0.0/svg/cart.svg │              │
│    │ /tenantA/Shop/1.0.0/css/style.css│              │
│    └──────────────────────────────────┘              │
│              │                                       │
│    ┌────────┴────────┐                              │
│    │ Caddy file_server│                              │
│    │ with shared volume│                             │
│    │ mount (read-only)│                              │
│    └─────────────────┘                              │
└─────────────────────────────────────────────────────┘
```

**Implementation:**

```caddyfile
# Dedicated asset-serving domain
assets.example.com {
    # Shared assets (global modules)
    @shared path /shared/*
    handle @shared {
        root * /app/shared-assets
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=31536000, immutable"
        header Access-Control-Allow-Origin "*"
    }
    
    # Per-tenant assets (read-only mount from tenant data)
    @tenant path /t/*
    handle @tenant {
        root * /app/tenant-assets
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=86400"
        header Access-Control-Allow-Origin "*"
    }
}
```

**Docker volume setup:**
```yaml
# docker-compose.yml
services:
  asset-proxy:
    image: caddy:2-alpine
    volumes:
      - shared_modules:/app/shared-assets:ro
      - tenant_a_webassets:/app/tenant-assets/a:ro
      - tenant_b_webassets:/app/tenant-assets/b:ro
    ports:
      - "8090:80"
```

#### Scenario D: User-Uploaded Files (Data Assets) (MEDIUM ⚠️)

Frontend needs to display user-uploaded files (avatars, product images) from another tenant:

```html
<img src="/data/uploads/product-photo-123.jpg">
```

**Challenges:**

| Challenge | Severity | Solution |
|-----------|----------|----------|
| **Filesystem isolation** | HIGH | Tenant B's files are in Tenant B's container— Tenant A cannot access |
| **Access control** | HIGH | Not all data should be cross-accessible |
| **URL routing** | MEDIUM | Need proxy to route `/data/` to correct tenant |

**Solution: Data Proxy via L4 DataRequest:**
```php
// Tenant A controller
public function showProduct(string $productId): void
{
    // Request product image from Tenant B via DataRequest
    $request  = $this->tenantData('tenant-b', 'shop-main', 'productModule');
    $response = $request->read("uploads/products/{$productId}.jpg");
    
    if ($response->exists() && $response->isReadable()) {
        header('Content-Type: ' . $response->getMimeType());
        header('Cache-Control: public, max-age=3600');
        echo $response->getContent();
    }
}
```

**Performance concern:** Serving binary files through PHP is slow. For high-traffic scenarios, use the signed URL pattern:

```php
// Tenant A generates a signed URL that the CDN/proxy can verify
$signedUrl = $this->tenantApi('tenant-b', 'shop-main', 'productModule')
    ->getSignedAssetUrl("uploads/products/{$productId}.jpg", expiry: 3600);

// Returns: https://assets.example.com/t/tenant-b/uploads/products/123.jpg?sig=abc&exp=1708704000
// CDN serves the file directly — no PHP involvement on subsequent requests
```

### 9.3 Difficulty Rating Summary

| Scenario | Difficulty | Effort | Notes |
|----------|-----------|--------|-------|
| **A. Same-tenant assets** | EASY (0h) | 0h | Already works, no changes needed |
| **B. Cross-tenant static assets** | MEDIUM | 2h | CORS headers in Caddy config |
| **C. Shared asset CDN** | MEDIUM | 8h | New Caddy service + volume mounts + CDN URL helper |
| **D. Cross-tenant data files** | HARD | 12h | DataRequest/DataResponse + signed URLs + CDN proxy |

### 9.4 Framework Changes for Asset URL Generation

```php
// Current: Controller::getAssetPath()
// Returns: {siteURL}/webassets/{alias}/{version}/
$path = $this->getAssetPath();
// → https://tenantA.example.com/webassets/Shop/1.0.0/

// New (Phase 3): Controller::getAssetUrl() with CDN support
// When RAZY_ASSET_CDN is set:
$url = $this->getAssetUrl();
// → https://assets.example.com/t/tenantA/Shop/1.0.0/

// New: Controller::getSharedAssetUrl()
$url = $this->getSharedAssetUrl('theme', '2.0.0');
// → https://assets.example.com/shared/theme/2.0.0/
```

**Implementation:**
```php
// In Controller.php
final public function getAssetUrl(): string
{
    $cdnBase = getenv('RAZY_ASSET_CDN');
    if ($cdnBase) {
        $tenantId = getenv('RAZY_TENANT_ID') ?: 'default';
        return rtrim($cdnBase, '/') . '/t/' . $tenantId . '/' 
            . $this->module->getModuleInfo()->getAlias() . '/' 
            . $this->module->getModuleInfo()->getVersion() . '/';
    }
    // Fallback to current behavior
    return $this->getAssetPath();
}
```

### 9.5 Cache Strategy for Tenant Assets

```
┌──────────────────────────────────────────────────────────┐
│                  Cache Layer Stack                         │
├──────────────────────────────────────────────────────────┤
│ L1: Browser Cache                                         │
│     Cache-Control: public, max-age=31536000, immutable    │
│     Version in URL = infinite cache (1 year)              │
├──────────────────────────────────────────────────────────┤
│ L2: CDN Edge Cache (CloudFlare / Caddy cache)             │
│     Per-tenant cache key: {tenantDomain}/{assetPath}      │
│     Purge on deployment via API                           │
├──────────────────────────────────────────────────────────┤
│ L3: Reverse Proxy Cache (Caddy internal)                  │
│     5-minute TTL for data assets                          │
│     Permanent for versioned webassets                     │
├──────────────────────────────────────────────────────────┤
│ L4: Origin (FrankenPHP file_server)                       │
│     Direct filesystem read, gzip/brotli pre-compression   │
└──────────────────────────────────────────────────────────┘
```

### 9.6 Recommended Implementation Order

| Phase | Deliverable | Prerequisites |
|-------|-------------|---------------|
| Phase 2 (Docker) | Per-container webasset serving (works automatically) | Phase 1 |
| Phase 3 (L4 Comms) | `DataRequest`/`DataResponse` for cross-tenant data files | Phase 1 |
| Phase 3.x | CORS headers in CaddyfileCompiler for cross-tenant assets | Phase 2 |
| Phase 3.x | `getAssetUrl()` with `RAZY_ASSET_CDN` env support | Phase 2 |
| Phase 4 (K8s) | Shared asset CDN service + Helm chart | Phase 2 |
| Phase 5 (Admin) | Signed URL generation for data assets | Phase 3 |
---

## 10. Caddy API + PHP Reverse Static Proxy, Container Mesh & Market Comparison

> **Scope:** Evaluate Caddy Admin API + PHP dynamic configuration to act as a reverse static-files proxy; Docker / K8s load-balancing container behavior; homogeneous same-version container mesh interconnectivity and data file structure; compare against market options — pros/cons.

### 10.1 Architecture Layer Roles

Before discussing the Caddy API + PHP approach, clarify Razy's current three-layer role separation — this directly affects responsibilities for static file routing.

```
┌────────────────────────────────────────────────────────────────────────────┐
│                         CORE LAYER (Application)                           │
│                                                                            │
│  Application.php ─→ matchDomain(FQDN) ─→ Domain ─→ matchQuery(urlPath)    │
│       │                                     │                              │
│       │  Manages:                           │  Manages:                    │
│       │  • sites.inc.php multisite config   │  • URL path → distCode@tag  │
│       │  • Domain alias resolution          │  • Pre-sorted mapping list  │
│       │  • Rewrite/Caddyfile generation     │  • Distributor cache (WM)   │
│       │  • DI Container (root)              │  • Config fingerprint check │
│       │  • Worker boot-once lifecycle       │  • dispatchQuery() fast path│
│       └─────────────────────────────────────┘                              │
│                                                                            │
│  ► Static file decision: Caddy Caddyfile (compiled by CaddyfileCompiler)   │
│    handles webassets + data BEFORE PHP — Application NEVER sees static     │
│    file requests in normal flow.                                           │
├────────────────────────────────────────────────────────────────────────────┤
│                       TENANT LAYER (Distributor)                           │
│                                                                            │
│  Distributor.php ─→ initialize() ─→ ModuleScanner ─→ ModuleRegistry       │
│       │                                      │                             │
│       │  Manages:                            │  Manages:                   │
│       │  • dist.php config (code, tag)       │  • Module scan + autoload  │
│       │  • Module lifecycle (__onInit→Ready)  │  • Route registration      │
│       │  • RouteDispatcher (standard/lazy)    │  • Cross-dist bridge API   │
│       │  • Data mapping (cross-site data/)    │  • Session per-dist        │
│       │  • dispatch() worker fast-path        │  • Module prerequisite     │
│       │                                      │                             │
│  ► Static file: Distributor owns the webassets/ and data/ paths.           │
│    CaddyfileCompiler queries Distributor.getDataMapping() and              │
│    each Module's ModuleInfo.getContainerPath() to generate rules.          │
├────────────────────────────────────────────────────────────────────────────┤
│                      MODULE LAYER (Controller)                             │
│                                                                            │
│  Controller.php → getAssetPath() → {siteURL}/webassets/{alias}/{ver}/      │
│       │                                                                    │
│       │  Module developers:                                                │
│       │  • Place static files in webassets/ subdirectory                   │
│       │  • Use getAssetPath() in templates for URL generation              │
│       │  • api() for L1 cross-module calls                                │
│       │  • Future: getAssetUrl() with CDN prefix (§9.4)                   │
│                                                                            │
│  ► Static file: Module is the OWNER of webasset content.                  │
│    It decides what to publish; Caddy/Apache serves it.                     │
└────────────────────────────────────────────────────────────────────────────┘
```

**Key Insight:** In the current architecture, static file routing is handled entirely by the web server layer (Caddy `file_server` / Apache `RewriteRule`); PHP does not intervene. The question is: in a multi-tenant + multi-container environment, can we dynamically manage these static file routes using the Caddy Admin API + PHP?

### 10.2 Caddy Admin API Overview

Caddy provides a REST Admin API (default `localhost:2019`), supporting:

| Endpoint | Method | Purpose |
|----------|--------|------|
| `/config/` | GET | retrieve full JSON configuration |
| `/config/apps/http/servers/{name}/routes` | POST | dynamically add route |
| `/config/apps/http/servers/{name}/routes/{id}` | PUT/DELETE | modify/delete specific route |
| `/load` | POST | atomic load of full config (atomic replace) |
| `/reverse_proxy/upstreams` | GET | inspect upstream pool status |
| `/id/{id}` | GET/PUT/DELETE | manipulate named nodes using `@id` tag |

**Core features:**
- **Zero-downtime reload:** configuration changes do not interrupt existing connections
- **Atomic swap:** `/load` endpoint performs a full replace, ensuring consistency
- **JSON-native:** Naturally compatible with PHP's `json_encode`/`json_decode`
- **can process ~5,000 config updates per second** (benchmark data)

### 10.3 Caddy API + PHP Reverse Static Proxy Feasibility

#### 10.3.1 Architecture Option

```
                   ┌──────────────────────────────────────────────┐
                   │         External Caddy (Front-Door)          │
                   │         ────────────────────────             │
                   │  :443 / :80   ← internet traffic            │
                   │  Admin API :2019 (internal only)             │
                   │                                              │
                   │  Configuration source:                       │
                   │  ┌────────────────────────────────────┐     │
             ┌─────│──│  PHP TenantProvisioner calls        │     │
             │     │  │  POST /load  (atomic config swap)   │     │
             │     │  │  → reverse_proxy rules per tenant   │     │
             │     │  │  → file_server rules for shared     │     │
             │     │  │    static assets                    │     │
             │     │  └────────────────────────────────────┘     │
             │     │                                              │
             │     │  Generated routes:                           │
             │     │  ┌──────────┬──────────┬──────────┐         │
             │     │  │ tenantA  │ tenantB  │ shared   │         │
             │     │  │ .com     │ .com     │ assets   │         │
             │     │  │ →:8081   │ →:8082   │ file_srv │         │
             │     │  └──────────┴──────────┴──────────┘         │
             │     └──────────────────────────────────────────────┘
             │                    │            │           │
             ▼                    ▼            ▼           ▼
   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐  ┌────────────┐
   │ PHP Host     │  │ Tenant A     │  │ Tenant B     │  │ Shared     │
   │ (provision   │  │ FrankenPHP   │  │ FrankenPHP   │  │ Asset Vol  │
   │  + admin)    │  │ Container    │  │ Container    │  │ (read-only)│
   │              │  │ :8081        │  │ :8082        │  │            │
   └──────────────┘  └──────────────┘  └──────────────┘  └────────────┘
```

#### 10.3.2 PHP Implementation for Calling the Caddy API

```php
/**
 * TenantProvisioner — Manages Caddy Admin API tenant routes.
 *
 * Called when a new tenant is provisioned, deprovisioned, or updated.
 * Uses Caddy's /load endpoint for atomic configuration swap.
 */
class TenantProvisioner
{
    private string $caddyAdminUrl;
    
    public function __construct(string $caddyAdminUrl = 'http://localhost:2019')
    {
        $this->caddyAdminUrl = $caddyAdminUrl;
    }
    
    /**
     * Provision a new tenant: add reverse_proxy + static file routes to Caddy.
     */
    public function provisionTenant(string $tenantId, string $domain, string $upstream): void
    {
        $currentConfig = $this->getCaddyConfig();
        
        // Add tenant route to Caddy config
        $tenantRoute = [
            '@id' => 'tenant_' . $tenantId,
            'match' => [['host' => [$domain]]],
            'handle' => [
                // Static webassets: serve from shared volume (no PHP)
                [
                    'handler' => 'subroute',
                    'routes' => [
                        [
                            'match' => [['path' => ['/webassets/*']]],
                            'handle' => [
                                ['handler' => 'file_server', 'root' => "/data/tenants/{$tenantId}/webassets"],
                            ],
                        ],
                    ],
                ],
                // Dynamic requests: reverse proxy to tenant container
                [
                    'handler' => 'reverse_proxy',
                    'upstreams' => [['dial' => $upstream]],
                    'health_checks' => [
                        'active' => ['interval' => '10s', 'timeout' => '5s', 'path' => '/health'],
                    ],
                ],
            ],
        ];
        
        $currentConfig['apps']['http']['servers']['main']['routes'][] = $tenantRoute;
        $this->loadConfig($currentConfig);
    }
    
    /**
     * Deprovision: remove tenant routes from Caddy.
     */
    public function deprovisionTenant(string $tenantId): void
    {
        // Use the @id tag for surgical deletion
        $this->apiDelete("/id/tenant_{$tenantId}");
    }
    
    /**
     * Atomic config load (zero-downtime).
     */
    private function loadConfig(array $config): void
    {
        $ch = curl_init($this->caddyAdminUrl . '/load');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($config),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \RuntimeException("Caddy API /load failed: HTTP {$httpCode} — {$response}");
        }
    }
    
    private function getCaddyConfig(): array
    {
        $response = file_get_contents($this->caddyAdminUrl . '/config/');
        return json_decode($response, true) ?: [];
    }
    
    private function apiDelete(string $path): void
    {
        $ch = curl_init($this->caddyAdminUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
```

#### 10.3.3 Caddy API Option — Pros & Cons

| Dimension | Advantages | Disadvantages |
|------|------|------|
| **Dynamism** | Add/remove tenant routes with zero downtime (~5ms to take effect) | Admin API is single-node — multiple Caddy instances need separate sync |
| **Static file performance** | Caddy `file_server` native performance, zero PHP involvement | A large number of tenants (>500) in the route table may impact Caddy's internal matcher performance |
| **Razy Integration** | PHP can `curl` the Caddy API directly, complementing the existing `CaddyfileCompiler` | Requires maintaining dual configuration sources (Caddyfile + API), risk of drift |
| **Security** | Admin API bound to `localhost:2019`; Docker network can restrict access | API has no built-in auth — if exposed externally, anyone could modify routing |
| **Observability** | Caddy includes built-in Prometheus metrics and access logs | Configuration changes lack an audit trail — requires custom audit logging |
| **Rollback** | `/load` atomic swap — can keep previous config for rollback | No built-in versioned config — must persist history yourself |

#### 10.3.4 Integration Strategy with Existing CaddyfileCompiler

```
Option A: Caddyfile as Source of Truth (RECOMMENDED for ≤50 tenants)
─────────────────────────────────────────────────────────────────

  PHP CLI:  php Razy.phar rewrite --caddy
      │
      ▼
  CaddyfileCompiler.php → generates Caddyfile
      │
      ▼
  caddy reload --config Caddyfile    ← graceful reload (~50ms)


Option B: Caddy API as Source of Truth (RECOMMENDED for >50 tenants)
─────────────────────────────────────────────────────────────────

  PHP:  TenantProvisioner → POST /load (atomic swap)
      │
      ▼
  Caddy Admin API → in-memory config (no file)
      │
      ▼
  Periodic: caddy adapt → dump current config for backup  

  CaddyfileCompiler is reduced to initial bootstrap only.


Option C: Hybrid (Source of Truth = PHP database/config)
──────────────────────────────────────────────────────

  PHP DB:  tenant_routes table (domain, upstream, status)
      │
      ▼
  TenantProvisioner → diff current Caddy config vs DB
      │
      ├─ Small change (1-5 routes)  → PATCH /config/...
      │
      └─ Large change (>5 routes)   → POST /load (full swap)
      │
      ▼
  CaddyfileCompiler generates file backup for disaster recovery
```

### 10.4 The Tenant Layer's Role in Static Routing

#### 10.4.1 Distributor Responsibilities for Static Files

**Distributor** is the **data source** for static file routing (but not the executor):

```php
// CaddyfileCompiler generates Caddy rules from the following Distributor data:

// 1. Webasset paths — from ModuleInfo::getContainerPath()
//    The webassets/ directory location for each module
$containerPath = $moduleInfo->getContainerPath(true);
// → "modules/vendor/package/default"

// 2. Data mapping — from Distributor::getDataMapping()
//    Cross-site data mapping (e.g., tenant A's /data/ points to tenant B's storage)
$dataMapping = $distributor->getDataMapping();
// → ['/uploads' => ['domain' => 'tenantB.com', 'dist' => 'main']]

// 3. Module alias + version — URL path component
$alias   = $moduleInfo->getAlias();    // "Shop"
$version = $moduleInfo->getVersion();  // "1.0.0"
// → /webassets/Shop/1.0.0/css/style.css
```

**Changes in a multi-container environment:**

| Monolith (current) | Multi-Container (target) |
|----------------|--------------------------|
| Distributor and Caddy in the same process | Distributor runs inside the tenant container; Caddy is at the front door |
| `CaddyfileCompiler` reads the filesystem directly | Requires build-time or API sync for webasset paths |
| Data mapping points to local `data/` | Data mapping points to a shared volume or remote storage |
| One Caddyfile manages all tenants | Front-door Caddy + per-container Caddy (second level) |

#### 10.4.2 Tenant Provisioning Flow (Caddy API Integration)

```
Tenant Provisioning (new tenant creation):

  1. Admin creates tenant (PHP admin panel / CLI)
     │
     ▼
  2. PHP creates tenant record in DB
     ├── tenant_id, domain, dist_code, tag
     ├── container_image (e.g., razy-tenant:1.0.1-beta)
     └── resource limits (CPU, memory)
     │
     ▼
  3. Orchestrator (Docker API / K8s API) creates container
     ├── docker run -d --name tenant-{id} --network razy-net razy-tenant:1.0.1
     ├── Mount: shared_assets:/app/shared:ro
     ├── Mount: tenant_data_{id}:/app/data/{tenant_id}
     └── Env: RAZY_TENANT_ID={id}, RAZY_DIST_CODE={code}
     │
     ▼
  4. Container health check passes
     │
     ▼
  5. PHP TenantProvisioner → Caddy Admin API
     ├── POST /config/apps/http/servers/main/routes
     │   ├── match: {host: [domain]}
     │   ├── handle: file_server for /webassets/* (shared volume)
     │   └── handle: reverse_proxy to tenant-{id}:8080
     │
     ▼
  6. Caddy applies config (~5ms) — tenant is LIVE
     │
     ▼
  7. PHP reports success to admin
```

### 10.5 The Core Layer's Application Routing Role in the Static Proxy

#### 10.5.1 Application Responsibilities for Multi-Tenant Routing

`Application.php` currently has the following responsibilities related to the static-file proxy:

```
Application Responsibility Matrix:

  ┌─ Config Management ──────────────────────────────────────────┐
  │  • loadSiteConfig() → sites.inc.php                          │
  │  • updateSites() → parse domains + distributors              │
  │  • writeSiteConfig() → persist changes                       │
  │  ► These configs are inputs to CaddyfileCompiler              │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Rewrite Generation ─────────────────────────────────────────┐
  │  • updateRewriteRules() → .htaccess (Apache)                 │
  │  • updateCaddyfile() → Caddyfile (Caddy)                     │
  │  ► The "compiler" for static file routing rules — runs only when config changes │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Domain Resolution ──────────────────────────────────────────┐
  │  • host(FQDN) → matchDomain() → Domain                      │
  │  • Wildcard matching, alias resolution                       │
  │  ► PHP runtime domain matching — static files do not go through this path │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Worker Mode Dispatch ───────────────────────────────────────┐
  │  • dispatch(urlQuery) → Domain::dispatchQuery()              │
  │  • Boot-once: Application + Module graph initializes only once │
  │  ► Pure dynamic requests — Caddy already intercepts static files │
  └──────────────────────────────────────────────────────────────┘
```

#### 10.5.2 Application's New Role in Caddy API Mode

```
                    Current Architecture              Caddy API Architecture
                    ────────────────────              ─────────────────────

   Config Source:   sites.inc.php (file)        →    sites.inc.php + tenant DB
   
   Rewrite Gen:     updateCaddyfile()           →    TenantProvisioner::syncCaddy()
                    (run once, write file)             (event-driven, API call)
   
   When invoked:    CLI: php Razy.phar rewrite  →    On tenant CRUD event
                    (manual / deploy script)          (auto via lifecycle hook)
   
   Static routing:  Pre-compiled Caddyfile      →    Dynamic Caddy JSON config
                    (restart to apply)                (zero-downtime, ~5ms)
   
   Integration:     Application::updateCaddyfile()
                         │
                         ▼
                    New method: Application::syncCaddyRoutes()
                    ┌──────────────────────────────────────────┐
                    │  1. Read $this->multisite + $this->alias │
                    │  2. Read tenant DB for container addrs   │
                    │  3. Build Caddy JSON config              │
                    │  4. POST /load → Caddy Admin API         │
                    │  5. Log config version for audit trail   │
                    └──────────────────────────────────────────┘
```

### 10.6 Docker / K8s Load Balancing Container Behaviour

#### 10.6.1 Docker Compose — Horizontal Scaling of Homogeneous Containers

```yaml
# docker-compose.yml — Tenant A with 3 replicas
services:
  tenant-a:
    image: razy-tenant:1.0.1-beta
    deploy:
      replicas: 3
      resources:
        limits: { cpus: '1', memory: '512M' }
    volumes:
      - tenant_a_data:/app/data/tenant-a
      - shared_modules:/app/shared:ro
    environment:
      - RAZY_TENANT_ID=tenant-a
      - RAZY_DIST_CODE=main
    networks:
      - razy-internal
    healthcheck:
      test: ["CMD", "curl", "-sf", "http://localhost:8080/health"]
      interval: 10s
      timeout: 3s
      retries: 3

  caddy-front:
    image: caddy:2-alpine
    ports:
      - "443:443"
      - "80:80"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - shared_assets:/app/assets:ro
    networks:
      - razy-internal
      - razy-external
```

**Built-in load balancing in Caddy reverse_proxy:**

```caddyfile
tenant-a.example.com {
    reverse_proxy tenant-a:8080 {
        # Docker DNS automatically resolves multiple replica IPs
        # Caddy default round-robin LB policy
        lb_policy       round_robin
        lb_try_duration 5s
        lb_try_interval 250ms
        
        # Active health check
        health_uri    /health
        health_interval 10s
        health_timeout  3s
        
        # Passive health check (circuit breaker)
        fail_duration 30s
        max_fails     3
        unhealthy_latency 5s
    }
}
```

> **Docker behavior:** When `docker-compose up --scale tenant-a=3` is used, Docker's built-in DNS round-robin resolves `tenant-a` to 3 container IPs. With active health checks, Caddy's `reverse_proxy` can automatically exclude unhealthy replicas.

#### 10.6.2 Kubernetes — Service + Ingress + Service Mesh

```yaml
# K8s Deployment — Tenant A
apiVersion: apps/v1
kind: Deployment
metadata:
  name: tenant-a
  labels:
    app: razy-tenant
    tenant: tenant-a
    version: "1.0.1-beta"
spec:
  replicas: 3
  selector:
    matchLabels: { app: razy-tenant, tenant: tenant-a }
  template:
    metadata:
      labels:
        app: razy-tenant
        tenant: tenant-a
        version: "1.0.1-beta"
    spec:
      containers:
        - name: frankenphp
          image: razy-tenant:1.0.1-beta
          ports: [{ containerPort: 8080 }]
          env:
            - { name: RAZY_TENANT_ID, value: "tenant-a" }
            - { name: RAZY_DIST_CODE, value: "main" }
          volumeMounts:
            - { name: shared-modules, mountPath: /app/shared, readOnly: true }
            - { name: tenant-data, mountPath: /app/data/tenant-a }
          readinessProbe:
            httpGet: { path: /health, port: 8080 }
            periodSeconds: 5
          livenessProbe:
            httpGet: { path: /health, port: 8080 }
            periodSeconds: 15
          resources:
            requests: { cpu: "250m", memory: "256Mi" }
            limits: { cpu: "1000m", memory: "512Mi" }
      volumes:
        - name: shared-modules
          persistentVolumeClaim: { claimName: shared-modules-pvc }
        - name: tenant-data
          persistentVolumeClaim: { claimName: tenant-a-data-pvc }
---
# K8s Service (ClusterIP) — internal load balancing
apiVersion: v1
kind: Service
metadata:
  name: tenant-a-svc
spec:
  selector: { app: razy-tenant, tenant: tenant-a }
  ports: [{ port: 8080, targetPort: 8080 }]
  type: ClusterIP
---
# K8s Ingress — external routing
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: tenant-a-ingress
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "10m"
spec:
  rules:
    - host: tenant-a.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service: { name: tenant-a-svc, port: { number: 8080 } }
  tls:
    - hosts: [tenant-a.example.com]
      secretName: tenant-a-tls
```

**K8s Load Balancing Behavior:**

| Layer | Component | LB Strategy | Characteristics |
|------|------|---------|------|
| L7 Ingress | Nginx Ingress / Traefik / Caddy Ingress | Host-based routing | SSL termination, path routing |
| L4 Service (ClusterIP) | kube-proxy (iptables/IPVS) | Round-robin / session affinity | Pod IPs managed automatically |
| Sidecar (optional) | Istio Envoy / Linkerd Proxy | Weighted, canary, circuit breaker | mTLS, observability |

#### 10.6.3 FrankenPHP Worker Mode Considerations Under Load Balancing

```
⚠ Worker Mode + Replicas notes:

  FrankenPHP worker mode keeps the PHP process resident; the module graph stays in memory.
  
  With multiple replicas:
  
    1. Each replica boots independently — each holds a complete module graph and route table
      → OK: Same image, same code → consistent boot results
     
    2. Sessions are not shared — session_start() writes to container-local /tmp
      → FIX: Use a Redis/Memcached session handler (configured at the Application layer)
     
    3. In-memory caches are not shared — OpCache, manifest cache are replica-local
      → OK: Does not affect correctness; each replica warms up independently
     
    4. Distributor cache (Domain::$distributorCache) is also replica-local
      → OK: configCheckInterval is counted per replica; config changes converge eventually
     
    5. DI Container (Container.php) singletons are process-local only
      → OK for stateless services; stateful services must use external storage
```

### 10.7 homogeneous same-version Container Mesh interconnectivity

#### 10.7.1 Data File Structure (Layout)

```
/app/                              ← Container root
├── site/                          ← SYSTEM_ROOT (from Razy.phar)
│   ├── index.php                  ← Entry point (includes Razy.phar)
│   ├── Razy.phar                  ← Framework archive
│   ├── config.inc.php             ← Site config
│   ├── sites.inc.php              ← Multisite mapping (if applicable)
│   ├── Caddyfile                  ← Per-container Caddy config
│   └── sites/                     ← Distributor definitions
│       └── {dist_code}/
│           ├── dist.php           ← Distributor config
│           └── modules/           ← Module source
│               └── vendor/pkg/ver/
│                   ├── module.php ← Module manifest
│                   ├── controller/
│                   ├── library/
│                   └── webassets/ ← Static assets
├── shared/                        ← Shared modules (global_module=true)
│   └── module/
│       └── vendor/shared-pkg/ver/
└── data/                          ← Per-tenant persistent data
    └── {domain}-{dist}/
        ├── uploads/
        ├── cache/
        └── config/

External mounts (Docker volumes / K8s PVC):
────────────────────────────────────
  shared_modules  →  /app/shared        (ReadOnly, shared by all containers of the same version)
  tenant_data     →  /app/data/{tenant} (ReadWrite, per-tenant)
  shared_assets   →  /app/assets        (ReadOnly, CDN origin — §9.3)
```

#### 10.7.2 Container Mesh Communication Patterns

```
┌──────────────────────────────────────────────────────────────────────┐
│                    Container Mesh Topology                            │
│                                                                      │
│   ┌─────────┐     ┌─────────┐     ┌─────────┐                      │
│   │ TenantA │     │ TenantA │     │ TenantA │  ← same image,       │
│   │ replica1│     │ replica2│     │ replica3│    same version       │
│   │ :8080   │     │ :8080   │     │ :8080   │                      │
│   └────┬────┘     └────┬────┘     └────┬────┘                      │
│        │               │               │                            │
│        └───────────────┼───────────────┘                            │
│                        │                                            │
│              Docker Network / K8s Service                            │
│              (automatic DNS round-robin)                             │
│                        │                                            │
│   ┌──────────────────────────────────────────────────┐              │
│   │              Caddy Front-Door                     │              │
│   │   reverse_proxy tenant-a:8080 {                  │              │
│   │       lb_policy round_robin                      │              │
│   │       health_uri /health                         │              │
│   │   }                                              │              │
│   └──────────────────────────────────────────────────┘              │
│                        │                                            │
│   ┌─────────┐     ┌─────────┐                                      │
│   │ TenantB │     │ TenantC │  ← different tenants                 │
│   │ replica1│     │ replica1│    (may be different version)         │
│   │ :8080   │     │ :8080   │                                      │
│   └─────────┘     └─────────┘                                      │
│                                                                      │
│   Cross-Tenant Communication:                                        │
│   TenantA replica1 → L4 HTTP POST → Caddy → TenantB:8080           │
│   (uses Docker DNS / K8s Service, NOT direct IP)                     │
└──────────────────────────────────────────────────────────────────────┘
```

**Data synchronization strategy between homogeneous replicas:**

| Data types | Option | Consistency | Latency |
|----------|------|--------|------|
| **Code / webassets** | Same Docker image → identical filesystem | Strong consistency | 0 (build-time) |
| **Shared modules** | ReadOnly volume mount | Strong consistency | 0 (mount) |
| **User data (uploads)** | Shared PVC / NFS / S3 | Eventual consistency | <10ms (NFS), ~50ms (S3) |
| **Session** | Redis / Memcached | Strong consistency | <1ms |
| **Cache (OpCache)** | Per-replica (independent) | N/A | 0 |
| **Module graph** | Per-replica (worker mode) | Eventual consistency | configCheckInterval (periodic) |
| **Database** | External shared DB (MySQL/PgSQL) | Strong consistency | <5ms |

#### 10.7.3 Shared Volume Strategies

```
Option 1: Docker Named Volumes + NFS (Small/medium scale, ≤20 tenants)
─────────────────────────────────────────────────────────
  
  Pro:  Simple; Docker-native support
  Con:  NFS single point of failure; write performance is constrained
  
  volumes:
    shared_modules:
      driver: local
      driver_opts:
        type: nfs
        o: addr=nfs-server,rw,soft,timeo=50
        device: ":/exports/razy-shared"


Option 2: K8s PersistentVolumeClaim + CSI Driver (Medium/large scale)
───────────────────────────────────────────────────────
  
  Pro:  K8s-native; supports ReadWriteMany (RWX)
  Con:  Requires a CSI driver (EFS, Azure Files, GlusterFS)
  
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: shared-modules-pvc
  spec:
    accessModes: [ReadWriteMany]
    resources: { requests: { storage: 10Gi } }
    storageClassName: efs-sc


Option 3: Object Storage (S3 / MinIO) + Sidecar Sync (Large scale, >100 tenants)
────────────────────────────────────────────────────────────────────────
  
  Pro:  Unlimited scalability; natural CDN integration
  Con:  Higher latency (~50ms); requires a sync agent
  
  ┌──────────┐      ┌─────────┐      ┌──────────────┐
  │ S3/MinIO │ ←──→ │ Sidecar │ ←──→ │ Local Cache  │
  │ (source  │      │ (s3sync)│      │ (/app/cache) │
  │  of truth)│      │ interval│      │ read-only    │
  └──────────┘      │ = 30s   │      └──────────────┘
                    └─────────┘
```

### 10.8 Market Comparison

#### 10.8.1 Static File Reverse Proxy / Dynamic Routing Options Comparison

| Option | Dynamic routing | Static file performance | Multi-tenant support | Docker/K8s integration | PHP integration | Complexity |
|------|---------|-----------|-------------------|----------------|---------|--------|
| **Caddy + Admin API** | ✅ REST API, takes effect in ~5ms | ✅ native `file_server` | ⚠️ requires a custom provisioner | ✅ DNS LB + health checks | ✅ curl-based | ★★☆ |
| **Traefik** | ✅ auto-discovery via Docker labels | ⚠️ requires a file provider or plugin | ✅ native router concept | ✅✅ Docker/K8s-native | ⚠️ no direct API | ★★☆ |
| **Nginx + lua/njs** | ⚠️ requires the `ngx_http_lua` module | ✅ best-in-class static file benchmarks | ⚠️ requires custom config generation | ⚠️ no native dynamic discovery | ❌ reload requires a signal | ★★★ |
| **Envoy** | ✅ xDS API (gRPC) | ✅ high performance | ✅ dynamic clusters/routes | ✅ Istio sidecar | ❌ requires a control plane | ★★★★ |
| **HAProxy** | ⚠️ limited runtime API | ✅ excellent performance | ⚠️ requires template generation | ⚠️ not native | ❌ complex config | ★★★ |
| **Cloudflare Workers** | ✅ edge functions | ✅ global CDN edge | ✅ Worker Routes | N/A (hosted) | ❌ JS/WASM only | ★★ |
| **AWS ALB + S3** | ✅ Target Group API | ✅ S3 = distributed storage | ✅ multiple target groups + multiple domains | ✅ ECS/EKS integration | ⚠️ SDK needed | ★★★ |

#### 10.8.2 Razy Option Positioning Analysis

```
                    Dynamic routing capability
                    ↑
                    │
         Envoy ●   │        ● Traefik
       (xDS gRPC)  │     (Docker auto-
                    │      discovery)
                    │
         HAProxy ●  │   ● Caddy API  ←── Razy best choice
                    │     (REST JSON)
                    │
          Nginx ●   │
        (signal     │
         reload)    │
                    │
                    └──────────────────────→ Operational simplicity
```

#### 10.8.3 Why Caddy API for Razy

| Decision factor | Why Caddy wins |
|----------|---------------|
| **FrankenPHP integration** | Caddy is the underlying server for FrankenPHP — same process, zero extra hops |
| **PHP-friendly** | REST JSON API + curl — no gRPC client (Envoy) or Docker socket (Traefik) required |
| **Unified static + dynamic** | `file_server` + `reverse_proxy` + `php_server` in a single config |
| **Auto HTTPS** | Let's Encrypt automatic certificates — no manual per-domain setup for multi-tenant |
| **Existing foundation** | `CaddyfileCompiler` already exists — only needs an API mode |
| **Worker mode** | FrankenPHP worker mode is validated (37× vs cold boot, §benchmark) |

#### 10.8.4 Why Not the Other Options

| Option | Reason not chosen |
|------|---------|
| **Traefik** | Docker label auto-discovery is convenient, but it loses FrankenPHP one-process integration — needs an extra PHP-FPM/Apache container |
| **Nginx** | No native dynamic routing API — would require nginx-proxy-manager or OpenResty Lua scripts, increasing operational complexity |
| **Envoy** | Control plane (gRPC xDS server) development cost is too high — fits the Istio ecosystem, not a mid-size PHP framework |
| **Cloudflare Workers** | Edge-only — not suitable for self-hosted deployments; Razy's core use case is on-premise |
| **AWS ALB** | Vendor lock-in — Razy should remain cloud-provider neutral |

### 10.9 Recommended Architecture (Recommended Architecture)

#### 10.9.1 Phase 2 (Docker) Recommended Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        Production Architecture                          │
│                        (Docker Compose, ≤50 tenants)                   │
│                                                                         │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                   Caddy Front-Door (:443/:80)                    │   │
│  │                                                                  │   │
│  │  Admin API :2019 (bind 127.0.0.1)                               │   │
│  │                                                                  │   │
│  │  ┌──────────────────────────────────────────────────────┐       │   │
│  │  │ Route Table (managed by TenantProvisioner PHP class) │       │   │
│  │  │                                                      │       │   │
│  │  │  tenantA.com  → reverse_proxy tenant-a:8080          │       │   │
│  │  │  tenantB.com  → reverse_proxy tenant-b:8080          │       │   │
│  │  │  assets.*.com → file_server /app/assets (shared vol) │       │   │
│  │  │  /_razy/internal/* → 404 (blocked from internet)    │       │   │
│  │  └──────────────────────────────────────────────────────┘       │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│            │                    │                   │                    │
│            ▼                    ▼                   ▼                    │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐                  │
│  │  Tenant A    │  │  Tenant B    │  │  Host Tenant  │                 │
│  │  FrankenPHP  │  │  FrankenPHP  │  │  (Admin +     │                 │
│  │  Worker Mode │  │  Worker Mode │  │   Provisioner)│                 │
│  │              │  │              │  │              │                   │
│  │  Razy.phar   │  │  Razy.phar   │  │  Razy.phar   │                 │
│  │  Distributor │  │  Distributor │  │  Application │                  │
│  │  (tenant)    │  │  (tenant)    │  │  (core)      │                  │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘                  │
│         │                 │                  │                          │
│         └─────────────────┼──────────────────┘                         │
│                           │                                            │
│              ┌────────────┴────────────┐                               │
│              │     Docker Network       │                              │
│              │     (razy-internal)       │                              │
│              └──────────────────────────┘                              │
│                           │                                            │
│              ┌────────────┴────────────┐                               │
│              │    Shared Services       │                              │
│              │  ┌─────┐ ┌──────┐       │                              │
│              │  │Redis│ │MySQL │       │                              │
│              │  └─────┘ └──────┘       │                              │
│              └──────────────────────────┘                              │
└─────────────────────────────────────────────────────────────────────────┘
```

#### 10.9.2 Phase 4 (K8s) Recommended Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    K8s Production Architecture                          │
│                    (>50 tenants, auto-scaling)                          │
│                                                                         │
│  ┌──────────────────────────────────────────┐                          │
│  │           Ingress Controller              │                          │
│  │   (Caddy Ingress / Traefik / Nginx)      │                          │
│  │   + CertManager (auto TLS)               │                          │
│  └──────────────────┬───────────────────────┘                          │
│                     │                                                   │
│  ┌──────────────────┼──────────────────────────────────────────┐       │
│  │                  │          K8s Namespace: razy-tenants     │       │
│  │    ┌─────────────┼──────────────────────┐                   │       │
│  │    │             ▼                      │                   │       │
│  │    │   ┌──────────────────┐            │                    │       │
│  │    │   │ Tenant Operator  │◄── CRD     │                    │       │
│  │    │   │ (Go/PHP sidecar) │   events   │                    │       │
│  │    │   │                  │            │                    │       │
│  │    │   │ Creates:         │            │                    │       │
│  │    │   │ • Deployment     │            │                    │       │
│  │    │   │ • Service        │            │                    │       │
│  │    │   │ • Ingress route  │            │                    │       │
│  │    │   │ • PVC            │            │                    │       │
│  │    │   └──────────────────┘            │                    │       │
│  │    │                                    │                    │       │
│  │    │   ┌────────────┐ ┌────────────┐  │                    │       │
│  │    │   │ TenantA    │ │ TenantB    │  │                    │       │
│  │    │   │ Deploy x3  │ │ Deploy x2  │  │ ← HPA auto-scale  │       │
│  │    │   │ ┌────┐     │ │ ┌────┐     │  │                    │       │
│  │    │   │ │Pod │ x3  │ │ │Pod │ x2  │  │                    │       │
│  │    │   │ └────┘     │ │ └────┘     │  │                    │       │
│  │    │   └────────────┘ └────────────┘  │                    │       │
│  │    └──────────────────────────────────┘                    │       │
│  │                                                             │       │
│  │    ┌────────────────────────────────────┐                  │       │
│  │    │      Shared Services Namespace     │                  │       │
│  │    │  Redis │ MySQL │ MinIO/S3          │                  │       │
│  │    └────────────────────────────────────┘                  │       │
│  └─────────────────────────────────────────────────────────────┘       │
└─────────────────────────────────────────────────────────────────────────┘
```

### 10.10 Implementation Effort & Priority

| Item | Hours | Priority | Dependencies | Notes |
|------|------|--------|------|------|
| `TenantProvisioner` class | 8h | P0 | Phase 1 | Caddy API CRUD + config versioning |
| `Application::syncCaddyRoutes()` | 4h | P0 | TenantProvisioner | Push multisite config into Caddy |
| Docker Compose multi-tenant template | 4h | P1 | Phase 2 | Includes Caddy front-door + volume mapping |
| Shared volume strategy (NFS/PVC) | 3h | P1 | Phase 2 | webassets + data sharing |
| Health check endpoint | 2h | P1 | Phase 1 | `GET /health` → 200 OK |
| K8s Deployment/Service YAML | 6h | P2 | Phase 4 | Helm chart + HPA |
| Tenant Operator (CRD) | 20h | P3 | Phase 4 | K8s custom controller (Go) |
| S3/MinIO asset sync sidecar | 8h | P3 | Phase 4 | Large-scale asset distribution |

**Total: ~55h** (Phase 2: ~21h, Phase 4: ~34h)

### 10.11 Risk Assessment

| Risk | Severity | Mitigation |
|------|--------|---------|
| Caddy Admin API has no auth | HIGH | Bind `127.0.0.1:2019` + Docker network isolation |
| Config drift (Caddyfile vs API) | MEDIUM | Option C hybrid — DB as source of truth, periodic dumps for backup |
| Shared volume SPOF | MEDIUM | NFS HA / Multi-AZ PVC / S3 multi-region |
| Worker mode sessions are not shared | HIGH | Redis session handler (known solution) |
| Many tenants (>500) route performance | LOW | Caddy uses an internal trie; host matching is optimized |
| FrankenPHP long-running memory usage | MEDIUM | Configure `max_requests` + periodic restarts via K8s liveness probe |

### 10.12 Summary & Decision Matrix

| Dimension | Recommended | Rationale |
|------|------|------|
| **Static proxy** | Caddy `file_server` on the front-door Caddy | Zero PHP involvement, native performance, unified with FrankenPHP |
| **Dynamic routing** | Caddy Admin API (`/load`) | ~5ms, zero-downtime; callable via PHP curl |
| **Config management** | Hybrid (DB + periodic file backup) | Avoids drift; keeps disaster-recovery capability |
| **Docker LB** | Caddy `reverse_proxy` + Docker DNS | Built-in health checks, round-robin, minimal config |
| **K8s LB** | K8s Service + Ingress Controller | Native pod scaling; HPA auto-scale |
| **Data sharing** | Phase 2: NFS/Docker volume; Phase 4: CSI/S3 | Incremental approach — avoid over-engineering |
| **Session sharing** | Redis (external) | Mature option; shared across all containers |
| **Core layer role** | Add `syncCaddyRoutes()` to Application — event-driven | Replaces the existing CLI-only `updateCaddyfile()` |
| **Tenant layer role** | Distributor remains unchanged — data source role | CaddyfileCompiler / TenantProvisioner consume its data |

---

## 11. Core-Delegated Volume + Static File External Access Feasibility

> **Background:** If the tenant container mounts a volume delegated by the Core layer, it can solve three problems at once:
> (1) Module-generated files have a persistent write location
> (2) The container rootfs stays immutable (read-only)
> (3) Each container sees only its own volume — natural isolation
>
> **The only challenge:** How can the static files inside the volume (webassets/, data/) be accessed by external browsers?

### 11.1 Problem Definition

```
┌─ Container (read-only rootfs) ──────────────────────────────────────────┐
│                                                                          │
│  /app/site/index.php           ← baked in image (immutable)             │
│  /app/site/Razy.phar           ← baked in image (immutable)             │
│  /app/site/sites/{dist}/       ← baked in image (immutable)             │
│  /app/site/sites/{dist}/modules/                                         │
│       └── vendor/pkg/ver/                                                │
│           ├── controller/      ← PHP code (immutable, from image)       │
│           ├── library/         ← PHP code (immutable, from image)       │
│           └── webassets/       ← static files — HOW TO SERVE?           │
│               ├── css/style.css                                          │
│               ├── js/app.js                                              │
│               └── images/logo.png                                        │
│                                                                          │
│  /app/data/ ← VOLUME MOUNT (writable, core-delegated)                   │
│       └── {tenant-id}/                                                   │
│           ├── uploads/         ← user-generated files — HOW TO SERVE?   │
│           ├── cache/           ← runtime cache (not public)              │
│           └── config/          ← runtime config (not public)             │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
         ▲                                      ▲
         │                                      │
    Container Network                      Container Network
    (only reachable via                    (only reachable via
     Docker/K8s internal)                   Docker/K8s internal)
         │                                      │
    ─── External browsers cannot access directly ─────────────────────
```

**Two categories of static files:**

| Type | Source | Location | Characteristics |
|------|------|------|------|
| **Webassets** | CSS/JS/images packaged by module developers | `modules/*/webassets/` inside the image | Known at build time, versioned, cacheable |
| **Data files** | Generated at module runtime (uploads, reports) | Volume mount `/app/data/` | Generated at runtime, dynamic, requires access control |

### 11.2 Overview of options

```
┌───────────────────────────────────────────────────────────────────────┐
│  Option A: Proxy-Through (Proxy-Through)                                  │
│  Option B: Front-Door Volume Mount (Front-Door Volume Mount)                        │  
│  Option C: Build-Time Asset Extraction (Build-Time Extraction)                      │
│  Option D: Sidecar Asset Sync (Sidecar Sync to Shared Layer)                         │
│  Option E: Caddy On-Demand Reverse File Server (On-Demand Reverse File Server)         │
│  Option F: Object Storage + CDN (Object Storage + CDN)                        │
└───────────────────────────────────────────────────────────────────────┘
```

### 11.3 Option A: Proxy-Through (Proxy-Through)

**How it works:** Front-door Caddy also reverse-proxies requests for `/webassets/*` and `/data/*` to the tenant container, where the in-container FrankenPHP/Caddy serves them directly via `file_server`.

```
  Browser → GET /webassets/Shop/1.0.0/css/style.css
      │
      ▼
  Caddy Front-Door
      │  reverse_proxy tenant-a:8080
      ▼
  Tenant Container (internal Caddy)
      │  @webasset_Shop path /webassets/Shop/*
      │  handle → file_server (from image filesystem)
      ▼
  Response: 200 OK + CSS file
```

**Caddyfile (Front-Door):**
```caddyfile
tenant-a.example.com {
    # ALL traffic goes to tenant container (including static)
    reverse_proxy tenant-a:8080 {
        lb_policy round_robin
        health_uri /health
        
        # Cache headers from upstream are preserved
        # Browser & CDN can cache based on Cache-Control
    }
    
    # Block internal bridge
    @internal path /_razy/internal/*
    respond @internal 404
}
```

**Tenant Container Caddyfile (auto-generated):**
```caddyfile
:8080 {
    root * /app/site
    
    # Webassets — served from IMAGE filesystem (immutable, fast)
    @webasset_Shop path /webassets/Shop/*
    handle @webasset_Shop {
        uri strip_prefix /webassets/Shop
        root * modules/vendor/shop/default
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=31536000, immutable"
    }
    
    # Data files — served from VOLUME mount (runtime-generated)
    @data path /data/*
    handle @data {
        uri strip_prefix /data
        root * /app/data/{tenant-id}
        file_server
        header Cache-Control "public, max-age=3600"
    }
    
    # PHP dynamic requests
    php_server {
        worker /app/site/index.php
    }
}
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★☆☆ (LOW) | Zero additional infrastructure — the existing CaddyfileCompiler already supports this |
| **Performance** | ★★☆ (MEDIUM) | One extra reverse_proxy hop (~0.1ms); but Caddy file_server is still direct file reads |
| **Isolation** | ★★★ (HIGH) | Each container serves only its own files; no shared volume required |
| **Consistency** | ★★★ (HIGH) | Webassets come from the image → strictly consistent with the code version |
| **Scalability** | ★★☆ (MEDIUM) | Static traffic consumes container resources; high traffic requires a CDN/front-door cache |
| **Change effort** | 0h | **Already supported by the current architecture** — no code changes required |

### 11.4 Option B: Front-Door Volume Mount (Front-Door Volume Mount)

**How it works:** Front-door Caddy mounts each tenant container's webasset directory (read-only) and serves it directly with `file_server`, without going through the tenant container.

```
  Browser → GET /webassets/Shop/1.0.0/css/style.css
      │
      ▼
  Caddy Front-Door
      │  @webasset_tenant_a path /webassets/Shop/*
      │  handle → file_server (from mounted volume)
      │  Note: file_server reads directly; no reverse_proxy hop
      ▼
  Response: 200 OK + CSS file
  
  (Dynamic requests still go through reverse_proxy)
```

**Docker Compose:**
```yaml
services:
  caddy-front:
    image: caddy:2-alpine
    volumes:
      # Each tenant's webasset directory mounted read-only
      - tenant_a_modules:/assets/tenant-a:ro
      - tenant_b_modules:/assets/tenant-b:ro
      # Alternatively if using dedicated webasset volume:
      - tenant_a_webassets:/assets/tenant-a/webassets:ro
      - tenant_b_webassets:/assets/tenant-b/webassets:ro
    ports:
      - "443:443"
      - "80:80"

  tenant-a:
    image: razy-tenant:1.0.1-beta
    read_only: true    # immutable rootfs
    volumes:
      - tenant_a_data:/app/data/tenant-a         # writable data
      - tenant_a_modules:/app/site/sites/main/modules:ro  # module code
    tmpfs:
      - /tmp:size=64M   # ephemeral temp

volumes:
  tenant_a_modules:    # module code (populated from image at first run)
  tenant_a_data:       # runtime data
  tenant_a_webassets:  # webasset extract (optional)
```

**Front-Door Caddyfile:**
```caddyfile
tenant-a.example.com {
    # Static: direct file_server (NO reverse_proxy hop)
    @webassets path /webassets/*
    handle @webassets {
        root * /assets/tenant-a
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=31536000, immutable"
    }
    
    @data path /data/*
    handle @data {
        root * /assets/tenant-a/data
        file_server
        header Cache-Control "public, max-age=3600"
    }
    
    # Dynamic: reverse_proxy to tenant container
    reverse_proxy tenant-a:8080 {
        lb_policy round_robin
        health_uri /health
    }
}
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★★☆ (MEDIUM) | Each new tenant requires mounting its volume into the front-door |
| **Performance** | ★★★ (HIGH) | Zero proxy hops — Caddy reads from local filesystem directly |
| **Isolation** | ★★☆ (MEDIUM) | Front-door can see every tenant's webassets (read-only) |
| **Consistency** | ★★☆ (MEDIUM) | Must keep volume contents in sync with the container image |
| **Scalability** | ★☆☆ (LOW) | Volume mounts grow linearly with tenants — not feasible beyond ~50 |
| **Change effort** | ~6h | Volume provisioning script + a new CaddyfileCompiler mode |

**⚠ Key issue: Webassets in image vs in volume**

Currently, Razy webassets are part of the module code and are baked into the Docker image. If the container rootfs is read-only, webassets are also read-only — which is ideal. But if the front-door must read them via a volume, there are two approaches:

```
Approach B1: Shared Volume from Image (Docker named volume + init container)
───────────────────────────────────────────────────────────────────────
  
  # Init container copies webassets from image to volume
  init-tenant-a:
    image: razy-tenant:1.0.1-beta
    command: ["cp", "-r", "/app/site/sites/main/modules", "/export/"]
    volumes:
      - tenant_a_modules:/export
  
  → Issue: must re-run the init container when the image is updated

Approach B2: Docker --volumes-from (shared container filesystem)
──────────────────────────────────────────────────────────
  
  caddy-front:
    volumes_from:
      - tenant-a:ro
  
  → Issue: security risk — front-door can see the tenant container's entire filesystem
  → Deprecated in Docker Compose v3; not recommended
```

### 11.5 Option C: Build-Time Asset Extraction (Build-Time Extraction)

**How it works:** During image build or in the CI/CD pipeline, extract webassets into a shared asset store (volume / S3 / CDN origin).

```
  CI/CD Pipeline:
  
  1. Build razy-tenant:1.0.1 Docker image
       │
       ▼
  2. Extract webassets from image
       docker create --name tmp razy-tenant:1.0.1
       docker cp tmp:/app/site/sites/main/modules/*/webassets/ ./extracted/
       docker rm tmp
       │
       ▼
  3. Upload to shared asset store
       ├─ Option A: Copy to shared Docker volume
       ├─ Option B: Upload to S3/MinIO
       └─ Option C: Push to CDN origin
       │
       ▼
  4. Caddy front-door serves from shared asset store
       file_server { root /app/shared-assets/tenant-a }
       OR
       reverse_proxy s3.internal:9000
```

**Dockerfile multi-stage build (recommended):**
```dockerfile
# ── Stage 1: Build Razy tenant image ──
FROM dunglas/frankenphp:latest AS runtime
COPY . /app/site/
RUN php /app/site/Razy.phar setup
# ... normal tenant image setup ...

# ── Stage 2: Extract webassets only ──
FROM alpine:3.19 AS asset-extract
COPY --from=runtime /app/site/sites/main/modules /tmp/modules
RUN mkdir -p /assets && \
    find /tmp/modules -path "*/webassets" -type d | while read d; do \
      module_path=$(dirname "$d"); \
      alias=$(basename $(dirname "$module_path")); \
      cp -r "$d" "/assets/$alias/"; \
    done

# ── Stage 3: Asset-serving image (lightweight) ──
FROM caddy:2-alpine AS asset-server
COPY --from=asset-extract /assets /srv/assets
# This image can be deployed as the asset CDN origin
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★★★ (HIGH) | CI/CD changes, extraction script, and multi-image management |
| **Performance** | ★★★ (HIGH) | CDN edge, zero PHP, zero proxy hops |
| **Isolation** | ★★★ (HIGH) | Webassets extracted as read-only; tenant containers are unaffected |
| **Consistency** | ★★★ (HIGH) | Strictly bound to the build-time code version |
| **Scalability** | ★★★ (HIGH) | Virtually unlimited with CDN/S3; tenant count does not matter |
| **Change effort** | ~12h | Dockerfile multi-stage + CI pipeline + CDN mode in CaddyfileCompiler |

### 11.6 Option D: Sidecar Asset Sync (Sidecar Sync)

**How it works:** Each tenant pod/container runs a sidecar that periodically (or event-driven) syncs webassets/data into shared storage.

```
  ┌─ Tenant Pod ──────────────────────────────────┐
  │                                                │
  │  ┌──────────────┐      ┌──────────────┐       │
  │  │ FrankenPHP   │      │ Asset Sync   │       │
  │  │ (main)       │      │ (sidecar)    │       │
  │  │              │      │              │       │
  │  │ /app/site/   │ ←──→ │ watches:     │       │
  │  │ modules/*/   │ vol  │ /app/site/   │       │
  │  │ webassets/   │      │ modules/*/   │       │
  │  │              │      │ webassets/   │       │
  │  │ /app/data/   │ ←──→ │ /app/data/   │       │
  │  └──────────────┘      │              │       │
  │                         │ syncs to:    │       │
  │                         │ → S3/MinIO   │       │
  │                         │ → shared vol │       │
  │                         └──────┬───────┘       │
  └────────────────────────────────┼───────────────┘
                                   │
                                   ▼
                            ┌─────────────┐
                            │ S3 / MinIO  │
                            │ or shared   │
                            │ volume      │
                            └──────┬──────┘
                                   │
                                   ▼
                            ┌─────────────┐
                            │ CDN / Caddy │
                            │ file_server │
                            └─────────────┘
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★★★ (HIGH) | Extra sidecar container, fswatch/inotify, and sync logic |
| **Performance** | ★★★ (HIGH) | Once synced, CDN serves directly |
| **Isolation** | ★★★ (HIGH) | Sidecar reads source → writes target |
| **Consistency** | ★★☆ (MEDIUM) | Sync latency (seconds); especially for data files |
| **Scalability** | ★★★ (HIGH) | Same as Option C — backed by S3/CDN |
| **Change effort** | ~10h | Sidecar image + sync script + K8s pod spec |

**Best for:** Webassets are build-time and immutable (Option C is better for that), but when **data files (generated at runtime)** must be synced dynamically, a sidecar is more suitable.

### 11.7 Option E: Caddy On-Demand Reverse File Server

**How it works:** When front-door Caddy receives a `/webassets/*` request, it fetches the file once from the tenant container and stores it in a local cache. Subsequent requests for the same file are served from cache.

```
  Browser → GET /webassets/Shop/1.0.0/css/style.css
      │
      ▼
  Caddy Front-Door
      │  Check local cache → MISS
      │  reverse_proxy tenant-a:8080/webassets/Shop/1.0.0/css/style.css
      │  ← 200 OK + file
      │  Store in local cache (disk/memory)
      │  ← Respond to browser
      │
  Next request (same file):
      │  Check local cache → HIT
      │  ← Respond immediately (no upstream call)
```

**Caddyfile (with cache directive):**
```caddyfile
# Requires: caddy-cache module (xcaddy build --with github.com/caddyserver/cache-handler)

tenant-a.example.com {
    # Webassets — reverse_proxy with aggressive caching
    @webassets path /webassets/*
    handle @webassets {
        cache {
            ttl 8760h          # 1 year (versioned assets = immutable)
            stale_ttl 24h    
            default_cache_control "public, max-age=31536000, immutable"
            key {
                disable_host
                disable_method
                disable_query
            }
        }
        reverse_proxy tenant-a:8080
    }
    
    # Data files — shorter cache
    @data path /data/*
    handle @data {
        cache {
            ttl 1h
            default_cache_control "public, max-age=3600"
        }
        reverse_proxy tenant-a:8080
    }
    
    # Dynamic PHP requests — no cache
    reverse_proxy tenant-a:8080
}
```

**Alternative Option E2 (no cache module):** Use built-in `file_server` with a `reverse_proxy` fallback:
```caddyfile
tenant-a.example.com {
    @webassets path /webassets/*
    handle @webassets {
        root * /cache/tenant-a
        
        # Try local cache first; if miss, fetch from upstream
        @cached file
        handle @cached {
            file_server
            header Cache-Control "public, max-age=31536000, immutable"
        }
        
        # Cache miss → proxy to tenant, then a background job populates cache
        handle {
            reverse_proxy tenant-a:8080
        }
    }
}
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★★☆ (MEDIUM) | Requires a custom Caddy build for cache module; or use the E2 fallback |
| **Performance** | ★★★ (HIGH) | First hit goes through proxy (~0.2ms); later hits are pure local cache |
| **Isolation** | ★★★ (HIGH) | No shared volume needed — pure network cache |
| **Consistency** | ★★★ (HIGH) | Versioned URL → cache key stays valid until the version changes |
| **Scalability** | ★★★ (HIGH) | Cache scales horizontally; CDN can scale further |
| **Change effort** | ~4h | Build Caddy with cache module + cache mode in CaddyfileCompiler |

### 11.8 Option F: Object Storage + CDN

**How it works:** Instead of doing this at the Caddy layer, the module publishes webassets directly to S3/MinIO, and the frontend accesses them via a CDN URL.

```
  Module Deploy:
  1. Module::publishAssets() → upload webassets/ to S3 bucket
  2. CDN (CloudFlare/CloudFront) → origin = S3 bucket
  3. Controller::getAssetUrl() → https://cdn.example.com/tenant-a/Shop/1.0.0/
  
  Browser:
  GET https://cdn.example.com/tenant-a/Shop/1.0.0/css/style.css
      │
      ▼
  CDN Edge → S3 Origin → Response (cached at edge)
```

| Dimension | Score | Notes |
|------|------|------|
| **Complexity** | ★★★ (HIGH) | S3 SDK, CDN configuration, asset publish pipeline |
| **Performance** | ★★★★ (BEST) | Global CDN edge; latency <10ms |
| **Isolation** | ★★★ (HIGH) | S3 bucket policy — per-tenant prefix isolation |
| **Consistency** | ★★★ (HIGH) | Versioned paths = no collisions |
| **Scalability** | ★★★★ (BEST) | Essentially unlimited — S3 + CloudFront |
| **Change effort** | ~16h | S3 upload logic + CDN setup + `getAssetUrl()` + env config |

### 11.9 Option comparison matrix

| Option | Complexity | Performance | Isolation | Consistency | Scalability | Change effort | Suitable scale |
|------|--------|------|------|--------|------|--------|----------|
| **A. Proxy-Through** | ★☆☆ | ★★☆ | ★★★ | ★★★ | ★★☆ | **0h** | ≤20 tenants |
| **B. Front-Door Mount** | ★★☆ | ★★★ | ★★☆ | ★★☆ | ★☆☆ | 6h | ≤10 tenants |
| **C. Build-Time Extract** | ★★★ | ★★★ | ★★★ | ★★★ | ★★★ | 12h | Any |
| **D. Sidecar Sync** | ★★★ | ★★★ | ★★★ | ★★☆ | ★★★ | 10h | Any (data-heavy) |
| **E. On-Demand Cache** | ★★☆ | ★★★ | ★★★ | ★★★ | ★★★ | **4h** | ≤100 tenants |
| **F. S3 + CDN** | ★★★ | ★★★★ | ★★★ | ★★★ | ★★★★ | 16h | >100 tenants |

### 11.10 Recommended phased strategy

```
Phase 2 (Docker, ≤20 tenants):
────────────────────────────────

  Recommended: Option A (Proxy-Through) — zero changes

  Why:
  • CaddyfileCompiler already generates webasset file_server rules inside the tenant
  • Front-door Caddy only needs reverse_proxy → tenant:8080
  • Static files are served by the tenant container's own Caddy file_server
  • Webassets come from the image (immutable) — consistency is guaranteed
  • Data files come from volume mounts — still served by file_server
  • The only "cost" is one extra reverse_proxy hop (~0.1ms) — negligible
  
  Add a CDN in front (optional):
  
    CloudFlare → Front-Door Caddy → Tenant Container
                                       ↑ file_server
  
  CloudFlare caches responses with `Cache-Control: immutable`,
  so subsequent requests have near-zero latency.


Phase 2+ (Docker, 20-100 tenants):
──────────────────────────────────

  Recommended: Option A + Option E (On-Demand Cache) hybrid

  Why:
  • Option A is the existing baseline
  • Option E adds a front-door cache layer — greatly reduces upstream static requests
  • Versioned URLs → cache hit rate approaches 100%
  • Only ~4h of changes (custom Caddy build with cache module)
  
  Architecture:
  
    Browser → Caddy Front-Door (cache HIT?) 
                  ├─ YES → respond directly (0 hop)
                  └─ NO  → reverse_proxy → tenant:8080 → file_server
                            → cache store for next time


Phase 4 (K8s, >100 tenants):
─────────────────────────────

  Recommended: Option C (Build-Time Extract) + Option F (S3 + CDN) 

  Why:
  • CI/CD pipelines are standard — adding an extraction step is low-cost
  • S3/MinIO provides scalable storage + reliability
  • Global CDN edge — best latency
  • Controller::getAssetUrl() can switch via the RAZY_ASSET_CDN env var
  • Use Option D (sidecar) to sync data files to S3
```

### 11.11 Volume mount design (Core-delegated)

Below is a concrete design where Core delegates volumes to tenants:

```yaml
# docker-compose.yml — Core-Delegated Volume Architecture
services:
  # ── Core Orchestrator ──
  core:
    image: razy-core:1.0.1-beta
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro   # Docker API access
      - core_config:/app/config                         # Tenant registry
    environment:
      - CADDY_ADMIN_URL=http://caddy-front:2019
    networks:
      - razy-management
      - razy-internal

  # ── Caddy Front-Door ──
  caddy-front:
    image: caddy:2-alpine    # or custom build with cache module
    ports:
      - "443:443"
      - "80:80"
    networks:
      - razy-external
      - razy-internal
    # NOTE: NO tenant volume mounts needed (Option A — proxy-through)
    # Caddy only does reverse_proxy + bridge blocking

  # ── Tenant A (core-delegated volumes) ──
  tenant-a:
    image: razy-tenant:1.0.1-beta
    read_only: true                           # ← Immutable rootfs
    volumes:
      - tenant_a_data:/app/data/tenant-a      # ← Core-delegated: writable data
      - shared_modules:/app/shared:ro         # ← Core-delegated: shared modules (read-only)
    tmpfs:
      - /tmp:size=64M,mode=1777              # ← Ephemeral temp (session, opcache)
    environment:
      - RAZY_TENANT_ID=tenant-a
      - RAZY_DIST_CODE=main
      - RAZY_DATA_PATH=/app/data/tenant-a
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    cap_add:
      - NET_BIND_SERVICE                      # Only: bind to port 8080
    networks:
      - razy-internal                         # ← NO external network access

  # ── Tenant B (same structure, different volume) ──
  tenant-b:
    image: razy-tenant:1.0.1-beta
    read_only: true
    volumes:
      - tenant_b_data:/app/data/tenant-b
      - shared_modules:/app/shared:ro
    tmpfs:
      - /tmp:size=64M,mode=1777
    environment:
      - RAZY_TENANT_ID=tenant-b
      - RAZY_DIST_CODE=storefront
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    cap_add:
      - NET_BIND_SERVICE
    networks:
      - razy-internal

volumes:
  core_config:         # Core management data
  tenant_a_data:       # Tenant A's writable data (uploads, cache, config)
  tenant_b_data:       # Tenant B's writable data
  shared_modules:      # Global shared modules (read-only to all tenants)

networks:
  razy-external:       # Internet-facing (only caddy-front)
    driver: bridge
  razy-internal:       # Tenant mesh (private)
    driver: bridge
    internal: true     # ← NO internet access from this network
  razy-management:     # Core → Docker socket (privileged)
    driver: bridge
    internal: true
```

**Volume responsibility matrix:**

| Volume | Owner | Mounted to | Access | Contents |
|--------|-------|---------|---------|------|
| `tenant_{id}_data` | Delegated by Core | Tenant container only | RW | uploads/, cache/, config/ |
| `shared_modules` | Managed by Core | All tenant containers | RO | Global shared modules (e.g., auth, theme) |
| `core_config` | Core only | Core orchestrator | RW | tenant registry, Caddy config history |

**Webassets do not require an extra volume** — they are baked into the Docker image and remain readable under a read-only rootfs. The in-container `file_server` can serve them normally.

### 11.12 Traffic path summary

```
═══════════════════════════════════════════════════════════════════════

  Static File (webasset) — Phase 2 (Option A):
  
    Browser
      → CDN (optional — cache immutable assets)
        → Caddy Front-Door (reverse_proxy)
          → Tenant Container (Caddy file_server)
            → Image Filesystem (read-only) ✅
    
    Latency: CDN hit 0ms | CDN miss ~0.5ms | direct ~0.3ms

═══════════════════════════════════════════════════════════════════════

  Static File (webasset) — Phase 2+ (Option A + E):
  
    Browser
      → CDN (optional)
        → Caddy Front-Door (local cache HIT?)
          ├─ HIT → respond immediately ✅
          └─ MISS → reverse_proxy → Tenant → file_server → cache store
    
    Latency: Cache hit ~0.1ms | Cache miss ~0.3ms (then cached)

═══════════════════════════════════════════════════════════════════════

  Data File (user uploads) — Phase 2:
  
    Browser
      → CDN (short cache — max-age 3600)
        → Caddy Front-Door (reverse_proxy)
          → Tenant Container (Caddy file_server)
            → Volume Mount (writable) ✅
    
    Latency: ~0.3ms (always through proxy — data is dynamic)

═══════════════════════════════════════════════════════════════════════

  Dynamic PHP Request:
  
    Browser
      → Caddy Front-Door (reverse_proxy)
        → Tenant Container (FrankenPHP worker)
          → Razy Application → Distributor → RouteDispatcher
            → Module Controller (PHP logic)
    
    Latency: ~1-5ms (depends on route complexity)

═══════════════════════════════════════════════════════════════════════
```

### 11.13 Key Insight (Key Insight)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   Webassets are in the image → container file_server serves directly │
  │   → Front-door only needs reverse_proxy → zero extra changes (Option A) │
  │                                                                 │
  │   Data files are on volumes → still served by file_server        │
  │   → Each container only sees its own volume                      │
  │                                                                 │
  │   The only "cost" is one extra reverse_proxy hop (~0.1ms)        │
  │   → CDN + cache can eliminate this cost                          │
  │                                                                 │
  │   ∴ Core-delegated volumes + Option A = a zero-change solution   │
  │   ∴ Adding Option E (cache) and Option F (S3+CDN) are incremental improvements │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 12. Data Access Rewrite (Module-Controlled) + Webassets Under Load Balancing

> **Assumption:** §11 confirms that webassets (in the image) and data files (in volumes) can be served directly by the in-container `file_server`. This section addresses two remaining practical issues:
> 1. **Data Access — Module-Controlled Cross-Dist Rewrite:** how a module controls which other Distributors are allowed to rewrite to its data folder
> 2. **Webassets Under Load Balancing:** rewrite consistency of `reverse_proxy` + `file_server` under multiple replicas

### 12.1 Background

#### Problem ①: Frontend rewrites for data files — missing module-level control

**Current state:** `data_mapping` in `dist.php` is a **consumer-side** configuration — the Distributor that wants to read declares which other Distributor's data path it wants to mount:

```php
// sites/main/dist.php — Consumer (Main dist wants to access Blog's data)
return [
    'dist' => 'main',
    'data_mapping' => [
        '/blog' => 'blog.example.com:blog',   // Mount Blog's data at /data/blog/
        '/'     => 'example.com:main',         // Own data at /data/
    ],
];
```

**Rules generated by CaddyfileCompiler:**
```caddyfile
# Data mapping: main
@data_main__0 path /data/*
handle @data_main__0 {
    uri strip_prefix /data
    root * /app/public/data/example.com-main
    file_server
}

@data_main_blog__1 path /blog/data/*
handle @data_main_blog__1 {
    uri strip_prefix /blog/data
    root * /app/public/data/blog.example.com-blog
    file_server
}
```

**Problem:** There is currently **no** mechanism for the producer module (the one being accessed) to control which data subdirectories can be accessed by external Distributors. Any Distributor that knows another Distributor's `domain:code` can mount it in `data_mapping` and read **all** data files.

```
  ⚠ Security Gap:
  
  Module A (uploads/) → should only allow external reads of public/images/
  Module A (cache/)   → should NOT be accessible to external Distributors
  Module A (reports/) → should be restricted to specific Distributors
  
  But currently data_mapping mounts the entire data/{domain}-{dist}/ directory
  → all subdirectories are treated the same for all consumers
```

#### Problem ②: Webassets Under Load Balancing

```
  Browser → Caddy Front-Door (reverse_proxy lb_policy round_robin)
               ├─→ Replica 1 (tenant-a:8080)  → file_server /webassets/Shop/*
               ├─→ Replica 2 (tenant-a:8080)  → file_server /webassets/Shop/*
               └─→ Replica 3 (tenant-a:8080)  → file_server /webassets/Shop/*
  
    Q: Replica 1 returns a URL like /webassets/Shop/1.0.0/css/style.css
      → If the next request is load-balanced to Replica 2, does Replica 2 have the same file?
      → Are rewrite rules identical across all replicas?
```

### 12.2 Existing architecture recap — Data layer

**Data file lifecycle:**

```
  Module Controller
      │ $this->getDataPath('my_module')
      │ → Distributor::getDataPath('my_module')
      │ → PathUtil::append(DATA_FOLDER, $this->getIdentity(), 'my_module')
      │ → /app/data/example.com-main/my_module/
      ▼
  Filesystem: /app/data/example.com-main/my_module/uploads/image.jpg
                                                  /cache/temp.dat
                                                  /reports/2024-Q1.pdf
```

**URL Access (frontend):**
```
  $this->getDataPathURL('my_module')
      → Distributor::getDataPath('my_module', true)
      → PathUtil::append($this->getSiteURL(), 'data', 'my_module')
      → https://example.com/data/my_module/
  
  Browser: GET https://example.com/data/my_module/uploads/image.jpg
      → Caddy @data matcher → file_server → /app/data/example.com-main/my_module/uploads/image.jpg
```

**Cross-Dist Access (current):**
```
  Blog Dist wants to read Main Dist's data:
  
  dist.php: 'data_mapping' => ['/' => 'example.com:main']
      → CaddyfileCompiler generates:
        @data_blog__0 path /data/*
        handle @data_blog__0 {
            root * /app/public/data/example.com-main   ← entire directory
            file_server
        }
  
  → Blog's frontend can read **all** Main data files
  → No fine-grained control
```

### 12.3 Design option: Module-Level Data Export Declaration

**Core idea:** Add a `data_exports` config to the module's `package.php`. The **producer module** declares which data subdirectories are externally visible, and which Distributors are allowed as rewrite targets.

#### 12.3.1 Add `data_exports` field to package.php

```php
// modules/vendor/shop/default/package.php
return [
    'alias'       => 'Shop',
    'api_name'    => 'shop',
    'require'     => ['vendor/auth' => '*'],
    'services'    => [
        CartInterface::class => ShoppingCart::class,
    ],
    
    // ── NEW: Data Export Declaration ──
    'data_exports' => [
      // Subdirectory => access rules
        'uploads/images' => [
        'access' => 'public',              // Any Dist can rewrite
        ],
        'uploads/avatars' => [
            'access' => 'public',
        ],
        'reports' => [
        'access' => 'restricted',          // Only specific Dists can rewrite
        'allow'  => ['admin', 'analytics'],// Only these dist codes are allowed
        ],
        'cache' => [
        'access' => 'private',             // Not exposed externally (default)
        ],
    ],
];
```

  **Access level definitions:**

  | Level | Meaning | Rewrite Target |
|------|------|----------------|
  | `public` | Any Distributor can access this subdir after mounting via `data_mapping` | ✅ All |
  | `restricted` | Only dist codes listed in `allow` can access | ✅ Restricted |
  | `private` | Not exposed externally — denied even if mounted via `data_mapping` | ❌ None |
  | *(undeclared)* | **default `private`** — any subdir not declared in `data_exports` is not exposed | ❌ None |

  #### 12.3.2 Architecture responsibilities — who does what

```
                             Build Time (CLI: php Razy.phar rewrite)
  ┌────────────────────────────────────────────────────────────────────┐
  │                                                                    │
  │  ① ModuleInfo::parseDataExports()                                 │
  │     Read package.php['data_exports'] → stored in $this->dataExports│
  │                                                                    │
  │  ② Distributor::getDataExports()                                  │
  │     Aggregate all loaded modules' data_exports → merge into a dist-level map │
  │                                                                    │
  │  ③ CaddyfileCompiler::compileDataMappingHandlers()                │
  │     ← Current: mount the entire data/{domain}-{dist}/ directory    │
  │     → New: for each consumer data_mapping entry, query the producer's data_exports
  │            → only generate matchers for allowed subdirectories      │
  │                                                                    │
  │  ④ RewriteRuleCompiler::compileDataMappingRules()                 │
  │     Same idea — htaccess version                                   │
  │                                                                    │
  └────────────────────────────────────────────────────────────────────┘
```

#### 12.3.3 New Caddyfile output (fine-grained data matchers)

**Before (§11 — coarse-grained):**
```caddyfile
# Blog dist mounts Main dist's data — ENTIRE directory
@data_blog__0 path /data/*
handle @data_blog__0 {
    uri strip_prefix /data
    root * /app/public/data/example.com-main
    file_server
}
```

**After (§12 — module-level granularity):**
```caddyfile
# Blog dist → Main:shop module — only exported sub-dirs

# shop/uploads/images → public (all dists OK)
@data_blog_shop_images path /data/shop/uploads/images/*
handle @data_blog_shop_images {
    uri strip_prefix /data/shop/uploads/images
    root * /app/public/data/example.com-main/shop/uploads/images
    file_server
}

# shop/uploads/avatars → public (all dists OK)
@data_blog_shop_avatars path /data/shop/uploads/avatars/*
handle @data_blog_shop_avatars {
    uri strip_prefix /data/shop/uploads/avatars
    root * /app/public/data/example.com-main/shop/uploads/avatars
    file_server
}

# shop/reports → BLOCKED for blog dist (restricted, blog not in allow list)
# shop/cache   → BLOCKED (private)
# Any unlisted subdir → BLOCKED (default private)
```

#### 12.3.4 Extend caddyfile.tpl template

```
<!-- START BLOCK: data_export -->
	# Data export: {$module_alias}/{$sub_path} ({$access_level})
	@data_{$data_id} path /{$route_path}data/{$module_code}/{$sub_path}/*
	handle @data_{$data_id} {
		uri strip_prefix /{$route_path}data/{$module_code}/{$sub_path}
		root * {$data_path}/{$module_code}/{$sub_path}
		file_server
	}
<!-- END BLOCK: data_export -->
```

**Corresponding htaccess.tpl extension:**
```
    <!-- START BLOCK: data_export -->
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/{$module_code}/{$sub_path}/(.+)$ {$data_base_path}/{$module_code}/{$sub_path}/$1 [L]
    <!-- END BLOCK: data_export -->
```

#### 12.3.5 Self-dist data access (modules within the same dist)

When a module accesses its own data within the same Distributor, it is **not restricted by `data_exports`** — because it runs in the same process and uses `Distributor::getDataPath()` to access the filesystem directly, without rewrites.

However, when the frontend (browser) accesses this dist's data via URL, rewrites still apply. CaddyfileCompiler should distinguish two scenarios:

```
    A. Self access (this dist frontend → this dist data):
      → default allow the entire directory (keep current behavior)
      → enable fine-grained control only if the module declares `self_restrict: true`
  
    B. Cross access (other dist frontend → this dist data via data_mapping):
      → strictly controlled by data_exports
      → undeclared = private = denied
```

  **self_restrict option (advanced):**
```php
'data_exports' => [
    'uploads/images' => [
        'access'        => 'public',
    'self_restrict'  => false,    // default false — not restricted for the same dist frontend
    ],
    'cache' => [
        'access'        => 'private',
    'self_restrict'  => true,     // disallow URL access even for the same dist frontend
    ],
],
```

### 12.4 Implementation path — code changes

#### 12.4.1 Extend ModuleInfo

```php
// src/library/Razy/ModuleInfo.php — add property + method

/** @var array<string, array{access: string, allow?: string[], self_restrict?: bool}> */
private array $dataExports = [];

/**
 * Parse data_exports from package.php settings.
 */
private function parseDataExports(array $settings): void
{
    if (!isset($settings['data_exports']) || !is_array($settings['data_exports'])) {
        return;
    }
    
    foreach ($settings['data_exports'] as $subPath => $rule) {
        $subPath = trim($subPath, '/');
        if (empty($subPath) || !is_array($rule)) {
            continue;
        }
        
        $access = $rule['access'] ?? 'private';
        if (!in_array($access, ['public', 'restricted', 'private'], true)) {
            $access = 'private';
        }
        
        $this->dataExports[$subPath] = [
            'access'        => $access,
            'allow'         => (array) ($rule['allow'] ?? []),
            'self_restrict'  => (bool) ($rule['self_restrict'] ?? false),
        ];
    }
}

/**
 * Get module data export declarations.
 *
 * @return array<string, array{access: string, allow: string[], self_restrict: bool}>
 */
public function getDataExports(): array
{
    return $this->dataExports;
}

/**
 * Check if a specific sub-path is accessible by a given distributor code.
 *
 * @param string $subPath  The data sub-directory (e.g., 'uploads/images')
 * @param string $distCode The requesting distributor code
 * @param bool   $isSelf   Whether the request is from the same distributor
 *
 * @return bool True if access is allowed
 */
public function isDataExportAllowed(string $subPath, string $distCode, bool $isSelf = false): bool
{
    // If no data_exports declared, default behavior:
    // - Self: allowed (backward compatible)
    // - Cross: denied (secure by default)
    if (empty($this->dataExports)) {
        return $isSelf;
    }
    
    // Find the most specific matching export rule
    $subPath = trim($subPath, '/');
    $matchedRule = null;
    $matchedLength = -1;
    
    foreach ($this->dataExports as $exportPath => $rule) {
        if ($subPath === $exportPath || str_starts_with($subPath, $exportPath . '/')) {
            if (strlen($exportPath) > $matchedLength) {
                $matchedRule = $rule;
                $matchedLength = strlen($exportPath);
            }
        }
    }
    
    if ($matchedRule === null) {
        return $isSelf; // Undeclared paths: self=OK, cross=denied
    }
    
    // Self-access with self_restrict check
    if ($isSelf) {
        return !$matchedRule['self_restrict'];
    }
    
    // Cross-access: check access level
    return match ($matchedRule['access']) {
        'public'     => true,
        'restricted' => in_array($distCode, $matchedRule['allow'], true),
        'private'    => false,
        default      => false,
    };
}
```

#### 12.4.2 Extend Distributor

```php
// src/library/Razy/Distributor.php — add method

/**
 * Aggregate data exports from all loaded modules.
 *
 * @return array<string, array<string, array{access: string, allow: string[], self_restrict: bool}>>
 *         Keyed by module code → sub-path → export rule
 */
public function getDataExports(): array
{
    $exports = [];
    foreach ($this->registry->getModules() as $module) {
        $moduleInfo = $module->getModuleInfo();
        $moduleExports = $moduleInfo->getDataExports();
        if (!empty($moduleExports)) {
            $exports[$moduleInfo->getCode()] = $moduleExports;
        }
    }
    return $exports;
}
```

#### 12.4.3 CaddyfileCompiler changes

```php
// CaddyfileCompiler::compileDataMappingHandlers() — refactor

private function compileDataMappingHandlers(
    mixed       $siteBlock,
    Distributor $distributor,
    string      $domain,
    string      $code,
    string      $routePath,
    string      $documentRoot,
): void {
    $dataMapping = $distributor->getDataMapping();
    $counter = 0;
    
    // ── Self-dist data (no data_mapping, or default '/') ──
    if (!count($dataMapping) || !isset($dataMapping['/'])) {
        $this->compileSelfDistDataRules($siteBlock, $distributor, $domain, $code, $routePath, $documentRoot, $counter);
    }
    
    // ── Cross-dist data (from data_mapping) ──
    foreach ($dataMapping as $path => $site) {
        $mappingRoutePath = ($path === '/')
            ? $routePath
            : rtrim($routePath . trim($path, '/'), '/') . '/';
        
        $isSelf = ($site['domain'] === $domain && $site['dist'] === $code);
        
        // Load the target distributor to check data_exports
        try {
            $targetDist = new Distributor($site['dist'], '*');
            $targetDist->initialize(true);
            $targetExports = $targetDist->getDataExports();
        } catch (Throwable) {
            // Cannot load target → fallback to legacy full-dir mount
            $dataPath = rtrim($documentRoot, '/') . '/data/' . $site['domain'] . '-' . $site['dist'];
            $dataId = preg_replace('/[^a-zA-Z0-9_]/', '_', $code . '_' . $mappingRoutePath . '_' . $counter++);
            $siteBlock->newBlock('data_mapping')->assign([
                'dist_code' => $code,
                'data_id'   => $dataId,
                'route_path' => $mappingRoutePath,
                'data_path'  => $dataPath,
            ]);
            continue;
        }
        
        if (empty($targetExports)) {
            // No data_exports declared → legacy behavior (full dir)
            // only if self-access or backward-compat mode
            if ($isSelf) {
                $dataPath = rtrim($documentRoot, '/') . '/data/' . $site['domain'] . '-' . $site['dist'];
                $dataId = preg_replace('/[^a-zA-Z0-9_]/', '_', $code . '_' . $mappingRoutePath . '_' . $counter++);
                $siteBlock->newBlock('data_mapping')->assign([
                    'dist_code' => $code,
                    'data_id'   => $dataId,
                    'route_path' => $mappingRoutePath,
                    'data_path'  => $dataPath,
                ]);
            }
            continue;
        }
        
        // Emit per-subdir matchers based on data_exports
        foreach ($targetExports as $moduleCode => $moduleExports) {
            foreach ($moduleExports as $subPath => $rule) {
                $moduleInfo = $targetDist->getRegistry()->getModule($moduleCode)?->getModuleInfo();
                if (!$moduleInfo) continue;
                
                if (!$moduleInfo->isDataExportAllowed($subPath, $code, $isSelf)) {
                    continue; // Access denied → skip this matcher
                }
                
                $safeModuleCode = preg_replace('/[^a-zA-Z0-9_]/', '_', $moduleCode);
                $safeSubPath = preg_replace('/[^a-zA-Z0-9_]/', '_', $subPath);
                $dataId = preg_replace('/[^a-zA-Z0-9_]/', '_',
                    $code . '_' . $safeModuleCode . '_' . $safeSubPath . '_' . $counter++);
                
                $dataBasePath = rtrim($documentRoot, '/') . '/data/' . $site['domain'] . '-' . $site['dist'];
                
                $siteBlock->newBlock('data_export')->assign([
                    'module_alias' => $moduleInfo->getAlias(),
                    'module_code'  => str_replace('/', '_', $moduleCode),
                    'sub_path'     => $subPath,
                    'access_level' => $rule['access'],
                    'data_id'      => $dataId,
                    'route_path'   => $mappingRoutePath,
                    'data_path'    => $dataBasePath,
                ]);
            }
        }
    }
}
```

#### 12.4.4 Effort estimate (hours)

| Item | Change | Hours |
|------|------|------|
| `ModuleInfo::parseDataExports()` | Add `data_exports` parsing + `isDataExportAllowed()` | 2h |
| `Distributor::getDataExports()` | Aggregate all module exports | 1h |
| `CaddyfileCompiler` refactor | Generate fine-grained data matchers | 4h |
| `RewriteRuleCompiler` changes | htaccess version | 2h |
| `caddyfile.tpl` + `htaccess.tpl` | New `data_export` block | 1h |
| Unit tests | parseDataExports, isDataExportAllowed, compiler tests | 4h |
| Integration tests | End-to-end rewrite verification (Caddy + Apache) | 2h |
| **Total** | | **~16h** |

### 12.5 Webassets Under Load Balancing — analysis

#### 12.5.1 LB architecture recap

```
  ┌─ Front-Door (Caddy) ──────────────────────────────────┐
  │                                                        │
  │  tenant-a.example.com {                                │
  │      reverse_proxy tenant-a:8080 {                     │
  │          lb_policy round_robin                         │
  │          # Docker DNS resolves tenant-a to              │
  │          # Replica 1  (10.0.1.10:8080)                 │
  │          # Replica 2  (10.0.1.11:8080)                 │
  │          # Replica 3  (10.0.1.12:8080)                 │
  │      }                                                 │
  │  }                                                     │
  │                                                        │
  └────────────────────────────────────────────────────────┘
             │            │            │
             ▼            ▼            ▼
       ┌──────────┐ ┌──────────┐ ┌──────────┐
       │ Replica 1│ │ Replica 2│ │ Replica 3│
       │  Image:  │ │  Image:  │ │  Image:  │
       │  razy-   │ │  razy-   │ │  razy-   │
       │  tenant: │ │  tenant: │ │  tenant: │
       │  1.0.1   │ │  1.0.1   │ │  1.0.1   │
       └──────────┘ └──────────┘ └──────────┘
        Same image    Same image   Same image
```

#### 12.5.2 Key fact: image consistency guarantee

**Webassets are inside the Docker image → all replicas are identical**

```
  Replica 1:
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/css/style.css  ← ✅
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/js/app.js      ← ✅
  
  Replica 2:
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/css/style.css  ← ✅ identical
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/js/app.js      ← ✅ identical
  
  Replica 3:
    (same as above) ← ✅
```

**Why:**
1. All replicas come from the same Docker image tag (`razy-tenant:1.0.1-beta`)
2. Module code (including webassets) is baked into the image at **build-time**
3. Image layers are content-addressable (SHA256) — identical
4. The container rootfs is read-only — cannot be modified at runtime

#### 12.5.3 Rewrite rule consistency

Each replica's Caddy (generated by CaddyfileCompiler) has the same `@webasset_*` matchers:

```
  ┌─ Caddyfile inside each replica (IDENTICAL) ──────────────┐
  │                                                           │
  │  :8080 {                                                  │
  │      root * /app/site                                     │
  │                                                           │
  │      # Webassets: Shop (identical across all replicas)     │
  │      @webasset_Shop_ path /webassets/Shop/*               │
  │      handle @webasset_Shop_ {                             │
  │          uri strip_prefix /webassets/Shop                  │
  │          root * sites/main/modules/vendor/shop             │
  │          file_server                                       │
  │      }                                                    │
  │                                                           │
  │      php_server {                                         │
  │          worker /app/site/index.php                       │
  │      }                                                    │
  │  }                                                        │
  │                                                           │
  └───────────────────────────────────────────────────────────┘
```

**∴ No matter which replica the load balancer routes a webasset request to, it will respond correctly.**

#### 12.5.4 Why webassets under LB are a “non-issue” (Option A)

| Dimension | Status | Reason |
|------|------|------|
| **File consistency** | ✅ Guaranteed | Same image → same webasset content |
| **Rewrite consistency** | ✅ Guaranteed | CaddyfileCompiler output is generated at build-time → baked into the image |
| **URL consistency** | ✅ Guaranteed | `Controller::getAssetPath()` returns a versioned URL → version comes from `package.php` → baked into the image |
| **Cache consistency** | ✅ Guaranteed | Versioned URL (`/webassets/Shop/1.0.0/...`) → ETag/Last-Modified identical across replicas |
| **LB sticky session** | Not needed | File content is identical across replicas → no session affinity needed |

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   Under Option A (Proxy-Through):                                 │
  │                                                                 │
  │   Webassets + LB = non-issue                                     │
  │                                                                 │
  │   • All replicas share the same image → same rewrite rules → same files │
  │   • Front-door only does reverse_proxy → no need to know file details  │
  │   • Round-robin distribution → every replica can respond identically  │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

### 12.6 Data files under load balancing — the real challenge

**Webassets are fine under LB, but data files are not.**

Data files are generated at runtime (uploads, reports, cache) and live on volumes. If replicas do not share volumes, you get inconsistency:

```
  ⚠ Scenario: user uploads an image → handled by Replica 1 → stored in Replica 1's local volume
  
  Replica 1: /app/data/tenant-a/shop/uploads/photo.jpg  ← ✅ exists
  Replica 2: /app/data/tenant-a/shop/uploads/photo.jpg  ← ❌ missing!
  Replica 3: /app/data/tenant-a/shop/uploads/photo.jpg  ← ❌ missing!
  
  → Next request is routed to Replica 2 → GET /data/shop/uploads/photo.jpg → 404!
```

#### 12.6.1 Solution option: shared volume

```
  All replicas mount the same Docker named volume or NFS:
  
  services:
    tenant-a:
      image: razy-tenant:1.0.1-beta
      deploy:
        replicas: 3
      volumes:
        - tenant_a_data:/app/data/tenant-a    # ← shared!
  
  volumes:
    tenant_a_data:
      driver: local        # Docker: all replicas on the same host → shared
      # Cross-host: use NFS or distributed storage
```

    **Cross-host options (Docker Swarm / K8s):**

    | Option | Suitable scale | Latency | Consistency |
|------|---------|-------|--------|
    | **NFS** | ≤50 tenants | ~1-5ms | Strong (sync writes) |
    | **GlusterFS** | ≤200 tenants | ~2-10ms | Eventual (sync optional) |
    | **Ceph (RBD/CephFS)** | >200 tenants | ~1-3ms | Strong |
    | **EFS (AWS)** | Any | ~5-10ms | Strong |
| **Longhorn (K8s)** | ≤100 tenants | ~1-3ms | Strong (ReadWriteMany) |

    #### 12.6.2 Docker Compose (single host) — no issue

    On a single-host Docker Compose deployment, multiple replicas mounting the same named volume are fully shared:

```yaml
services:
  tenant-a:
    image: razy-tenant:1.0.1-beta
    deploy:
      replicas: 3
    read_only: true
    volumes:
      - tenant_a_data:/app/data/tenant-a     # ← shared across replicas
    tmpfs:
      - /tmp:size=64M

volumes:
  tenant_a_data:     # Docker engine auto-shares on same host
```

```
  Replica 1 writes: /app/data/tenant-a/shop/uploads/photo.jpg
      ↓ (same volume)
  Replica 2 reads:  /app/data/tenant-a/shop/uploads/photo.jpg  ← ✅
  Replica 3 reads:  /app/data/tenant-a/shop/uploads/photo.jpg  ← ✅
```

#### 12.6.3 Kubernetes (Multi-Host) — ReadWriteMany PVC

```yaml
# K8s PersistentVolumeClaim with ReadWriteMany
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: tenant-a-data
spec:
  accessModes:
    - ReadWriteMany          # ← multiple pods can read/write concurrently
  storageClassName: nfs-csi  # or ceph-csi, efs-csi
  resources:
    requests:
      storage: 10Gi

---
# Deployment with shared PVC
apiVersion: apps/v1
kind: Deployment
metadata:
  name: tenant-a
spec:
  replicas: 3
  template:
    spec:
      containers:
        - name: frankenphp
          image: razy-tenant:1.0.1-beta
          securityContext:
            readOnlyRootFilesystem: true
          volumeMounts:
            - name: data
              mountPath: /app/data/tenant-a
            - name: tmp
              mountPath: /tmp
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: tenant-a-data
        - name: tmp
          emptyDir:
            sizeLimit: 64Mi
```

### 12.7 Rewrite rule generation under LB — practical guidance

#### 12.7.1 Caddy front-door configuration (Option A — recommended)

**Front-door does not need to know module/webasset details** — it only does domain → upstream mapping:

```caddyfile
# ── Front-Door Caddyfile (SIMPLE) ──

tenant-a.example.com {
    reverse_proxy {
        to tenant-a:8080      # Docker DNS → round-robin across replicas
        lb_policy round_robin
        health_uri /health
        health_interval 5s

        # Webasset requests also go through reverse_proxy → handled by in-replica file_server
        # Data requests also go through reverse_proxy → handled by in-replica file_server (shared volume)
        # PHP requests go through reverse_proxy → handled by in-replica FrankenPHP worker
        
        # ∴ Front-door needs no @webasset / @data matchers
    }
}

tenant-b.example.com {
    reverse_proxy {
        to tenant-b:8080
        lb_policy round_robin
        health_uri /health
    }
}
```

**∴ The front-door Caddyfile stays minimal — one `reverse_proxy` block per domain is enough.**

#### 12.7.2 Tenant container internal Caddyfile (generated by CaddyfileCompiler)

```caddyfile
# ── Inside tenant container (same for each replica) ──

:8080 {
    root * /app/site

    # ── Webasset file_server (from image — immutable) ──
    @webasset_Shop_ path /webassets/Shop/*
    handle @webasset_Shop_ {
        uri strip_prefix /webassets/Shop
        root * sites/main/modules/vendor/shop
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=31536000, immutable"
    }

    # ── Data file_server (from shared volume — runtime) ──
    
    # Self-dist data: full directory (backward compatible)
    @data_main__0 path /data/*
    handle @data_main__0 {
        uri strip_prefix /data
        root * /app/data/example.com-main
        file_server
        header Cache-Control "public, max-age=3600"
    }
    
    # Cross-dist data: module-level exports only (§12.3)
    # (generated from data_mapping + data_exports intersection)
    @data_main_blog_shop_images path /blog/data/shop/uploads/images/*
    handle @data_main_blog_shop_images {
        uri strip_prefix /blog/data/shop/uploads/images
        root * /app/data/blog.example.com-blog/shop/uploads/images
        file_server
    }

    # ── Shared modules ──
    @shared path /*/shared/*
    handle @shared {
        root * /app/site
        file_server
    }

    # ── PHP worker (FrankenPHP) ──
    php_server {
        worker /app/site/index.php
    }
}
```

#### 12.7.3 Equivalent htaccess configuration (Apache, non-LB scenario)

In Apache environments, LB is typically not used (php-fpm or mod_php). The rewrite idea is the same as Caddy, but expressed via `RewriteRule` + `RewriteCond`:

```apache
# Cross-dist data export (module-level)
RewriteCond %{ENV:RAZY_DOMAIN} =example.com
RewriteRule ^blog/data/shop/uploads/images/(.+)$ %{ENV:BASE}data/blog.example.com-blog/shop/uploads/images/$1 [L]

# Cross-dist: BLOCKED paths → no RewriteRule generated → falls through to 404 or PHP
# (shop/cache, shop/reports for non-allowed dists → simply not emitted)
```

### 12.8 Rolling update — mixed-version period

**Special case under LB:** During a rolling update, some replicas run the new image while others still run the old image.

```
  ┌────────────────────────────────────────────────────┐
  │ Rolling Update: razy-tenant:1.0.1 → 1.0.2         │
  │                                                    │
  │ Time T1:                                           │
  │   Replica 1: image 1.0.2 ← new (updated)           │
  │   Replica 2: image 1.0.1 ← old (pending update)    │
  │   Replica 3: image 1.0.1 ← old (pending update)    │
  │                                                    │
  │ Webasset URL:                                      │
  │   New: /webassets/Shop/1.0.2/css/style.css         │
  │   Old: /webassets/Shop/1.0.1/css/style.css         │
  └────────────────────────────────────────────────────┘
```

**Do versioned URLs automatically solve the mixed-version period?**

```
    1. User A visits at time T1 → hits Replica 1 (new)
      → PHP generates HTML containing: /webassets/Shop/1.0.2/css/style.css
      → Browser requests /webassets/Shop/1.0.2/css/style.css
      → LB may route to Replica 2 (old)
      → Replica 2 file_server matches @webasset_Shop_
      → But after stripping the prefix, it looks for sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css
      → ❌ Replica 2 only has 1.0.1 → 404??
  
    Wait — let's check the rewrite path generated by CaddyfileCompiler:
```

**Corrected analysis — CaddyfileCompiler container_path:**

```
  CaddyfileCompiler uses ModuleInfo::getContainerPath(true) as the root:
  
  containerPath = sites/main/modules/vendor/shop
  (does not include the version — the version comes from the URL)
  
  Caddyfile:
    @webasset_Shop_ path /webassets/Shop/*
    handle @webasset_Shop_ {
        uri strip_prefix /webassets/Shop
        root * sites/main/modules/vendor/shop   ← container path (no version)
        file_server
    }
  
  URL: /webassets/Shop/1.0.0/css/style.css
  Strip prefix /webassets/Shop → /1.0.0/css/style.css
  Full path: sites/main/modules/vendor/shop/1.0.0/webassets/css/style.css
```

**Wait —** `getContainerPath()` returns the container path (vendor/shop), then appends the version subdirectory (1.0.0), and finally `webassets/file.css`.

The htaccess template makes this clearer:
```
RewriteRule ^{route_path}webassets/{mapping}/(.+?)/(.+)$ {dist_path} [END]
```
Where `dist_path = containerPathRel + /$1/webassets/$2`, `$1` captures the version, and `$2` captures the file path.

**∴ Rolling update analysis:**

```
  Replica 1 (image 1.0.2):
    filesystem: sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css ✅
    filesystem: sites/main/modules/vendor/shop/1.0.1/webassets/css/style.css ❌ (missing)
  
  Replica 2 (image 1.0.1):
    filesystem: sites/main/modules/vendor/shop/1.0.1/webassets/css/style.css ✅
    filesystem: sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css ❌ (missing)
  
  User A: HTML from Replica 1 → URL /webassets/Shop/1.0.2/...
          → LB routes to Replica 2 → looks for 1.0.2 → 404 ⚠
  
  User B: HTML from Replica 2 → URL /webassets/Shop/1.0.1/...
          → LB routes to Replica 1 → looks for 1.0.1 → 404 ⚠
```

**Rolling update is the only scenario that can break!**

#### 12.8.1 Solution options

**Strategy 1: Blue-Green Deployment (Recommended)**

```
  Avoid rolling updates → use blue-green instead:
  
  1. Start all new replicas (green) alongside old replicas (blue)
  2. After health checks pass, switch the LB to green atomically
  3. After validation, tear down blue
  
  → No mixed-version period → no 404
```

**Strategy 2: Versioned upstream (multi-version coexistence)**

```caddyfile
# Front-door uses two upstream pools (during rolling update)
tenant-a.example.com {
    # Version-aware routing:
    # 1.0.2 assets → only to new replicas
    @v102_assets path /webassets/*/1.0.2/*
    handle @v102_assets {
        reverse_proxy tenant-a-v102:8080
    }
    
    # 1.0.1 assets → only to old replicas
    @v101_assets path /webassets/*/1.0.1/*
    handle @v101_assets {
        reverse_proxy tenant-a-v101:8080
    }
    
    # All other requests → latest
    reverse_proxy tenant-a:8080
}
```

**High complexity — only meaningful at larger K8s scale.**

**Strategy 3: CDN cache warming (most practical)**

```
  Before rolling update:
  1. CDN already cached 1.0.1 webassets (immutable, 1yr max-age)
  2. Deploy 1.0.2 → new replicas come online
  3. New HTML references 1.0.2 → CDN cache not yet present
  4. CDN → front-door → any replica
     → If routed to an old replica → 404
     → CDN does not cache 404
     → Browser retries (or CDN retries another upstream)
  5. Routed to a new replica → 200 → CDN caches
  6. Old replicas finish updating → everything becomes consistent
  
  Impact: during a few seconds of rolling update, a small number of webasset requests may 404
  → Browser may still use cached versions (if previously visited)
  → New users may see brief unstyled content
```

**Strategy 4: maxSurge = 100% (recommended, simplest)**

```yaml
# K8s Deployment strategy
spec:
  strategy:
    rollingUpdate:
      maxSurge: 100%        # start all new pods first
      maxUnavailable: 0     # then stop old pods
  
  # Effect: equivalent to blue-green — new/old pods briefly coexist
  # After new pods are ready, LB gradually shifts traffic
  # With readiness probes → ensure new pods are fully ready before receiving traffic
```

### 12.9 Full traffic path diagram (LB + Data Export)

```
═══════════════════════════════════════════════════════════════════════════════

  Webasset Request Under LB:

    Browser
      → CDN (cache: immutable, 1yr)
        → Caddy Front-Door
          → reverse_proxy (round_robin)
            → Replica N (any):8080
              → Internal Caddy @webasset_Shop_ matcher
                → uri strip_prefix /webassets/Shop
                → file_server from image (read-only)
                → 200 OK + CSS/JS/image
  
    ✅ All replicas share the same image → same webassets → LB transparent
    ✅ Versioned URLs → CDN cache hit rate approaches 100%
    ⚠  During rolling update, use blue-green or maxSurge=100%

═══════════════════════════════════════════════════════════════════════════════

  Self-Dist Data Access (same dist frontend):

    Browser
      → CDN (cache: short, 1hr)
        → Caddy Front-Door
          → reverse_proxy (round_robin)
            → Replica N (any):8080
              → Internal Caddy @data_main__0 matcher
                → uri strip_prefix /data
                → file_server from shared volume
                → 200 OK + uploaded file
  
    ✅ Shared volume → all replicas see the same data → LB transparent
    ✅ Self-dist data: full-directory file_server (keep existing behavior)

═══════════════════════════════════════════════════════════════════════════════

  Cross-Dist Data Access (Module-Controlled):

    Browser
      → CDN
        → Caddy Front-Door
          → reverse_proxy → Replica N:8080
            → Internal Caddy (§12.3 new rules)
              → @data_blog_shop_images matcher
                → module 'shop' data_exports: 'uploads/images' = public ✅
                → file_server from target dist's shared volume
                → 200 OK

    ✅ Module-level granularity control
    ✅ Unmatched subdirs → no matcher → falls through to php_server → Razy 404

═══════════════════════════════════════════════════════════════════════════════

  Cross-Dist Data BLOCKED:

    Browser
      → GET /data/shop/cache/temp.dat
        → No @data matcher generated (private in data_exports)
          → Falls through to php_server
            → Razy RouteDispatcher → no matching route → 404

    ✅ Secure by default — undeclared = private
    ✅ Decided at build-time → no runtime overhead

═══════════════════════════════════════════════════════════════════════════════
```

### 12.10 Backward compatibility

| Scenario | Behavior |
|------|------|
| Module has **no** `data_exports` + self-dist | ✅ Full-directory file_server (existing behavior unchanged) |
| Module has **no** `data_exports` + cross-dist | ⚠ Deny by default (secure by default) — **behavior change** |
| Module **has** `data_exports` + self-dist | ✅ Full-directory (unless `self_restrict: true`) |
| Module **has** `data_exports` + cross-dist | ✅ Only declared subdirectories are allowed |

**Migration Path:**

For existing deployments that already use `data_mapping`, after upgrading, cross-dist data will be blocked by default. Add `data_exports` declarations in `package.php` for the modules being accessed.

```
  Upgrade steps:
  1. Identify which modules' data is accessed by other dists via data_mapping
  2. Add data_exports to those modules' package.php
  3. Re-run php Razy.phar rewrite (--caddy or --htaccess)
  4. Verify cross-dist data access works as expected
```

You can set `RAZY_DATA_EXPORT_LEGACY=true` as an environment variable to keep legacy behavior during the transition (full-directory open), and log warnings to remind you to migrate.

### 12.11 Option Comparison — Overall View

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                  Problem ① Data Access                                 │
  │                                                                         │
  │  Before (§8-§11): data_mapping is coarse-grained — whole directory open │
  │                 to all consumers                                        │
  │  After  (§12):    data_exports is fine-grained — module controls sub-dir│
  │                 + ACL                                                   │
  │                                                                         │
  │  Change effort: ~16h                                                    │
  │  Security improvement: ★★★★ (from zero control to 3 levels:             │
  │    public/restricted/private)                                           │
  │  Backward compatible: ⚠ Cross-dist default changes open → closed        │
  │    (migration required)                                                 │
  ├─────────────────────────────────────────────────────────────────────────┤
  │                  Problem ② Webassets Under LB                           │
  │                                                                         │
  │  Conclusion: Under Option A (Proxy-Through), webassets under LB         │
  │  is a non-issue                                                         │
  │                                                                         │
  │  • Same image across replicas → same webassets → same Caddyfile         │
  │  • Front-door only does reverse_proxy → no module-level knowledge needed│
  │  • Only risk: rolling update mixed-version period → blue-green or       │
  │    maxSurge=100%                                                       │
  │                                                                         │
  │  Data files LB:                                                         │
  │  • Shared volume (Docker named volume / NFS / CephFS / EFS)             │
  │  • All replicas see the same data → LB transparent                       │
  │                                                                         │
  │  Change effort: 0h (Option A — existing architecture already supports it)│
  │         +4h (front-door Caddyfile config + health check)                 │
  └─────────────────────────────────────────────────────────────────────────┘
```

### 12.12 Key Insight (Key Insight)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   Data Access:                                                  │
  │   • Existing data_mapping is a consumer-side mount → no         │
  │     producer-side control → security gap                        │
  │   • Add package.php data_exports = producer-side ACL             │
  │   • 3-level control: public / restricted / private             │
  │   • Build-time writes Caddyfile → zero runtime overhead        │
  │   • CaddyfileCompiler granularity: per-module, per-subdir rule │
  │                                                                 │
  │   Webassets Under LB:                                           │
  │   • Image consistency → all replicas have identical webassets  │
  │   • Caddyfile consistency → identical rewrite rules everywhere │
  │   • ∴ LB round-robin is transparent for webassets — non-issue   │
  │   • Only watch-out: rolling update → use a blue-green strategy  │
  │                                                                 │
  │   Data Files Under LB:                                          │
  │   • Need shared volume (docker named vol / NFS / CephFS)       │
  │   • After sharing → all replicas see same data → LB transparent │
  │   • Docker Compose (single host): named volumes are shared      │
  │   • K8s (multi-host): ReadWriteMany PVC (NFS/CephFS/EFS)        │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 13. Webasset Pack — Build-Time Asset Extraction & External Storage

> **Premise:** §11 Option C introduced the Build-Time Asset Extraction concept; §12 confirmed webassets can work under LB via image consistency. This section designs a complete **Webasset Pack** mechanism — when the tenant packaging command runs, webassets are extracted into an independent pack; when Core joins a tenant, it finds the pack and unpacks it to the target storage path (local / S3 / CDN origin), so `Controller::getAssetPath()` points to the correct external URL.

### 13.1 Motivation and Problem Recap

**Conclusions from §11–§12:**

| Scenario | Current solution | Limitation |
|------|---------|------|
| Single host Docker (≤20 tenants) | Option A (Proxy-Through) — zero changes | Static traffic consumes PHP container resources |
| LB + multi-replica | Option A still works (image consistency) | Every replica must serve static requests |
| High traffic (>100 tenants) | Option F (S3 + CDN) | Requires an additional publish pipeline |
| Rolling update | Blue-green deployment | Risk during the mixed-version period |

**Goal of Webasset Pack:** Insert a webasset extraction step naturally into the existing `pack` (→ `.phar`) + `sync` (→ install) lifecycle, so when Core joins a tenant it automatically deploys webassets to external storage, fully removing static traffic dependency from the PHP container.

### 13.2 Existing CLI Pipeline Analysis

```
  Existing flow:
  
  ① pack   : php Razy.phar pack vendor/shop 1.0.0
              → packages/vendor/shop/1.0.0.phar        (module code as .phar)
              → packages/vendor/shop/1.0.0-assets/     (webassets copy, already supported)
              → packages/vendor/shop/manifest.json
              → packages/vendor/shop/latest.json
  
  ② publish: php Razy.phar publish --push
              → GitHub Release v1.0.0 + 1.0.0.phar asset
              → index.json (master repository index)
  
  ③ sync   : php Razy.phar sync main
              → RepositoryManager → download .phar from GitHub Release
              → Phar::extractTo() → sites/main/vendor/shop/ or shared/module/vendor/shop/
  
  ④ rewrite: php Razy.phar rewrite --caddy
              → CaddyfileCompiler → @webasset_Shop_ matcher → file_server from module dir
```

**Key observations:**

```
  ┌─────────────────────────────────────────────────────────────────────┐
  │                                                                     │
  │  pack.inc.php already extracts webassets! (line 224-234)            │
  │                                                                     │
  │    $assetsOutputPath = PathUtil::append($outputPath, $version . '-assets')
  │    xcopy($assetsPath, $assetsOutputPath)                            │
  │                                                                     │
  │  → packages/vendor/shop/1.0.0-assets/                              │
  │    └── css/style.css                                                │
  │    └── js/app.js                                                    │
  │    └── images/logo.png                                              │
  │                                                                     │
  │  But publish + sync completely ignore these asset files!            │
  │  → Assets stay under packages/ and are never deployed externally    │
  │                                                                     │
  └─────────────────────────────────────────────────────────────────────┘
```

### 13.3 Design Overview — Webasset Pack Lifecycle

```
═══════════════════════════════════════════════════════════════════════════

  PHASE 1: Pack (run by developer)
  
  php Razy.phar pack vendor/shop 1.0.0
      │
      ├─→ 1.0.0.phar           (module code, existing)
      ├─→ 1.0.0-assets/        (webassets directory, existing but unused)
      ├─→ 1.0.0-assets.tar.gz  (NEW: webasset pack — compressed archive)
      ├─→ manifest.json         (existing, add assets_checksum field)
      └─→ latest.json           (existing)

═══════════════════════════════════════════════════════════════════════════

  PHASE 2: Publish (push to repository)
  
  php Razy.phar publish --push
      │
      ├─→ GitHub Release: v1.0.0
      │     ├── 1.0.0.phar                 (module archive)
      │     └── 1.0.0-assets.tar.gz        (NEW: webasset pack)
      │
      └─→ manifest.json (updated):
            {
              "releases": {
                "1.0.0": {
                  "file": "1.0.0.phar",
                  "assets_file": "1.0.0-assets.tar.gz",    ← NEW
                  "assets_checksum": "sha256:abc123...",    ← NEW
                  ...
                }
              }
            }

═══════════════════════════════════════════════════════════════════════════

  PHASE 3: Sync + Deploy (when Core joins a tenant)
  
  php Razy.phar sync main
      │
      ├─→ Download 1.0.0.phar → extract to sites/main/vendor/shop/  (existing)
      │
      └─→ Download 1.0.0-assets.tar.gz → extract to ASSET_STORAGE   (NEW)
            │
            │   PACK_ID = 1.0.0-a1b2c3d4 (read from manifest)
            │
            ├── Local:  /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
            ├── S3:     s3://razy-assets/tenant-a/Shop/1.0.0-a1b2c3d4/css/style.css
            └── CDN:    https://cdn.example.com/tenant-a/Shop/1.0.0-a1b2c3d4/css/style.css
            
            + Write .asset_pack_id file to module dir (read by runtime)

═══════════════════════════════════════════════════════════════════════════

  PHASE 4: Runtime (module gets asset URL)
  
  Controller::getAssetPath()   — original (self-serve):
      → https://example.com/webassets/Shop/1.0.0/
  
    Controller::getAssetUrl()    — new (external storage + PACK_ID):
      → Read .asset_pack_id → 1.0.0-a1b2c3d4
      → Check RAZY_ASSET_BASE_URL env var
      → If set: https://cdn.example.com/tenant-a/Shop/1.0.0-a1b2c3d4/
      → If not: fallback to getAssetPath() (self-serve, backward compatible)

═══════════════════════════════════════════════════════════════════════════

  PHASE 5: Purge (cleanup after transition)
  
  php Razy.phar asset:purge --keep=1
      │
      ├─→ List all PACK_ID per alias:
      │     Shop: 1.0.0-a1b2c3d4 (OLD), 1.1.0-99aabb00 (CURRENT)
      │
      ├─→ Keep newest 1 copy: 1.1.0-99aabb00
      │
      └─→ Delete: 1.0.0-a1b2c3d4 (free storage)

═══════════════════════════════════════════════════════════════════════════
```

### 13.4 Asset Pack Format Design

#### 13.4.1 Compression Format: `.tar.gz`

**Why not .phar or .zip:**

| Format | Advantages | Disadvantages |
|------|------|------|
| `.phar` | Native to PHP | Designed for PHP code; static files don't need execution; S3/CDN won't recognize it |
| `.zip` | Widely supported | PHP ZipArchive requires ext-zip; compression ratio is worse than gzip |
| **`.tar.gz`** | Native on Linux; supported by S3; easy to extract | Windows needs `tar` (PHP 8+ includes `PharData`) |

**Choice:** `.tar.gz` — handled via PHP built-in `PharData`, no extra extension required.

#### 13.4.2 Pack Internal Structure (PACK_ID isolation)

```
  1.0.0-assets.tar.gz
  └── Shop/                              ← alias (not module code)
      └── 1.0.0-a1b2c3d4/               ← PACK_ID (version + content hash)
          ├── css/
          │   └── style.css
          ├── js/
          │   └── app.js
          └── images/
              └── logo.png
  
  After extraction (in ASSET_STORAGE):
  
  /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
  /app/assets/Shop/1.0.0-a1b2c3d4/js/app.js
  /app/assets/Shop/1.0.0-a1b2c3d4/images/logo.png
```

**Why use alias instead of module code:** `Controller::getAssetPath()` already uses alias as the URL segment (`/webassets/{alias}/{version}/`). Keeping it consistent → external storage path = URL path → zero mapping overhead.

**Why use PACK_ID instead of version in the path:** See §13.4.3 — hot-plug version conflict.

#### 13.4.3 PACK_ID — Hot-Plug Asset Version Isolation

**Problem scenario (FrankenPHP Worker Mode Hot-Plug):**

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                                                                         │
  │   Timeline:                                                            │
  │   ─────────────────────────────────────────────────────────────────     │
  │   T0          T1                T2              T3                      │
  │   │           │                 │               │                       │
  │   │  v1.0     │  hot-plug start │  transition   │  fully switched       │
  │   │  running  │  v1.1 deploy    │  v1.0 + v1.1  │  only v1.1            │
  │   │           │                 │  both running │                       │
  │   ─────────────────────────────────────────────────────────────────     │
  │                                                                         │
  │   T1: sync deploy v1.1 asset pack                                       │
  │                                                                         │
  │   ❌ If using {alias}/{version}/ path:                                  │
  │      v1.0 assets → /app/assets/Shop/1.0.0/css/style.css               │
  │      v1.1 assets → /app/assets/Shop/1.1.0/css/style.css               │
  │      → Different versions are fine... but what about a v1.0.0 hotfix    │
  │        (same version)?                                                 │
  │      → Overwrite! v1.0 workers read new CSS → inconsistency!           │
  │                                                                         │
  │   ❌ Same-version hotfix scenario:                                     │
  │      Developer fixes a CSS bug, re-pack v1.0.0 (no version bump)       │
  │      New v1.0.0 assets overwrite old v1.0.0 → old workers get new CSS  │
  │      → Browser cached old JS + receives new CSS → layout breaks        │
  │                                                                         │
  │   ✅ PACK_ID solution:                                                 │
  │      Every pack generates a unique PACK_ID = {version}-{content_hash_8}│
  │      Old: /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css               │
  │      New: /app/assets/Shop/1.0.0-e5f6g7h8/css/style.css               │
  │      → Both coexist! Zero conflict!                                    │
  │      → Old workers keep using a1b2c3d4, new workers use e5f6g7h8        │
  │      → After T3 fully switches: `asset:purge --keep=1` removes old pack│
  │                                                                         │
  └─────────────────────────────────────────────────────────────────────────┘
```

**PACK_ID format:**

```
  PACK_ID = {version}-{content_hash_8}
  
  where:
    version       = module version (e.g., 1.0.0, 1.1.0)
    content_hash  = first 8 chars of SHA256(tar.gz contents)
  
  examples:
    1.0.0-a1b2c3d4    ← initial pack
    1.0.0-e5f6g7h8    ← same-version hotfix (CSS changed → hash differs)
    1.1.0-99aabb00    ← new version
  
  properties:
    • Deterministic: same content → same PACK_ID (idempotent deploy)
    • Collision-free: different content → different PACK_ID (even same version)
    • Readable: version first → human-friendly
    • Sortable: version-first ordering → easier purge decisions
```

**PACK_ID lifecycle:**

```
  ┌─────────────────┐        ┌──────────────────┐        ┌────────────────┐
  │  pack (Build)   │───────→│  sync (Deploy)   │───────→│  Runtime       │
  │                 │        │                  │        │                │
    │  generate PACK_ID│       │  extract into    │        │  getAssetUrl() │
    │  write manifest │        │  {alias}/{packId}│        │  uses PACK_ID  │
    │  embed tar.gz   │        │  directory       │        │  to resolve URL│
  └─────────────────┘        └──────────────────┘        └────────────────┘
                                                                │
                     │ after transition
                                                                ▼
                                                         ┌────────────────┐
                                                         │ asset:purge    │
                                                         │                │
                   │ remove old IDs │
                   │ keep newest N  │
                                                         └────────────────┘
```

#### 13.4.4 manifest.json Extension

```json
{
    "module_code": "vendor/shop",
    "description": "E-commerce shop module",
    "author": "vendor",
    "latest": "1.0.0",
    "versions": ["1.0.0"],
    "updated": "2026-02-27 10:00:00",
    "releases": {
        "1.0.0": {
            "file": "1.0.0.phar",
            "size": 45678,
            "checksum": "sha256:aabbcc...",
            "created": "2026-02-27 10:00:00",
            "php_version": "8.3",
            "razy_version": "1.0.1-beta",
            
            "assets_file": "1.0.0-assets.tar.gz",
            "assets_size": 12345,
            "assets_checksum": "sha256:ddeeff...",
            "assets_alias": "Shop",
            "assets_pack_id": "1.0.0-a1b2c3d4"
        }
    }
}
```

### 13.5 Phase 1 Implementation: `pack` Command Extension

**Change: `pack.inc.php` — add `.tar.gz` packaging**

```php
// After existing xcopy of webassets (line ~234 of pack.inc.php)

// Create tar.gz pack for external deployment
$assetsPath = PathUtil::append($packagePath, 'webassets');
if ($includeAssets && is_dir($assetsPath) && count(glob($assetsPath . '/*')) > 0) {
    // Existing: copy to $version-assets/ directory (kept for backward compat)
    $assetsOutputPath = PathUtil::append($outputPath, $version . '-assets');
    // ... (existing xcopy code) ...
    
    // NEW: Create tar.gz asset pack with PACK_ID
    $alias = $packageConfig['alias'] ?? $className;
    $tarGzFile = PathUtil::append($outputPath, $version . '-assets.tar.gz');
    
    $this->writeLineLogging('[{@c:yellow}ASSET PACK{@reset}] Creating asset archive...', true);
    
    try {
        // Step 1: Build tar archive using PharData (no ext-zip needed)
        $tarFile = PathUtil::append($outputPath, $version . '-assets.tar');
        if (is_file($tarFile)) unlink($tarFile);
        if (is_file($tarGzFile)) unlink($tarGzFile);
        
        // Step 2: Compute content hash BEFORE creating archive
        //         (hash all asset file contents for deterministic PACK_ID)
        $contentHash = hash_init('sha256');
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($assetsPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $files = [];
        foreach ($iterator as $file) {
            $relativePath = substr($file->getPathname(), strlen($assetsPath) + 1);
            $relativePath = str_replace('\\', '/', $relativePath);
            $files[$relativePath] = $file->getPathname();
        }
        ksort($files); // deterministic order
        foreach ($files as $relPath => $absPath) {
            hash_update($contentHash, $relPath);
            hash_update_file($contentHash, $absPath);
        }
        $shortHash = substr(hash_final($contentHash), 0, 8);
        
        // Step 3: Generate PACK_ID = {version}-{content_hash_8}
        $packId = $version . '-' . $shortHash;
        $this->writeLineLogging('[{@c:cyan}PACK_ID{@reset}] ' . $packId, true);
        
        // Step 4: Build tar with PACK_ID directory structure
        $tar = new PharData($tarFile);
        foreach ($files as $relPath => $absPath) {
            // Internal structure: {alias}/{pack_id}/{relative_path}
            $tar->addFile($absPath, $alias . '/' . $packId . '/' . $relPath);
        }
        
        // Step 5: Compress to .tar.gz
        $tar->compress(Phar::GZ);
        unlink($tarFile); // Remove uncompressed .tar
        
        $assetPackSize = filesize($tarGzFile);
        $assetsChecksum = hash_file('sha256', $tarGzFile);
        
        $this->writeLineLogging('[{@c:green}✓{@reset}] Asset pack: ' . basename($tarGzFile)
            . ' (' . round($assetPackSize / 1024, 2) . ' KB)'
            . ' PACK_ID=' . $packId, true);
    } catch (Exception $e) {
        $this->writeLineLogging('[{@c:yellow}WARN{@reset}] Asset pack creation failed: '
            . $e->getMessage(), true);
        $assetsChecksum = null;
        $assetPackSize = 0;
        $packId = null;
    }
    
    // Step 6: Update manifest with asset pack info + PACK_ID
    if ($assetsChecksum && $packId) {
        $manifest['releases'][$version]['assets_file'] = $version . '-assets.tar.gz';
        $manifest['releases'][$version]['assets_size'] = $assetPackSize;
        $manifest['releases'][$version]['assets_checksum'] = 'sha256:' . $assetsChecksum;
        $manifest['releases'][$version]['assets_alias'] = $alias;
        $manifest['releases'][$version]['assets_pack_id'] = $packId;
    }
}
```

**Outputs:**
```
  packages/vendor/shop/
  ├── 1.0.0.phar              (module code archive)
  ├── 1.0.0-assets/            (extracted webassets — backward compat)
  ├── 1.0.0-assets.tar.gz      (NEW: deployable asset pack)
  ├── manifest.json             (updated with assets_file, assets_checksum)
  └── latest.json
```

### 13.6 Phase 2 Implementation: `publish` Command Extension

**Change: `publish.inc.php` — upload asset pack to GitHub Release**

```php
// In the existing GitHub Release upload loop:

// After uploading .phar asset, also upload assets.tar.gz
$assetTarGz = PathUtil::append($moduleDir, $version . '-assets.tar.gz');
if (is_file($assetTarGz)) {
    $this->writeLineLogging('    [{@c:yellow}UPLOAD{@reset}] ' . basename($assetTarGz), true);
    $this->uploadReleaseAsset($token, $repo, $releaseId, $assetTarGz);
    $this->writeLineLogging('    [{@c:green}✓{@reset}] Asset pack uploaded', true);
}
```

**GitHub Release result:**
```
  Release: v1.0.0 (vendor/shop)
  ├── 1.0.0.phar               (Module download)
  └── 1.0.0-assets.tar.gz      (Webasset pack download)
```

### 13.7 Phase 3 Implementation: `sync` Command Extension — Asset Deploy

**This is the most critical change.** After installing the module `.phar`, the `sync` command also downloads the asset pack and deploys it to the configured storage.

#### 13.7.1 Asset Storage Configuration

**Add to config.inc.php:**
```php
return [
    'install_path' => '/app/site',
    'timezone'     => 'Asia/Hong_Kong',
    
    // ── NEW: Asset storage configuration ──
    'asset_storage' => [
        'driver'   => 'local',                          // 'local' | 's3' | 'gcs'
        'path'     => '/app/assets',                    // Local filesystem path
        'base_url' => 'https://cdn.example.com/assets', // Public URL prefix
        // 's3' driver additional config:
        // 'bucket'   => 'razy-assets',
        // 'region'   => 'ap-east-1',
        // 'prefix'   => 'tenant-a',
    ],
];
```

**Environment variable overrides (Docker first):**
```yaml
environment:
  - RAZY_ASSET_DRIVER=s3
  - RAZY_ASSET_BUCKET=razy-assets
  - RAZY_ASSET_REGION=ap-east-1
  - RAZY_ASSET_PREFIX=tenant-a
  - RAZY_ASSET_BASE_URL=https://cdn.example.com/assets/tenant-a
```

#### 13.7.2 sync.inc.php Changes

```php
// After existing Phar::extractTo() (line ~310 of sync.inc.php):

// ── NEW: Deploy webasset pack to external storage ──

$assetStorageConfig = $GLOBALS['RAZY_CONFIG']['asset_storage'] ?? null;
if (!$assetStorageConfig) {
    // Check environment variables
    $driver = getenv('RAZY_ASSET_DRIVER');
    if ($driver) {
        $assetStorageConfig = [
            'driver'   => $driver,
            'bucket'   => getenv('RAZY_ASSET_BUCKET') ?: '',
            'region'   => getenv('RAZY_ASSET_REGION') ?: '',
            'prefix'   => getenv('RAZY_ASSET_PREFIX') ?: '',
            'path'     => getenv('RAZY_ASSET_PATH') ?: '/app/assets',
            'base_url' => getenv('RAZY_ASSET_BASE_URL') ?: '',
        ];
    }
}

if ($assetStorageConfig) {
    // Check if the module release has an asset pack
    $assetsFile = $moduleInfo['releases'][$targetVersion]['assets_file'] ?? null;
    $assetsChecksum = $moduleInfo['releases'][$targetVersion]['assets_checksum'] ?? null;
    $assetsAlias = $moduleInfo['releases'][$targetVersion]['assets_alias'] ?? null;
    
    if ($assetsFile && $assetsAlias) {
        $this->writeLineLogging('    [{@c:yellow}ASSETS{@reset}] Deploying webasset pack...', true);
        
        // Download asset pack
        $assetUrl = $repoManager->getDownloadUrl($moduleCode, $targetVersion, 'assets');
        if ($assetUrl) {
            $tempAssetPack = tempnam(sys_get_temp_dir(), 'razy_asset_');
            // ... (download logic similar to .phar download) ...
            
            // Verify checksum
            if ($assetsChecksum) {
                $actual = 'sha256:' . hash_file('sha256', $tempAssetPack);
                if ($actual !== $assetsChecksum) {
                    $this->writeLineLogging('    {@c:red}[ERROR] Asset checksum mismatch{@reset}', true);
                    @unlink($tempAssetPack);
                    continue;
                }
            }
            
            // Deploy based on storage driver — uses PACK_ID for isolation
            $packId = $moduleInfo['releases'][$targetVersion]['assets_pack_id'] ?? null;
            if (!$packId) {
                // Fallback: generate pack_id from checksum if not in manifest
                $packId = $targetVersion . '-' . substr(hash_file('sha256', $tempAssetPack), 0, 8);
            }
            
            $assetDeployer = AssetDeployer::create($assetStorageConfig);
            $assetDeployer->deploy($tempAssetPack, $assetsAlias, $packId);
            
            @unlink($tempAssetPack);
            $this->writeLineLogging('    [{@c:green}✓{@reset}] Assets deployed to '
                . $assetStorageConfig['driver'] . ' [PACK_ID=' . $packId . ']', true);
            
            // Write pack_id to module's installed metadata for runtime resolution
            $packIdFile = PathUtil::append($targetPath, '.asset_pack_id');
            file_put_contents($packIdFile, $packId);
        }
    }
}
```

#### 13.7.3 AssetDeployer Class Design

```php
// src/library/Razy/AssetDeployer.php

namespace Razy;

use PharData;
use Razy\Util\PathUtil;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Deploys extracted webasset packs to configured storage backends.
 *
 * Supported drivers:
 * - 'local': Extract to local filesystem path
 * - 's3': Upload to Amazon S3 / MinIO
 * - 'gcs': Upload to Google Cloud Storage
 */
class AssetDeployer
{
    private string $driver;
    private array $config;
    
    public static function create(array $config): self
    {
        return new self($config);
    }
    
    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'local';
        $this->config = $config;
    }
    
    /**
     * Deploy a .tar.gz asset pack to the configured storage.
     * Uses PACK_ID instead of version to ensure hot-plug isolation.
     *
     * @param string $tarGzPath  Path to the .tar.gz file
     * @param string $alias      Module alias (used as path prefix)
     * @param string $packId     PACK_ID (e.g., '1.0.0-a1b2c3d4')
     * @return bool True on success
     */
    public function deploy(string $tarGzPath, string $alias, string $packId): bool
    {
        return match ($this->driver) {
            'local' => $this->deployLocal($tarGzPath, $alias, $packId),
            's3'    => $this->deployS3($tarGzPath, $alias, $packId),
            'gcs'   => $this->deployGcs($tarGzPath, $alias, $packId),
            default => throw new \RuntimeException("Unknown asset driver: {$this->driver}"),
        };
    }
    
    /**
     * Local filesystem deployment: extract tar.gz to path.
     * PACK_ID ensures old + new versions coexist without overwrite.
     */
    private function deployLocal(string $tarGzPath, string $alias, string $packId): bool
    {
        $basePath = $this->config['path'] ?? '/app/assets';
        $targetDir = PathUtil::append($basePath, $alias, $packId);
        
        // Skip if already deployed (idempotent — same content = same PACK_ID)
        if (is_dir($targetDir) && count(glob($targetDir . '/*')) > 0) {
            return true; // Already exists with same content hash
        }
        
        // Create target directory
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        
        // Extract tar.gz — internal structure already contains {alias}/{pack_id}/
        $phar = new PharData($tarGzPath);
        $phar->extractTo($basePath, null, true);
        
        return is_dir($targetDir);
    }
    
    /**
     * S3/MinIO deployment: upload extracted files to bucket.
     */
    private function deployS3(string $tarGzPath, string $alias, string $packId): bool
    {
        $bucket = $this->config['bucket'] ?? '';
        $region = $this->config['region'] ?? 'us-east-1';
        $prefix = $this->config['prefix'] ?? '';
        
        // Extract to temp dir first
        $tempDir = sys_get_temp_dir() . '/razy_asset_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        $phar = new PharData($tarGzPath);
        $phar->extractTo($tempDir, null, true);
        
        // Upload each file to S3 — path includes PACK_ID
        $s3Prefix = trim($prefix . '/' . $alias . '/' . $packId, '/');
        $sourceDir = PathUtil::append($tempDir, $alias, $packId);
        
        if (is_dir($sourceDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                $relativePath = substr($file->getPathname(), strlen($sourceDir) + 1);
                $s3Key = $s3Prefix . '/' . str_replace('\\', '/', $relativePath);
                
                // Use AWS SDK or pre-signed PUT via HttpClient
                $this->s3PutObject($bucket, $region, $s3Key, $file->getPathname());
            }
        }
        
        // Cleanup temp dir
        $this->removeDir($tempDir);
        
        return true;
    }
    
    /**
     * Purge old asset packs, keeping the N most recent per alias.
     *
     * @param string $alias  Module alias to purge
     * @param int    $keep   Number of most recent packs to keep
     * @return array List of purged PACK_IDs
     */
    public function purge(string $alias, int $keep = 1): array
    {
        return match ($this->driver) {
            'local' => $this->purgeLocal($alias, $keep),
            's3'    => $this->purgeS3($alias, $keep),
            default => [],
        };
    }
    
    /**
     * List all deployed PACK_IDs for a given alias.
     */
    public function listPacks(string $alias): array
    {
        $basePath = $this->config['path'] ?? '/app/assets';
        $aliasDir = PathUtil::append($basePath, $alias);
        
        if (!is_dir($aliasDir)) return [];
        
        $packs = [];
        foreach (scandir($aliasDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir(PathUtil::append($aliasDir, $entry))) {
                $packs[] = $entry;
            }
        }
        
        // Sort by version (PACK_ID starts with version)
        usort($packs, 'version_compare');
        return $packs;
    }
    
    /**
     * Get the public URL for an asset.
     */
    public function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? '';
    }
}
```

### 13.8 Phase 4 Implementation: Runtime Asset URL Resolution

**Core change: add `Controller::getAssetUrl()`**

```php
// src/library/Razy/Controller.php — new

/**
 * Get the module's asset URL, preferring external storage if configured.
 * Uses PACK_ID for hot-plug safe asset resolution.
 *
 * Resolution order:
 * 1. RAZY_ASSET_BASE_URL env → external CDN/S3 URL + PACK_ID
 * 2. Fallback → getAssetPath() (self-serve via Caddy file_server)
 *
 * @return string The asset base URL with trailing slash
 */
final public function getAssetUrl(): string
{
    $baseUrl = getenv('RAZY_ASSET_BASE_URL');
    
    if ($baseUrl) {
        // External storage mode:
        // URL = {base_url}/{alias}/{pack_id}/
        $packId = $this->resolvePackId();
        return rtrim($baseUrl, '/') . '/'
            . $this->module->getModuleInfo()->getAlias() . '/'
            . $packId . '/';
    }
    
    // Self-serve fallback (backward compatible)
    return $this->getAssetPath();
}

/**
 * Resolve the PACK_ID for the currently loaded module.
 *
 * Resolution:
 * 1. Module's installed .asset_pack_id file (written by sync)
 * 2. Generate from version + content hash (fallback)
 * 3. Use version only (legacy fallback)
 */
private function resolvePackId(): string
{
    $moduleInfo = $this->module->getModuleInfo();
    
    // 1. From .asset_pack_id file (written by sync during deploy)
    $packIdFile = $moduleInfo->getContainerPath(false) . '/.asset_pack_id';
    if (is_file($packIdFile)) {
        $packId = trim(file_get_contents($packIdFile));
        if ($packId !== '') return $packId;
    }
    
    // 2. From package.php metadata (if pack_id was embedded)
    $packId = $moduleInfo->getMetadata('assets_pack_id');
    if ($packId) return $packId;
    
    // 3. Legacy fallback: use version (no content hash)
    return $moduleInfo->getVersion();
}
```

**Usage (Template/Controller):**

```php
// In module controller:
public function __onReady(): void
{
  // Old way (still works, self-serve):
    $cssUrl = $this->getAssetPath() . 'css/style.css';
    
  // New way (auto-select best URL + PACK_ID isolation):
    $cssUrl = $this->getAssetUrl() . 'css/style.css';
  // → env not set: https://example.com/webassets/Shop/1.0.0/css/style.css
  // → env set:     https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/css/style.css
  //   ↑ PACK_ID ensures each worker points to its own asset snapshot during hot-plug
}
```

### 13.9 End-to-End Flow Diagram — From Pack to Browser

```
═══════════════════════════════════════════════════════════════════════════════

  Developer (Development Phase):
  
  ① php Razy.phar pack vendor/shop 1.0.0
     │
     ├─→ 1.0.0.phar                (module code)
     ├─→ 1.0.0-assets.tar.gz       (webasset pack, NEW)
     └─→ manifest.json              (with assets_file + checksum)
  
  ② php Razy.phar publish --push
     │
     └─→ GitHub Release: v1.0.0
          ├── 1.0.0.phar
          └── 1.0.0-assets.tar.gz   (uploaded as release asset)

═══════════════════════════════════════════════════════════════════════════════

  Deployer / Core Orchestrator (Deployment Phase):
  
  ③ php Razy.phar sync main
     │
     ├─→ Download 1.0.0.phar → extract to sites/main/vendor/shop/
     │
     └─→ Download 1.0.0-assets.tar.gz
          │
          │   PACK_ID = 1.0.0-a1b2c3d4 (read from manifest)
          │   → write .asset_pack_id to module dir
          │
          ├── driver=local:
          │   → PharData::extractTo('/app/assets')
          │   → /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
          │
          ├── driver=s3:
          │   → Extract to temp → S3 PutObject
          │   → s3://razy-assets/Shop/1.0.0-a1b2c3d4/css/style.css
          │   → CDN: https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/css/style.css
          │
          └── driver=gcs:
              → Extract to temp → GCS upload
              → gs://razy-assets/Shop/1.0.0-a1b2c3d4/css/style.css

═══════════════════════════════════════════════════════════════════════════════

  Purge (after transition, run manually):
  
  ⑦ php Razy.phar asset:purge --keep=1
     │
    ├─→ List: Shop → [1.0.0-a1b2c3d4 (old), 1.1.0-99aabb00 (current)]
    ├─→ Keep: 1.1.0-99aabb00
    └─→ Delete: 1.0.0-a1b2c3d4
          → Local: rm -rf /app/assets/Shop/1.0.0-a1b2c3d4/
          → S3:    aws s3 rm --recursive s3://razy-assets/Shop/1.0.0-a1b2c3d4/
  ④ Module Controller::getAssetUrl()
     │
     ├── Read .asset_pack_id → PACK_ID = 1.0.0-a1b2c3d4
     │
     ├── RAZY_ASSET_BASE_URL is set:
     │   → https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/
     │
     └── RAZY_ASSET_BASE_URL is not set:
       → https://example.com/webassets/Shop/1.0.0/   (self-serve, fallback)
  
  ⑤ Browser:
     GET https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/css/style.css
         │
         ├── CDN Edge HIT → respond immediately (~5ms)
         └── CDN Edge MISS → S3 Origin → respond + cache at edge

  ⑥ Hot-Plug transition (v1.0 + v1.1 coexist):
  
     Worker A (v1.0): .asset_pack_id = 1.0.0-a1b2c3d4
       → getAssetUrl() → .../Shop/1.0.0-a1b2c3d4/css/style.css ✅
     
     Worker B (v1.1): .asset_pack_id = 1.1.0-99aabb00
       → getAssetUrl() → .../Shop/1.1.0-99aabb00/css/style.css ✅
     
    → Both packs coexist in storage! Zero conflicts! Zero downtime!

═══════════════════════════════════════════════════════════════════════════════
```

### 13.10 Local Storage Mode — Caddy Front-Door file_server

**When `driver=local`,** the asset pack is extracted to `/app/assets/`. The front-door Caddy can serve it directly via `file_server`, without going through the tenant container:

```caddyfile
# Front-Door Caddyfile — Local Asset Storage Mode

tenant-a.example.com {
    # ── Static assets from extracted pack (LOCAL STORAGE) ──
    @webassets path /webassets/*
    handle @webassets {
        uri replace /webassets/ / 1
        root * /app/assets
        file_server {
            precompressed gzip br
        }
        header Cache-Control "public, max-age=31536000, immutable"
    }
    
    # ── Data files (still via proxy to tenant container) ──
    @data path /data/*
    handle @data {
        reverse_proxy tenant-a:8080
    }
    
    # ── Dynamic PHP requests ──
    reverse_proxy tenant-a:8080
}
```

**Volume configuration:**
```yaml
services:
  caddy-front:
    image: caddy:2-alpine
    volumes:
      - shared_assets:/app/assets:ro   # ← Extracted asset packs
    ports:
      - "443:443"
      - "80:80"

  tenant-a:
    image: razy-tenant:1.0.1-beta
    read_only: true
    volumes:
      - tenant_a_data:/app/data/tenant-a
    environment:
      - RAZY_ASSET_BASE_URL=https://tenant-a.example.com/webassets
      # OR for CDN:
      # - RAZY_ASSET_BASE_URL=https://cdn.example.com/assets

volumes:
  shared_assets:   # Populated by sync command's AssetDeployer
  tenant_a_data:   # Runtime data (uploads, cache)
```

**Traffic path:**
```
  Browser → GET /webassets/Shop/1.0.0-a1b2c3d4/css/style.css
      → Caddy Front-Door @webassets matcher
        → uri replace /webassets/ → /
        → root * /app/assets → /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
        → file_server → 200 OK
  
  ✅ Zero reverse_proxy hop — direct local file read
  ✅ Tenant container does not handle static requests → 100% resources for PHP
  ✅ PACK_ID in path → two versions can coexist during hot-plug transition
```

### 13.11 S3 Mode — CDN + Object Storage

```
  Browser
    → CDN (CloudFlare / CloudFront)
      → S3 Origin: s3://razy-assets/tenant-a/Shop/1.0.0/css/style.css
        → 200 OK (cached at CDN edge)

  ┌─ S3 Bucket: razy-assets ───────────────────────────┐
  │                                                     │
  │  tenant-a/                                          │
  │    Shop/                                            │
  │      1.0.0/                                         │
  │        css/style.css                                │
  │        js/app.js                                    │
  │    Auth/                                            │
  │      2.0.0/                                         │
  │        css/login.css                                │
  │                                                     │
  │  tenant-b/                                          │
  │    Shop/                                            │
  │      1.0.0/                                         │
  │        css/style.css   (same module, different tenant)
  │                                                     │
  └─────────────────────────────────────────────────────┘
```

**S3 Bucket Policy (per-tenant isolation):**
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "CDNReadAccess",
            "Effect": "Allow",
            "Principal": {"Service": "cloudfront.amazonaws.com"},
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::razy-assets/*"
        }
    ]
}
```

### 13.12 Multi-Tenant Asset Path Strategy

**Question:** The same module (e.g., `vendor/shop`) may be used by multiple tenants. If the asset pack contents are identical, do we need to store one copy per tenant?

**Answer: No.** `PACK_ID` includes a content hash — same content = same `PACK_ID` = natural deduplication.

#### Recommended Strategy: Shared Pool (single bucket, module > PACK_ID) ✅

```
  /app/assets/                    ← single storage bucket (LOCAL)
    Shop/                         ← module alias
      1.0.0-a1b2c3d4/            ← PACK_ID
        css/style.css
        js/app.js
      1.1.0-99aabb00/            ← hot-plug coexistence (new version)
        css/style.css
    Auth/
      2.0.0-ccdd1122/
        css/login.css
```

| Dimension | Score | Notes |
|------|------|------|
| Isolation | ★★★ | `PACK_ID` content hash ensures different content never collides |
| Storage efficiency | ★★★ | Same content → same `PACK_ID` → natural dedup |
| Deployment simplicity | ★★★ | No tenant prefix → `AssetDeployer` logic stays simple |
| CDN compatibility | ★★★ | `PACK_ID` is in the URL → CDN cache key is naturally isolated |

**Why no tenant prefix is needed:**

| Scenario | Result |
|------|------|
| Tenant A/B use same module + same version + same content | Same `PACK_ID` → store one copy (dedup) |
| Tenant A upgrades, Tenant B does not | Different versions → different `PACK_ID` → coexist |
| Tenant A customizes (fork/theme) | Same version but different content → different content hash → different `PACK_ID` → isolated |
| Hot-plug transition | Old/new `PACK_ID` coexist under the same module directory |

#### Alternative Strategy A: Per-Tenant Prefix (Not recommended)

```
  /app/assets/
    tenant-a/
      Shop/1.0.0-a1b2c3d4/css/style.css
    tenant-b/
      Shop/1.0.0-a1b2c3d4/css/style.css    ← duplicate storage for same content
```

→ Wastes storage, increases deploy complexity, and CDN purge must be done per tenant prefix.
`PACK_ID` already solves isolation → tenant prefixes provide no additional value.

#### Alternative Strategy C: Content-Hash Dedup (Advanced, Phase 4+)

```
  /app/assets/
    _blob/
      sha256-abc123/css/style.css    ← content-hash addressing
    _map/
      Shop/1.0.0 → sha256-abc123
      Shop/1.0.1 → sha256-def456
```

Too complex — only consider in Phase 4+. Shared Pool + `PACK_ID` already provides sufficient dedup.

**Conclusion: Use Shared Pool in all phases. `PACK_ID`'s content hash solves both isolation and dedup.**

### 13.13 Environment Variables + Config Override Chain

```
  Resolution order (high priority → low):
  
  1. RAZY_ASSET_BASE_URL env var
       → https://cdn.example.com/assets
  
  2. config.inc.php asset_storage.base_url
       → https://cdn.example.com/assets
  
  3. Distributor dist.php asset_base_url
       → https://cdn.example.com/assets
  
  4. (None) → fallback to Controller::getAssetPath()
       → https://example.com/webassets/{alias}/{version}/
```

**Runtime resolution (Controller.php):**

```php
final public function getAssetUrl(): string
{
    // 1. ENV override (highest priority)
    $baseUrl = getenv('RAZY_ASSET_BASE_URL');
    
    // 2. Config file
    if (!$baseUrl) {
        $config = $GLOBALS['RAZY_CONFIG']['asset_storage'] ?? [];
        $baseUrl = $config['base_url'] ?? '';
    }
    
    // 3. Distributor-level override
    if (!$baseUrl) {
        $baseUrl = $this->module->getDistributor()->getAssetBaseUrl();
    }
    
    if ($baseUrl) {
        // Resolve PACK_ID for hot-plug safe URL
        $packId = $this->resolvePackId();
        return rtrim($baseUrl, '/') . '/'
            . $this->module->getModuleInfo()->getAlias() . '/'
            . $packId . '/';
    }
    
    // 4. Self-serve fallback
    return $this->getAssetPath();
}
```

### 13.14 Rewrite Rule Interaction — Automatic Mode Switching

**When external asset storage is enabled, CaddyfileCompiler can automatically skip `@webasset_*` matchers:**

```
  Mode A (Self-Serve, no RAZY_ASSET_BASE_URL):
  ─────────────────────────────────────────────
  
  Tenant Container Caddyfile:
    @webasset_Shop_ path /webassets/Shop/*
    handle @webasset_Shop_ {
        uri strip_prefix /webassets/Shop
        root * sites/main/modules/vendor/shop
        file_server
    }
  
  Controller::getAssetUrl() → /webassets/Shop/1.0.0/...
  → Request hits the tenant container → file_server responds
  
  Note: In self-serve mode the URL still uses {version} (no PACK_ID)
  → because CaddyfileCompiler's @webasset matcher matches by alias
  → version is in the URL path, and file_server serves from the module dir


  Mode B (External Storage, RAZY_ASSET_BASE_URL is set):
  ──────────────────────────────────────────────────
  
  Tenant Container Caddyfile:
    # Keep @webasset_Shop_ (secure fallback net)
    # But Controller::getAssetUrl() points to CDN + PACK_ID → bypass container
  
  Controller::getAssetUrl() → https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/...
  → Request hits CDN / front-door file_server → bypass tenant container
  → PACK_ID in URL → different URLs during hot-plug transition → CDN cache never mixes
  
  Result: tenant container static traffic drops to zero → 100% resources for PHP
```

**Important: Even with external storage enabled, keep the `@webasset_*` rules in the Caddyfile as a secure fallback net.** If CDN fails or S3 is unavailable, admins can remove the `RAZY_ASSET_BASE_URL` env var to instantly fall back to self-serve.

### 13.15 Asset Purge / Clean CLI Command

**Core requirement:** After the hot-plug transition completes, old packs still occupy storage. We need an explicit command to clean them up — it cannot be auto-deleted (the framework cannot know when the transition is truly complete).

#### 13.15.1 Command Design

```
  php Razy.phar asset:purge [OPTIONS]
  
  Purpose: Remove old asset packs to free storage
  
  Options:
    --keep=N         Keep newest N packs per alias (default: 1)
    --alias=ALIAS    Only purge the specified alias (default: all)
    --dry-run        Show what would be deleted, do not execute
    --force          Skip confirmation prompt
    --dist=CODE      Specify distributor (default: current)
  
  Examples:
    php Razy.phar asset:purge --keep=1               # keep newest 1 pack per alias
    php Razy.phar asset:purge --keep=2 --dry-run     # preview purge result
    php Razy.phar asset:purge --alias=Shop --force   # force purge old packs for Shop
```

#### 13.15.2 Execution Flow

```php
// src/system/terminal/asset_purge.inc.php

$keep = (int)($options['keep'] ?? 1);
$targetAlias = $options['alias'] ?? null;
$dryRun = isset($options['dry-run']);
$force = isset($options['force']);

$assetDeployer = AssetDeployer::create($assetStorageConfig);

// 1. List all aliases in storage
$aliases = $targetAlias ? [$targetAlias] : $assetDeployer->listAliases();

foreach ($aliases as $alias) {
    $packs = $assetDeployer->listPacks($alias);
    // Sorted by version (PACK_ID starts with version → natural sort works)
    
    if (count($packs) <= $keep) {
        $this->writeLineLogging("  {$alias}: {count($packs)} pack(s) — nothing to purge", true);
        continue;
    }
    
    // Keep the $keep most recent, purge the rest
    $toPurge = array_slice($packs, 0, -$keep);
    $toKeep = array_slice($packs, -$keep);
    
    $this->writeLineLogging("  {$alias}:", true);
    foreach ($toKeep as $pack) {
        $this->writeLineLogging("    [{@c:green}KEEP{@reset}]  {$pack}", true);
    }
    foreach ($toPurge as $pack) {
        $this->writeLineLogging("    [{@c:red}PURGE{@reset}] {$pack}", true);
        if (!$dryRun) {
            $assetDeployer->removePack($alias, $pack);
        }
    }
}

if ($dryRun) {
    $this->writeLineLogging("\n[{@c:yellow}DRY RUN{@reset}] No files were deleted.", true);
}
```

#### 13.15.3 Execution Examples

```
  $ php Razy.phar asset:purge --keep=1 --dry-run
  
  Asset Purge (dry-run)
  ──────────────────────────────
  Storage: /app/assets (local)
  
  Shop:
    [KEEP]  1.1.0-99aabb00
    [PURGE] 1.0.0-e5f6g7h8    (223 KB)
    [PURGE] 1.0.0-a1b2c3d4    (220 KB)
  
  Auth:
    [KEEP]  2.0.0-ccdd1122
  
  Total to purge: 2 packs, ~443 KB
  
  [DRY RUN] No files were deleted.
  Run without --dry-run to execute.
```

#### 13.15.4 Hot-Plug End-to-End Operation Flow

```
  ═════════════════════════════════════════════════════════════════
  
  Step 1: Package the new version
  ──────
  $ php Razy.phar pack vendor/shop 1.1.0
  → PACK_ID: 1.1.0-99aabb00
  → 1.1.0-assets.tar.gz created
  
  Step 2: Publish to the repository
  ──────
  $ php Razy.phar publish --push
  → GitHub Release: v1.1.0 + assets.tar.gz uploaded
  
  Step 3: Sync to tenant (deploy the new version)
  ──────
  $ php Razy.phar sync main
  → Module:  sites/main/vendor/shop/ → v1.1.0 extracted
  → Assets:  /app/assets/Shop/1.1.0-99aabb00/ → deployed
  → .asset_pack_id → "1.1.0-99aabb00" written
  
  Storage state at this point:
    /app/assets/Shop/
      1.0.0-a1b2c3d4/    ← v1.0 (old workers still using it)
      1.1.0-99aabb00/    ← v1.1 (new workers begin using it)
  
  Step 4: Hot-Plug transition
  ──────
  FrankenPHP worker hot swap:
  → Old workers (v1.0) gradually drain…
  → New workers (v1.1) take over new requests…
  → Transition window: both versions run simultaneously; each getAssetUrl() points to its own PACK_ID
  → Zero conflicts! Zero 404s! Zero downtime!
  
  Step 5: Confirm the transition is complete
  ──────
  → All workers have switched to v1.1
  → No v1.0 requests in-flight
  → CDN cache has refreshed (or different PACK_ID means different cache keys)
  
  Step 6: Purge old packs
  ──────
  $ php Razy.phar asset:purge --keep=1
  
    Shop:
      [KEEP]  1.1.0-99aabb00
      [PURGE] 1.0.0-a1b2c3d4    → deleted!
  
  ═════════════════════════════════════════════════════════════════
```

#### 13.15.5 CDN Cache and PACK_ID Interaction

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │  Question: Will cached old-version assets in the CDN affect the  │
  │  new version?                                                   │
  │                                                                 │
  │  Answer: No! Different PACK_ID → different URL → different cache │
  │  keys.                                                          │
  │                                                                 │
  │  v1.0: /Shop/1.0.0-a1b2c3d4/css/style.css  → CDN cache A      │
  │  v1.1: /Shop/1.1.0-99aabb00/css/style.css  → CDN cache B      │
  │                                                                 │
  │  → No CDN purge needed! The new version naturally uses a new URL │
  │  → Old CDN cache naturally expires (TTL) or is evicted by LRU    │
  │  → Same-version hotfix: different PACK_ID hash → new cache key   │
  │    as well → safe                                                │
  │                                                                 │
  │  Biggest advantage of PACK_ID over pure versioning:              │
  │  even re-packing the same version won't make the CDN serve stale │
  │  content.                                                       │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

### 13.16 Effort Estimate (Hours)

| Item | Change | Hours |
|------|------|------|
| `pack.inc.php` — `.tar.gz` + PACK_ID | PharData archive + content hash + manifest | 4h |
| `publish.inc.php` — Release asset upload | Upload .tar.gz to GitHub Release | 2h |
| `sync.inc.php` — Asset pack download + deploy | Download + checksum + extract + .asset_pack_id | 5h |
| `AssetDeployer.php` — New class | Local + S3 driver + purge + listPacks | 7h |
| `asset_purge.inc.php` — Purge CLI | List + keep-N + dry-run + force | 3h |
| `Controller::getAssetUrl()` + `resolvePackId()` | PACK_ID resolution chain + .asset_pack_id reads | 3h |
| `config.inc.php.tpl` — Config template | asset_storage section | 1h |
| `RepositoryManager` — getDownloadUrl assets | Support assets-type downloads | 2h |
| Unit tests | Pack / Deploy / PACK_ID / Purge / URL resolution | 8h |
| Integration tests | End-to-end: pack → publish → sync → hot-plug → purge | 5h |
| Docs | CLI help + Wiki page | 2h |
| **Total** | | **~42h** |

### 13.17 Phased Implementation Recommendation

```
  Phase 2 (Docker, ≤20 tenants):
  ─────────────────────────────
  
  1. pack.inc.php add .tar.gz + PACK_ID       (4h)
  2. sync.inc.php + AssetDeployer (local)     (7h)
  3. Controller::getAssetUrl() + resolvePackId (3h)
  4. asset:purge CLI                          (3h)
  5. Front-door Caddy file_server for assets  (2h)
  ─────────────────────────────────────────────
  Total: ~19h
  
  Outcome:
  • Webassets move from tenant containers to front-door direct serving
  • PACK_ID ensures zero conflicts during the hot-plug transition window
  • asset:purge removes old packs to free space
  • Automatic fallback: remove env → self-serve mode
  
  
  Phase 3 (Multi-Host Docker / K8s, 20-100 tenants):
  ──────────────────────────────────────────────────
  
  6. publish.inc.php upload .tar.gz           (2h)
  7. AssetDeployer S3 driver                   (6h)
  8. RepositoryManager asset download support   (2h)
  ─────────────────────────────────────────────
  Total: ~10h (cumulative ~29h)
  
  Outcome:
  • Store assets in S3/MinIO → deliver via CDN
  • Use `PACK_ID` as the CDN cache key → no cache purge needed
  • Unlimited scaling + low latency globally
  
  
  Phase 4 (Enterprise, >100 tenants):
  ──────────────────────────────────
  
  9. GCS driver + Multi-CDN                    (4h)
  10. Content-hash dedup (Strategy C)          (5h)
  11. Auto-purge scheduler (cron-based)         (4h)
  ─────────────────────────────────────────────
  Total: ~13h (cumulative ~42h)
```

### 13.18 Backward Compatibility Guarantee

| Scenario | Behavior |
|------|------|
| No `asset_storage` config, no `RAZY_ASSET_BASE_URL` | ✅ No change — self-serve via Caddyfile `file_server` |
| `getAssetPath()` calls | ✅ Unchanged — always returns self-serve URL |
| New `getAssetUrl()` calls | ✅ Automatically chooses the best URL (CDN / self-serve) + PACK_ID isolation |
| Old modules without `.tar.gz` | ✅ `sync` skips asset deploy — uses self-serve |
| `pack --no-assets` | ✅ No .tar.gz produced — consistent with current behavior |
| No `.asset_pack_id` file | ✅ `resolvePackId()` falls back to version |
| CDN outage | ✅ Remove `RAZY_ASSET_BASE_URL` env → immediate fallback |
| Hot-plug transition window | ✅ New and old PACK_ID coexist → zero 404s, zero conflicts |
| Same-version hotfix (re-pack) | ✅ Different content hash → new PACK_ID → does not overwrite old packs |

### 13.19 Key Insight (Key Insight)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   PACK_ID = {version}-{content_hash_8}                         │
  │   → Each pack produces a unique PACK_ID (different content →    │
  │     different hash)                                             │
  │   → Solves hot-plug transition conflicts: new/old packs coexist  │
  │     in storage                                                  │
  │   → Solves same-version hotfix: re-pack → new PACK_ID → no       │
  │     overwrite                                                   │
  │   → Solves CDN caching: PACK_ID in the URL → a natural cache     │
  │     buster                                                      │
  │                                                                 │
  │   pack.inc.php already extracts webassets (1.0.0-assets/)       │
  │   → Add .tar.gz packaging + embed PACK_ID = a complete pipeline │
  │                                                                 │
  │   AssetDeployer (local/S3/GCS):                                │
  │   • Single bucket: /{module}/{PACK_ID}/ — no tenant prefix      │
  │   • Deploy into PACK_ID dirs → never overwrite → immutable      │
  │     snapshot                                                    │
  │   • Same content → same PACK_ID → natural dedupe; different     │
  │     content → naturally isolated                                │
  │   • Purge explicitly deletes → admin-controlled lifecycle       │
  │                                                                 │
  │   Controller::getAssetUrl() automatic resolution:               │
  │   • .asset_pack_id → metadata → version (3-level fallback)      │
  │   • Each worker reads its own PACK_ID → zero conflicts during   │
  │     the transition window                                       │
  │   • 100% backward compatible — getAssetPath() unchanged         │
  │                                                                 │
  │   Phase 2 only needs ~19h → immediate gains:                    │
  │   • Front-door serves static directly → containers carry no     │
  │     static load                                                 │
  │   • Hot-plug safe (PACK_ID isolation)                           │
  │   • asset:purge cleanup + automatic fallback safety net         │
  │   • Adding S3/CDN later is incremental (+10h)                   │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 14. Best Solution & Unified Upgrade Roadmap (Best Solution & Unified Upgrade Roadmap)

> **Status:** Synthesis of Sections 1–13 findings  
> **Scope:** From v1.0.1-beta (current) → v2.0.0 (full enterprise multi-tenant)  
> **Replaces:** `UPGRADE-ROADMAP.md` original estimate (~105h) with fully reconciled effort

### 14.1 Executive Summary

Sections 1–13 of this document analysed every facet of Razy's multi-tenant architecture:
communication layers, injection threats, process isolation, cryptography, latency,
URL rewriting, static assets, reverse proxy, volume strategies, data access control,
and build-time asset extraction. This section synthesises those findings into a
single **recommended architecture** and a **unified upgrade roadmap** with reconciled
effort estimates.

**Core Conclusions:**

1. **Communication:** The L1–L4 layered model is sound. L1/L2 are shipped. L4 (`TenantEmitter → HTTP POST → Bridge`) with HMAC-SHA256 authentication is the right Phase 3 primitive. Ed25519 is deferred to Phase 4+ (marginal gain vs complexity).

2. **Isolation:** FrankenPHP worker mode (current) is the default. Docker containers per tenant (Phase 2) provide production-grade isolation. Kubernetes (Phase 4) enables enterprise scale. FPM pool mode is optional (~10h) for mid-tier use cases but NOT recommended as default path.

3. **Static Assets:** The zero-change proxy-through (§11 Option A) works for Phase 2 MVP. The **Webasset Pack + PACK_ID** pipeline (§13) is the long-term solution — front-door Caddy `file_server` serves static assets directly, eliminating container load. PACK_ID (`{version}-{content_hash_8}`) solves hot-plug transitions, CDN cache busting, and same-version hotfixes in one mechanism.

4. **Routing & Rewrite:** `CaddyfileCompiler` extension + Caddy Admin API dynamic config is the dual strategy. Bridge blocking (`/_razy/internal/*`) is a **P0 security requirement**. Multi-container reverse proxy generation slots into the existing compiler pattern.

5. **Data Access:** Producer-side `data_exports` ACL in `package.php` is the correct model. Module authors declare what's public/restricted/private; the compiler generates per-module Caddy matchers.

6. **Security:** Double-gate architecture (outer tenant gate + inner bridge gate) for L4. HMAC-SHA256 default with timestamp + nonce dedup. CLI guard on `executeInternalCommand()`. `realpath()` + base path validation on all file access.

---

### 14.2 Architecture Decision Matrix

| Domain | Problem | Recommended Solution | Alternative (Rejected / Deferred) | Phase | Effort | Source |
|--------|---------|---------------------|----------------------------------|-------|--------|--------|
| **Communication** | Cross-tenant API calls | L4 `TenantEmitter` → HTTP POST → HMAC → Bridge | Unix domain sockets (FPM-only, marginal) | Phase 3 | 20h | §1–2 |
| **Authentication** | Bridge request signing | HMAC-SHA256 (`hash_equals()`, 60s window) | Ed25519 (deferred Phase 4+) | Phase 3 | 2h | §6 |
| **Injection Prevention** | 11 attack vectors | CLI guard + HMAC + regex + `realpath()` | WAF (external, not framework scope) | Phase 3 | incl. | §3, §5 |
| **Process Isolation** | Library conflicts, memory | Docker containers per tenant | FPM pools (-15% perf, optional ~10h) | Phase 2 | 14h | §4 |
| **Routing** | Multi-tenant URL dispatch | `CaddyfileCompiler` + Caddy Admin API | Nginx (no admin API), Traefik (no `file_server`) | Phase 2 | 12h+8h | §8, §10 |
| **Static Assets** | Container serves static (wasteful) | Front-door `file_server` + **PACK_ID** pipeline | Proxy-through only (0h, Phase 2 MVP) | Phase 2 | 19h | §11, §13 |
| **Asset Storage** | Scale beyond single host | Single S3 bucket, module/PACK_ID layout + CDN | NFS shared volume (poor at scale), per-tenant bucket (wasteful) | Phase 3 | 10h | §13 |
| **Data Access** | Cross-module file sharing | `data_exports` ACL in `package.php` | Open data dir (security risk) | Phase 3 | 16h | §12 |
| **Volume Strategy** | Webassets in container image | Baked-in image (zero mounts needed) | Host volume bind (fragile, permission issues) | Phase 2 | 0h | §11 |
| **Latency** | L4 call overhead | Connection pooling (O1) + Batch API (O2) | MessagePack (marginal 10–30µs) | Phase 3 | 6h | §7 |
| **CDN Integration** | Global asset delivery | `RAZY_ASSET_CDN` env + PACK_ID URL | Manual CDN config (error-prone) | Phase 3 | 4h | §9, §13 |
| **K8s Orchestration** | Enterprise scale (>100 tenants) | Helm chart + NetworkPolicy + HPA | Docker Compose only (manual scaling) | Phase 4 | 25h | §10 |
| **Advanced K8s** | Tenant lifecycle automation | Custom CRD Tenant Operator (Go) | Manual `kubectl` operations | Phase 4 | 20h | §10 |
| **Configuration** | Isolation tier selection | `isolation_mode` config: `frankenphp`/`fpm-pool`/`docker` | Hardcoded mode | Phase 1 | 1h | §5 |

---

### 14.3 Best Solution Stack (Progressive Architecture)

The recommended architecture follows a **progressive enhancement** model.
Each tier builds on the previous with zero breaking changes.

#### 14.3.1 Tier 1 — Single Host (Current → Phase 1)

```
┌─────────────────────────────────────────────────────┐
│                    Single Server                     │
│                                                     │
│  ┌───────────────────────────────────────────────┐  │
│  │           FrankenPHP Worker Mode               │  │
│  │                                                │  │
│  │  Application ──► Domain ──► Distributor(s)     │  │
│  │       │                        │               │  │
│  │       ▼                        ▼               │  │
│  │  ModuleRegistry          Route Dispatch        │  │
│  │       │                        │               │  │
│  │       ▼                        ▼               │  │
│  │   L1 (Direct)  ◄──────► L2 (Emitter)          │  │
│  │  within distributor      across distributors   │  │
│  └───────────────────────────────────────────────┘  │
│                                                     │
│  Static: PHP serves /assets/* (current behavior)    │
│  Data:   flat file in data/{module}/                │
│  Config: env var RAZY_TENANT_ISOLATED gates all     │
└─────────────────────────────────────────────────────┘
```

**Characteristics:**
- Zero infrastructure change from current v1.0.1-beta
- `isolation_mode = 'frankenphp'` (default)
- All distributors share one worker process
- Library version conflict: documented as same-version constraint
- Performance: 37× boot-once improvement already shipped

#### 14.3.2 Tier 2 — Docker Multi-Tenant (Phase 2)

```
┌──────────────────────────────────────────────────────────────┐
│                        Docker Host                            │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              Caddy Front-Door (Reverse Proxy)         │   │
│  │                                                       │   │
│  │  file_server /assets/{module}/{PACK_ID}/* → local storage │   │
│  │  reverse_proxy /_razy/internal/* → BLOCKED (403)     │   │
│  │  reverse_proxy tenant-a.example.com → container_a    │   │
│  │  reverse_proxy tenant-b.example.com → container_b    │   │
│  └──────────────────┬───────────────────────────────────┘   │
│                     │                                        │
│         ┌───────────┼───────────────┐                       │
│         ▼           ▼               ▼                       │
│  ┌─────────┐  ┌─────────┐   ┌─────────────┐               │
│  │Tenant A │  │Tenant B │   │ Host Tenant  │               │
│  │ (FPH)   │  │ (FPH)   │   │ (Hotplug)   │               │
│  │         │  │         │   │             │               │
│  │ PACK_ID │  │ PACK_ID │   │ tenants.json│               │
│  │ .a_p_id │  │ .a_p_id │   │ plug/unplug │               │
│  └─────────┘  └─────────┘   └─────────────┘               │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐   │
│  │            /assets/ (shared volume, module > PACK_ID)   │   │
│  │  Shop/1.0.0-a1b2c3d4/  Auth/2.0.0-ccdd1122/ (immutable)│   │
│  └──────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────┘
```

**Characteristics:**
- `isolation_mode = 'docker'`
- Each tenant = isolated FrankenPHP container (own `open_basedir`, `disable_functions`)
- Caddy front-door serves static assets from shared `/assets/` volume (module/PACK_ID layout) → container handles PHP only
- Bridge route `/_razy/internal/*` blocked at Caddy level (P0 security)
- `TenantProvisioner` manages Caddy Admin API CRUD for tenant routes
- Single storage bucket: `/assets/{module}/{PACK_ID}/` — no tenant prefix (content hash provides isolation)
- PACK_ID hot-plug: new container gets new PACK_ID → old still served until purge
- Supports ≤20 tenants comfortably on single Docker host

#### 14.3.3 Tier 3 — Multi-Host / S3+CDN (Phase 3)

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CDN Edge (CloudFront / Cloudflare)           │
│  URL: https://cdn.example.com/assets/{module}/{PACK_ID}/asset.js    │
│  Cache Key: {module}/{PACK_ID} (immutable → infinite TTL)           │
└────────────────────────────────┬────────────────────────────────────┘
                                 │ origin pull
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    S3 / MinIO Object Storage                        │
│  s3://razy-assets/{module}/{PACK_ID}/asset.js                       │
│  Immutable objects (write-once, never overwrite)                    │
└─────────────────────────────────────────────────────────────────────┘
                                 ▲ upload
                                 │
┌────────────────────────────────┼────────────────────────────────────┐
│                         Host A │           Host B                   │
│  ┌─────────┐  ┌─────────┐     │  ┌─────────┐  ┌─────────┐        │
│  │Tenant A │  │Tenant B │     │  │Tenant C │  │Tenant D │        │
│  │ (FPH)   │  │ (FPH)   │     │  │ (FPH)   │  │ (FPH)   │        │
│  └─────────┘  └─────────┘     │  └─────────┘  └─────────┘        │
│         ▲           ▲         │         ▲           ▲             │
│         │   L4 TenantEmitter  │         │   L4 TenantEmitter      │
│         └─── HTTP + HMAC ─────┘─────────┘─── HTTP + HMAC ──┘     │
│                                                                    │
│  Controller::getAssetUrl() → https://cdn.example.com/assets/...   │
│  RAZY_ASSET_CDN=https://cdn.example.com                           │
└────────────────────────────────────────────────────────────────────┘
```

**Characteristics:**
- L4 `TenantEmitter` enables cross-host communication (HTTP POST + HMAC-SHA256)
- Connection pooling (O1) ships with Phase 3 — biggest single latency win
- Static assets served from single S3 bucket (`s3://razy-assets/{module}/{PACK_ID}/`) → CDN edge cache
- `AssetDeployer` S3 driver uploads during `sync` → CDN origin-pull
- `data_exports` ACL enables fine-grained cross-module data access
- `DataRequest`/`DataResponse` for file-level data sharing across tenants
- Supports 20–100 tenants across multiple hosts

#### 14.3.4 Tier 4 — Kubernetes Enterprise (Phase 4–5)

```
┌─────────────────────────────────────────────────────────────────────┐
│                     Kubernetes Cluster                               │
│                                                                     │
│  ┌───────────────────┐  ┌──────────────────────────────────────┐   │
│  │  Ingress Controller│  │  Tenant Operator (Custom CRD)       │   │
│  │  (Caddy / Nginx)  │  │  • Auto-provision namespace + PVC   │   │
│  │  + NetworkPolicy   │  │  • Auto-configure Caddy routes      │   │
│  └───────┬───────────┘  │  • Auto-deploy PACK_ID assets        │   │
│          │              │  • HPA auto-scale per tenant          │   │
│          │              └──────────────────────────────────────┘   │
│          │                                                         │
│  ┌───────┼──────────────────────────────────────────────────────┐  │
│  │       ▼                                                      │  │
│  │  namespace: tenant-a     namespace: tenant-b                 │  │
│  │  ┌──────────────┐       ┌──────────────┐                    │  │
│  │  │ Pod (FPH)    │       │ Pod (FPH)    │                    │  │
│  │  │ replicas: 2  │       │ replicas: 3  │                    │  │
│  │  │ PVC: data    │       │ PVC: data    │                    │  │
│  │  └──────────────┘       └──────────────┘                    │  │
│  │                                                              │  │
│  │  NetworkPolicy: deny inter-tenant, allow ingress + system   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  S3/GCS + Multi-CDN                                          │  │
│  │  Content-hash dedup (same asset across tenants → stored 1×)  │  │
│  │  Auto-purge scheduler (cron job, keep-N policy)              │  │
│  │  Ed25519 signed L4 payloads (compliance mode)                │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Admin UI (v2.0.0)                                           │  │
│  │  • Tenant lifecycle dashboard (plug / unplug / provision)    │  │
│  │  • Whitelist ACL editor (TenantAccessPolicy)                 │  │
│  │  • Health monitoring + PACK_ID management                    │  │
│  └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

**Characteristics:**
- Full orchestration via Helm chart + Custom CRD Tenant Operator
- Per-tenant namespace with `NetworkPolicy` (deny inter-tenant traffic)
- HPA auto-scales pods per tenant based on CPU/memory
- Ed25519 signing for compliance-grade L4 authentication
- Content-hash dedup across tenants (same asset stored once in S3)
- Auto-purge scheduler manages PACK_ID lifecycle
- `TenantAccessPolicy` JSON-based ACL with expiry + path scoping
- Admin UI for tenant lifecycle management
- Supports >100 tenants

---

### 14.4 Unified Upgrade Roadmap (Unified Upgrade Roadmap)

> **Methodology:** Original `UPGRADE-ROADMAP.md` (105h) served as the skeleton.
> Sections 8–13 identified additional capabilities not in the original plan.
> This unified roadmap reconciles all effort estimates, deduplicates overlap,
> and provides a single source of truth.

#### 14.4.1 Reconciliation Notes

| Source | Original Estimate | Section Additions | Overlap Deduction | Reconciled |
|--------|------------------|-------------------|-------------------|------------|
| Phase 1 (Isolation Core) | 16h | — | — | **16h** |
| Phase 2 (Docker) | 14h | §8 compiler (12h) + §10 Provisioner (12h) + §13 Pack local (19h) | -5h (Caddyfile overlap) | **52h** |
| Phase 3 (L4 Comms) | 20h | §7 latency (8h) + §9 CORS+CDN (4h) + §12 data exports (16h) + §13 S3 (10h) | -6h (test overlap) | **52h** |
| Phase 4 (K8s) | 25h | §10 Operator+sidecar (28h) + §13 enterprise pack (13h) + §6 Ed25519 (4h) | -10h (K8s YAML overlap) | **60h** |
| Phase 5 (Admin UI) | 30h | §9 signed URL data assets (4h) | — | **34h** |
| **Total** | **105h** | **+130h** | **-21h** | **~214h** |

> ⚠️ The jump from 105h → 214h reflects **real scope** discovered during deep analysis.
> The original roadmap covered core framework changes only; sections 8–13 added
> infrastructure tooling (compiler, provisioner, asset pipeline, data ACL) that are
> essential for production multi-tenant deployment.

#### 14.4.2 Phase 0 — Foundation ✅ DONE (v1.0.1-beta)

No remaining work. All prerequisites shipped.

| Deliverable | Status |
|-------------|--------|
| DI Container security blocklist (14 classes) | ✅ |
| Worker dispatch guards (HTTPS, Host, method) | ✅ |
| Boot-once optimization (37× improvement) | ✅ |
| Distributor caching in `Domain::dispatchQuery()` | ✅ |
| `RestartSignal` + `WorkerState` + `ChangeType` | ✅ |
| `WorkerLifecycleManager` (4 strategies) | ✅ |
| `ModuleChangeDetector` (PHP tokenizer) | ✅ |
| Benchmark suite (k6 + Docker, 6 scenarios) | ✅ |
| All 4,794 tests passing | ✅ |

---

#### 14.4.3 Phase 1 — Tenant Isolation Core (~16h) → v1.1.0-beta

**Goal:** Framework-level tenant isolation. Backward compatible. No Docker dependency.

| # | Task | Hrs | Source |
|---|------|-----|--------|
| 1.1 | Bootstrap tenant constants (`RAZY_TENANT_ISOLATED`, `RAZY_TENANT_ID`) | 1h | ROADMAP |
| 1.2 | Opaque data path guard (`getDataPath()`) | 2h | ROADMAP |
| 1.3 | Disable `data_mapping` in isolation mode | 1h | ROADMAP |
| 1.4 | Config path isolation (`loadConfig()`) | 2h | ROADMAP |
| 1.5 | Hotplug engine (`plugTenant()`, `unplugTenant()`) | 4h | ROADMAP |
| 1.6 | `rebuildMultisite()` + `replayTenantOverlays()` | 2h | ROADMAP |
| 1.7 | `RestartSignal` plug/unplug constants | 0.5h | ROADMAP |
| 1.8 | Worker loop signal handler | 1h | ROADMAP |
| 1.9 | `isolation_mode` config option | 0.5h | §5 |
| 1.10 | Unit tests (~30 tests) | 3h | ROADMAP |
| | **Subtotal** | **~16h** | |

**Exit Criteria:**
- `RAZY_TENANT_ISOLATED=true` activates all isolation guards
- Hotplug engine works: plug → routes reachable, unplug → 404
- `tenants.json` survives worker restarts
- All existing 4,794 tests still pass + ~30 new tests

**Dependencies:** None (extends existing code only)

---

#### 14.4.4 Phase 2 — Docker + Static Asset Pipeline (~52h) → v1.1.0

**Goal:** Production-ready Docker multi-tenant with front-door static asset serving.

| # | Task | Hrs | Source |
|---|------|-----|--------|
| **Docker Deployment** | | | |
| 2.1 | Tenant Dockerfile (hardened: `open_basedir`, opcache, JIT) | 2h | ROADMAP |
| 2.2 | Host Dockerfile (hotplug support, `tenants.json` RW) | 2h | ROADMAP |
| 2.3 | Docker Compose multi-tenant template | 3h | ROADMAP |
| 2.4 | Tenant config generator script | 2h | ROADMAP |
| 2.5 | Docker integration tests (isolation + hotplug) | 4h | ROADMAP |
| **CaddyfileCompiler Extensions** | | | |
| 2.6 | Bridge blocking rule (`/_razy/internal/*` → 403) | 2h | §8 |
| 2.7 | Multi-container reverse proxy mode | 3h | §8 |
| 2.8 | Tenant asset CDN prefix support | 2h | §8 |
| 2.9 | `RewriteRuleCompiler` bridge blocking + VirtualHost | 3h | §8 |
| 2.10 | CLI command `php Razy.phar rewrite --multi-tenant` | 2h | §8 |
| **Caddy Admin API Integration** | | | |
| 2.11 | `TenantProvisioner` class (Caddy API CRUD + config versioning) | 5h | §10 |
| 2.12 | `Application::syncCaddyRoutes()` | 3h | §10 |
| 2.13 | Health check endpoint (`GET /health → 200`) | 2h | §10 |
| **Webasset Pack (Local Pipeline)** | | | |
| 2.14 | `pack.inc.php` — `.tar.gz` + PACK_ID (`{version}-{content_hash_8}`) | 4h | §13 |
| 2.15 | `sync.inc.php` — Asset pack download + deploy + `.asset_pack_id` | 5h | §13 |
| 2.16 | `AssetDeployer.php` — Local driver + purge + listPacks | 4h | §13 |
| 2.17 | `asset_purge.inc.php` — CLI (list, keep-N, dry-run, force) | 3h | §13 |
| 2.18 | `Controller::getAssetUrl()` + `resolvePackId()` (3-layer fallback) | 3h | §13 |
| | **Subtotal** | **~52h** | |

**Exit Criteria:**
- `docker compose up` starts host + N tenant containers (verified with 2+)
- Each tenant isolated: own filesystem, `open_basedir` enforced
- Bridge route `/_razy/internal/*` returns 403 at Caddy level
- `TenantProvisioner` manages Caddy routes via Admin API
- `razy pack` produces `.tar.gz` with valid PACK_ID
- `razy sync` deploys assets to front-door `file_server` directory
- `getAssetUrl()` resolves PACK_ID → URL with 3-layer fallback
- `razy asset:purge` cleans old PACK_ID directories
- Hot-plug transition: zero 404s (old + new PACK_ID coexist)

**Dependencies:** Phase 1 (Isolation Core)

---

#### 14.4.5 Phase 3 — L4 Communication + Data Access (~52h) → v1.2.0

**Goal:** Cross-tenant API calls, data sharing with ACL, S3 asset storage, CDN.

| # | Task | Hrs | Source |
|---|------|-----|--------|
| **L4 Communication Core** | | | |
| 3.1 | `TenantEmitter` class (HTTP bridge client, JSON-RPC envelope) | 3h | ROADMAP |
| 3.2 | Bridge route handler (`/_razy/internal/bridge`) | 3h | ROADMAP |
| 3.3 | HMAC-SHA256 authentication (sign + verify + timestamp) | 2h | ROADMAP |
| 3.4 | `Controller::tenantApi()` convenience method | 1h | ROADMAP |
| 3.5 | Permission gates (`__onTenantCall` callback) | 2h | ROADMAP |
| 3.6 | CLI tenant commands (plug/unplug/list/status) | 2h | ROADMAP |
| **Latency Optimizations** | | | |
| 3.7 | O1: Connection pooling (persistent connections) | 2h | §7 |
| 3.8 | O2: Batch API calls (reduce round-trips) | 4h | §7 |
| 3.9 | O5: Async fire-and-forget for non-blocking calls | 2h | §7 |
| **Data Access Layer** | | | |
| 3.10 | `DataRequest` + `DataResponse` classes | 5h | ROADMAP |
| 3.11 | `Controller::data()` convenience + `__onDataRequest` gate | 1h | ROADMAP |
| 3.12 | `ModuleInfo::parseDataExports()` + `isDataExportAllowed()` | 2h | §12 |
| 3.13 | `Distributor::getDataExports()` aggregation | 1h | §12 |
| 3.14 | `CaddyfileCompiler` data export matchers | 4h | §12 |
| 3.15 | `RewriteRuleCompiler` data export rules | 2h | §12 |
| 3.16 | Templates: `caddyfile.tpl` + `htaccess.tpl` data block | 1h | §12 |
| **S3 Asset Storage** | | | |
| 3.17 | `AssetDeployer` S3 driver | 4h | §13 |
| 3.18 | `publish.inc.php` — Release asset upload to S3 | 2h | §13 |
| 3.19 | `RepositoryManager` — asset type download support | 2h | §13 |
| 3.20 | `getAssetUrl()` CDN URL generation (`RAZY_ASSET_CDN` env) | 2h | §13 |
| **Frontend Access** | | | |
| 3.21 | Cross-tenant CORS headers in `CaddyfileCompiler` | 2h | §9 |
| **Testing** | | | |
| 3.22 | Unit tests (L4, HMAC, DataRequest, data exports, PACK_ID S3) | 6h | ALL |
| | **Subtotal** | **~52h** | |

**Exit Criteria:**
- `$this->tenantApi()` completes cross-container L4 call with HMAC auth
- Bridge rejects unauthorized / expired / replayed requests
- `DataRequest` → `DataResponse` round-trip works with `__onDataRequest` gate
- `data_exports` in `package.php` controls which files are accessible
- `CaddyfileCompiler` generates per-module data export matchers
- `AssetDeployer` uploads to S3; CDN origin-pull serves assets
- Connection pooling reduces L4 overhead by ~40%
- CORS headers correctly set for cross-tenant asset access

**Dependencies:** Phase 1. Can run in parallel with Phase 2 for L4 core (items 3.1–3.6), but Phase 2 must ship first for Docker-dependent tasks.

---

#### 14.4.6 Phase 4 — Kubernetes + Enterprise Scale (~60h) → v1.3.0

**Goal:** K8s deployment, Tenant Operator automation, enterprise asset pipeline.

| # | Task | Hrs | Source |
|---|------|-----|--------|
| **K8s Infrastructure** | | | |
| 4.1 | K8s namespace + PVC templates | 4h | ROADMAP |
| 4.2 | Deployment + Service + Ingress YAML | 4h | ROADMAP |
| 4.3 | NetworkPolicy per tenant (deny inter-tenant) | 3h | ROADMAP |
| 4.4 | Helm chart (parameterized: tenant count, domains, limits, secrets) | 8h | ROADMAP |
| 4.5 | `WorkerLifecycleManager` integration into `main.php` worker loop | 3h | ROADMAP |
| 4.6 | Integration tests (minikube: namespace, NetworkPolicy, health) | 3h | ROADMAP |
| **Tenant Operator** | | | |
| 4.7 | Custom CRD `Tenant` resource definition | 4h | §10 |
| 4.8 | Operator controller (Go): reconcile loop — provision ns/pvc/deploy | 12h | §10 |
| 4.9 | Operator: Caddy route auto-registration on Tenant create/delete | 4h | §10 |
| **Asset Pipeline — Enterprise** | | | |
| 4.10 | `AssetDeployer` GCS driver | 3h | §13 |
| 4.11 | Content-hash dedup (same asset across tenants → store once) | 4h | §13 |
| 4.12 | Auto-purge scheduler (CronJob, keep-N, tenant-scoped) | 4h | §13 |
| 4.13 | S3/MinIO sidecar container for asset sync | 2h | §10 |
| **Cryptography** | | | |
| 4.14 | Ed25519 signing option (`bridge_auth: ed25519`) | 2h | §6 |
| 4.15 | Optional Sodium sealed box for sensitive payloads | 2h | §6 |
| | **Subtotal** | **~60h** | |

**Exit Criteria:**
- `helm install` deploys host + N tenants with correct namespace isolation
- NetworkPolicy blocks direct tenant-to-tenant traffic
- Tenant Operator CRD: `kubectl apply -f tenant.yaml` → auto-provisions everything
- HPA auto-scales tenant pods
- Content-hash dedup verified (upload same asset from 2 tenants → 1 S3 object)
- Auto-purge CronJob cleans PACK_IDs older than keep-N threshold
- Ed25519 mode passes all existing HMAC tests (pluggable auth backend)

**Dependencies:** Phase 1 + Phase 2

---

#### 14.4.7 Phase 5 — Admin UI + Whitelist ACL (~34h) → v2.0.0

**Goal:** Web-based tenant management dashboard with fine-grained access control.

| # | Task | Hrs | Source |
|---|------|-----|--------|
| 5.1 | `TenantAccessPolicy` class (JSON ACL: whitelist, expiry, path scope) | 4h | ROADMAP |
| 5.2 | Core Admin API endpoints (CRUD tenants, whitelist, monitoring) | 8h | ROADMAP |
| 5.3 | Admin UI frontend (dashboard, whitelist editor, health) | 12h | ROADMAP |
| 5.4 | Signed URL generation for data asset access (cross-tenant) | 4h | §9 |
| 5.5 | End-to-end tests (provision → whitelist → cross-tenant → verify) | 6h | ROADMAP |
| | **Subtotal** | **~34h** | |

**Exit Criteria:**
- `TenantAccessPolicy` grants/denies/expires based on `whitelist.json`
- Admin API: full CRUD for tenants, whitelist rules, health
- Admin UI: web dashboard for tenant lifecycle management
- Signed URLs enable controlled cross-tenant data asset access
- Path traversal blocked by `realpath()` + base path validation

**Dependencies:** Phase 1 + (Phase 2 or Phase 3)

---

### 14.5 Phase Dependency Diagram

```
                              Phase 0 ✅
                            (Foundation)
                           v1.0.1-beta
                                │
                                ▼
                           Phase 1 (16h)
                        (Isolation Core)
                          v1.1.0-beta
                                │
                 ┌──────────────┼──────────────┐
                 ▼                             ▼
           Phase 2 (52h)                 Phase 3 (52h)
       (Docker + Assets)             (L4 + Data Access)
            v1.1.0                       v1.2.0
                 │                             │
                 │    ┌────────────────────────┘
                 │    │
                 ▼    ▼
           Phase 4 (60h)
        (K8s + Enterprise)
             v1.3.0
                 │
                 ▼
           Phase 5 (34h)
         (Admin UI + ACL)
             v2.0.0

         ════════════════
         Total: ~214h
         ════════════════
```

**Parallelism opportunities:**
- Phase 2 and Phase 3 can start **concurrently** after Phase 1 ships
- Within Phase 2: Docker tasks (2.1–2.5) and Compiler tasks (2.6–2.10) can be parallel
- Within Phase 3: L4 core (3.1–3.6) and Data exports (3.12–3.16) can be parallel
- Phase 4 depends on both Phase 2 AND Phase 3
- Phase 5 depends on Phase 1 + at least one of Phase 2/3

**Critical Path:** Phase 0 → Phase 1 → Phase 2 → Phase 4 → Phase 5 = **162h**
**With parallelism:** Phase 2 ∥ Phase 3 saves ~52h on wallclock → ~162h wallclock

---

### 14.6 Implementation Priority Matrix

Ranked by **impact ÷ effort** ratio:

| Rank | Item | Impact | Effort | Phase | Rationale |
|------|------|--------|--------|-------|-----------|
| **1** | Bridge blocking (`/_razy/internal/* → 403`) | 🔴 Critical | 2h | P2 | Security P0: blocks external access to internal bridge — MUST be first deployed rule |
| **2** | `isolation_mode` config + bootstrap constants | 🔴 Critical | 1.5h | P1 | Entire isolation architecture gates on this one config key |
| **3** | HMAC-SHA256 auth (sign + verify + timestamp) | 🔴 Critical | 2h | P3 | L4 communication is insecure without this — zero-compromise item |
| **4** | CLI guard on `executeInternalCommand()` | 🔴 Critical | 1h | P3 | Prevents command injection via bridge — P0 security |
| **5** | Hotplug engine (plug/unplug/rebuild) | 🟠 High | 6.5h | P1 | Core capability: dynamic tenant provisioning |
| **6** | Connection pooling (O1) | 🟠 High | 2h | P3 | Biggest single latency win (~40% for L4 calls) |
| **7** | `pack.inc.php` PACK_ID + `.tar.gz` | 🟠 High | 4h | P2 | Foundation for entire asset pipeline |
| **8** | Front-door `file_server` + `AssetDeployer` (local) | 🟠 High | 4h | P2 | Eliminates static asset load from containers |
| **9** | `TenantProvisioner` (Caddy Admin API) | 🟠 High | 5h | P2 | Dynamic routing without restart — production requirement |
| **10** | Docker container isolation (Dockerfiles + Compose) | 🟠 High | 7h | P2 | Production-grade tenant isolation |
| **11** | `data_exports` ACL + CaddyfileCompiler matchers | 🟡 Medium | 10h | P3 | Fine-grained data access control |
| **12** | S3 + CDN asset pipeline | 🟡 Medium | 10h | P3 | Multi-host scale for static assets |
| **13** | `TenantEmitter` + Bridge handler | 🟡 Medium | 6h | P3 | Cross-tenant API calls (essential for L4) |
| **14** | Helm chart + K8s templates | 🟡 Medium | 22h | P4 | Enterprise orchestration (only needed at >20 tenants) |
| **15** | Tenant Operator CRD (Go) | 🟢 Low | 20h | P4 | Automation — high effort, only critical at >100 tenants |
| **16** | Admin UI frontend | 🟢 Low | 12h | P5 | Nice-to-have; CLI covers same functionality |
| **17** | Ed25519 (crypto upgrade) | 🟢 Low | 4h | P4 | Marginal security gain over HMAC-SHA256 |
| **18** | FPM Pool mode (optional) | ⚪ Deferred | 10h | — | Niche use case; Docker is the recommended isolation path |

---

### 14.7 Risk Assessment & Mitigation

| # | Risk | Probability | Impact | Phase | Mitigation |
|---|------|-------------|--------|-------|------------|
| R1 | **PACK_ID collision** (same version + same content hash) | Very Low | Medium | P2 | 8-char hex hash = 4 billion combinations per version. Add fallback: append `-N` suffix on collision detection in `AssetDeployer::deploy()` |
| R2 | **Caddy Admin API unavailable** (crashed, overloaded) | Low | High | P2 | `TenantProvisioner` writes periodic file backup (`Caddyfile.backup`). On Caddy restart, restore from file. Health check endpoint detects failure. |
| R3 | **L4 bridge replay attack** | Medium | High | P3 | HMAC timestamp window (60s) + nonce dedup store. Phase 3.x: nonce TTL = 120s with Redis/APCu backend |
| R4 | **Docker container escape** | Very Low | Critical | P2 | `open_basedir` + `disable_functions` + read-only root filesystem. gVisor / Kata Containers for compliance environments |
| R5 | **S3 outage → asset 404** | Low | High | P3 | `getAssetUrl()` 3-layer fallback: `.asset_pack_id` → metadata → version string. Local cache as emergency fallback |
| R6 | **Hot-plug PACK_ID race** (old container serves while new deploys) | Medium | Low | P2 | PACK_ID immutability guarantees old URL keeps working. Old PACK_ID purged only by explicit `asset:purge` command (never automatic in Phase 2) |
| R7 | **Tenant Operator bugs → cascade failure** | Low | Critical | P4 | Rate-limit reconciliation loop. Circuit breaker on Caddy API calls. Operator has `--dry-run` mode for validation |
| R8 | **Content-hash dedup false positive** | Very Low | Medium | P4 | Use SHA-256 (not truncated) for dedup comparison. Truncated hash used in PACK_ID only for human-readability |
| R9 | **HMAC shared secret leak** | Low | Critical | P3 | Secret from env var (never in config file). Rotate via `RAZY_BRIDGE_SECRET_OLD` + `RAZY_BRIDGE_SECRET` dual-accept window. Phase 4: Ed25519 eliminates shared secret entirely |
| R10 | **Migration overhead from v1.0.x** | High | Medium | P1 | `RAZY_TENANT_ISOLATED=false` by default → zero impact. Feature-gated activation. Exhaustive backward compat tests in Phase 1 |

---

### 14.8 Version Milestone Summary

```
Version      Phase        Ship Criteria                           Weeks*  Effort
─────────────────────────────────────────────────────────────────────────────────
v1.0.1-beta  Phase 0 ✅   Foundation: security + perf shipped      Done     0h
v1.1.0-beta  Phase 1      Isolation Core: hotplug, guards, tests   2-3w    16h
v1.1.0       Phase 2      Docker: containers, Caddy, PACK_ID       6-8w    52h
v1.2.0       Phase 3      L4 + Data: TenantEmitter, ACL, S3/CDN    6-8w    52h
v1.3.0       Phase 4      K8s: Helm, Operator, enterprise assets   8-10w   60h
v2.0.0       Phase 5      Admin UI: dashboard, whitelist ACL       4-6w    34h
─────────────────────────────────────────────────────────────────────────────────
                                                         Total:   ~26-35w  ~214h
```

\* Weeks assume 1 senior developer at ~8h/week dedicated to this track.
With 2 developers (Phase 2 ∥ Phase 3), the critical path drops to ~20-28 weeks.

---

### 14.9 Quick-Start Recommendations

For teams evaluating which phase to start:

**Scenario A — "We have ≤5 tenants on one server"**
→ Phase 1 only (16h). Keep `isolation_mode = 'frankenphp'`.
Document the same-library-version constraint and move on.

**Scenario B — "We need 5–20 tenants, production deployment"**
→ Phase 1 + Phase 2 (68h). Docker isolation + PACK_ID asset pipeline.
This is the **recommended starting point** for most production deployments.

**Scenario C — "We need cross-tenant API calls"**
→ Phase 1 + Phase 2 + Phase 3 (120h). Full L4 communication stack.
Required if tenants need to share data or call each other's APIs.

**Scenario D — "Enterprise: >100 tenants, compliance requirements"**
→ All Phases (214h). Full K8s orchestration + Admin UI.
Only needed at enterprise scale with SLA and compliance mandates.

---

### 14.10 Comparison to Original UPGRADE-ROADMAP.md

| Aspect | Original ROADMAP | Unified (This Section) |
|--------|-----------------|----------------------|
| Total effort | ~105h | ~214h |
| Phases | 5 (+ Phase 0) | 5 (+ Phase 0) — same structure |
| Static asset story | Not addressed | Full pipeline: PACK_ID, AssetDeployer, S3/CDN, purge |
| Caddy integration | 1h (single Caddyfile) | 20h (CaddyfileCompiler extension + Admin API + TenantProvisioner) |
| Data access control | Not addressed | 16h (`data_exports` ACL + compiler matchers) |
| Latency optimization | Not addressed | 8h (connection pooling + batch + async) |
| Rewrite rules | Not addressed | 12h (CaddyfileCompiler + RewriteRuleCompiler multi-tenant) |
| K8s Operator | Not addressed | 20h (Custom CRD, Go controller) |
| Risk analysis | Implicit | Explicit (10 risks with mitigation) |
| Exit criteria | Per-phase checklist | Per-phase checklist + priority matrix |

> The original roadmap remains valid as the **core framework layer**. This unified
> roadmap adds the **infrastructure and tooling layer** required for real-world deployment.

---

### 14.11 Key Insight (Key Insight)

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                                                                         │
  │   13 sections, ~6,200 lines of analysis → 6 core decisions:            │
  │                                                                         │
  │   1. ISOLATION:  Docker containers (not FPM pools)                     │
  │      → FrankenPHP worker retained, just one per container              │
  │      → FPM pool is optional mid-tier path, not default                 │
  │                                                                         │
  │   2. COMMUNICATION:  L4 = HTTP POST + HMAC-SHA256                      │
  │      → Bridge endpoint + double-gate + connection pooling              │
  │      → Ed25519 is Phase 4+ incremental (not critical path)            │
  │                                                                         │
  │   3. STATIC ASSETS:  PACK_ID pipeline + single-bucket storage          │
  │      → {version}-{content_hash_8} = hot-plug safe + CDN friendly      │
  │      → Single bucket: /{module}/{PACK_ID}/ — no tenant prefix         │
  │      → Same content = same PACK_ID = natural dedupe; different        │
  │        content = naturally isolated                                   │
  │      → Front-door file_server (Phase 2) → S3+CDN (Phase 3)           │
  │      → AssetDeployer: deploy-once, never overwrite, purge on demand   │
  │                                                                         │
  │   4. DATA ACCESS:  Producer-side ACL (data_exports)                    │
  │      → Module declares public/restricted/private in package.php        │
  │      → Compiler generates per-module Caddy matchers                    │
  │      → Zero runtime overhead (static rewrite rules)                    │
  │                                                                         │
  │   5. ROUTING:  CaddyfileCompiler + Admin API (dual strategy)           │
  │      → Compiler for static config, Admin API for dynamic CRUD         │
  │      → Bridge blocking (/_razy/internal/ → 403) is P0 security        │
  │      → TenantProvisioner wraps Caddy API with versioning              │
  │                                                                         │
  │   6. SCALE PROGRESSION:  Single Host → Docker → K8s                    │
  │      → Each tier adds capability, zero breaking changes                │
  │      → Feature-gated by isolation_mode config                          │
  │      → Teams pick their tier: 16h / 68h / 120h / 214h                 │
  │                                                                         │
  │   ══════════════════════════════════════════════════════════            │
  │   Original ROADMAP: 105h (framework only)                              │
  │   Unified ROADMAP:  214h (framework + infrastructure + tooling)        │
  │   Critical Path:    162h (with Phase 2 ∥ Phase 3 parallelism)         │
  │   ══════════════════════════════════════════════════════════            │
  │                                                                         │
  │   Start with Phase 1 (16h) + Phase 2 (52h) = 68h                      │
  │   → covers 80% of production multi-tenant use cases                    │
  │   → Docker isolation + PACK_ID assets + Caddy routing                  │
  │   → L4 communication + K8s are incremental additions                   │
  │                                                                         │
  └─────────────────────────────────────────────────────────────────────────┘
```