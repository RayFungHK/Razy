# Cross-Tenant Process Flow、Injection Analysis & FPM Pool Evaluation

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
10. [Caddy API + PHP Reverse Static Proxy、Container Mesh & Market Comparison](#10-caddy-api--php-reverse-static-proxycontainer-mesh--market-comparison)
11. [Core-Delegated Volume + Static File External Access Feasibility](#11-core-delegated-volume--static-file-external-access-feasibility)
12. [Data Access Rewrite (Module-Controlled) + Webassets Under Load Balancing](#12-data-access-rewrite-module-controlled--webassets-under-load-balancing)
13. [Webasset Pack — Build-Time Asset Extraction & External Storage](#13-webasset-pack--build-time-asset-extraction--external-storage)
14. [Best Solution & Unified Upgrade Roadmap](#14-best-solution--unified-upgrade-roadmap-最佳方案--統一升級路線圖)

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

## 10. Caddy API + PHP Reverse Static Proxy、Container Mesh & Market Comparison

> **涵蓋範圍:** 評估 Caddy Admin API + PHP 動態配置用作反向靜態檔案 proxy；Docker / K8s 的 Load Balance container 行為；同質同版本 container mesh 互聯及 data file structure；對比市場方案之優劣。

### 10.1 Architecture Layer Roles

在討論 Caddy API + PHP 方案之前，先釐清 Razy 當前的**三層角色分工**——這直接影響 static file routing 的職責歸屬。

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

**關鍵洞察：** 在目前架構中，static file routing 完全由 **web server 層** (Caddy `file_server` / Apache `RewriteRule`) 處理，PHP 層不介入。問題是：在 multi-tenant + multi-container 環境下，能否用 **Caddy Admin API + PHP** 動態管理這些 static file routes？

### 10.2 Caddy Admin API Overview

Caddy 提供 REST Admin API (default `localhost:2019`)，支援：

| Endpoint | Method | 用途 |
|----------|--------|------|
| `/config/` | GET | 取得完整 JSON 配置 |
| `/config/apps/http/servers/{name}/routes` | POST | 動態新增 route |
| `/config/apps/http/servers/{name}/routes/{id}` | PUT/DELETE | 修改/刪除特定 route |
| `/load` | POST | 一次性載入完整配置（atomic replace） |
| `/reverse_proxy/upstreams` | GET | 查看 upstream pool 狀態 |
| `/id/{id}` | GET/PUT/DELETE | 用 `@id` tag 操作命名節點 |

**核心特性：**
- **Zero-downtime reload:** 配置變更不中斷現有連線
- **Atomic swap:** `/load` 端點整批替換，保證一致性
- **JSON-native:** 與 PHP 的 `json_encode/decode` 天然相容
- **每秒可處理 ~5,000 config 更新** (benchmark 數據)

### 10.3 Caddy API + PHP Reverse Static Proxy 可行性

#### 10.3.1 架構方案

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

#### 10.3.2 PHP 調用 Caddy API 的實現

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

#### 10.3.3 Caddy API 方案 — 優劣分析

| 維度 | 優勢 | 劣勢 |
|------|------|------|
| **動態性** | 零停機新增/移除 tenant routes，~5ms 生效 | Admin API 是 single-node — 多 Caddy 實例需分別 sync |
| **靜態檔效能** | Caddy `file_server` 原生效能，零 PHP 介入 | 大量 tenant (>500) 的 route 表可能影響 Caddy 內部 matcher 效能 |
| **Razy 整合** | PHP 可直接 `curl` → Caddy API，與現有 `CaddyfileCompiler` 互補 | 需維護雙配置來源 (Caddyfile + API)，drift 風險 |
| **安全性** | Admin API bind `localhost:2019`，Docker network 可限制訪問 | API 無內建 auth — 若暴露外部則任何人可修改 routing |
| **可觀測性** | Caddy 內建 Prometheus metrics + access logs | 配置變更缺少 audit trail — 需自建 |
| **Rollback** | `/load` atomic swap — 可保留上一版 config 做 rollback | 無內建 versioned config — 需自行持久化歷史 |

#### 10.3.4 與現有 CaddyfileCompiler 的整合策略

```
方案 A: Caddyfile 為 Source of Truth (RECOMMENDED for ≤50 tenants)
─────────────────────────────────────────────────────────────────

  PHP CLI:  php Razy.phar rewrite --caddy
      │
      ▼
  CaddyfileCompiler.php → generates Caddyfile
      │
      ▼
  caddy reload --config Caddyfile    ← graceful reload (~50ms)


方案 B: Caddy API 為 Source of Truth (RECOMMENDED for >50 tenants)
─────────────────────────────────────────────────────────────────

  PHP:  TenantProvisioner → POST /load (atomic swap)
      │
      ▼
  Caddy Admin API → in-memory config (no file)
      │
      ▼
  Periodic: caddy adapt → dump current config for backup  

  CaddyfileCompiler 退化為 initial bootstrap only.


方案 C: Hybrid (Source of Truth = PHP database/config)
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

### 10.4 Tenant Layer 在 Static Routing 的角色

#### 10.4.1 Distributor 的 Static File 職責

**Distributor** 是 static file routing 的**數據源頭** (但非執行者)：

```php
// CaddyfileCompiler 從以下 Distributor 數據生成 Caddy 規則：

// 1. Webasset paths — 來自 ModuleInfo::getContainerPath()
//    每個 module 的 webassets/ 目錄位置
$containerPath = $moduleInfo->getContainerPath(true);
// → "modules/vendor/package/default"

// 2. Data mapping — 來自 Distributor::getDataMapping()  
//    跨站數據映射 (e.g., tenant A 的 /data/ 指向 tenant B 的存儲)
$dataMapping = $distributor->getDataMapping();
// → ['/uploads' => ['domain' => 'tenantB.com', 'dist' => 'main']]

// 3. Module alias + version — URL path component
$alias   = $moduleInfo->getAlias();    // "Shop"
$version = $moduleInfo->getVersion();  // "1.0.0"
// → /webassets/Shop/1.0.0/css/style.css
```

**在 multi-container 環境下的變化：**

| 單體 (current) | Multi-Container (target) |
|----------------|--------------------------|
| Distributor 與 Caddy 同進程 | Distributor 在 tenant container 內，Caddy 在 front-door |
| `CaddyfileCompiler` 直接讀 filesystem | 需要 build-time 或 API 同步 webasset 路徑 |
| Data mapping 指向本地 `data/` | Data mapping 指向 shared volume 或 remote storage |
| 一個 Caddyfile 管所有 tenant | Front-door Caddy + per-container Caddy (二級) |

#### 10.4.2 Tenant Provisioning Flow (Caddy API 整合)

```
Tenant Provisioning (新租戶建立):

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

### 10.5 Core Layer Application Routing 在 Static Proxy 的角色

#### 10.5.1 Application 的 Multi-Tenant Routing 職責

`Application.php` 目前擁有以下與 static file proxy 相關的職責：

```
Application 責任矩陣:

  ┌─ Config Management ──────────────────────────────────────────┐
  │  • loadSiteConfig() → sites.inc.php                          │
  │  • updateSites() → parse domains + distributors              │
  │  • writeSiteConfig() → persist changes                       │
  │  ► 這些 config 是 CaddyfileCompiler 的輸入源                  │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Rewrite Generation ─────────────────────────────────────────┐
  │  • updateRewriteRules() → .htaccess (Apache)                 │
  │  • updateCaddyfile() → Caddyfile (Caddy)                     │
  │  ► 靜態檔 routing 規則的「編譯器」── 只在配置變更時執行         │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Domain Resolution ──────────────────────────────────────────┐
  │  • host(FQDN) → matchDomain() → Domain                      │
  │  • Wildcard matching, alias resolution                       │
  │  ► PHP runtime 的 domain matching — static file 不經過此路徑  │
  └──────────────────────────────────────────────────────────────┘
  
  ┌─ Worker Mode Dispatch ───────────────────────────────────────┐
  │  • dispatch(urlQuery) → Domain::dispatchQuery()              │
  │  • Boot-once: Application + Module graph 只 init 一次        │
  │  ► 純 dynamic request — Caddy 已攔截 static file             │
  └──────────────────────────────────────────────────────────────┘
```

#### 10.5.2 Application 在 Caddy API 模式的新角色

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

#### 10.6.1 Docker Compose — 同質 Container 水平擴展

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

**Caddy reverse_proxy 內建 Load Balancing：**

```caddyfile
tenant-a.example.com {
    reverse_proxy tenant-a:8080 {
        # Docker DNS 自動解析多個 replica IP
        # Caddy 預設 round-robin LB policy
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

> **Docker 行為:** 當 `docker-compose up --scale tenant-a=3` 時，Docker 內建 DNS round-robin 會把 `tenant-a` 解析到 3 個 container IP。Caddy 的 `reverse_proxy` 配合 active health check 可自動排除故障 replica。

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

**K8s LB 行為：**

| 層級 | 元件 | LB 策略 | 特性 |
|------|------|---------|------|
| L7 Ingress | Nginx Ingress / Traefik / Caddy Ingress | Host-based routing | SSL termination, path routing |
| L4 Service (ClusterIP) | kube-proxy (iptables/IPVS) | Round-robin / session affinity | Pod IP 自動管理 |
| Sidecar (optional) | Istio Envoy / Linkerd Proxy | Weighted, canary, circuit breaker | mTLS, observability |

#### 10.6.3 FrankenPHP Worker Mode 在 LB 下的特殊考量

```
⚠ Worker Mode + Replicas 注意事項:

  FrankenPHP worker mode 保持 PHP process 常駐，module graph in-memory.
  
  多 replica 時：
  
  1. 每個 replica 獨立 boot — 各自持有完整 module graph, route table
     → OK: 同 image 同 code, boot 結果一致
     
  2. Session 不共享 — session_start() 寫入 container 本地 /tmp
     → FIX: 用 Redis/Memcached session handler (Application 層配置)
     
  3. In-memory cache 不共享 — OpCache, manifest cache 各自獨立  
     → OK: 不影響正確性，只是各 replica 各自 warm up
     
  4. Distributor cache (Domain::$distributorCache) 也各自獨立
     → OK: configCheckInterval 各自計數，config 變更最終一致
     
  5. DI Container (Container.php) singleton 僅 process-local
     → OK for stateless services; 有狀態服務需使用外部存儲
```

### 10.7 同質同版本 Container Mesh 互聯

#### 10.7.1 資料檔結構 (Data File Structure)

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

外部掛載 (Docker volumes / K8s PVC):
────────────────────────────────────
  shared_modules  →  /app/shared        (ReadOnly, 所有同版本 container 共享)
  tenant_data     →  /app/data/{tenant} (ReadWrite, per-tenant)
  shared_assets   →  /app/assets        (ReadOnly, CDN origin — §9.3)
```

#### 10.7.2 Container Mesh 通訊模式

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

**同質 replica 間資料同步策略：**

| 資料類型 | 方案 | 一致性 | 延遲 |
|----------|------|--------|------|
| **Code / webassets** | Same Docker image → identical filesystem | 強一致 | 0 (build-time) |
| **Shared modules** | ReadOnly volume mount | 強一致 | 0 (mount) |
| **User data (uploads)** | Shared PVC / NFS / S3 | 最終一致 | <10ms (NFS), ~50ms (S3) |
| **Session** | Redis / Memcached | 強一致 | <1ms |
| **Cache (OpCache)** | Per-replica (independent) | N/A | 0 |
| **Module graph** | Per-replica (worker mode) | 最終一致 | configCheckInterval (periodic) |
| **Database** | External shared DB (MySQL/PgSQL) | 強一致 | <5ms |

#### 10.7.3 Shared Volume Strategies

```
方案 1: Docker Named Volumes + NFS (中小規模 ≤20 tenants)
─────────────────────────────────────────────────────────
  
  Pro:  簡單、Docker 原生支持
  Con:  NFS 單點故障、寫入效能受限
  
  volumes:
    shared_modules:
      driver: local
      driver_opts:
        type: nfs
        o: addr=nfs-server,rw,soft,timeo=50
        device: ":/exports/razy-shared"


方案 2: K8s PersistentVolumeClaim + CSI Driver (中大規模)
───────────────────────────────────────────────────────
  
  Pro:  K8s 原生、support ReadWriteMany (RWX)
  Con:  需要 CSI driver (EFS, Azure Files, GlusterFS)
  
  apiVersion: v1
  kind: PersistentVolumeClaim
  metadata:
    name: shared-modules-pvc
  spec:
    accessModes: [ReadWriteMany]
    resources: { requests: { storage: 10Gi } }
    storageClassName: efs-sc


方案 3: Object Storage (S3 / MinIO) + Sidecar Sync (大規模 >100 tenants)
────────────────────────────────────────────────────────────────────────
  
  Pro:  無限擴展、CDN 天然整合
  Con:  延遲較高 (~50ms)、需 sync agent
  
  ┌──────────┐      ┌─────────┐      ┌──────────────┐
  │ S3/MinIO │ ←──→ │ Sidecar │ ←──→ │ Local Cache  │
  │ (source  │      │ (s3sync)│      │ (/app/cache) │
  │  of truth)│      │ interval│      │ read-only    │
  └──────────┘      │ = 30s   │      └──────────────┘
                    └─────────┘
```

### 10.8 Market Comparison (市場對比)

#### 10.8.1 Static File Reverse Proxy / Dynamic Routing 方案對比

| 方案 | 動態路由 | 靜態檔效能 | Multi-Tenant 支援 | Docker/K8s 整合 | PHP 整合 | 複雜度 |
|------|---------|-----------|-------------------|----------------|---------|--------|
| **Caddy + Admin API** | ✅ REST API, ~5ms 生效 | ✅ `file_server` 原生 | ⚠️ 需 custom provisioner | ✅ DNS LB + health check | ✅ curl 即可 | ★★☆ |
| **Traefik** | ✅ Docker labels 自動發現 | ⚠️ 需 file provider 或 plugin | ✅ 原生 router 概念 | ✅✅ Docker/K8s 原生 | ⚠️ 無直接 API | ★★☆ |
| **Nginx + lua/njs** | ⚠️ 需 `ngx_http_lua` 模組 | ✅ 靜態檔 benchmark 王者 | ⚠️ 需 custom config 生成 | ⚠️ 無原生動態發現 | ❌ Reload 需信號 | ★★★ |
| **Envoy** | ✅ xDS API (gRPC) | ✅ 高效能 | ✅ cluster/route 動態 | ✅ Istio sidecar | ❌ 需控制面板 | ★★★★ |
| **HAProxy** | ⚠️ Runtime API 有限 | ✅ 效能極佳 | ⚠️ 需 template 生成 | ⚠️ 非原生 | ❌ 複雜 config | ★★★ |
| **Cloudflare Workers** | ✅ Edge function | ✅ 全球 CDN 邊緣 | ✅ Worker Routes | N/A (hosted) | ❌ JS/WASM only | ★★ |
| **AWS ALB + S3** | ✅ Target Group API | ✅ S3 = 分散式存儲 | ✅ 多 TG 多域名 | ✅ ECS/EKS 整合 | ⚠️ SDK needed | ★★★ |

#### 10.8.2 Razy 方案定位分析

```
                    動態路由能力
                    ↑
                    │
         Envoy ●   │        ● Traefik
       (xDS gRPC)  │     (Docker auto-
                    │      discovery)
                    │
         HAProxy ●  │   ● Caddy API  ←── Razy 最佳選擇
                    │     (REST JSON)
                    │
          Nginx ●   │
        (signal     │
         reload)    │
                    │
                    └──────────────────────→ 運維簡潔度
```

#### 10.8.3 Why Caddy API for Razy

| 決策因素 | Caddy 勝出原因 |
|----------|---------------|
| **FrankenPHP 一體化** | Caddy 是 FrankenPHP 的底層 — 同一進程，零額外 hop |
| **PHP 友好** | REST JSON API + curl — 不需 gRPC client (Envoy) 或 Docker socket (Traefik) |
| **靜態 + 動態統一** | `file_server` + `reverse_proxy` + `php_server` 在同一 config |
| **Auto HTTPS** | Let's Encrypt 自動證書 — multi-tenant 域名免手動配置 |
| **已有基礎** | `CaddyfileCompiler` 已存在 — 只需增加 API 模式 |
| **Worker mode** | FrankenPHP worker 已驗證（37× vs cold boot, §benchmark） |

#### 10.8.4 不選其他方案的原因

| 方案 | 不選原因 |
|------|---------|
| **Traefik** | Docker label 自動發現很方便，但失去 FrankenPHP 一體化 — 需額外 PHP-FPM/Apache container |
| **Nginx** | 無原生動態路由 API — 要用 nginx-proxy-manager 或 OpenResty lua 腳本，增加運維複雜度 |
| **Envoy** | 控制面板 (gRPC xDS server) 開發成本太高 — 適合 Istio 生態，不適合 PHP 中小框架 |
| **Cloudflare Workers** | Edge-only — 不能用於 self-hosted 部署，Razy 核心使用場景是 on-premise |
| **AWS ALB** | Vendor lock-in — Razy 需保持雲廠商中立 |

### 10.9 Recommended Architecture (推薦架構)

#### 10.9.1 Phase 2 (Docker) 推薦架構

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

#### 10.9.2 Phase 4 (K8s) 推薦架構

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

| 項目 | 工時 | 優先級 | 依賴 | 說明 |
|------|------|--------|------|------|
| `TenantProvisioner` class | 8h | P0 | Phase 1 | Caddy API CRUD + config versioning |
| `Application::syncCaddyRoutes()` | 4h | P0 | TenantProvisioner | 把 multisite config 推到 Caddy |
| Docker Compose 多租戶模板 | 4h | P1 | Phase 2 | 含 Caddy front-door + volume mapping |
| Shared volume 策略 (NFS/PVC) | 3h | P1 | Phase 2 | webassets + data 共享 |
| Health check endpoint | 2h | P1 | Phase 1 | `GET /health` → 200 OK |
| K8s Deployment/Service YAML | 6h | P2 | Phase 4 | Helm chart + HPA |
| Tenant Operator (CRD) | 20h | P3 | Phase 4 | K8s custom controller (Go) |
| S3/MinIO asset sync sidecar | 8h | P3 | Phase 4 | 大規模資產分發 |

**Total: ~55h** (Phase 2: ~21h, Phase 4: ~34h)

### 10.11 Risk Assessment

| 風險 | 嚴重度 | 緩解措施 |
|------|--------|---------|
| Caddy Admin API 無 auth | HIGH | Bind `127.0.0.1:2019` + Docker network 隔離 |
| Config drift (Caddyfile vs API) | MEDIUM | 方案 C hybrid — DB 為 source of truth，定期 dump 備份 |
| Shared volume SPOF | MEDIUM | NFS HA / Multi-AZ PVC / S3 multi-region |
| Worker mode session 不共享 | HIGH | Redis session handler (已有解法) |
| 大量 tenant (>500) route 效能 | LOW | Caddy 內部 trie 結構，已最佳化 host-matching |
| FrankenPHP 長期記憶體 | MEDIUM | `max_requests` 配置 + K8s liveness probe 定期重啟 |

### 10.12 Summary & Decision Matrix

| 維度 | 推薦 | 理由 |
|------|------|------|
| **Static proxy** | Caddy `file_server` on front-door Caddy | 零 PHP 介入、原生效能、與 FrankenPHP 一體 |
| **Dynamic routing** | Caddy Admin API (`/load`) | ~5ms zero-downtime，PHP curl 即可調用 |
| **Config management** | Hybrid (DB + periodic file backup) | 避免 drift，保留災難恢復能力 |
| **Docker LB** | Caddy `reverse_proxy` + Docker DNS | 內建 health check、round-robin、zero config |
| **K8s LB** | K8s Service + Ingress Controller | 原生 pod 擴縮、HPA auto-scale |
| **Data sharing** | Phase 2: NFS/Docker volume；Phase 4: CSI/S3 | 漸進式 — 不過度工程 |
| **Session sharing** | Redis (external) | 已有成熟方案，與所有 container 共享 |
| **Core layer role** | Application 新增 `syncCaddyRoutes()` — 事件驅動 | 替代原有 CLI-only `updateCaddyfile()` |
| **Tenant layer role** | Distributor 保持不變 — 數據源角色 | CaddyfileCompiler / TenantProvisioner 消費其數據 |

---

## 11. Core-Delegated Volume + Static File External Access Feasibility

> **背景：** Tenant container mount 由 Core 層委派的 volume，可同時解決三個問題：  
> (1) Module 產生的檔案有持久化寫入點  
> (2) Container rootfs 保持 immutable (read-only)  
> (3) 每個 container 只看到自己的 volume — 天然隔離  
>  
> **唯一難題：** volume 內的靜態檔案 (webassets/, data/) 如何讓外部瀏覽器訪問？

### 11.1 問題定義

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
    ─── 外部瀏覽器無法直接觸及 ─────────────────────
```

**兩類靜態檔：**

| 類型 | 來源 | 位置 | 特性 |
|------|------|------|------|
| **Webassets** | Module 開發者打包的 CSS/JS/images | Image 內 `modules/*/webassets/` | Build-time 已知、versioned、可快取 |
| **Data files** | Module runtime 產生 (uploads, reports) | Volume mount `/app/data/` | Runtime 產生、動態、需權限控制 |

### 11.2 方案總覽

```
┌───────────────────────────────────────────────────────────────────────┐
│  方案 A: Proxy-Through (反向代理穿透)                                  │
│  方案 B: Front-Door Volume Mount (前置服務掛載)                        │  
│  方案 C: Build-Time Asset Extraction (建置時提取)                      │
│  方案 D: Sidecar Asset Sync (邊車同步到共享層)                         │
│  方案 E: Caddy On-Demand Reverse File Server (按需反向檔案服務)         │
│  方案 F: Object Storage + CDN (物件儲存 + CDN)                        │
└───────────────────────────────────────────────────────────────────────┘
```

### 11.3 方案 A: Proxy-Through (反向代理穿透)

**原理：** Front-door Caddy 把 `/webassets/*` 和 `/data/*` 的請求也 reverse_proxy 到 tenant container，由 container 內的 FrankenPHP/Caddy 用 `file_server` 直接回應。

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

**Tenant Container 內 Caddyfile (自動生成):**
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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★☆☆ (LOW) | 零額外基礎設施 — 原有 CaddyfileCompiler 已支持 |
| **效能** | ★★☆ (MEDIUM) | 多一跳 reverse_proxy hop (~0.1ms)；但 Caddy file_server 仍是直接檔案讀取 |
| **隔離性** | ★★★ (HIGH) | 每個 container 只 serve 自己的檔案，無需共享 volume |
| **一致性** | ★★★ (HIGH) | Webassets 來自 image = 與 code 版本嚴格一致 |
| **擴展性** | ★★☆ (MEDIUM) | 靜態流量佔用 container 資源；高流量需 CDN 前置 |
| **改動量** | 0h | **現有架構已支持** — 無需任何 code change |

### 11.4 方案 B: Front-Door Volume Mount (前置服務掛載)

**原理：** Front-door Caddy 同時掛載每個 tenant container 的 webasset directory (read-only)，直接用 `file_server` serve，不經過 tenant container。

```
  Browser → GET /webassets/Shop/1.0.0/css/style.css
      │
      ▼
  Caddy Front-Door
      │  @webasset_tenant_a path /webassets/Shop/*
      │  handle → file_server (from mounted volume)
      │  ※ file_server 直接讀取，不經 reverse_proxy
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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★★☆ (MEDIUM) | 每新增 tenant 需同步掛載 volume 到 front-door |
| **效能** | ★★★ (HIGH) | 零 proxy hop — Caddy 直讀本地檔案系統 |
| **隔離性** | ★★☆ (MEDIUM) | Front-door 可看到所有 tenant 的 webassets (read-only) |
| **一致性** | ★★☆ (MEDIUM) | 需確保 volume 內容與 container image 同步 |
| **擴展性** | ★☆☆ (LOW) | Volume mount 數隨 tenant 線性增長 — >50 tenants 不可行 |
| **改動量** | ~6h | Volume provisioning script + CaddyfileCompiler 新模式 |

**⚠ 關鍵問題：Webassets 在 Image 內 vs Volume 內**

目前 Razy 的 webassets 是 module code 的一部分，baked in Docker image 內。如果 container rootfs 是 read-only，webassets 也是 read-only — 這正是理想狀態。但 front-door 要透過 volume 讀取它們，有兩種做法：

```
做法 B1: Shared Volume from Image (Docker named volume + init container)
───────────────────────────────────────────────────────────────────────
  
  # Init container copies webassets from image to volume
  init-tenant-a:
    image: razy-tenant:1.0.1-beta
    command: ["cp", "-r", "/app/site/sites/main/modules", "/export/"]
    volumes:
      - tenant_a_modules:/export
  
  → 問題: Image 更新時需重新跑 init container

做法 B2: Docker --volumes-from (共享 container filesystem)
──────────────────────────────────────────────────────────
  
  caddy-front:
    volumes_from:
      - tenant-a:ro
  
  → 問題: 安全風險 — front-door 看到 tenant container 的整個 filesystem
  → 已棄用在 Docker Compose v3，不推薦
```

### 11.5 方案 C: Build-Time Asset Extraction (建置時提取)

**原理：** 在 Docker image 建置或 CI/CD pipeline 中，把 webassets 提取到一個共享的 assets 目錄 (volume / S3 / CDN origin)。

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

**Dockerfile 多階段建置 (推薦):**
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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★★★ (HIGH) | CI/CD pipeline 改動、webasset 提取腳本、多 image 管理 |
| **效能** | ★★★ (HIGH) | CDN 邊緣、零 PHP、零 proxy hop |
| **隔離性** | ★★★ (HIGH) | Webassets 只讀提取，tenant container 不受影響 |
| **一致性** | ★★★ (HIGH) | Build-time 與 code 版本嚴格繫結 |
| **擴展性** | ★★★ (HIGH) | CDN/S3 無限擴展、tenant 數量不影響 |
| **改動量** | ~12h | Dockerfile multi-stage + CI pipeline + CaddyfileCompiler CDN 模式 |

### 11.6 方案 D: Sidecar Asset Sync (邊車同步)

**原理：** 每個 tenant pod/container 配一個 sidecar，定期或 event-driven 把 webassets/data 同步到共享存儲。

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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★★★ (HIGH) | 額外 sidecar container、fswatch/inotify、sync 邏輯 |
| **效能** | ★★★ (HIGH) | 一旦同步完成，CDN 直 serve |
| **隔離性** | ★★★ (HIGH) | Sidecar 只讀 source → 寫 target |
| **一致性** | ★★☆ (MEDIUM) | 有同步延遲 (秒級)；data files 尤其 |
| **擴展性** | ★★★ (HIGH) | 同方案 C — 後端是 S3/CDN |
| **改動量** | ~10h | Sidecar image + sync script + K8s pod spec |

**適用場景：** Webassets 是 build-time 固定的 (方案 C 更好)，但 **data files (runtime 產生的)** 需要動態同步時，sidecar 方案更合適。

### 11.7 方案 E: Caddy On-Demand Reverse File Server

**原理：** Front-door Caddy 收到 `/webassets/*` 請求時，動態向 tenant container 發起一次逆向取檔，同時做本地快取。後續同檔案請求直接從快取回應。

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

**替代方案 E2 (無需 cache module)：** 利用 Caddy 內建的 `file_server` + `reverse_proxy` fallback：
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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★★☆ (MEDIUM) | cache module 需 custom Caddy build；或用 E2 fallback 方案 |
| **效能** | ★★★ (HIGH) | First hit 經 proxy (~0.2ms)，後續 hit 純本地 cache |
| **隔離性** | ★★★ (HIGH) | 不需共享 volume — 純 network cache |
| **一致性** | ★★★ (HIGH) | Versioned URL → cache key 永不過期直到版本變更 |
| **擴展性** | ★★★ (HIGH) | Cache 可水平擴展、CDN 層進一步擴展 |
| **改動量** | ~4h | Caddy cache module build + CaddyfileCompiler cache 模式 |

### 11.8 方案 F: Object Storage + CDN

**原理：** 不在 Caddy 層做，而是 module 發佈 webassets 時直接推送到 S3/MinIO，前端透過 CDN URL 訪問。

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

| 維度 | 評分 | 說明 |
|------|------|------|
| **複雜度** | ★★★ (HIGH) | S3 SDK、CDN 配置、asset publish pipeline |
| **效能** | ★★★★ (BEST) | CDN 全球邊緣，延遲 <10ms |
| **隔離性** | ★★★ (HIGH) | S3 bucket policy — per-tenant prefix isolation |
| **一致性** | ★★★ (HIGH) | 版本化路徑 = 永不衝突 |
| **擴展性** | ★★★★ (BEST) | 無限 — S3 + CloudFront |
| **改動量** | ~16h | S3 upload logic + CDN setup + `getAssetUrl()` + env config |

### 11.9 方案對比矩陣

| 方案 | 複雜度 | 效能 | 隔離 | 一致性 | 擴展 | 改動量 | 適用規模 |
|------|--------|------|------|--------|------|--------|----------|
| **A. Proxy-Through** | ★☆☆ | ★★☆ | ★★★ | ★★★ | ★★☆ | **0h** | ≤20 tenants |
| **B. Front-Door Mount** | ★★☆ | ★★★ | ★★☆ | ★★☆ | ★☆☆ | 6h | ≤10 tenants |
| **C. Build-Time Extract** | ★★★ | ★★★ | ★★★ | ★★★ | ★★★ | 12h | Any |
| **D. Sidecar Sync** | ★★★ | ★★★ | ★★★ | ★★☆ | ★★★ | 10h | Any (data-heavy) |
| **E. On-Demand Cache** | ★★☆ | ★★★ | ★★★ | ★★★ | ★★★ | **4h** | ≤100 tenants |
| **F. S3 + CDN** | ★★★ | ★★★★ | ★★★ | ★★★ | ★★★★ | 16h | >100 tenants |

### 11.10 推薦分階段策略

```
Phase 2 (Docker, ≤20 tenants):
────────────────────────────────

  推薦: 方案 A (Proxy-Through) — 零改動

  理由:
  • CaddyfileCompiler 已生成 tenant 內的 webasset file_server 規則
  • Front-door Caddy 只需 reverse_proxy → tenant:8080
  • 靜態檔由 tenant container 自己的 Caddy file_server 回應
  • Webasset 來自 image 內 (immutable) — 一致性保證
  • Data files 來自 volume mount — file_server 同樣可 serve
  • 唯一「代價」是多一跳 reverse_proxy (~0.1ms) — 可忽略
  
  加入 CDN 前置 (optional):
  
    CloudFlare → Front-Door Caddy → Tenant Container
                                       ↑ file_server
  
  CloudFlare 會 cache 有 `Cache-Control: immutable` 的回應，
  第二次訪問同檔案零延遲。


Phase 2+ (Docker, 20-100 tenants):
──────────────────────────────────

  推薦: 方案 A + 方案 E (On-Demand Cache) 混合

  理由:
  • 方案 A 作為 baseline (已有)
  • 方案 E 增加 front-door cache 層 — 大幅減少 upstream 靜態請求
  • Versioned URL → cache 命中率趨近 100%
  • 只需 4h 改動 (custom Caddy build with cache module)
  
  架構:
  
    Browser → Caddy Front-Door (cache HIT?) 
                  ├─ YES → 直接回應 (0 hop)
                  └─ NO  → reverse_proxy → tenant:8080 → file_server
                            → cache store for next time


Phase 4 (K8s, >100 tenants):
─────────────────────────────

  推薦: 方案 C (Build-Time Extract) + 方案 F (S3 + CDN) 

  理由:
  • CI/CD pipeline 已經是標配 — 加一步 webasset 提取成本低
  • S3/MinIO 提供無限存儲 + 可靠性
  • CDN 全球邊緣 — 最佳延遲
  • Controller::getAssetUrl() 使用 RAZY_ASSET_CDN env 切換
  • Data files 用方案 D (sidecar) 同步到 S3
```

### 11.11 Volume 掛載設計 (Core-Delegated)

以下是 Core 層委派 volume 給 tenant 的具體設計：

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
    # NOTE: NO tenant volume mounts needed (方案 A — proxy-through)
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

**Volume 職責矩陣：**

| Volume | Owner | 掛載對象 | RW 模式 | 內容 |
|--------|-------|---------|---------|------|
| `tenant_{id}_data` | Core 委派 | Tenant container only | RW | uploads/, cache/, config/ |
| `shared_modules` | Core 管理 | All tenant containers | RO | 全局共用 module (e.g., auth, theme) |
| `core_config` | Core only | Core orchestrator | RW | tenant registry, Caddy config history |

**Webassets 不需要額外 volume** — 它們 baked 在 Docker image 內，rootfs read-only 模式下仍可讀取。Container 內的 `file_server` 可正常 serve。

### 11.12 流量路徑總結

```
═══════════════════════════════════════════════════════════════════════

  Static File (webasset) — Phase 2 (方案 A):
  
    Browser
      → CDN (optional — cache immutable assets)
        → Caddy Front-Door (reverse_proxy)
          → Tenant Container (Caddy file_server)
            → Image Filesystem (read-only) ✅
    
    延遲: CDN hit 0ms | CDN miss ~0.5ms | 直連 ~0.3ms

═══════════════════════════════════════════════════════════════════════

  Static File (webasset) — Phase 2+ (方案 A + E):
  
    Browser
      → CDN (optional)
        → Caddy Front-Door (local cache HIT?)
          ├─ HIT → respond immediately ✅
          └─ MISS → reverse_proxy → Tenant → file_server → cache store
    
    延遲: Cache hit ~0.1ms | Cache miss ~0.3ms (then cached)

═══════════════════════════════════════════════════════════════════════

  Data File (user uploads) — Phase 2:
  
    Browser
      → CDN (short cache — max-age 3600)
        → Caddy Front-Door (reverse_proxy)
          → Tenant Container (Caddy file_server)
            → Volume Mount (writable) ✅
    
    延遲: ~0.3ms (always through proxy — data is dynamic)

═══════════════════════════════════════════════════════════════════════

  Dynamic PHP Request:
  
    Browser
      → Caddy Front-Door (reverse_proxy)
        → Tenant Container (FrankenPHP worker)
          → Razy Application → Distributor → RouteDispatcher
            → Module Controller (PHP logic)
    
    延遲: ~1-5ms (depends on route complexity)

═══════════════════════════════════════════════════════════════════════
```

### 11.13 Key Insight (關鍵洞察)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   Webassets 已在 Image 內 → Container file_server 可直接 serve  │
  │   → Front-door 只需 reverse_proxy → 零額外改動 (方案 A)         │
  │                                                                 │
  │   Data files 在 Volume 內 → 同樣走 file_server                 │
  │   → 每個 container 只看到自己的 volume                          │
  │                                                                 │
  │   唯一「代價」是 reverse_proxy 多一跳 (~0.1ms)                  │
  │   → CDN + cache 完全消除此代價                                  │
  │                                                                 │
  │   ∴ Core-delegated volume 模式 + 方案 A = 零改動解決方案        │
  │   ∴ 後續加入方案 E (cache) 和方案 F (S3+CDN) 是增量改進        │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 12. Data Access Rewrite (Module-Controlled) + Webassets Under Load Balancing

> **前提：** §11 已確認 webassets (image 內) 和 data files (volume 內) 可透過 container 內的 `file_server` 直接 serve。本節解決兩個尚未涵蓋的實際問題：
> 1. **Data Access — Module-Controlled Cross-Dist Rewrite:** 模組如何控制自己的 data folder 允許其他 Distributor 作為 rewrite target
> 2. **Webassets Under Load Balancing:** 多 Replica 下 `reverse_proxy` + `file_server` 的 rewrite 一致性問題

### 12.1 問題背景

#### 問題 ①: Data Files 的前端 Rewrite — 缺少 Module 級控制

**現狀：** `dist.php` 中的 `data_mapping` 是 **消費端 (consumer-side)** 配置 — 由「想讀取」的 Distributor 聲明它要掛載哪個其他 Distributor 的 data path：

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

**CaddyfileCompiler 生成的規則：**
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

**問題：** 目前 **沒有** 機制讓被訪問的模組 (producer) 控制哪些 data 子目錄可以被外部 Distributor 存取。任何 Distributor 只要知道另一個 Distributor 的 domain:code，就可以在 `data_mapping` 中掛載並讀取 **全部** data files。

```
  ⚠ Security Gap:
  
  Module A (uploads/) → 應只允許 public/images/ 子目錄被外部讀取
  Module A (cache/)   → 不應被外部 Distributor 存取
  Module A (reports/) → 應限定特定 Distributor 才能存取
  
  但目前 data_mapping 掛載的是整個 data/{domain}-{dist}/ 目錄
  → 所有子目錄對所有 consumer 一視同仁
```

#### 問題 ②: Webassets Under Load Balancing

```
  Browser → Caddy Front-Door (reverse_proxy lb_policy round_robin)
               ├─→ Replica 1 (tenant-a:8080)  → file_server /webassets/Shop/*
               ├─→ Replica 2 (tenant-a:8080)  → file_server /webassets/Shop/*
               └─→ Replica 3 (tenant-a:8080)  → file_server /webassets/Shop/*
  
  Q: Replica 1 生成的 URL 是 /webassets/Shop/1.0.0/css/style.css
     → 如果下一個請求被 LB 導到 Replica 2，Replica 2 也有同樣的檔案嗎？
     → Rewrite 規則在每個 Replica 上都一致嗎？
```

### 12.2 現有架構回顧 — Data 層

**Data 檔案的生命週期：**

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

**URL Access (前端)：**
```
  $this->getDataPathURL('my_module')
      → Distributor::getDataPath('my_module', true)
      → PathUtil::append($this->getSiteURL(), 'data', 'my_module')
      → https://example.com/data/my_module/
  
  Browser: GET https://example.com/data/my_module/uploads/image.jpg
      → Caddy @data matcher → file_server → /app/data/example.com-main/my_module/uploads/image.jpg
```

**Cross-Dist Access (目前)：**
```
  Blog Dist 想讀 Main Dist 的 data:
  
  dist.php: 'data_mapping' => ['/' => 'example.com:main']
      → CaddyfileCompiler 生成:
        @data_blog__0 path /data/*
        handle @data_blog__0 {
            root * /app/public/data/example.com-main   ← 整個目錄
            file_server
        }
  
  → Blog 的前端可以讀取 Main 的 **所有** data files
  → 無粒度控制
```

### 12.3 設計方案: Module-Level Data Export Declaration

**核心思路：** 在模組的 `package.php` 中新增 `data_exports` 配置，由 **生產端 (producer module)** 聲明哪些 data 子目錄對外可見，以及允許哪些 Distributor 可以作為 rewrite target。

#### 12.3.1 package.php 新增 `data_exports` 欄位

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
        // 子目錄 => 存取規則
        'uploads/images' => [
            'access' => 'public',              // 任何 Dist 都可 rewrite
        ],
        'uploads/avatars' => [
            'access' => 'public',
        ],
        'reports' => [
            'access' => 'restricted',          // 限定 Dist 才可 rewrite
            'allow'  => ['admin', 'analytics'],// 只允許這些 dist code
        ],
        'cache' => [
            'access' => 'private',             // 完全不對外 (預設)
        ],
    ],
];
```

**存取等級定義：**

| 等級 | 意義 | Rewrite Target |
|------|------|----------------|
| `public` | 任何 Distributor 透過 `data_mapping` 掛載後可存取此子目錄 | ✅ 所有 |
| `restricted` | 只有 `allow` 清單中的 dist code 可存取 | ✅ 限定 |
| `private` | 不對外暴露 — 即使對方 `data_mapping` 掛載了也拒絕 | ❌ 無 |
| *(未聲明)* | **預設 `private`** — 未在 `data_exports` 中聲明的子目錄不對外 | ❌ 無 |

#### 12.3.2 架構層級 — 誰負責什麼

```
                             Build Time (CLI: php Razy.phar rewrite)
  ┌────────────────────────────────────────────────────────────────────┐
  │                                                                    │
  │  ① ModuleInfo::parseDataExports()                                 │
  │     讀取 package.php['data_exports'] → stored in $this->dataExports│
  │                                                                    │
  │  ② Distributor::getDataExports()                                  │
  │     聚合所有已載入 module 的 data_exports → 合併為 dist-level map  │
  │                                                                    │
  │  ③ CaddyfileCompiler::compileDataMappingHandlers()                │
  │     ← 現在: 直接掛載 data/{domain}-{dist}/ 整個目錄               │
  │     → 新版: 對每個 consumer 的 data_mapping entry, 查詢 producer  │
  │             的 data_exports → 只生成 allowed 子目錄的 matcher      │
  │                                                                    │
  │  ④ RewriteRuleCompiler::compileDataMappingRules()                 │
  │     同上 — htaccess 版本                                           │
  │                                                                    │
  └────────────────────────────────────────────────────────────────────┘
```

#### 12.3.3 新的 Caddyfile 輸出 (精細化 Data Matcher)

**Before (§11 — 粗粒度)：**
```caddyfile
# Blog dist mounts Main dist's data — ENTIRE directory
@data_blog__0 path /data/*
handle @data_blog__0 {
    uri strip_prefix /data
    root * /app/public/data/example.com-main
    file_server
}
```

**After (§12 — module-level 粒度)：**
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

#### 12.3.4 caddyfile.tpl 模板擴展

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

**htaccess.tpl 對應擴展：**
```
    <!-- START BLOCK: data_export -->
    RewriteCond %{ENV:RAZY_DOMAIN} ={$domain}
    RewriteRule ^{$route_path}data/{$module_code}/{$sub_path}/(.+)$ {$data_base_path}/{$module_code}/{$sub_path}/$1 [L]
    <!-- END BLOCK: data_export -->
```

#### 12.3.5 Self-Dist Data Access (本 Dist 的 Module)

同一個 Distributor 內的 module 存取自己的 data 時，**不受 `data_exports` 限制** — 因為它在同一個 process 內，走的是 `Distributor::getDataPath()` 直接檔案系統存取，不經 rewrite。

但前端（瀏覽器）透過 URL 存取本 Dist 的 data 時，仍需 rewrite。此時 CaddyfileCompiler 需區分兩種場景：

```
  A. Self-Access (本 Dist 前端 → 本 Dist data):
     → 預設全目錄開放 (維持現有行為)
     → 除非 module 聲明 `self_restrict: true` 才啟用精細控制
  
  B. Cross-Access (其他 Dist 前端 → 本 Dist data via data_mapping):
     → 受 data_exports 嚴格控制
     → 未聲明 = private = 拒絕
```

**self_restrict 選項 (進階)：**
```php
'data_exports' => [
    'uploads/images' => [
        'access'        => 'public',
        'self_restrict'  => false,    // 預設 false — 本 Dist 前端不受限
    ],
    'cache' => [
        'access'        => 'private',
        'self_restrict'  => true,     // 即使本 Dist 前端也不可經 URL 存取
    ],
],
```

### 12.4 實作路徑 — Code Changes

#### 12.4.1 ModuleInfo 擴展

```php
// src/library/Razy/ModuleInfo.php — 新增屬性 + 方法

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

#### 12.4.2 Distributor 擴展

```php
// src/library/Razy/Distributor.php — 新增方法

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

#### 12.4.3 CaddyfileCompiler 改動

```php
// CaddyfileCompiler::compileDataMappingHandlers() — 重構

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

#### 12.4.4 工時估算

| 項目 | 改動 | 工時 |
|------|------|------|
| `ModuleInfo::parseDataExports()` | 新增 `data_exports` parsing + `isDataExportAllowed()` | 2h |
| `Distributor::getDataExports()` | 聚合所有 module exports | 1h |
| `CaddyfileCompiler` 重構 | 精細化 data matcher 生成 | 4h |
| `RewriteRuleCompiler` 對應改動 | htaccess 版本 | 2h |
| `caddyfile.tpl` + `htaccess.tpl` | 新 `data_export` block | 1h |
| 單元測試 | parseDataExports, isDataExportAllowed, compiler tests | 4h |
| 整合測試 | 端對端 rewrite 驗證 (Caddy + Apache) | 2h |
| **合計** | | **~16h** |

### 12.5 Webassets Under Load Balancing — 問題分析

#### 12.5.1 LB 架構回顧

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

#### 12.5.2 關鍵事實: Image 一致性保證

**Webassets 在 Docker image 內 → 所有 Replica 完全相同**

```
  Replica 1:
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/css/style.css  ← ✅
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/js/app.js      ← ✅
  
  Replica 2:
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/css/style.css  ← ✅ 完全一致
    /app/site/sites/main/modules/vendor/shop/1.0.0/webassets/js/app.js      ← ✅ 完全一致
  
  Replica 3:
    (同上) ← ✅
```

**原因：**
1. 所有 Replica 來自同一個 Docker image tag (`razy-tenant:1.0.1-beta`)
2. Module code (含 webassets) 是 **build-time** baked in image
3. Image layer 是 content-addressable (SHA256) — 完全一致
4. Container rootfs 是 read-only — 不可能被 runtime 修改

#### 12.5.3 Rewrite 規則一致性

每個 Replica 內的 Caddy (由 CaddyfileCompiler 產生) 有相同的 `@webasset_*` matchers：

```
  ┌─ 每個 Replica 內部的 Caddyfile (IDENTICAL) ──────────────┐
  │                                                           │
  │  :8080 {                                                  │
  │      root * /app/site                                     │
  │                                                           │
  │      # Webassets: Shop (所有 Replica 一模一樣)            │
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

**∴ Load Balancer 無論把 webasset 請求導到哪個 Replica，都能正確回應。**

#### 12.5.4 為什麼 Webassets 在 LB 下是「非問題」(方案 A)

| 維度 | 狀態 | 原因 |
|------|------|------|
| **檔案一致性** | ✅ 保證 | 同一 image → 同一 webasset 內容 |
| **Rewrite 一致性** | ✅ 保證 | CaddyfileCompiler 在 build-time 產生 → baked in image |
| **URL 一致性** | ✅ 保證 | `Controller::getAssetPath()` 回傳 versioned URL → 版本號來自 `package.php` → baked in image |
| **Cache 一致性** | ✅ 保證 | 版本化 URL (`/webassets/Shop/1.0.0/...`) → ETag/Last-Modified 在所有 Replica 相同 |
| **LB Sticky Session** | 不需要 | 檔案內容在所有 Replica 一致 → 無 session affinity 需求 |

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   方案 A (Proxy-Through) 下:                                   │
  │                                                                 │
  │   Webassets + LB = 零問題                                      │
  │                                                                 │
  │   • 所有 Replica 有相同 image → 相同 rewrite 規則 → 相同檔案   │
  │   • Front-door 只做 reverse_proxy → 不需知道具體內容           │
  │   • Round-robin 隨機分配 → 每個 Replica 都能回應 → 無差異     │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

### 12.6 Data Files Under Load Balancing — 真正的挑戰

**Webassets 在 LB 下沒問題，但 Data files 有。**

Data files 是 runtime 產生的 (uploads, reports, cache) → 存在 volume 中 → 如果 Replica 之間不共享 volume，就會有不一致：

```
  ⚠ 場景: User 上傳圖片 → Replica 1 處理 → 存入 Replica 1 的 local volume
  
  Replica 1: /app/data/tenant-a/shop/uploads/photo.jpg  ← ✅ 存在
  Replica 2: /app/data/tenant-a/shop/uploads/photo.jpg  ← ❌ 不存在!
  Replica 3: /app/data/tenant-a/shop/uploads/photo.jpg  ← ❌ 不存在!
  
  → 下一個請求被 LB 導到 Replica 2 → GET /data/shop/uploads/photo.jpg → 404!
```

#### 12.6.1 解決方案: Shared Volume (共享 Volume)

```
  所有 Replica 掛載同一個 Docker named volume 或 NFS:
  
  services:
    tenant-a:
      image: razy-tenant:1.0.1-beta
      deploy:
        replicas: 3
      volumes:
        - tenant_a_data:/app/data/tenant-a    # ← 共享!
  
  volumes:
    tenant_a_data:
      driver: local        # Docker: 所有 Replica 在同一 host → 共享
      # 跨 host: 使用 NFS 或 distributed storage
```

**Docker Swarm / K8s 跨 Host 方案：**

| 方案 | 適用規模 | 延遲 | 一致性 |
|------|---------|-------|--------|
| **NFS** | ≤50 tenants | ~1-5ms | 強一致 (同步寫入) |
| **GlusterFS** | ≤200 tenants | ~2-10ms | 最終一致 (可配同步) |
| **Ceph (RBD/CephFS)** | >200 tenants | ~1-3ms | 強一致 |
| **EFS (AWS)** | Any | ~5-10ms | 強一致 |
| **Longhorn (K8s)** | ≤100 tenants | ~1-3ms | 強一致 (ReadWriteMany) |

#### 12.6.2 Docker Compose (Single Host) — 無問題

在單機 Docker Compose 下，多個 Replica 掛載同一個 named volume = 完全共享：

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
    - ReadWriteMany          # ← 多 Pod 可同時讀寫
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

### 12.7 LB 下的 Rewrite 規則生成 — 實際操作

#### 12.7.1 Caddy Front-Door 配置 (方案 A — 推薦)

**Front-door 完全不需要知道 module/webasset 細節** — 只做 domain → upstream mapping：

```caddyfile
# ── Front-Door Caddyfile (SIMPLE) ──

tenant-a.example.com {
    reverse_proxy {
        to tenant-a:8080      # Docker DNS → round-robin across replicas
        lb_policy round_robin
        health_uri /health
        health_interval 5s

        # Webasset 請求也走 reverse_proxy → Replica 內的 file_server 處理
        # Data 請求也走 reverse_proxy → Replica 內的 file_server 處理 (shared volume)
        # PHP 請求走 reverse_proxy → Replica 內的 FrankenPHP worker 處理
        
        # ∴ Front-door 不需要任何 @webasset / @data matcher
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

**∴ Front-door Caddyfile 極簡 — per-domain 一個 `reverse_proxy` block 即可。**

#### 12.7.2 Tenant Container 內部 Caddyfile (由 CaddyfileCompiler 生成)

```caddyfile
# ── Tenant Container 內部 (每個 Replica 相同) ──

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

#### 12.7.3 htaccess 等效配置 (Apache, 非 LB 場景)

在 Apache 環境下，通常不使用 LB (走 php-fpm 或 mod_php)。htaccess rewrite 與 Caddy 同理，但使用 `RewriteRule` + `RewriteCond`：

```apache
# Cross-dist data export (module-level)
RewriteCond %{ENV:RAZY_DOMAIN} =example.com
RewriteRule ^blog/data/shop/uploads/images/(.+)$ %{ENV:BASE}data/blog.example.com-blog/shop/uploads/images/$1 [L]

# Cross-dist: BLOCKED paths → no RewriteRule generated → falls through to 404 or PHP
# (shop/cache, shop/reports for non-allowed dists → simply not emitted)
```

### 12.8 Rolling Update (滾動更新) — 版本混合期

**LB 下的特殊場景：** Rolling update 期間，部分 Replica 跑新版 image，部分跑舊版。

```
  ┌────────────────────────────────────────────────────┐
  │ Rolling Update: razy-tenant:1.0.1 → 1.0.2         │
  │                                                    │
  │ 時間 T1:                                           │
  │   Replica 1: image 1.0.2 ← 新版 (已更新)         │
  │   Replica 2: image 1.0.1 ← 舊版 (等待更新)       │
  │   Replica 3: image 1.0.1 ← 舊版 (等待更新)       │
  │                                                    │
  │ Webasset URL:                                      │
  │   新版: /webassets/Shop/1.0.2/css/style.css       │
  │   舊版: /webassets/Shop/1.0.1/css/style.css       │
  └────────────────────────────────────────────────────┘
```

**Versioned URL 自動解決版本混合問題：**

```
  1. 用戶 A 在 T1 時刻訪問 → 打到 Replica 1 (新版)
     → PHP 生成 HTML 包含: /webassets/Shop/1.0.2/css/style.css
     → Browser 請求 /webassets/Shop/1.0.2/css/style.css
     → LB 可能導到 Replica 2 (舊版)
     → Replica 2 的 file_server 匹配 @webasset_Shop_
     → 但 URI strip 後找的是 sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css
     → ❌ Replica 2 只有 1.0.1 → 404??
  
  等等 — 不對！讓我們看看 CaddyfileCompiler 的 rewrite 路徑：
```

**修正分析 — CaddyfileCompiler 的 container_path：**

```
  CaddyfileCompiler 使用 ModuleInfo::getContainerPath(true) 作為 root：
  
  containerPath = sites/main/modules/vendor/shop
  (不包含版本號 — 版本號在 URL 中由 browser 自帶)
  
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

**Wait —** `getContainerPath()` returns 容器路徑 (vendor/shop)，再加上版本子目錄 (1.0.0)，最後加 `webassets/file.css`。

在 htaccess template 中可以看到更清楚：
```
RewriteRule ^{route_path}webassets/{mapping}/(.+?)/(.+)$ {dist_path} [END]
```
其中 `dist_path = containerPathRel + /$1/webassets/$2`，`$1` 捕獲版本號，`$2` 捕獲檔案路徑。

**∴ Rolling Update 問題分析：**

```
  Replica 1 (image 1.0.2):
    filesystem: sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css ✅
    filesystem: sites/main/modules/vendor/shop/1.0.1/webassets/css/style.css ❌ (不存在)
  
  Replica 2 (image 1.0.1):
    filesystem: sites/main/modules/vendor/shop/1.0.1/webassets/css/style.css ✅
    filesystem: sites/main/modules/vendor/shop/1.0.2/webassets/css/style.css ❌ (不存在)
  
  用戶 A: HTML from Replica 1 → URL /webassets/Shop/1.0.2/...
          → LB 導到 Replica 2 → 找 1.0.2 → 404 ⚠
  
  用戶 B: HTML from Replica 2 → URL /webassets/Shop/1.0.1/...
          → LB 導到 Replica 1 → 找 1.0.1 → 404 ⚠
```

**Rolling Update 是唯一會出問題的場景！**

#### 12.8.1 解決方案

**策略 1: Blue-Green Deployment (推薦)**

```
  不做 rolling update → 而是 blue-green:
  
  1. 啟動全部新 Replica (green) 並行舊 Replica (blue)
  2. 健康檢查通過後，一次性切換 LB 到 green
  3. 確認無問題後銷毀 blue
  
  → 不存在版本混合期 → 不會有 404
```

**策略 2: Versioned Upstream (多版本共存)**

```caddyfile
# Front-door 配置兩個 upstream (rolling update 期間)
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

**複雜度高 — 僅在大規模 K8s 下有意義。**

**策略 3: CDN Cache Warming (最實用)**

```
  Rolling update 前:
  1. CDN 已 cache 了 1.0.1 版 webassets (immutable, 1yr max-age)
  2. 部署 1.0.2 → 新 Replica 上線
  3. 新 HTML 引用 1.0.2 → CDN 尚未有 cache
  4. CDN → Front-door → 任意 Replica
     → 如果打到舊 Replica → 404
     → CDN 不 cache 404
     → Browser retry (或 CDN retry with next upstream)
  5. 打到新 Replica → 200 → CDN cache
  6. 舊 Replica 也更新完成 → 全部一致
  
  影響: Rolling update 的幾秒內，少數 webasset 請求可能 404
  → Browser 會用 cache 版本 (如果之前訪問過)
  → 新用戶可能看到短暫的 unstyled content
```

**策略 4: Max Surge = 100% (推薦, 最簡單)**

```yaml
# K8s Deployment 策略
spec:
  strategy:
    rollingUpdate:
      maxSurge: 100%        # 先啟動所有新 Pod
      maxUnavailable: 0     # 再停舊 Pod
  
  # 效果: 等同 blue-green — 新舊 Pod 短暫共存
  # 但新 Pod ready 後 LB 會逐步遷移流量
  # 配合 readiness probe → 確保新 Pod 完全啟動後才接收流量
```

### 12.9 完整流量路徑圖 (LB + Data Export)

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
  
    ✅ 所有 Replica 相同 image → 相同 webassets → LB 透明
    ✅ Versioned URL → CDN cache 命中率趨近 100%
    ⚠  Rolling update 時需 blue-green 或 maxSurge=100%

═══════════════════════════════════════════════════════════════════════════════

  Self-Dist Data Access (本 Dist 前端):

    Browser
      → CDN (cache: short, 1hr)
        → Caddy Front-Door
          → reverse_proxy (round_robin)
            → Replica N (any):8080
              → Internal Caddy @data_main__0 matcher
                → uri strip_prefix /data
                → file_server from shared volume
                → 200 OK + uploaded file
  
    ✅ 共享 volume → 所有 Replica 看到相同 data → LB 透明
    ✅ Self-dist data: 全目錄 file_server (維持現有行為)

═══════════════════════════════════════════════════════════════════════════════

  Cross-Dist Data Access (Module-Controlled):

    Browser
      → CDN
        → Caddy Front-Door
          → reverse_proxy → Replica N:8080
            → Internal Caddy (§12.3 新規則)
              → @data_blog_shop_images matcher
                → module 'shop' data_exports: 'uploads/images' = public ✅
                → file_server from target dist's shared volume
                → 200 OK

    ✅ Module-level 粒度控制
    ✅ 未匹配的子目錄 → 無 matcher → 落入 php_server → Razy 404

═══════════════════════════════════════════════════════════════════════════════

  Cross-Dist Data BLOCKED:

    Browser
      → GET /data/shop/cache/temp.dat
        → No @data matcher generated (private in data_exports)
          → Falls through to php_server
            → Razy RouteDispatcher → no matching route → 404

    ✅ Secure by default — 未聲明 = private
    ✅ Build-time 決定 → 無 runtime 開銷

═══════════════════════════════════════════════════════════════════════════════
```

### 12.10 向後相容性

| 場景 | 行為 |
|------|------|
| Module **沒有** `data_exports` + Self-dist | ✅ 全目錄 file_server (現有行為不變) |
| Module **沒有** `data_exports` + Cross-dist | ⚠ 預設拒絕 (secure by default) — **行為變更** |
| Module **有** `data_exports` + Self-dist | ✅ 全目錄 (除非 `self_restrict: true`) |
| Module **有** `data_exports` + Cross-dist | ✅ 只開放聲明的子目錄 |

**Migration 路徑：**

對於已使用 `data_mapping` 的現有部署，升級後 cross-dist data 會預設 blocked。需在被訪問的 module 的 `package.php` 中加入 `data_exports` 聲明。

```
  升級步驟:
  1. 識別哪些 module 的 data 被其他 dist 透過 data_mapping 訪問
  2. 在這些 module 的 package.php 加入 data_exports
  3. 重新執行 php Razy.phar rewrite (--caddy 或 --htaccess)
  4. 驗證 cross-dist data 訪問正常
```

可增加 `RAZY_DATA_EXPORT_LEGACY=true` 環境變數在過渡期維持舊行為 (全目錄開放)，並在日誌中記錄 warning 提示需升級。

### 12.11 方案對比 — 整體視角

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                  問題 ① Data Access                                    │
  │                                                                         │
  │  Before (§8-§11): data_mapping 粗粒度 — 整個目錄對所有 consumer 開放   │
  │  After  (§12):    data_exports 精細化 — module 控制 sub-dir + ACL       │
  │                                                                         │
  │  改動量: ~16h                                                           │
  │  安全提升: ★★★★ (從零控制到 public/restricted/private 三級)             │
  │  向後相容: ⚠ Cross-dist 預設行為從 open → closed (需 migration)         │
  ├─────────────────────────────────────────────────────────────────────────┤
  │                  問題 ② Webassets Under LB                              │
  │                                                                         │
  │  結論: 方案 A (Proxy-Through) 下 webassets LB = 非問題                 │
  │                                                                         │
  │  • 所有 Replica 同一 image → 相同 webasset → 相同 Caddyfile           │
  │  • Front-door 只做 reverse_proxy → 不需知道 module 細節                │
  │  • 唯一風險: rolling update 版本混合 → blue-green 或 maxSurge=100%     │
  │                                                                         │
  │  Data files LB:                                                         │
  │  • 共享 volume (Docker named volume / NFS / CephFS / EFS)              │
  │  • 所有 Replica 看到相同 data → LB 透明                                │
  │                                                                         │
  │  改動量: 0h (方案 A — 現有架構已支持)                                   │
  │         +4h (front-door Caddyfile 配置 + health check)                  │
  └─────────────────────────────────────────────────────────────────────────┘
```

### 12.12 Key Insight (關鍵洞察)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   Data Access:                                                  │
  │   • 現有 data_mapping 是 consumer-side 掛載 → 無 producer      │
  │     控制 → 安全缺口                                            │
  │   • 新增 package.php data_exports = producer-side ACL           │
  │   • 三級控制: public / restricted / private                     │
  │   • Build-time 寫入 Caddyfile → 零 runtime overhead            │
  │   • CaddyfileCompiler 精細化: 每 module 每 sub-dir 一條 rule   │
  │                                                                 │
  │   Webassets Under LB:                                           │
  │   • Image 一致性 → 所有 Replica 的 webasset 完全相同           │
  │   • Caddyfile 一致性 → 所有 Replica 的 rewrite 完全相同        │
  │   • ∴ LB round-robin 對 webassets 完全透明 — 非問題            │
  │   • 唯一注意: rolling update → blue-green 部署策略              │
  │                                                                 │
  │   Data Files Under LB:                                          │
  │   • 需共享 volume (docker named vol / NFS / CephFS)            │
  │   • 共享後 → 所有 Replica 看到相同 data → LB 透明              │
  │   • Docker Compose 單機: named volume 自動共享                  │
  │   • K8s 跨 host: ReadWriteMany PVC (NFS/CephFS/EFS)           │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 13. Webasset Pack — Build-Time Asset Extraction & External Storage

> **前提：** §11 方案 C 提出了 Build-Time Asset Extraction 概念，§12 確認 webassets 在 LB 下依靠 image 一致性可運作。本節進一步設計一套完整的 **Webasset Pack** 機制 — 當 tenant 打包指令執行時，webassets 被提取為獨立 PACK，Core 加入 tenant 時找到 PACK 並解壓到指定 storage path (local / S3 / CDN origin)，讓 `Controller::getAssetPath()` 指向正確的外部 URL。

### 13.1 動機與問題回顧

**§11-§12 的結論：**

| 場景 | 現有解法 | 限制 |
|------|---------|------|
| 單機 Docker (≤20 tenants) | 方案 A (Proxy-Through) — 零改動 | 靜態流量佔 PHP container 資源 |
| LB + 多 Replica | 方案 A 仍可用 (image 一致性) | 每個 Replica 都要回應靜態請求 |
| 高流量 (>100 tenants) | 方案 F (S3 + CDN) | 需額外 publish pipeline |
| Rolling update | Blue-green 部署 | 版本混合期風險 |

**Webasset Pack 的目標：** 在現有 `pack` (→ `.phar`) + `sync` (→ install) 生命週期中自然插入 webasset 提取步驟，讓 Core 加入 tenant 時自動把 webassets 部署到外部 storage，徹底解除靜態流量對 PHP container 的依賴。

### 13.2 現有 CLI Pipeline 分析

```
  現有流程:
  
  ① pack   : php Razy.phar pack vendor/shop 1.0.0
              → packages/vendor/shop/1.0.0.phar       (module code as .phar)
              → packages/vendor/shop/1.0.0-assets/     (webassets copy, 已支持!)
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

**關鍵觀察：**

```
  ┌─────────────────────────────────────────────────────────────────────┐
  │                                                                     │
  │  pack.inc.php 已經做了 webasset 提取！ (line 224-234)              │
  │                                                                     │
  │    $assetsOutputPath = PathUtil::append($outputPath, $version . '-assets')
  │    xcopy($assetsPath, $assetsOutputPath)                            │
  │                                                                     │
  │  → packages/vendor/shop/1.0.0-assets/                              │
  │    └── css/style.css                                                │
  │    └── js/app.js                                                    │
  │    └── images/logo.png                                              │
  │                                                                     │
  │  但 publish + sync 完全忽略這些 asset files！                      │
  │  → Assets 只停留在 packages/ 目錄，從未被部署到外部 storage        │
  │                                                                     │
  └─────────────────────────────────────────────────────────────────────┘
```

### 13.3 設計概覽 — Webasset Pack Lifecycle

```
═══════════════════════════════════════════════════════════════════════════

  PHASE 1: Pack (開發者執行)
  
  php Razy.phar pack vendor/shop 1.0.0
      │
      ├─→ 1.0.0.phar           (module code, 現有)
      ├─→ 1.0.0-assets/        (webassets 目錄, 現有但未利用)
      ├─→ 1.0.0-assets.tar.gz  (NEW: webasset pack — 壓縮封裝)
      ├─→ manifest.json         (現有, 新增 assets_checksum 欄位)
      └─→ latest.json           (現有)

═══════════════════════════════════════════════════════════════════════════

  PHASE 2: Publish (推送到 Repository)
  
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

  PHASE 3: Sync + Deploy (Core 加入 Tenant 時)
  
  php Razy.phar sync main
      │
      ├─→ Download 1.0.0.phar → extract to sites/main/vendor/shop/  (現有)
      │
      └─→ Download 1.0.0-assets.tar.gz → extract to ASSET_STORAGE   (NEW)
            │
            │   PACK_ID = 1.0.0-a1b2c3d4 (從 manifest 讀取)
            │
            ├── Local:  /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
            ├── S3:     s3://razy-assets/tenant-a/Shop/1.0.0-a1b2c3d4/css/style.css
            └── CDN:    https://cdn.example.com/tenant-a/Shop/1.0.0-a1b2c3d4/css/style.css
            
            + 寫入 .asset_pack_id 檔案到 module dir (Runtime 讀取用)

═══════════════════════════════════════════════════════════════════════════

  PHASE 4: Runtime (Module 取得 Asset URL)
  
  Controller::getAssetPath()   — 原始 (self-serve):
      → https://example.com/webassets/Shop/1.0.0/
  
  Controller::getAssetUrl()    — 新增 (external storage + PACK_ID):
      → 讀取 .asset_pack_id 檔 → 1.0.0-a1b2c3d4
      → 檢查 RAZY_ASSET_BASE_URL 環境變數
      → 有: https://cdn.example.com/tenant-a/Shop/1.0.0-a1b2c3d4/
      → 無: fallback to getAssetPath() (self-serve, 向後相容)

═══════════════════════════════════════════════════════════════════════════

  PHASE 5: Purge (過渡完成後清理)
  
  php Razy.phar asset:purge --keep=1
      │
      ├─→ 列出所有 PACK_ID per alias:
      │     Shop: 1.0.0-a1b2c3d4 (OLD), 1.1.0-99aabb00 (CURRENT)
      │
      ├─→ 保留最新 1 份: 1.1.0-99aabb00
      │
      └─→ 刪除: 1.0.0-a1b2c3d4 (釋放 storage)

═══════════════════════════════════════════════════════════════════════════
```

### 13.4 Asset Pack 格式設計

#### 13.4.1 壓縮封裝: `.tar.gz`

**為什麼不用 .phar 或 .zip：**

| 格式 | 優勢 | 劣勢 |
|------|------|------|
| `.phar` | PHP 原生 | 專為 PHP 代碼；靜態檔案不需執行；S3/CDN 不認得 |
| `.zip` | 廣泛支持 | PHP 的 ZipArchive 需 ext-zip；壓縮率不如 gzip |
| **`.tar.gz`** | 所有 Linux 原生；S3 支持；Caddy 原生解壓 | Windows 需 `tar` (PHP 8+ 自帶 PharData) |

**選擇：** `.tar.gz` — 使用 PHP 內建的 `PharData` 處理，無需額外擴展。

#### 13.4.2 Pack 內部結構 (PACK_ID 隔離)

```
  1.0.0-assets.tar.gz
  └── Shop/                              ← alias (不是 module code)
      └── 1.0.0-a1b2c3d4/               ← PACK_ID (version + content hash)
          ├── css/
          │   └── style.css
          ├── js/
          │   └── app.js
          └── images/
              └── logo.png
  
  解壓後 (在 ASSET_STORAGE):
  
  /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
  /app/assets/Shop/1.0.0-a1b2c3d4/js/app.js
  /app/assets/Shop/1.0.0-a1b2c3d4/images/logo.png
```

**使用 alias 而非 module code 的原因：** `Controller::getAssetPath()` 已使用 alias 作為 URL segment (`/webassets/{alias}/{version}/`)。保持一致 → 外部 storage 路徑 = URL 路徑 → 零映射開銷。

**為什麼路徑用 PACK_ID 而非 version：** 見 §13.4.3 — Hot-Plug 版本衝突問題。

#### 13.4.3 PACK_ID — Hot-Plug Asset 版本隔離

**問題場景 (FrankenPHP Worker Mode Hot-Plug)：**

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                                                                         │
  │   時間軸:                                                               │
  │   ─────────────────────────────────────────────────────────────────     │
  │   T0          T1                T2              T3                      │
  │   │           │                 │               │                       │
  │   │  v1.0     │  hot-plug 開始  │  過渡期       │  完全切換             │
  │   │  running  │  v1.1 deploy    │  v1.0 + v1.1  │  only v1.1            │
  │   │           │                 │  同時在跑     │                       │
  │   ─────────────────────────────────────────────────────────────────     │
  │                                                                         │
  │   T1: sync 部署 v1.1 asset pack                                        │
  │                                                                         │
  │   ❌ 如果用 {alias}/{version}/ 路徑:                                   │
  │      v1.0 assets → /app/assets/Shop/1.0.0/css/style.css               │
  │      v1.1 assets → /app/assets/Shop/1.1.0/css/style.css               │
  │      → 版本不同時沒問題… 但如果 v1.0.0 hotfix (same version)?          │
  │      → 覆蓋! v1.0 workers 取到新 CSS → 不一致!                         │
  │                                                                         │
  │   ❌ 同版本 hotfix 場景:                                               │
  │      開發者修了 CSS bug, re-pack v1.0.0 (不升版號)                     │
  │      新 v1.0.0 assets 覆蓋舊 v1.0.0 → 舊 workers 拿到新 CSS          │
  │      → 瀏覽器已快取舊 JS + 拿到新 CSS → 排版炸裂                      │
  │                                                                         │
  │   ✅ PACK_ID 解法:                                                     │
  │      每次 pack 產生唯一 PACK_ID = {version}-{content_hash_8}           │
  │      舊: /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css                │
  │      新: /app/assets/Shop/1.0.0-e5f6g7h8/css/style.css                │
  │      → 兩組共存! 零衝突!                                               │
  │      → 舊 workers 繼續用 a1b2c3d4, 新 workers 用 e5f6g7h8            │
  │      → T3 完全切換後: `asset:purge --keep=1` 清除舊 pack              │
  │                                                                         │
  └─────────────────────────────────────────────────────────────────────────┘
```

**PACK_ID 格式：**

```
  PACK_ID = {version}-{content_hash_8}
  
  其中:
    version       = module 版本 (e.g., 1.0.0, 1.1.0)
    content_hash  = SHA256(tar.gz contents) 前 8 碼
  
  範例:
    1.0.0-a1b2c3d4    ← 初次打包
    1.0.0-e5f6g7h8    ← 同版本 hotfix (CSS 修改 → hash 不同)
    1.1.0-99aabb00    ← 新版本
  
  特性:
    • 確定性: 相同內容 → 相同 PACK_ID (idempotent deploy)
    • 防衝突: 不同內容 → 不同 PACK_ID (即使同版本)
    • 可讀性: 版本號在前 → 人眼可辨識
    • 排序性: 按版本排序 → 方便 purge 決策
```

**PACK_ID 生命週期：**

```
  ┌─────────────────┐        ┌──────────────────┐        ┌────────────────┐
  │  pack (Build)   │───────→│  sync (Deploy)   │───────→│  Runtime       │
  │                 │        │                  │        │                │
  │  生成 PACK_ID   │        │  解壓到          │        │  getAssetUrl() │
  │  寫入 manifest  │        │  {alias}/{packId}│        │  用 PACK_ID    │
  │  嵌入 tar.gz    │        │  目錄下          │        │  解析 URL      │
  └─────────────────┘        └──────────────────┘        └────────────────┘
                                                                │
                                                                │ 過渡完成後
                                                                ▼
                                                         ┌────────────────┐
                                                         │ asset:purge    │
                                                         │                │
                                                         │ 清除舊 PACK_ID │
                                                         │ 保留 N 份最新  │
                                                         └────────────────┘
```

#### 13.4.4 manifest.json 擴展

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

### 13.5 Phase 1 實作: pack 指令擴展

**改動: `pack.inc.php` — 新增 `.tar.gz` 封裝**

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

**產出:**
```
  packages/vendor/shop/
  ├── 1.0.0.phar              (module code archive)
  ├── 1.0.0-assets/            (extracted webassets — backward compat)
  ├── 1.0.0-assets.tar.gz      (NEW: deployable asset pack)
  ├── manifest.json             (updated with assets_file, assets_checksum)
  └── latest.json
```

### 13.6 Phase 2 實作: publish 指令擴展

**改動: `publish.inc.php` — 上傳 asset pack 到 GitHub Release**

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

**GitHub Release 結果:**
```
  Release: v1.0.0 (vendor/shop)
  ├── 1.0.0.phar               (Module download)
  └── 1.0.0-assets.tar.gz      (Webasset pack download)
```

### 13.7 Phase 3 實作: sync 指令擴展 — Asset Deploy

**這是最關鍵的改動。** `sync` 指令在安裝 module `.phar` 後，額外下載 asset pack 並部署到指定 storage。

#### 13.7.1 Asset Storage 配置

**config.inc.php 新增:**
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

**環境變數覆蓋 (Docker 優先):**
```yaml
environment:
  - RAZY_ASSET_DRIVER=s3
  - RAZY_ASSET_BUCKET=razy-assets
  - RAZY_ASSET_REGION=ap-east-1
  - RAZY_ASSET_PREFIX=tenant-a
  - RAZY_ASSET_BASE_URL=https://cdn.example.com/assets/tenant-a
```

#### 13.7.2 sync.inc.php 改動

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

#### 13.7.3 AssetDeployer 類別設計

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

### 13.8 Phase 4 實作: Runtime Asset URL Resolution

**核心改動: `Controller::getAssetUrl()` 新增方法**

```php
// src/library/Razy/Controller.php — 新增

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

**使用方式 (Template/Controller):**

```php
// In module controller:
public function __onReady(): void
{
    // 舊方式 (仍可用, self-serve):
    $cssUrl = $this->getAssetPath() . 'css/style.css';
    
    // 新方式 (自動選擇最優 URL + PACK_ID 隔離):
    $cssUrl = $this->getAssetUrl() . 'css/style.css';
    // → 未設 env: https://example.com/webassets/Shop/1.0.0/css/style.css
    // → 設了 env: https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/css/style.css
    //   ↑ PACK_ID 確保 hot-plug 過渡期服務的每個 worker 都指向自己的 asset snapshot
}
```

### 13.9 完整流程圖 — 從 Pack 到 Browser

```
═══════════════════════════════════════════════════════════════════════════════

  開發者 (Development Phase):
  
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

  部署者 / Core Orchestrator (Deployment Phase):
  
  ③ php Razy.phar sync main
     │
     ├─→ Download 1.0.0.phar → extract to sites/main/vendor/shop/
     │
     └─→ Download 1.0.0-assets.tar.gz
          │
          │   PACK_ID = 1.0.0-a1b2c3d4 (從 manifest 讀取)
          │   → 寫入 .asset_pack_id 到 module dir
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

  Purge (過渡完成後, 手動執行):
  
  ⑦ php Razy.phar asset:purge --keep=1
     │
     ├─→ 列出: Shop → [1.0.0-a1b2c3d4 (old), 1.1.0-99aabb00 (current)]
     ├─→ 保留: 1.1.0-99aabb00
     └─→ 刪除: 1.0.0-a1b2c3d4
          → Local: rm -rf /app/assets/Shop/1.0.0-a1b2c3d4/
          → S3:    aws s3 rm --recursive s3://razy-assets/Shop/1.0.0-a1b2c3d4/
  ④ Module Controller::getAssetUrl()
     │
     ├── 讀取 .asset_pack_id → PACK_ID = 1.0.0-a1b2c3d4
     │
     ├── RAZY_ASSET_BASE_URL 已設定:
     │   → https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/
     │
     └── RAZY_ASSET_BASE_URL 未設定:
         → https://example.com/webassets/Shop/1.0.0/   (自 serve, fallback)
  
  ⑤ Browser:
     GET https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/css/style.css
         │
         ├── CDN Edge HIT → respond immediately (~5ms)
         └── CDN Edge MISS → S3 Origin → respond + cache at edge

  ⑥ Hot-Plug 過渡期 (v1.0 + v1.1 並存):
  
     Worker A (v1.0): .asset_pack_id = 1.0.0-a1b2c3d4
       → getAssetUrl() → .../Shop/1.0.0-a1b2c3d4/css/style.css ✅
     
     Worker B (v1.1): .asset_pack_id = 1.1.0-99aabb00
       → getAssetUrl() → .../Shop/1.1.0-99aabb00/css/style.css ✅
     
     → 兩組 PACK 共存於 storage! 零衝突! 零 downtime!

═══════════════════════════════════════════════════════════════════════════════
```

### 13.10 Local Storage 模式 — Caddy Front-Door file_server

**當 `driver=local` 時，** asset pack 解壓到 `/app/assets/`。Front-door Caddy 可直接用 `file_server` serve，不經 tenant container：

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

**Volume 配置:**
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

**流量路徑:**
```
  Browser → GET /webassets/Shop/1.0.0-a1b2c3d4/css/style.css
      → Caddy Front-Door @webassets matcher
        → uri replace /webassets/ → /
        → root * /app/assets → /app/assets/Shop/1.0.0-a1b2c3d4/css/style.css
        → file_server → 200 OK
  
  ✅ 零 reverse_proxy hop — 直接本地檔案讀取
  ✅ Tenant container 不處理靜態請求 → 100% 資源用於 PHP
  ✅ PACK_ID 在路徑中 → hot-plug 過渡期兩版本共存
```

### 13.11 S3 模式 — CDN + Object Storage

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

### 13.12 Multi-Tenant Asset 路徑策略

**問題：** 同一個 module (e.g., `vendor/shop`) 可能被多個 tenant 使用。Asset pack 內容相同 → 是否需要每個 tenant 存一份？

**答案：不需要。** PACK_ID 包含 content hash — 同內容 = 同 PACK_ID = 自然去重。

#### 推薦策略: Shared Pool (單一 bucket, module > PACK_ID) ✅

```
  /app/assets/                    ← 單一 storage bucket (LOCAL)
    Shop/                         ← module alias
      1.0.0-a1b2c3d4/            ← PACK_ID
        css/style.css
        js/app.js
      1.1.0-99aabb00/            ← hot-plug 新版共存
        css/style.css
    Auth/
      2.0.0-ccdd1122/
        css/login.css
```

| 維度 | 評分 | 說明 |
|------|------|------|
| 隔離性 | ★★★ | PACK_ID content hash 確保不同內容永不衝突 |
| 存儲效率 | ★★★ | 同內容同 PACK_ID → 天然去重 |
| 部署簡單 | ★★★ | 無 tenant 前綴 → AssetDeployer 邏輯簡單 |
| CDN 相容 | ★★★ | PACK_ID 在 URL 中 → CDN cache key 天然獨立 |

**為什麼不需要 tenant 前綴：**

| 情境 | 結果 |
|------|------|
| Tenant A/B 同 module 同版本同內容 | 同 PACK_ID → 只存一份 (自然去重) |
| Tenant A 升級, B 未升級 | 不同版本 → 不同 PACK_ID → 共存 |
| Tenant A 自定義 (fork/theme) | 同版本不同內容 → 不同 content hash → 不同 PACK_ID → 隔離 |
| Hot-plug 過渡期 | 新舊 PACK_ID 共存於同一 module 目錄 |

#### 備選策略 A: Per-Tenant Prefix (不推薦)

```
  /app/assets/
    tenant-a/
      Shop/1.0.0-a1b2c3d4/css/style.css
    tenant-b/
      Shop/1.0.0-a1b2c3d4/css/style.css    ← 同內容重複存儲
```

→ 浪費存儲、增加 deploy 複雜度、CDN purge 需按 tenant prefix。
PACK_ID 已解決隔離問題 → tenant prefix 無額外價值。

#### 備選策略 C: Content-Hash Dedup (進階, Phase 4+)

```
  /app/assets/
    _blob/
      sha256-abc123/css/style.css    ← 按內容哈希存儲
    _map/
      Shop/1.0.0 → sha256-abc123
      Shop/1.0.1 → sha256-def456
```

太複雜 — Phase 4+ 才考慮。Shared Pool + PACK_ID 已提供足夠去重。

**結論：全階段使用 Shared Pool。PACK_ID 的 content hash 同時解決隔離和去重。**

### 13.13 環境變數 + 配置覆蓋鏈

```
  解析順序 (高優先 → 低):
  
  1. RAZY_ASSET_BASE_URL 環境變數
       → https://cdn.example.com/assets
  
  2. config.inc.php asset_storage.base_url
       → https://cdn.example.com/assets
  
  3. Distributor dist.php asset_base_url
       → https://cdn.example.com/assets
  
  4. (None) → fallback to Controller::getAssetPath()
       → https://example.com/webassets/{alias}/{version}/
```

**Runtime 解析 (Controller.php):**

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

### 13.14 Rewrite 規則互動 — 模式自動切換

**當啟用外部 asset storage 時，CaddyfileCompiler 可自動跳過 `@webasset_*` matchers：**

```
  模式 A (Self-Serve, 無 RAZY_ASSET_BASE_URL):
  ─────────────────────────────────────────────
  
  Tenant Container Caddyfile:
    @webasset_Shop_ path /webassets/Shop/*
    handle @webasset_Shop_ {
        uri strip_prefix /webassets/Shop
        root * sites/main/modules/vendor/shop
        file_server
    }
  
  Controller::getAssetUrl() → /webassets/Shop/1.0.0/...
  → Request 打到 Tenant Container → file_server 回應
  
  注意: Self-serve 模式下 URL 仍用 {version} (不用 PACK_ID)
  → 因為 CaddyfileCompiler 的 @webasset matcher 以 alias 匹配
  → 版本在 URL path 裡但 file_server 會在 module dir 中找到正確檔案


  模式 B (External Storage, 有 RAZY_ASSET_BASE_URL):
  ──────────────────────────────────────────────────
  
  Tenant Container Caddyfile:
    # @webasset_Shop_ 仍然保留 (fallback 安全網)
    # 但 Controller::getAssetUrl() 指向 CDN + PACK_ID → 請求不經 Container
  
  Controller::getAssetUrl() → https://cdn.example.com/assets/Shop/1.0.0-a1b2c3d4/...
  → Request 打到 CDN / Front-Door file_server → 不經 Tenant Container
  → PACK_ID 在 URL 中 → hot-plug 過渡期兩版本 URL 不同 → CDN cache 永不混淆
  
  效果: Tenant Container 靜態流量降至零 → 100% 資源用於 PHP
```

**重要：即使啟用外部 storage，仍保留 Caddyfile 中的 `@webasset_*` rules 作為安全網。** 萬一 CDN 故障或 S3 不可用，管理員只需移除 `RAZY_ASSET_BASE_URL` env → 即時 fallback 到 self-serve。

### 13.15 Asset Purge / Clean CLI 指令

**核心需求：** Hot-plug 過渡完成後，舊 PACK 仍佔佔存儲空間。需要明確指令清除——不能自動刪（因為 framework 無法知道過渡是否完全完成）。

#### 13.15.1 指令設計

```
  php Razy.phar asset:purge [OPTIONS]
  
  用途: 清除舊版 asset pack，釋放存儲空間
  
  選項:
    --keep=N         每個 alias 保留最新 N 份 PACK (預設: 1)
    --alias=ALIAS    只清理指定 alias (預設: 所有)
    --dry-run        顯示將被刪除的 PACK，不實際執行
    --force          跳過確認提示
    --dist=CODE      指定 distributor (預設: 當前)
  
  範例:
    php Razy.phar asset:purge --keep=1              # 保留每個 alias 最新 1 份
    php Razy.phar asset:purge --keep=2 --dry-run    # 預覽清理結果
    php Razy.phar asset:purge --alias=Shop --force   # 強制清理 Shop 的舊 pack
```

#### 13.15.2 執行流程

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

#### 13.15.3 執行範例

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

#### 13.15.4 Hot-Plug 完整操作流程

```
  ═════════════════════════════════════════════════════════════════
  
  Step 1: 打包新版本
  ──────
  $ php Razy.phar pack vendor/shop 1.1.0
  → PACK_ID: 1.1.0-99aabb00
  → 1.1.0-assets.tar.gz 已創建
  
  Step 2: 發佈到 Repository
  ──────
  $ php Razy.phar publish --push
  → GitHub Release: v1.1.0 + assets.tar.gz uploaded
  
  Step 3: 同步到 Tenant (部署新版)
  ──────
  $ php Razy.phar sync main
  → Module:  sites/main/vendor/shop/ → v1.1.0 extracted
  → Assets:  /app/assets/Shop/1.1.0-99aabb00/ → deployed
  → .asset_pack_id → "1.1.0-99aabb00" 寫入
  
  此時 storage 狀態:
    /app/assets/Shop/
      1.0.0-a1b2c3d4/    ← v1.0 (old workers 仍在用)
      1.1.0-99aabb00/    ← v1.1 (new workers 開始用)
  
  Step 4: Hot-Plug 過渡
  ──────
  FrankenPHP worker 熱替換:
  → Old workers (v1.0) 漸漸 drain…
  → New workers (v1.1) 接手新請求…
  → 過渡期: 兩版本同時在跑, 各自 getAssetUrl() 指向自己的 PACK_ID
  → 零衝突! 零 404! 零 downtime!
  
  Step 5: 確認過渡完成
  ──────
  → 所有 workers 已切換到 v1.1
  → 無 v1.0 請求在飛
  → CDN cache 已更新 (or PACK_ID 不同所以 cache key 不同)
  
  Step 6: 清理舊 Pack
  ──────
  $ php Razy.phar asset:purge --keep=1
  
    Shop:
      [KEEP]  1.1.0-99aabb00
      [PURGE] 1.0.0-a1b2c3d4    → 刪除!
  
  ═════════════════════════════════════════════════════════════════
```

#### 13.15.5 CDN Cache 與 PACK_ID 的交互

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │  問題: 舊版本 asset 在 CDN 的 cache 會不會影響新版本?             │
  │                                                                 │
  │  答案: 不會! 因為 PACK_ID 不同 → URL 不同 → cache key 不同       │
  │                                                                 │
  │  v1.0: /Shop/1.0.0-a1b2c3d4/css/style.css  → CDN cache A      │
  │  v1.1: /Shop/1.1.0-99aabb00/css/style.css  → CDN cache B      │
  │                                                                 │
  │  → 無需 CDN purge! 新版本自然用新 URL                             │
  │  → 舊 CDN cache 自然過期 (TTL) 或被 LRU 置換                      │
  │  → 同版本 hotfix: PACK_ID hash 不同 → 也是新 cache key → 安全!  │
  │                                                                 │
  │  這是 PACK_ID 相對於純 version 的最大優勢:                      │
  │  即使同版本 re-pack, CDN 也不會 serve 舊內容                     │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

### 13.16 工時估算

| 項目 | 改動 | 工時 |
|------|------|------|
| `pack.inc.php` — `.tar.gz` + PACK_ID | PharData archive + content hash + manifest | 4h |
| `publish.inc.php` — Release asset upload | 上傳 .tar.gz 到 GitHub Release | 2h |
| `sync.inc.php` — Asset pack download + deploy | Download + checksum + extract + .asset_pack_id | 5h |
| `AssetDeployer.php` — 新類別 | Local + S3 driver + purge + listPacks | 7h |
| `asset_purge.inc.php` — Purge CLI | List + keep-N + dry-run + force | 3h |
| `Controller::getAssetUrl()` + `resolvePackId()` | PACK_ID 解析鏈 + .asset_pack_id 讀取 | 3h |
| `config.inc.php.tpl` — 配置模板 | asset_storage section | 1h |
| `RepositoryManager` — getDownloadUrl assets | 支持 assets type 下載 | 2h |
| 單元測試 | Pack / Deploy / PACK_ID / Purge / URL resolution | 8h |
| 整合測試 | 端對端: pack → publish → sync → hot-plug → purge | 5h |
| 文檔 | CLI help + Wiki page | 2h |
| **合計** | | **~42h** |

### 13.17 分階段實施建議

```
  Phase 2 (Docker, ≤20 tenants):
  ─────────────────────────────
  
  1. pack.inc.php 新增 .tar.gz + PACK_ID      (4h)
  2. sync.inc.php + AssetDeployer (local)     (7h)
  3. Controller::getAssetUrl() + resolvePackId (3h)
  4. asset:purge CLI                          (3h)
  5. Front-door Caddy file_server for assets  (2h)
  ─────────────────────────────────────────────
  合計: ~19h
  
  效果:
  • Webassets 從 tenant container 移到 front-door 直 serve
  • PACK_ID 確保 hot-plug 過渡期零衝突
  • asset:purge 清除舊 pack 釋放空間
  • 自動 fallback: 移除 env → self-serve 模式
  
  
  Phase 3 (Multi-Host Docker / K8s, 20-100 tenants):
  ──────────────────────────────────────────────────
  
  6. publish.inc.php 上傳 .tar.gz             (2h)
  7. AssetDeployer S3 driver                   (6h)
  8. RepositoryManager asset download support   (2h)
  ─────────────────────────────────────────────
  合計: ~10h (累計 ~29h)
  
  效果:
  • Assets 存到 S3/MinIO → CDN 分發
  • PACK_ID 作為 CDN cache key → 無需 cache purge
  • 無限擴展 + 全球低延遲
  
  
  Phase 4 (Enterprise, >100 tenants):
  ──────────────────────────────────
  
  9. GCS driver + Multi-CDN                    (4h)
  10. Content-hash dedup (策略 C)              (5h)
  11. Auto-purge scheduler (cron-based)         (4h)
  ─────────────────────────────────────────────
  合計: ~13h (累計 ~42h)
```

### 13.18 向後相容性保證

| 場景 | 行為 |
|------|------|
| 無 `asset_storage` 配置, 無 `RAZY_ASSET_BASE_URL` | ✅ 完全不變 — self-serve via Caddyfile `file_server` |
| `getAssetPath()` 呼叫 | ✅ 不受影響 — 始終回傳 self-serve URL |
| `getAssetUrl()` 新呼叫 | ✅ 自動選擇最優 URL (CDN / self-serve) + PACK_ID 隔離 |
| 舊版 module 未產生 `.tar.gz` | ✅ `sync` 跳過 asset deploy — 用 self-serve |
| `pack --no-assets` | ✅ 不產生 .tar.gz — 與現有行為一致 |
| 無 `.asset_pack_id` 檔案 | ✅ `resolvePackId()` fallback 到 version |
| CDN 故障 | ✅ 移除 `RAZY_ASSET_BASE_URL` env → 即時 fallback |
| Hot-plug 過渡期 | ✅ 新舊 PACK_ID 共存 → 零 404、零衝突 |
| 同版本 hotfix (re-pack) | ✅ Content hash 不同 → 新 PACK_ID → 不覆蓋舊版 |

### 13.19 Key Insight (關鍵洞察)

```
  ┌─────────────────────────────────────────────────────────────────┐
  │                                                                 │
  │   PACK_ID = {version}-{content_hash_8}                         │
  │   → 每次 pack 產生唯一 PACK_ID (內容不同 → hash 不同)         │
  │   → 解決 hot-plug 過渡期版本衝突: 新舊 PACK 共存於 storage    │
  │   → 解決同版本 hotfix: re-pack → 新 PACK_ID → 不覆蓋          │
  │   → 解決 CDN cache: PACK_ID 在 URL 中 → 天然 cache buster     │
  │                                                                 │
  │   pack.inc.php 已做 webasset 提取 (1.0.0-assets/)             │
  │   → 補上 .tar.gz 封裝 + PACK_ID 嵌入 = 完整 pipeline         │
  │                                                                 │
  │   AssetDeployer (local/S3/GCS):                                │
  │   • 單一 bucket: /{module}/{PACK_ID}/ — 無 tenant 前綴        │
  │   • deploy 用 PACK_ID 目錄 → 永不覆蓋 → immutable snapshot    │
  │   • 同內容同 PACK_ID → 天然去重; 不同內容 → 自然隔離          │
  │   • purge 指令明確清除 → 管理員控制生命週期                    │
  │                                                                 │
  │   Controller::getAssetUrl() 自動解析:                          │
  │   • .asset_pack_id → metadata → version (三層 fallback)        │
  │   • 每個 worker 讀自己的 PACK_ID → 過渡期零衝突               │
  │   • 向後 100% 相容 — getAssetPath() 不受影響                  │
  │                                                                 │
  │   Phase 2 只需 ~19h → 立刻獲得:                               │
  │   • Front-door 直 serve 靜態 → container 零靜態負載           │
  │   • Hot-plug safe (PACK_ID 隔離)                               │
  │   • asset:purge 清理 + 自動 fallback safety net                │
  │   • 之後加 S3/CDN 是增量 (+10h)                               │
  │                                                                 │
  └─────────────────────────────────────────────────────────────────┘
```

---

## 14. Best Solution & Unified Upgrade Roadmap (最佳方案 & 統一升級路線圖)

> **Status:** Synthesis of Sections 1–13 findings  
> **Scope:** From v1.0.1-beta (current) → v2.0.0 (full enterprise multi-tenant)  
> **Replaces:** `UPGRADE-ROADMAP.md` original estimate (~105h) with fully reconciled effort

### 14.1 Executive Summary (執行摘要)

Sections 1–13 of this document analysed every facet of Razy's multi-tenant architecture:
communication layers, injection threats, process isolation, cryptography, latency,
URL rewriting, static assets, reverse proxy, volume strategies, data access control,
and build-time asset extraction. This section synthesises those findings into a
single **recommended architecture** and a **unified upgrade roadmap** with reconciled
effort estimates.

**Core Conclusions:**

1. **Communication:** The L1–L4 layered model is sound. L1/L2 are shipped. L4 (`TenantEmitter → HTTP POST → Bridge`) with HMAC-SHA256 authentication is the right Phase 3 primitive. Ed25519 is deferred to Phase 4+ (marginal gain vs complexity).

2. **Isolation:** FrankenPHP worker mode (current) is the default. Docker containers per tenant (Phase 2) provide production-grade isolation. Kubernetes (Phase 4) enables enterprise scale. FPM pool mode is optional (~10h) for mid-tier use cases but NOT recommended as default path.

3. **Static Assets:** The zero-change proxy-through (§11 方案 A) works for Phase 2 MVP. The **Webasset Pack + PACK_ID** pipeline (§13) is the long-term solution — front-door Caddy `file_server` serves static assets directly, eliminating container load. PACK_ID (`{version}-{content_hash_8}`) solves hot-plug transitions, CDN cache busting, and same-version hotfixes in one mechanism.

4. **Routing & Rewrite:** `CaddyfileCompiler` extension + Caddy Admin API dynamic config is the dual strategy. Bridge blocking (`/_razy/internal/*`) is a **P0 security requirement**. Multi-container reverse proxy generation slots into the existing compiler pattern.

5. **Data Access:** Producer-side `data_exports` ACL in `package.php` is the correct model. Module authors declare what's public/restricted/private; the compiler generates per-module Caddy matchers.

6. **Security:** Double-gate architecture (outer tenant gate + inner bridge gate) for L4. HMAC-SHA256 default with timestamp + nonce dedup. CLI guard on `executeInternalCommand()`. `realpath()` + base path validation on all file access.

---

### 14.2 Architecture Decision Matrix (架構決策矩陣)

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

### 14.3 Best Solution Stack (最佳方案 — 漸進式架構)

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

### 14.4 Unified Upgrade Roadmap (統一升級路線圖)

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

### 14.5 Phase Dependency Diagram (階段依賴圖)

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

### 14.6 Implementation Priority Matrix (實施優先級矩陣)

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

### 14.7 Risk Assessment & Mitigation (風險評估與緩解)

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

### 14.8 Version Milestone Summary (版本里程碑總覽)

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

### 14.9 Quick-Start Recommendations (快速啟動建議)

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

### 14.11 Key Insight (關鍵洞察)

```
  ┌─────────────────────────────────────────────────────────────────────────┐
  │                                                                         │
  │   13 sections、~6,200 lines of analysis → 6 core decisions:            │
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
  │      → 單一 bucket: /{module}/{PACK_ID}/ — 無 tenant 前綴             │
  │      → 同內容 = 同 PACK_ID = 天然去重; 不同內容 = 自然隔離           │
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