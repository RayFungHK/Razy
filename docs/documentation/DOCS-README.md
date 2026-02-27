# Razy Framework Documentation

Comprehensive documentation for the Razy PHP framework (v0.5.4).

**📌 Current Version**: [v0.5.4 - Feb 8, 2026](../releases/VERSION.md) | [Changelog](../releases/CHANGELOG.md) | [What's New](#whats-new-in-v054)

---

## What's New in v0.5.4
⚡ **Built-in Cache System** - PSR-16 compatible caching with FileAdapter, ApcuAdapter, file-validated caching, and CLI management  📦 **GitHub Module Installer** - Download and install modules from GitHub directly via CLI  
� **Distributor Inspector** - Inspect distributor configuration, domains, and module status  
📚 **Plugin System Documentation** - Complete guide to creating Template, Collection, FlowManager, and Statement plugins  
🗃️ **Database Query Documentation** - Complete guide to TableJoinSyntax and WhereSyntax with all operators  
🚀 **Multiple Install Modes** - Branch, release, distributor modules, custom paths  
🔐 **Private Repository Support** - Use personal access tokens for private repos  
🐛 **.htaccess Fix** - Fixed critical bugs in rewrite rule generation for cross-platform compatibility  

See [CACHE-SYSTEM.md](../guides/CACHE-SYSTEM.md) for cache guide, [PLUGIN-SYSTEM.md](../guides/PLUGIN-SYSTEM.md) for plugin guide, [DATABASE-QUERY-SYNTAX.md](../guides/DATABASE-QUERY-SYNTAX.md) for queries, [GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md) for installer, [INSPECT-COMMAND.md](../guides/INSPECT-COMMAND.md) for diagnostics, or [CHANGELOG.md](../releases/CHANGELOG.md) for full details.

---

## Quick Start

1. **New to Razy?** Start with [USAGE-SUMMARY.md](USAGE-SUMMARY.md) for a high-level overview
2. **Creating plugins?** Read [PLUGIN-QUICK-REFERENCE.md](../quick-reference/PLUGIN-QUICK-REFERENCE.md) for quick templates or [PLUGIN-SYSTEM.md](../guides/PLUGIN-SYSTEM.md) for deep dive
3. **Database queries?** See [DATABASE-QUERY-QUICK-REFERENCE.md](../quick-reference/DATABASE-QUERY-QUICK-REFERENCE.md) for operators or [DATABASE-QUERY-SYNTAX.md](../guides/DATABASE-QUERY-SYNTAX.md) for complete guide
4. **Building modules?** Read [PRODUCTION-USAGE-ANALYSIS.md](../usage/PRODUCTION-USAGE-ANALYSIS.md) for real-world patterns
5. **Installing modules?** See [GITHUB-INSTALLER.md](../guides/GITHUB-INSTALLER.md) for GitHub installation or [INSPECT-COMMAND.md](../guides/INSPECT-COMMAND.md) for diagnostics
6. **Using packages?** See [COMPOSER-QUICK-REFERENCE.md](../quick-reference/COMPOSER-QUICK-REFERENCE.md) for dependency management
7. **Caching?** See [CACHE-QUICK-REFERENCE.md](../quick-reference/CACHE-QUICK-REFERENCE.md) for caching or [CACHE-SYSTEM.md](../guides/CACHE-SYSTEM.md) for full guide
8. **Need performance?** See [CADDY-WORKER-QUICK-REFERENCE.md](../quick-reference/CADDY-WORKER-QUICK-REFERENCE.md) for 7x speed boost
9. **What's new?** Check [CHANGELOG.md](../releases/CHANGELOG.md) for version history or [RELEASE-NOTES.md](../releases/RELEASE-NOTES.md) for highlights

## Documentation Index

### Core Guides

| Document | Description | Best For |
|----------|-------------|----------|
| [**VERSION.md**](../releases/VERSION.md) | Current version info and quick stats | Version checking, what's new |
| [**GITHUB-INSTALLER.md**](../guides/GITHUB-INSTALLER.md) | Download and install modules from GitHub | Module installation, CLI usage |
| [**INSPECT-COMMAND.md**](../guides/INSPECT-COMMAND.md) | Distributor inspector reference | Diagnostics, module status, troubleshooting |
| [**PLUGIN-SYSTEM.md**](../guides/PLUGIN-SYSTEM.md) | Complete plugin architecture and development guide | Understanding plugins, creating extensions |
| [**PLUGIN-QUICK-REFERENCE.md**](../quick-reference/PLUGIN-QUICK-REFERENCE.md) | Plugin development quick reference and templates | Quick plugin creation, daily development |
| [**DATABASE-QUERY-SYNTAX.md**](../guides/DATABASE-QUERY-SYNTAX.md) | Complete TableJoinSyntax and WhereSyntax guide | Database queries, JOIN syntax, WHERE clauses |
| [**DATABASE-QUERY-QUICK-REFERENCE.md**](../quick-reference/DATABASE-QUERY-QUICK-REFERENCE.md) | Database query operator quick reference | Fast operator lookup, query patterns |
| [**COMPOSER-INTEGRATION.md**](../guides/COMPOSER-INTEGRATION.md) | Complete Composer package management guide | Understanding dependency system, troubleshooting |
| [**COMPOSER-QUICK-REFERENCE.md**](../quick-reference/COMPOSER-QUICK-REFERENCE.md) | Version constraint cheatsheet and examples | Quick lookup, daily development |
| [**CADDY-WORKER-MODE.md**](../guides/CADDY-WORKER-MODE.md) | FrankenPHP/Caddy worker mode for high performance | Production deployment, 3-10x performance boost |
| [**CADDY-WORKER-QUICK-REFERENCE.md**](../quick-reference/CADDY-WORKER-QUICK-REFERENCE.md) | Worker mode setup and best practices cheatsheet | Quick deployment, daily worker development |
| [**OFFICE365-SSO.md**](../guides/OFFICE365-SSO.md) | Office 365 / Azure AD SSO authentication guide | Enterprise authentication, Microsoft Graph API |
| [**OFFICE365-SSO-QUICK-REFERENCE.md**](../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md) | Office 365 SSO setup and configuration cheatsheet | Quick Azure AD setup, daily SSO development |
| [**YAML-QUICK-REFERENCE.md**](../quick-reference/YAML-QUICK-REFERENCE.md) | YAML configuration syntax and usage cheatsheet | Quick YAML syntax, config file management |
| [**CACHE-SYSTEM.md**](../guides/CACHE-SYSTEM.md) | Complete PSR-16 cache system guide | Caching, adapters, file-validated caching |
| [**CACHE-QUICK-REFERENCE.md**](../quick-reference/CACHE-QUICK-REFERENCE.md) | Cache system quick reference card | Quick lookup, cache operations, CLI |
| [**TESTING.md**](../guides/TESTING.md) | Unit testing guide with PHPUnit | Writing tests, coverage, CI/CD |
| [**TEST-COVERAGE-SUMMARY.md**](../status/TEST-COVERAGE-SUMMARY.md) | Test coverage report and statistics | Test status, coverage metrics, next steps |
| [**PSR-STANDARDS.md**](PSR-STANDARDS.md) | PSR-1, PSR-4, PSR-12 compliance guide | Code style, autoloading, quality standards |
| [**PSR-QUICK-REFERENCE.md**](../quick-reference/PSR-QUICK-REFERENCE.md) | PSR-12 quick reference and common patterns | Quick lookup, daily development |
| [**usage/PRODUCTION-USAGE-ANALYSIS.md**](../usage/PRODUCTION-USAGE-ANALYSIS.md) | Real-world module patterns from production site | Module architecture, best practices |
| [**usage/LLM-PROMPT.md**](../usage/LLM-PROMPT.md) | LLM integration guide for Razy documentation | AI assistant setup, automated help |

### Class Reference

Detailed documentation for all Razy classes in [`usage/`](usage/) directory:

#### Application & Lifecycle
- [Razy.Application.md](usage/Razy.Application.md) - Main application controller
- [Razy.Domain.md](usage/Razy.Domain.md) - Domain/site management
- [Razy.Distributor.md](usage/Razy.Distributor.md) - Module distribution and loading
- [Razy.Module.md](usage/Razy.Module.md) - Module base class
- [Razy.ModuleInfo.md](usage/Razy.ModuleInfo.md) - Module metadata and configuration
- [Razy.Controller.md](usage/Razy.Controller.md) - Controller base class

#### Routing & Agent
- [Razy.Agent.md](usage/Razy.Agent.md) - Routing agent for modules
- [Razy.Route.md](usage/Razy.Route.md) - Route definition and matching

#### Templates
- [Razy.Template.md](usage/Razy.Template.md) - Template engine core
- [Razy.Template.Source.md](usage/Razy.Template.Source.md) - Template source parsing
- [Razy.Template.Block.md](usage/Razy.Template.Block.md) - Template block management
- [Razy.Template.Entity.md](usage/Razy.Template.Entity.md) - Template entity handling

#### Database
- [Razy.Database.md](usage/Razy.Database.md) - Database connection management
- [Razy.Database.Statement.md](usage/Razy.Database.Statement.md) - Query builder
- [Razy.Database.Query.md](usage/Razy.Database.Query.md) - Query execution
- [Razy.Database.Table.md](usage/Razy.Database.Table.md) - Table abstraction
- [Razy.Database.WhereSyntax.md](usage/Razy.Database.WhereSyntax.md) - WHERE clause builder
- [Razy.Database.TableJoinSyntax.md](usage/Razy.Database.TableJoinSyntax.md) - JOIN syntax
- [Razy.Database.Column.md](usage/Razy.Database.Column.md) - Column definition
- [Razy.Database.Preset.md](usage/Razy.Database.Preset.md) - Query presets

#### Flow Management
- [Razy.FlowManager.md](usage/Razy.FlowManager.md) - Workflow orchestration
- [Razy.FlowManager.Flow.md](usage/Razy.FlowManager.Flow.md) - Individual flow steps
- [Razy.FlowManager.Transmitter.md](usage/Razy.FlowManager.Transmitter.md) - Data transmission

#### Events & API
- [Razy.EventEmitter.md](usage/Razy.EventEmitter.md) - Event system core
- [Razy.Emitter.md](usage/Razy.Emitter.md) - Event emitter interface
- [Razy.API.md](usage/Razy.API.md) - Module API system

#### Package Management
- [Razy.PackageManager.md](usage/Razy.PackageManager.md) - Composer integration

#### Authentication & OAuth
- [Razy.OAuth2.md](usage/Razy.OAuth2.md) - Generic OAuth 2.0 client
- [Razy.Office365SSO.md](usage/Razy.Office365SSO.md) - Office 365 / Azure AD SSO

#### Caching
- [Razy.Cache.md](usage/Razy.Cache.md) - Cache facade (PSR-16)
- [Razy.Cache.CacheInterface.md](usage/Razy.Cache.CacheInterface.md) - Cache adapter interface
- [Razy.Cache.FileAdapter.md](usage/Razy.Cache.FileAdapter.md) - File-based cache adapter
- [Razy.Cache.ApcuAdapter.md](usage/Razy.Cache.ApcuAdapter.md) - APCu cache adapter
- [Razy.Cache.NullAdapter.md](usage/Razy.Cache.NullAdapter.md) - Null cache adapter

#### Utilities
- [Razy.Configuration.md](usage/Razy.Configuration.md) - Configuration management
- [Razy.YAML.md](usage/Razy.YAML.md) - YAML parser and dumper
- [Razy.Collection.md](usage/Razy.Collection.md) - Data collections
- [Razy.HashMap.md](usage/Razy.HashMap.md) - Hash map implementation
- [Razy.XHR.md](usage/Razy.XHR.md) - AJAX/XHR handling
- [Razy.DOM.md](usage/Razy.DOM.md) - DOM manipulation
- [Razy.Crypt.md](usage/Razy.Crypt.md) - Encryption utilities
- [Razy.Mailer.md](usage/Razy.Mailer.md) - Email sending
- [Razy.FileReader.md](usage/Razy.FileReader.md) - File operations
- [Razy.SimpleSyntax.md](usage/Razy.SimpleSyntax.md) - Syntax parsing
- [Razy.SimplifiedMessage.md](usage/Razy.SimplifiedMessage.md) - Message formatting
- [Razy.Profiler.md](usage/Razy.Profiler.md) - Performance profiling
- [Razy.Terminal.md](usage/Razy.Terminal.md) - CLI operations
- [Razy.Error.md](usage/Razy.Error.md) - Error handling

## Common Tasks

### Package Management

**Add a dependency:**
```php
// In module's package.php
'prerequisite' => [
    'vendor/package' => '~2.0.0',
]
```

**Install packages:**
```bash
php main.php compose <distributor-code>
```

→ See [COMPOSER-QUICK-REFERENCE.md](COMPOSER-QUICK-REFERENCE.md)

### Module Development

**Create a module:**
1. Create folder: `sites/<site>/module/<module-name>/default/`
2. Add `module.php` (metadata)
3. Add `package.php` (runtime config)
4. Add `controller.inc.php` (business logic)

→ See [PRODUCTION-USAGE-ANALYSIS.md § 3](usage/PRODUCTION-USAGE-ANALYSIS.md#3-module-development-patterns)

### Database Queries

**Query builder:**
```php
$stmt = $this->prepare('users');
$stmt->where('status', 'active')
     ->orderBy('created_at', 'DESC')
     ->limit(10);
$users = $this->query($stmt);
```

→ See [Razy.Database.Statement.md](usage/Razy.Database.Statement.md)

### Template Rendering

**Render template:**
```php
$tpl = $this->prepareTemplate('view/page');
$tpl->assign('title', 'My Page');       // Copies value immediately
$tpl->assign('items', $itemArray);
return $tpl->render();
```

**Deferred binding (value resolved at render time):**
```php
$total = 0;
$tpl->bind('total', $total);            // Stores reference pointer
foreach ($rows as $row) {
    $root->newBlock('item')->assign($row);
    $total += $row['amount'];
}
echo $tpl->render();                     // {$total} reflects final value
```

**Scope hierarchy** (narrowest to widest):
`Entity → Block → Source → Template` — see [Razy.Template.md](usage/Razy.Template.md)

→ See [Razy.Template.md](usage/Razy.Template.md)

### Configuration Files

**Load config (PHP/JSON/INI/YAML):**
```php
$config = new Configuration('config/app.yaml');
echo $config['database']['host'];  // localhost
$config['debug'] = true;
$config->save();  // Auto-saves as YAML
```

→ See [Razy.Configuration.md](usage/Razy.Configuration.md) and [Razy.YAML.md](usage/Razy.YAML.md)

### API & Events

**Register API:**
```php
$this->agent->addAPICommand('my-action', function($data) {
    return ['success' => true, 'result' => $data];
});
```

**Trigger event:**
```php
$this->trigger('user:created', ['id' => $userId]);
```

→ See [Razy.API.md](usage/Razy.API.md) and [Razy.EventEmitter.md](usage/Razy.EventEmitter.md)

### Authentication & OAuth

**Office 365 SSO:**
```php
$sso = new Office365SSO($clientId, $secret, $redirect, $tenant);
$authUrl = $sso->getAuthorizationUrl();
$tokenData = $sso->getAccessToken($code);
$userInfo = $sso->getUserInfo($token);
```

→ See [OFFICE365-SSO-QUICK-REFERENCE.md](OFFICE365-SSO-QUICK-REFERENCE.md)

## CLI Commands

| Command | Description | Example |
|---------|-------------|---------|
| `compose` | Install package dependencies | `php main.php compose mysite` |
| `build` | Build PHAR archive | `php main.php build` |
| `run` | Start dev server | `php main.php run` |
| `version` | Show version info | `php main.php version` |
| `help` | Show help | `php main.php help` |
| `query` | Execute database query | `php main.php query mysite "SELECT * FROM users"` |
| `set` | Configure settings | `php main.php set` |

→ See [usage-summary.md § CLI Commands](../usage-summary.md#cli-commands-from-srcsystemterminalhelp.incphp)

## Learning Paths

### Path 1: Quick Start (15 min)
1. Read [`usage-summary.md`](../usage-summary.md) (5 min)
2. Read [COMPOSER-QUICK-REFERENCE.md](COMPOSER-QUICK-REFERENCE.md) (5 min)
3. Browse [PRODUCTION-USAGE-ANALYSIS.md § Common Patterns](usage/PRODUCTION-USAGE-ANALYSIS.md#4-common-patterns-and-techniques) (5 min)

### Path 2: Module Developer (1 hour)
1. Read [PRODUCTION-USAGE-ANALYSIS.md § Module Lifecycle](usage/PRODUCTION-USAGE-ANALYSIS.md#31-module-lifecycle-and-initialization) (15 min)
2. Read [Razy.Module.md](usage/Razy.Module.md) (15 min)
3. Read [Razy.Controller.md](usage/Razy.Controller.md) (15 min)
4. Read [Razy.Agent.md](usage/Razy.Agent.md) (15 min)

### Path 3: Database & Queries (30 min)
1. Read [Razy.Database.md](usage/Razy.Database.md) (10 min)
2. Read [Razy.Database.Statement.md](usage/Razy.Database.Statement.md) (10 min)
3. Read [Razy.Database.WhereSyntax.md](usage/Razy.Database.WhereSyntax.md) (10 min)

### Path 4: Templates (30 min)
1. Read [Razy.Template.md](usage/Razy.Template.md) (15 min)
2. Read [PRODUCTION-USAGE-ANALYSIS.md § Template Patterns](usage/PRODUCTION-USAGE-ANALYSIS.md#42-template-rendering-and-ui) (15 min)

### Path 5: Advanced Architecture (2 hours)
1. Read [Razy.Application.md](usage/Razy.Application.md) (20 min)
2. Read [Razy.Distributor.md](usage/Razy.Distributor.md) (20 min)
3. Read [Razy.FlowManager.md](usage/Razy.FlowManager.md) (20 min)
4. Read [PRODUCTION-USAGE-ANALYSIS.md](usage/PRODUCTION-USAGE-ANALYSIS.md) (60 min)

### Path 6: Performance & Deployment (45 min)
1. Read [CADDY-WORKER-MODE.md](CADDY-WORKER-MODE.md) (30 min)
2. Review worker mode best practices (15 min)

## Documentation Standards

All class documentation follows this structure:
1. **Purpose**: What the class does
2. **Usage**: How to use it
3. **Key Concepts**: Important patterns
4. **Public API**: Methods and properties
5. **Examples**: Code samples
6. **Related Classes**: Dependencies

## Contributing

When updating documentation:
1. Keep examples concise and practical
2. Reference real production code when possible
3. Update [LLM-PROMPT.md](usage/LLM-PROMPT.md) for new sections
4. Add entries to this README index

## Version

Documentation version: **0.5.0** (matches Razy framework version)

Last updated: 2024-01-XX

## Support

- Framework source: [`src/library/Razy/`](../src/library/Razy/)
- Production examples: [`../development-razy0.4/`](../development-razy0.4/) (if available)
- Issue tracking: [GitHub Issues](https://github.com/RayFungHK/Razy/issues) (if applicable)
