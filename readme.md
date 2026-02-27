# Razy Framework

**A modular PHP framework for multi-site, multi-distributor application development.**

[![CI](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml/badge.svg)](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.0.1--beta-blue.svg)](https://github.com/RayFungHK/Razy/releases)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-4564_passing-success.svg)](#testing)
[![codecov](https://codecov.io/gh/RayFungHK/Razy/branch/master/graph/badge.svg)](https://codecov.io/gh/RayFungHK/Razy)
[![PSR-4](https://img.shields.io/badge/PSR--4-compliant-brightgreen.svg)](https://www.php-fig.org/psr/psr-4/)
[![PSR-12](https://img.shields.io/badge/PSR--12-compliant-brightgreen.svg)](https://www.php-fig.org/psr/psr-12/)

Razy lets you manage multiple websites, APIs, and services from a single codebase. Each **distributor** runs its own set of versioned modules with independent routing, templates, and database access — while sharing common services through a unified module system.

---

## Table of Contents

- [Key Features](#key-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Docker](#docker)
- [Architecture Overview](#architecture-overview)
- [Module Lifecycle](#module-lifecycle)
- [Core Concepts](#core-concepts)
- [Package Management (Composer Integration)](#package-management-composer-integration)
- [Demo Modules](#demo-modules)
- [Performance: Razy vs Laravel](#performance-razy-vs-laravel)
- [Testing](#testing)
- [Documentation](#documentation)
- [Roadmap](#roadmap)
- [Version Milestone Summary](#version-milestone-summary)
- [Contributing](#contributing)
- [Development Journey](#development-journey)
- [License](#license)

---

## Key Features

| Category | Highlights |
|----------|------------|
| **Multi-Site Architecture** | Run multiple distributors (sites/apps) from one installation with independent module sets, routing, and configuration |
| **Module System** | Dependency-aware loading with 14 lifecycle hooks, cross-module APIs, event emitters, and bridge commands |
| **Template Engine** | Block-based rendering with modifiers, function tags, WRAPPER/INCLUDE/TEMPLATE/USE/RECURSION blocks |
| **Database Layer** | Multi-driver (MySQL, PostgreSQL, SQLite) with fluent query builder, Simple Syntax, ORM, migrations, and schema management |
| **CLI Tooling** | 20+ commands — build, pack, publish, install from GitHub, interactive shell (`runapp`), bridge calls |
| **Thread System** | Process-based concurrency via `ThreadManager` with spawn, await, and joinAll |
| **Package Management** | Version-locked modules, Composer integration, phar distribution, repository publishing |
| **Built-in Classes** | SSE, XHR (CORS), Mailer (SMTP), DOM builder, Crypt (AES-256), Collection, HashMap, YAML, OAuth2, Cache (PSR-16), Authenticator (TOTP/HOTP 2FA), FTPClient, SFTPClient, WebSocket |

---

## Requirements

- PHP 8.2 or higher
- Extensions: `ext-zip`, `ext-curl`, `ext-json`
- Composer (recommended)

## Installation

### Via Composer

```bash
composer require rayfunghk/razy
```

### Via Docker

```bash
docker pull ghcr.io/rayfunghk/razy:latest
docker run -p 8080:8080 ghcr.io/rayfunghk/razy
```

### From Source

```bash
git clone https://github.com/RayFungHK/Razy.git
cd Razy
composer install
php build.php
```

## Quick Start

```bash
# Build the Razy environment
php Razy.phar build

# Create a distributor
php Razy.phar init dist mysite

# Generate rewrite rules
php Razy.phar rewrite mysite
```

This creates the working directory structure:

```
project/
├── Razy.phar               # Framework binary
├── config.inc.php           # Global configuration
├── sites.inc.php            # Domain → distributor mapping
├── index.php                # Web entry point
├── shared/module/           # Cross-distributor modules
└── sites/mysite/            # Your distributor
    ├── dist.php             # Distributor config (tags, modules, bridge)
    └── vendor/module/       # Your modules
        ├── module.php       # Module metadata
        └── default/
            ├── package.php  # Package config (API name, requires)
            └── controller/  # Route handlers
```

---

## Docker

Razy ships with a complete Docker setup for development and testing.

### Development

```bash
# Start PHP dev server + Caddy reverse proxy
docker compose -f .docker/docker-compose.yml up

# With live reload and auto-build
docker compose -f .docker/docker-compose.yml -f .docker/docker-compose.dev.yml up
```

### Full Test Suite (Linux + Redis + SSH2)

Run all tests including those that require Linux-only extensions:

```bash
docker compose -f .docker/docker-compose.test.yml up --build --abort-on-container-exit
# → 4,564 tests, 8,178 assertions, 0 skipped
```

### DevContainer (VS Code / Codespaces)

Open the project in VS Code and select **"Reopen in Container"** — the DevContainer is pre-configured with PHP 8.3, Composer, and all required extensions.

---

## Architecture Overview

```
                    ┌─────────────────────────────────────┐
                    │           Application                │
                    │  (Domain matching → Distributor)     │
                    └──────────────┬──────────────────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              ▼                    ▼                     ▼
      ┌──────────────┐   ┌──────────────┐      ┌──────────────┐
      │  Distributor  │   │  Distributor  │      │  Standalone   │
      │   (mysite)    │   │   (admin)     │      │   (lite)      │
      └──────┬───────┘   └──────┬───────┘      └──────┬───────┘
             │                   │                      │
        ┌────┴────┐         ┌────┴────┐           ┌────┴────┐
        │ Modules │         │ Modules │           │ Modules │
        │ (tagged)│         │ (tagged)│           │ (direct)│
        └─────────┘         └─────────┘           └─────────┘
```

Each distributor maps to a domain/path via `sites.inc.php` and loads its own versioned module set. Modules communicate through **APIs**, **events**, and **bridge commands**. The **Standalone** mode provides a lightweight runtime for single-module applications.

---

## Module Lifecycle

Modules progress through a well-defined lifecycle during each request:

```
__onInit  →  __onLoad  →  __onRequire  →  (await callbacks)
    →  __onReady  →  __onScriptReady / __onRouted  →  __onEntry
```

```php
return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register routes, APIs, events, scripts
        $agent->addLazyRoute(['dashboard' => 'dashboard']);
        $agent->addAPICommand('getUser', 'api/get_user.php');
        $agent->listen('auth/user:onLogin', 'onUserLogin');
        return true;
    }

    public function __onReady(): bool
    {
        // Safe to call APIs here — all modules are loaded
        return true;
    }
};
```

---

## Core Concepts

### Routing

Two routing strategies: **lazy routes** (convention-based) and **regex routes** (pattern-based).

```php
// Lazy: /modulecode/users/list → controller/users/list.php
$agent->addLazyRoute(['users' => ['list' => 'list']]);

// Regex: /api/user-42/profile → controller/Route.user_profile.php
$agent->addRoute('/api/user-(:d)/profile', 'user_profile');
```

### Cross-Module API

Modules expose and consume APIs for inter-module communication:

```php
// Provider: register in __onInit
$agent->addAPICommand('getData', 'api/get_data.php');

// Consumer: call from any handler
$result = $this->api('vendor/provider')->getData($id);
```

### Template Engine

Block-based templates with variables, modifiers, conditionals, and iteration:

```
{$user.name|capitalize}

{@if $user.role="admin"}
  <span class="badge">Admin</span>
{/if}

{@each source=$items as="item"}
  <li>{$item.name} — {$item.price}</li>
{/each}
```

### Database Simple Syntax

Shorthand syntax for joins, WHERE clauses, and JSON operations:

```php
// Simple syntax generates complex SQL automatically
$stmt = $db->prepare()
    ->from('u.user-g.group[group_id]')
    ->where('u.user_id=?,!g.auths~=?')
    ->assign(['auths' => 'view', 'user_id' => 1]);

// → SELECT * FROM `user` AS `u` JOIN `group` AS `g`
//   ON u.group_id = g.group_id
//   WHERE `u`.`user_id` = 1
//   AND !(JSON_CONTAINS(JSON_EXTRACT(`g`.`auths`, '$.*'), '"view"') = 1)
```

### CLI Commands

```bash
php Razy.phar build                    # Build environment
php Razy.phar runapp mysite            # Interactive shell
php Razy.phar install owner/repo       # Install from GitHub
php Razy.phar pack distCode            # Package modules
php Razy.phar publish                  # Publish to repository
php Razy.phar validate distCode        # Validate & install deps
php Razy.phar bridge '{"dist":"..."}'  # Cross-distributor call
```

---

## Package Management (Composer Integration)

Razy includes a built-in **Composer-compatible package manager** that downloads, extracts, and version-locks third-party packages from Packagist or any private mirror — **scoped per distributor** so each site gets its own isolated dependency tree.

### How It Works

1. **Modules declare prerequisites** in their `package.php` using `vendor/package` notation:

   ```php
   // vendor/blog/default/package.php
   return [
       'module_code'    => 'vendor/blog',
       'version'        => '1.0.0',
       'api_name'       => 'blog',
       'require'        => ['vendor/auth' => '>=1.0.0'],   // Razy module dependency
       'prerequisite'   => [                                 // Composer package dependency
           'monolog/monolog'    => '^3.0',
           'guzzlehttp/guzzle' => '^7.0',
       ],
   ];
   ```

2. **On compose**, the framework collects all `prerequisite` entries across every loaded module, resolves version constraints, and downloads matching packages:

   ```bash
   php Razy.phar compose mysite
   # → Fetches metadata from Packagist
   # → Downloads & extracts monolog/monolog ^3.0
   # → Downloads & extracts guzzlehttp/guzzle ^7.0
   # → Writes autoload/lock.json
   ```

3. **Per-distributor isolation** — packages are extracted into `autoload/{distributor_code}/` with PSR-4/PSR-0 namespace mapping, and a single `autoload/lock.json` tracks installed versions keyed by distributor:

   ```
   autoload/
   ├── lock.json                          # Version lock (all distributors)
   ├── mysite/                            # Packages for "mysite" distributor
   │   ├── Monolog\
   │   └── GuzzleHttp\
   └── admin/                             # Packages for "admin" distributor
       └── Monolog\
   ```

### Transport Layer

The package manager is **transport-agnostic**. By default it fetches from Packagist over HTTPS, but you can point it at any mirror using a pluggable transport:

| Transport | Protocol | Use Case |
|-----------|----------|----------|
| `HttpTransport` | HTTP/HTTPS | Packagist, Satis, Private Packagist, GitHub |
| `FtpTransport` | FTP/FTPS | FTP mirrors with optional TLS |
| `SftpTransport` | SFTP | SSH-based secure transfer |
| `SmbTransport` | SMB/CIFS | Windows network shares, Samba |
| `LocalTransport` | File system | Local directory or mounted drive |

All transports implement `PackageTransportInterface`. Set a global default at bootstrap:

```php
use Razy\PackageManager;
use Razy\PackageManager\FtpTransport;

PackageManager::setDefaultTransport(new FtpTransport(
    host: 'mirror.internal',
    username: 'deploy',
    password: 'secret',
    basePath: '/composer',
));
```

### Version Constraints

Supports the same constraint syntax as Composer: `^1.0`, `~2.3`, `>=1.2.0`, `*`, exact versions, and stability flags (`@dev`, `@beta`, `@RC`). Sub-dependencies declared in each package's own `require` block are resolved recursively.

> **Full details:** [Packaging & Distribution wiki](https://github.com/RayFungHK/Razy/wiki/Packaging-Distribution)

---

## Demo Modules

The [`demo_modules/`](demo_modules/) directory contains 22 production-ready reference modules organized by category:

| Category | Modules |
|----------|---------|
| **core/** | event_demo, event_receiver, route_demo, template_demo, thread_demo, bridge_provider |
| **data/** | collection_demo, database_demo, hashmap_demo, yaml_demo |
| **demo/** | demo_index, hello_world, markdown_consumer |
| **io/** | api_demo, api_provider, bridge_demo, dom_demo, mailer_demo, message_demo, sse_demo, xhr_demo |
| **system/** | advanced_features, helper_module, markdown_service, plugin_demo, profiler_demo |

Each module includes inline documentation and can be copied directly into your distributor's module directory. See the [demo README](demo_modules/README.md) for detailed descriptions.

---

## Roadmap

All items for v1.0 are complete. The framework is in **beta** — APIs are stable but may receive minor refinements before the final release.

| Status | Feature |
|--------|---------|
| ✅ | Multi-site distributor architecture with domain routing |
| ✅ | Module system with dependency resolution & 14 lifecycle hooks |
| ✅ | Template engine with blocks, modifiers, conditionals, iteration |
| ✅ | Multi-driver database layer (MySQL, PostgreSQL, SQLite) |
| ✅ | GitHub module installer via CLI |
| ✅ | Thread system (`ThreadManager`) |
| ✅ | Cross-distributor bridge system |
| ✅ | Module repository & publishing system |
| ✅ | Cache system (PSR-16 SimpleCache with File, Redis, Null adapters) |
| ✅ | Authenticator (TOTP/HOTP 2FA) |
| ✅ | FTP/SFTP file transfer clients |
| ✅ | Database migration system |
| ✅ | Queue / job dispatching |
| ✅ | Rate limiting middleware |
| ✅ | WebSocket server & client |
| ✅ | Docker image & CI/CD pipeline |
| ✅ | Comprehensive test suite (4,564 tests, 8,178 assertions) |

---

## Version Milestone Summary

### v1.0-beta — First Public Beta (Feb 2026)

The foundation release. Razy shipped as an open-source project with full governance (MIT license, contributing guide, security policy), Docker infrastructure, a Composer-compatible package manager, and a comprehensive test suite of 4,564 tests with zero skips. This milestone established the framework's public contract — stable APIs, reproducible builds, and CI/CD from day one.

### v1.0.1-beta — Worker Optimization & Tenant Isolation (Feb 2026)

Two problems drove this release:

**1. Performance under persistent workers.**  
The original FrankenPHP worker loop rebuilt the entire object graph (Application, Container, Standalone, Module, RouteDispatcher) on every single request — the same work a traditional CGI process does, but inside a persistent worker where it should only happen once. Fixing this was straightforward in concept (boot once, dispatch many) but required rethinking how state flows through the framework. The result was a **37× throughput improvement** (171 → 6,311 RPS) and **5× faster than Laravel Octane (Swoole)** on read-heavy workloads, with an additional round of 8 hot-path micro-optimizations shaving another 4.6% off tail latency.

**2. Cross-vendor module identity collisions.**  
Razy's module system uses a two-part `vendor/package` code (e.g., `acme/logger`), but several internal paths — config files, asset URLs, API registration, closure prefixes, rewrite rules — were keyed on only the short class name or alias (the last segment). In a single-vendor setup this worked fine. But in multi-tenant and multi-vendor deployments — the exact use case Razy was designed for — two modules from different vendors with the same package name (e.g., `acme/logger` and `beta/logger`) would silently collide: one module could read another's config, hijack its API, shadow its assets, or cause rewrite rules to be silently dropped.

This is not an edge case. In enterprise SaaS environments, module vendors operate independently and cannot coordinate naming. A platform hosting modules from multiple vendors **must** guarantee vendor-scoped isolation by default. Five collision vectors were identified and fixed, API registration now throws on duplicates instead of silently overwriting, and all identity keys now use the full `vendor/package` module code.

| Version | Key Changes |
|---------|------------|
| **v1.0-beta** | Open-source readiness, Docker, Composer package management, 4,564 tests |
| **v1.0.1-beta** | 37× worker throughput, 5× vs Laravel, cross-vendor module isolation (5 collision fixes), DI security hardening, pre-commit hook, 4,794 tests |

> Full per-version changelogs: [`changelog/`](changelog/) directory.

### Cross-Tenant Architecture — Why and What's Next

Razy was designed from the start as a **multi-site, multi-distributor** framework: one codebase, many projects. But as the architecture matured — especially with FrankenPHP worker mode keeping the entire Application graph alive in memory — a deeper problem surfaced.

**The problem: shared-process trust boundaries.**

In a traditional CGI model, each request starts a fresh PHP process. Isolation is free — one request can't reach into another's memory. But in a persistent worker, all distributors and modules share the same process. A malicious or buggy module in one distributor can theoretically access another distributor's data, configs, or API registrations. The v1.0.1-beta cross-vendor collision fixes addressed the **naming** side of this problem, but the **runtime isolation** side remains.

The real-world scenario is straightforward: a SaaS platform hosts multiple tenants (clients), each with their own distributors, modules, domains, and data. Today, they all run inside the same PHP process, share the same filesystem, and trust each other implicitly. For internal tooling this is acceptable. For enterprise multi-tenant SaaS — where tenants are separate legal entities with separate data obligations — it is not.

**The solution: 1 Tenant = 1 Razy Application environment.**

Each tenant runs as a complete, isolated Razy instance — its own container, its own filesystem, its own `open_basedir`. The local host becomes just another tenant (the "Host Tenant"). Cross-tenant communication uses an explicit HTTP bridge with HMAC authentication, not shared memory. Module code requires **zero changes** — isolation is enforced at the framework and OS layers.

| Phase | Version | What It Delivers |
|-------|---------|-----------------|
| Phase 0 — Foundation | **v1.0.1-beta** ✅ | DI security blocklist, worker dispatch guards, boot-once, distributor caching, module change detection |
| Phase 1 — Tenant Isolation Core | v1.1.0-beta | Bootstrap tenant constants, data path isolation guards, in-memory hotplug (`plugTenant`/`unplugTenant`), worker signal integration |
| Phase 2 — Docker Multi-Tenant | v1.1.0 | Hardened tenant Dockerfile (`open_basedir` + `disable_functions`), Compose templates, per-tenant config generator |
| Phase 3 — Communication Layers | v1.2.0 | `TenantEmitter` (HTTP bridge + HMAC), `DataRequest`/`DataResponse` (file I/O), `__onTenantCall` permission gates, CLI `razy tenant` commands |
| Phase 4 — Kubernetes + Lifecycle | v1.3.0 | K8s namespace/PVC/NetworkPolicy templates, Helm chart, WorkerLifecycleManager integration |
| Phase 5 — Whitelist + Admin UI | v2.0.0 | `TenantAccessPolicy`, fine-grained cross-tenant data sharing, admin dashboard |

```
Phase 1 (isolation core) ─────┐
                               ├──► Phase 2 (Docker)  ──► Phase 4 (K8s)
                               │                              │
                               └──► Phase 3 (L4 + Data) ─────┘──► Phase 5 (Whitelist)
```

> Architecture deep-dive: [`docs/ENTERPRISE-TENANT-ISOLATION.md`](docs/ENTERPRISE-TENANT-ISOLATION.md)

---

## Performance: Razy vs Laravel

Benchmarked against **Laravel 12 + Octane (Swoole)** under identical conditions — same host, same MySQL, same container resources (2 CPUs / 4 GB RAM), same k6 load profiles.

### Head-to-Head Results

| Scenario | Razy RPS | Laravel RPS | Razy Advantage | Razy p95 | Laravel p95 |
|----------|----------|-------------|---------------|----------|-------------|
| **Static Route** | **6,331** | 1,254 | **5.0×** faster | 18.6ms | 186ms |
| **Template Render** | **6,264** | 1,137 | **5.5×** faster | 19.0ms | 189ms |
| **DB Read** (SELECT) | **3,763** | 952 | **4.0×** faster | 38.5ms | 191ms |
| **DB Write** (INSERT) | 754 | **842** | Laravel 1.1× | 182ms | 186ms |
| **Composite** (DB + Template) | **4,528** | 958 | **4.7×** faster | 72.4ms | 395ms |
| **Heavy CPU** (500K MD5) | 144 | **325** | Laravel 2.3× | 595ms | 1,590ms |

> Razy outperforms Laravel Octane in **4 of 6 scenarios** — all throughput-dominant workloads.
> Laravel leads in DB Write (MySQL INSERT is the bottleneck, not framework overhead) and CPU-bound fast-request throughput (Swoole's coroutine isolation).
> Even in the Heavy CPU scenario, Razy achieves **2.7× lower tail latency** (p95: 595ms vs 1,590ms).

### Runtime Configuration

| | Razy | Laravel |
|---|---|---|
| **Runtime** | FrankenPHP (Caddy, PHP 8.3.7, Alpine) | PHP 8.3-cli + Swoole (Octane) |
| **Worker Mode** | Persistent worker, boot-once dispatch | Octane Swoole (`--workers=auto`) |
| **OPcache** | JIT 1255, 128 MB buffer | JIT 1255, 128 MB buffer |
| **Config Cache** | N/A (standalone Phar) | `config:cache`, `route:cache`, `view:cache` |

### Why Is Razy Faster?

The difference is **architectural**, not just runtime tuning:

| | Razy | Laravel |
|---|---|---|
| **Per-request overhead** | ~0.05ms (dispatch only) | ~0.8ms (service container resolution, middleware pipeline, route matching) |
| **Object graph** | Boot once, reuse across all requests | Rebuilt partially per request even with Octane |
| **Template engine** | Native PHP blocks, zero compilation | Blade compiles to PHP, then executes |
| **Route matching** | Direct hash lookup from pre-compiled table | Regex matching through middleware stack |
| **Deployment** | Single `Razy.phar` — nothing to cache | Requires `config:cache`, `route:cache`, `view:cache`, `event:cache` for production |

### Conceptual Differences

Razy and Laravel solve different problems with fundamentally different philosophies:

| Aspect | Razy | Laravel |
|--------|------|----------|
| **Design goal** | Multi-project, multi-tenant module platform | Full-featured web application framework |
| **Unit of work** | Module (reusable, versioned, distributable) | Application (monolithic, project-bound) |
| **Multi-site** | First-class — distributors share modules | Bolted on via tenancy packages |
| **Team boundary** | Distributor per team, modules per sub-team, API/Event contracts | Package per team, service classes, facades |
| **Upgrade model** | Update shared module → all projects benefit | Update per project via `composer update` |
| **Code sharing** | Shared Modules (reference, not clone) | Composer packages (vendor lock per project) |
| **Configuration** | `dist.php` + `package.php` (minimal, flat) | `.env` + `config/*.php` + service providers (layered, ceremonial) |
| **Learning curve** | Steep upfront (module lifecycle), low ongoing | Low entry (conventions), steep at scale (deep service container knowledge) |
| **Ecosystem** | Purpose-built, self-contained | Massive third-party ecosystem (Forge, Vapor, Nova, Livewire, etc.) |

### When to Choose Razy

**Razy is ideal for:**

- **Multi-client platforms** — agencies or SaaS providers maintaining many client projects on a single codebase
- **Module-driven SaaS** — products where each customer gets a different combination of features (modules)
- **Subscription-based services** — where continuous upgrades across all clients is a core business requirement
- **High-throughput APIs** — services where 5× throughput and 10× lower latency matter (real-time, IoT, fintech)
- **Small teams managing many projects** — one module update benefits every project simultaneously
- **Microservice backends** — lightweight, fast startup, single-binary deployment

**Laravel is ideal for:**

- **Standalone web applications** — CMS, e-commerce, admin panels with rich UI needs
- **Teams that value convention over configuration** — developers familiar with Rails/Django patterns
- **Projects that rely heavily on third-party packages** — authentication, billing, notifications, queues
- **Prototyping and MVPs** — rapid scaffolding with Artisan generators
- **CPU-bound workloads** — Swoole's coroutine model handles mixed I/O + CPU better

> Full benchmark methodology, raw data, and reproduction steps: [`benchmark/`](benchmark/) directory.

---

## Testing

```bash
# Install dependencies
composer install

# Run the full test suite
composer test                  # 4,564 tests, 8,046 assertions (Windows — 87 skipped)
composer test-coverage         # Generate coverage report

# Code quality
composer cs-check              # Check PSR-12 compliance
composer cs-fix                # Auto-fix code style
composer quality               # Run tests + style checks
```

### Full Platform Coverage via Docker

To run all tests with zero skips (including Redis, SSH2, and Linux-only permission tests):

```bash
docker compose -f .docker/docker-compose.test.yml up --build --abort-on-container-exit
# → 4,564 tests, 8,178 assertions, 0 skipped, 0 errors
```

**Test suite covers**: 102 test classes across Authenticator, Cache (File/Redis/Null), Collection, Configuration, Container (DI), Controller, Crypt, Database (drivers, queries, transactions, migrations), DOM, EventDispatcher, FTPClient, HashMap, HttpClient, Logger, Mailer, Middleware, Module system, ORM, Pipeline, Routing, SFTPClient, Session, Template, Validation, WebSocket, Worker lifecycle, YAML, and more.

---

## Documentation

> **New here?** Start with the **[Quick Start (5 min)](https://github.com/RayFungHK/Razy/wiki/Quick-Start)** tutorial — build and run your first module in under 5 minutes.

Full documentation is available on the **[GitHub Wiki](https://github.com/RayFungHK/Razy/wiki)** and the **[Documentation Site](https://rayfunghk.github.io/Razy/)**.

| Section | Topics |
|---------|--------|
| **Getting Started** | [Quick Start](https://github.com/RayFungHK/Razy/wiki/Quick-Start) · [Installation](https://github.com/RayFungHK/Razy/wiki/Installation) · [Architecture](https://github.com/RayFungHK/Razy/wiki/Architecture) |
| **Core Concepts** | [Modules](https://github.com/RayFungHK/Razy/wiki/Module-System) · [Controller](https://github.com/RayFungHK/Razy/wiki/Controller) · [Agent](https://github.com/RayFungHK/Razy/wiki/Agent) · [Routing](https://github.com/RayFungHK/Razy/wiki/Routing) · [Events](https://github.com/RayFungHK/Razy/wiki/Event-System) |
| **Data & Storage** | [Database](https://github.com/RayFungHK/Razy/wiki/Database) · [Collection](https://github.com/RayFungHK/Razy/wiki/Collection) · [Configuration](https://github.com/RayFungHK/Razy/wiki/Configuration) · [HashMap](https://github.com/RayFungHK/Razy/wiki/HashMap) · [YAML](https://github.com/RayFungHK/Razy/wiki/YAML) |
| **Rendering** | [Template Engine](https://github.com/RayFungHK/Razy/wiki/Template-Engine) · [DOM Builder](https://github.com/RayFungHK/Razy/wiki/DOM-Builder) |
| **IO & Communication** | [XHR](https://github.com/RayFungHK/Razy/wiki/XHR) · [SSE](https://github.com/RayFungHK/Razy/wiki/SSE) · [Mailer](https://github.com/RayFungHK/Razy/wiki/Mailer) · [FTP/SFTP](https://github.com/RayFungHK/Razy/wiki/FTP-SFTP) · [SimplifiedMessage](https://github.com/RayFungHK/Razy/wiki/SimplifiedMessage) |
| **Security** | [Crypt](https://github.com/RayFungHK/Razy/wiki/Crypt) · [Authenticator](https://github.com/RayFungHK/Razy/wiki/Authenticator) |
| **Advanced** | [Plugins](https://github.com/RayFungHK/Razy/wiki/Plugin-System) · [Threads](https://github.com/RayFungHK/Razy/wiki/Thread-ThreadManager) · [Simple Syntax](https://github.com/RayFungHK/Razy/wiki/Simple-Syntax) |
| **Deployment** | [Sites Config](https://github.com/RayFungHK/Razy/wiki/Sites-Configuration) · [Packaging](https://github.com/RayFungHK/Razy/wiki/Packaging-Distribution) · [CLI](https://github.com/RayFungHK/Razy/wiki/CLI-Commands) · [Caddy Worker](https://github.com/RayFungHK/Razy/wiki/Caddy-Worker-Mode) |
| **Reference** | [API Reference](https://github.com/RayFungHK/Razy/wiki/API-Reference) · [ModuleInfo](https://github.com/RayFungHK/Razy/wiki/ModuleInfo) · [Utilities](https://github.com/RayFungHK/Razy/wiki/Utility-Functions) · [Testing](https://github.com/RayFungHK/Razy/wiki/Testing) |

---

## Contributing

Contributions are welcome! Please read the [Contributing Guide](CONTRIBUTING.md) before submitting a pull request.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Write tests for your changes
4. Ensure all tests pass (`composer test`)
5. Submit a pull request

For bug reports and feature requests, please use [GitHub Issues](https://github.com/RayFungHK/Razy/issues).

See also: [Code of Conduct](CODE_OF_CONDUCT.md) · [Security Policy](SECURITY.md)

---

## Why Razy?

Razy was born from real-world freelance experience managing multiple client projects simultaneously. Traditional frameworks made it painful to share code between projects, backport updates across deployments, and maintain version-specific module sets for different clients.

Razy solves this by treating **modules as versioned, distributable units** — each project (distributor) picks exactly which module versions to load, shared services are available globally, and the entire system packages into a single phar for deployment.

**Design principles:**

- **Multi-tenancy by design** — not bolted on as an afterthought
- **Version isolation** — different distributors can run different module versions side by side
- **Zero-conflict autoloading** — Composer packages are scoped per distributor
- **One binary deployment** — `Razy.phar` contains the entire framework

---

## Development Journey

Razy didn't start as a framework. It grew out of years of real-world project delivery, each stage solving a pain that the previous one exposed.

### Phase 1 — The Template Problem

In the early days of web development, PHP and HTML were tangled together. MVC was a good idea in theory, but in practice, frontend designers and backend developers constantly stepped on each other's toes. To reduce friction between the two roles, the first generation of Razy's **template engine** was born — inspired by phpBB's template architecture rather than Smarty, because Smarty's syntax was something a frontend person couldn't read at a glance. The goal was simple: **give designers markup they can understand without learning a programming language.**

### Phase 2 — The Copy-Paste Trap

When freelance projects started coming in, a painful pattern emerged. Every new project meant creating a new folder, copying in the template engine, configuring everything from scratch, and dragging over whatever libraries were useful from the last project. Each copy diverged the moment it was created. Changes were large, setup was slow, and nothing was truly reusable.

### Phase 3 — The Version Drift Crisis

The natural next step was to consolidate common code into a shared library. But as that library grew, maintaining it became its own problem. Updating a feature in one project didn't transfer cleanly to another — it wasn't just copy-and-paste. Worse, working with other vendors' systems revealed a deeper structural issue: **the earlier a client was onboarded, the more outdated their system became.** Debugging old versions was expensive, shipping new features to legacy clients was impractical, and the business incentive for building new functionality dropped because only the newest clients would benefit.

### Phase 4 — Rethinking the Development Model

This led to a fundamental rethink. Instead of treating each project as a standalone codebase, **what if code management and project management were the same thing?** What if every client's system was part of one unified development environment — different configurations of the same modules, not different copies? The concept of a module-driven, version-aware architecture started taking shape.

### Phase 5 — From Project Fees to Subscription Services

The business model evolved alongside the architecture. Rather than charging one-time project fees and walking away, the shift was toward **monthly subscription services** — maintaining a long-term relationship with each client. This meant every client, old and new, could receive continuous upgrades on a shared foundation. The economic incentive aligned perfectly with the architectural vision: invest once in a feature, roll it out across all subscribers.

### Phase 6 — Razy Takes Shape

Razy's first real prototype emerged with a clear mission: **minimize the cost of developing and maintaining modules.** Modules became reusable across projects. Multiple projects shared a single development environment. Module functionality was broken into small, focused fragments. URL-path-to-controller mapping made code navigation intuitive. **Shared Modules** could be referenced rather than cloned — one update propagated everywhere.

### Phase 7 — Team Boundaries via API & Event

As projects grew, multiple teams began working on the same system. To prevent teams from interfering with each other's codebases, Razy introduced **Module API** and **Event** systems. Each team declared what data they needed via requirement requests, and the providing team exposed it through formal APIs and events. Cross-module communication became **explicit and permissioned** rather than implicit and fragile.

### Phase 8 — From Module-Base to Distributor-Base

The unit of team ownership expanded. A team no longer managed just a single module — they managed an entire **distributor**, with sub-teams responsible for individual modules within it. This matched real organizational structures: one team owns the shop, another owns the admin panel, another owns the API gateway — each a distributor with its own routing, modules, and release cycle.

### Phase 9 — Security & Isolation Hardening

With multiple teams and multiple distributors sharing infrastructure, security became a priority. Razy went through continuous refinement to ensure **modules couldn't reach across distributor boundaries** and tamper with core logic. The architecture evolved to enforce isolation by default — not as a policy, but as a structural impossibility.

### Phase 10 — Developer Experience Refinement

Through years of real project delivery, Razy was continuously refined. Features like **Simple Syntax** (a shorthand for complex SQL joins and JSON operations) were added to make everyday development faster and more intuitive. Each pain point encountered in production fed back into the framework's design.

### Phase 11 — Modern Stack & v1.0-Beta

The modern development environment demanded more. **FrankenPHP Worker Mode** was integrated for persistent-process performance. Mainstream deployment patterns (Docker, Caddy, CI/CD) were supported natively. AI tooling was adopted to accelerate documentation, code auditing, and architectural analysis — turning months of manual work into days. After **three years of development** plus **six months of AI-assisted refinement**, Razy v1.0-Beta shipped. Benchmarks showed **higher throughput, lower latency, and better resource efficiency** than mainstream frameworks like Laravel.

### Phase 12 — Container Architecture for Zero-Downtime Updates

To minimize the impact of hotfixes and upgrades on running systems, Razy introduced **Core Container** and **Module Container** concepts. As long as a worker process was serving requests, hotplugged updates could be staged and transitioned using configurable strategies — from graceful drain to immediate swap — enabling **zero-downtime version transitions** in production.

### Phase 13 — Tenant Isolation for Enterprise & SaaS

The latest evolution brings **enterprise-grade tenant isolation**. Each tenant runs as an isolated pod with its own filesystem, data, and configuration — supporting both Docker Compose and Kubernetes deployments. Staging environments are cleaner, SaaS onboarding is streamlined, and microservice architectures can leverage Razy's module system without sacrificing security boundaries. The framework now supports the full spectrum from single-developer side projects to **multi-team, multi-tenant enterprise platforms**.

---

## License

[MIT License](LICENSE) — Copyright (c) Ray Fung
