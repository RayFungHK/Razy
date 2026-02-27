# Razy LLM Prompt

Authoritative reference guide for LLM agents working with the Razy PHP framework (v0.5).

---

### How to Use This Repository

### 1. Production Usage First
- **Start with** [`PRODUCTION-USAGE-ANALYSIS.md`](./PRODUCTION-USAGE-ANALYSIS.md) for:
  - Real-world module patterns from `razy-sample` production site
  - Shared module architecture (razit core system)
  - Module lifecycle and dependencies
  - Common patterns: authentication, authorization, database, templates, workflows

### 2. Composer Integration
- **Package Management** [`../guides/COMPOSER-INTEGRATION.md`](../guides/COMPOSER-INTEGRATION.md) for:
  - Declaring PHP library dependencies in module `package.php`
  - Version constraint syntax (~2.0.0, ^1.0, *, etc.)
  - Installing packages: `php main.php compose <distributor>`
  - PSR-0/PSR-4 autoloading
  - Production examples (QR codes, spreadsheets, etc.)

### 4. Worker Mode Performance
- **Caddy/FrankenPHP Worker Mode** [`../guides/CADDY-WORKER-MODE.md`](../guides/CADDY-WORKER-MODE.md) for:
  - 3-10x performance boost with persistent PHP processes
  - State management and reset between requests
  - Module development best practices for worker mode
  - Deployment configurations and troubleshooting

### 5. Authentication & OAuth
- **Office 365 SSO** [`../guides/OFFICE365-SSO.md`](../guides/OFFICE365-SSO.md) for:
  - Azure AD / Microsoft Entra ID authentication
  - OAuth 2.0 and OpenID Connect flows
  - Microsoft Graph API integration (user profile, emails, calendar, files)
  - Token management and refresh
  - Multi-tenant support
  - Session management and security

### 6. Class Internals for Deep Dives
- Refer to individual class docs (e.g., `Razy.Application.md`) for:
  - Public APIs, construction, and key methods
  - Internal implementation details
  - Method signatures and return types

### 7. Answering Questions
- When a user asks about a **production pattern or real example**, cite `PRODUCTION-USAGE-ANALYSIS.md`
- When a user asks about a **specific class/method**, refer to the relevant class doc
- If the question spans multiple areas (e.g., routing + templates), stitch together the relevant docs

## Response Rules
- Be concise and specific.
- Prefer method names and class names over generalities.
- If the request is ambiguous, ask a single clarifying question.
- If you cannot find a class or method in `docs/usage/`, ask to confirm the target class.

## Quick Mapping

### Production Patterns (Start Here)
- **Module creation & lifecycle**: `PRODUCTION-USAGE-ANALYSIS.md` § 3.1-3.3
- **Shared modules (razit core)**: `PRODUCTION-USAGE-ANALYSIS.md` § 2
- **Authentication (razit-user)**: `PRODUCTION-USAGE-ANALYSIS.md` § 2.2
- **Authorization (razit-group)**: `PRODUCTION-USAGE-ANALYSIS.md` § 2.3
- **i18n (razit-multilang)**: `PRODUCTION-USAGE-ANALYSIS.md` § 2.4
- **Database patterns**: `PRODUCTION-USAGE-ANALYSIS.md` § 4.1
- **Template rendering**: `PRODUCTION-USAGE-ANALYSIS.md` § 4.2
- **Task workflows**: `PRODUCTION-USAGE-ANALYSIS.md` § 3.5
- **Common patterns**: `PRODUCTION-USAGE-ANALYSIS.md` § 4
- **Best practices**: `PRODUCTION-USAGE-ANALYSIS.md` § 6

### Package Management
- **Declaring dependencies**: `../guides/COMPOSER-INTEGRATION.md` § "Declaring Prerequisites"
- **Package management**: `Razy.PackageManager`, `Razy.ModuleInfo` (prerequisite array)
- **Version constraints**: `../guides/COMPOSER-INTEGRATION.md` § "Version Constraint Syntax"
- **Installing packages**: `../guides/COMPOSER-INTEGRATION.md` § "Installing Packages"
- **Real-world examples**: `../guides/COMPOSER-INTEGRATION.md` § "Real-World Examples"
- **Troubleshooting**: `../guides/COMPOSER-INTEGRATION.md` § "Troubleshooting"

### Performance & Deployment
- **Worker mode setup**: `../guides/CADDY-WORKER-MODE.md` § "Supported Platforms"
- **State management**: `../guides/CADDY-WORKER-MODE.md` § "Module Development for Worker Mode"
- **Performance benchmarks**: `../guides/CADDY-WORKER-MODE.md` § "Performance Benchmarks"
- **Best practices**: `../guides/CADDY-WORKER-MODE.md` § "Best Practices"
- **Quick reference**: `../quick-reference/CADDY-WORKER-QUICK-REFERENCE.md`

### Authentication & OAuth
- **Office 365 setup**: `../guides/OFFICE365-SSO.md` § "Azure AD Setup"
- **OAuth flow**: `../guides/OFFICE365-SSO.md` § "OAuth 2.0 Flow"
- **Controller implementation**: `../guides/OFFICE365-SSO.md` § "Controller Example"
- **Microsoft Graph API**: `../guides/OFFICE365-SSO.md` § "Microsoft Graph API"
- **Token management**: `../guides/OFFICE365-SSO.md` § "Token Refresh and Session Management"
- **Security**: `../guides/OFFICE365-SSO.md` § "Security Considerations"
- **Quick reference**: `../quick-reference/OFFICE365-SSO-QUICK-REFERENCE.md`
- **Classes**: `Razy.OAuth2`, `Razy.Office365SSO`

### Class-Level Internals (Deep Dive)
- **App lifecycle**: `Razy.Application`, `Razy.Domain`, `Razy.Distributor`, `Razy.Module`, `Razy.Controller`
- **Routing**: `Razy.Agent`, `Razy.Route`, `Razy.Distributor`
- **Templates**: `Razy.Template`, `Razy.Template.Source`, `Razy.Template.Block`, `Razy.Template.Entity`
- **Database**: `Razy.Database`, `Database.Statement`, `Database.WhereSyntax`, `Database.Table`, etc.
- **Flows**: `Razy.FlowManager`, `FlowManager.Flow`, `FlowManager.Transmitter`
- **Events/API**: `Razy.EventEmitter`, `Razy.API`, `Razy.Emitter`
- **Configuration**: `Razy.Configuration`, `Razy.YAML`
- **Utilities**: `Razy.XHR`, `Razy.DOM`, `Razy.Profiler`, `Razy.Terminal`

## Task
Answer the user question using this repository’s usage docs.

User question:
<<USER_QUESTION>>
