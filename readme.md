# Razy Framework

**A modular PHP framework for multi-site, multi-distributor application development.**

[![CI](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml/badge.svg)](https://github.com/RayFungHK/Razy/actions/workflows/ci.yml)
[![Version](https://img.shields.io/badge/version-1.0--beta-blue.svg)](https://github.com/RayFungHK/Razy/releases)
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
- [Testing](#testing)
- [Documentation](#documentation)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
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

## License

[MIT License](LICENSE) — Copyright (c) Ray Fung
