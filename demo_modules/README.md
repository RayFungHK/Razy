# Razy Demo Modules

This directory contains production-ready demo modules showcasing Razy framework features.
These modules can be copied to a site's module directory for reference and testing.

## Categories

| Category | Count | Modules |
|----------|-------|---------|
| **core/** | 7 | event_demo, event_receiver, route_demo, template_demo, thread_demo, bridge_provider |
| **data/** | 4 | collection_demo, database_demo, hashmap_demo, yaml_demo |
| **demo/** | 3 | demo_index, hello_world, markdown_consumer |
| **io/** | 8 | api_demo, api_provider, bridge_demo, dom_demo, mailer_demo, message_demo, sse_demo, xhr_demo |
| **system/** | 4 | advanced_features, helper_module, markdown_service, plugin_demo, profiler_demo |

## Development Workflow

1. **Playground Testing** - New demos are first created and tested in `playground/`
2. **Promotion** - Once tests pass, demos are moved here for permanent storage
3. **Reference** - Use these modules as templates for your own implementations

See `Razy-Building.ipynb` in the project root to set up the playground environment.

## Quick Testing with runapp

Test modules quickly using the interactive shell (no web server needed):

```bash
# Start interactive shell
php Razy.phar runapp appdemo

# In shell:
[appdemo]> routes    # List routes
[appdemo]> modules   # List modules
[appdemo]> api       # List API modules
[appdemo]> run /hello/World  # Execute a route
[appdemo]> exit      # Exit shell
```

## Modules

### 0. hello_world ⭐ Start Here

The **absolute minimum** Razy module — one route, plain text output, no events/API/template.
Perfect for newcomers who want to understand the basics before looking at advanced features.

**4 files total:**

```
demo/hello_world/
├── module.php                          # Module identity (name, version, …)
└── default/
    ├── package.php                     # Package configuration
    └── controller/
        ├── hello_world.php             # Main controller — registers 1 route
        └── hello_world.index.php       # Route handler — echoes "Hello, World!"
```

**Try it:**
```bash
php Razy.phar runapp appdemo
[appdemo]> run /hello_world/
# → Hello, World!
```

Every file is heavily commented explaining *why* each line exists.

---

### 1. advanced_features

Demonstrates:
- **`Agent::await()`** - Wait for other modules before executing
- **`addAPICommand('#...')`** - Internal method binding with `#` prefix
- **Complex `addLazyRoute()`** - Nested route structures with `@self`
- **`addShadowRoute()`** - Route proxy to other modules

**Structure:**
```
advanced_features/
├── module.php                          # Module configuration
└── default/
    ├── package.php                     # Version package
    └── controller/
        ├── advanced_features.php       # Main controller
        ├── main.php                    # / route handler
        ├── internal/
        │   ├── validate.php            # #validateInput binding
        │   ├── format.php              # #formatOutput binding
        │   └── logger.php              # #logAction binding
        ├── dashboard/
        │   └── index.php               # /dashboard (@self)
        └── api/
            ├── status.php              # getStatus API (public only)
            └── users/
                ├── list.php            # /api/users (@self)
                └── get.php             # /api/users/(:d)
```

### 2. helper_module

Companion module for `advanced_features`:
- Target for `await()` callbacks
- Target for `addShadowRoute()` proxies

**Structure:**
```
helper_module/
├── module.php
└── default/
    ├── package.php
    └── controller/
        ├── helper_module.php           # Main controller
        ├── main.php                    # / route handler
        ├── shared/
        │   └── handler.php             # Shadow route target
        └── api/
            ├── register-client.php     # API for await demos
            ├── get-clients.php
            └── is-ready.php
```

## Usage

### 1. Copy to Site Modules

```bash
# Copy to your site's module directory
cp -r workbook/advanced_features test-razy-cli/sites/mysite/
cp -r workbook/helper_module test-razy-cli/sites/mysite/
```

### 2. Register in dist.php

```php
// In test-razy-cli/sites/mysite/dist.php
return [
    'dist' => 'mysite',
    'modules' => [
        '*' => [
            'workbook/advanced_features' => '^1.0',
            'workbook/helper_module' => '^1.0',
        ],
    ],
];
```

### 3. Access Routes

After starting the server, access:
- `/advanced_features` - Main page
- `/advanced_features/dashboard` - Dashboard (@self demo)
- `/advanced_features/api/users` - Users list (@self in nested)
- `/advanced_features/api/users/123` - Get user with ID capture
- `/advanced_features/helper` - Shadow route to helper_module

### 4. Test Internal Bindings

Routes that use internal bindings (`#` prefix):
- `/advanced_features/api/users` calls:
  - `$this->logAction()` - via `#logAction` binding
  - `$this->formatOutput()` - via `#formatOutput` binding

---

## Feature Demo Modules

### Markdown Service (`system/markdown_service/`)
Shared service pattern for Composer dependencies: wraps league/commonmark, exposes version-agnostic API, solves version conflicts

**Routes:**
- `/system/markdown_service/demo` - Service demo with sample markdown

**API:**
- `$this->api('markdown')->parse($text)` - Convert markdown to HTML
- `$this->api('markdown')->parseFile($path)` - Parse markdown file
- `$this->api('markdown')->getInfo()` - Service information

### Markdown Consumer (`demo/markdown_consumer/`)
Demonstrates using shared service pattern to consume libraries without version conflicts

**Routes:**
- `/demo/markdown_consumer/render` - Interactive markdown editor
- `/demo/markdown_consumer/blog` - Simulated blog using markdown
- `/demo/markdown_consumer/readme` - Parse README.md file
- `/demo/markdown_consumer/info` - Service info display

### API Demo (`io/api_demo/`) + API Provider (`io/api_provider/`)
Cross-module API tutorial pair. The **provider** registers 5 API commands (greet, calculate, user, config, transform) via `addAPICommand()`. The **demo** calls them via `$this->api('io/api_provider')` with 7 routes showing each pattern.

**Routes:**
- `/io/api_demo/` - Overview page
- `/io/api_demo/demo/greet` - Greeting API call
- `/io/api_demo/demo/calculate` - Calculator API call
- `/io/api_demo/demo/user` - User CRUD API call
- `/io/api_demo/demo/config` - Config API call
- `/io/api_demo/demo/transform` - Transform API call
- `/io/api_demo/demo/chain` - Chained API calls

### Bridge Demo (`io/bridge_demo/`) + Bridge Provider (`core/bridge_provider/`)
Cross-distributor communication pair. The **provider** (runs in a separate distributor) registers bridge commands via `addBridgeCommand()` with `__onBridgeCall()` authorization. The **demo** shows how to call APIs across distributors via HTTP/CLI bridge.

**Routes:**
- `/io/bridge_demo/` - Overview page
- `/io/bridge_demo/demo/data` - Cross-distributor data request
- `/io/bridge_demo/demo/calculate` - Cross-distributor calculation
- `/io/bridge_demo/demo/config` - Cross-distributor config
- `/io/bridge_demo/demo/cli` - CLI bridge call example

> **Note:** The bridge feature requires multi-site setup (provider in a separate distributor). See the Bridge section in the documentation for configuration details.

### Database Demo (`database_demo/`)
Database operations: SELECT, INSERT, UPDATE, DELETE, Joins, Transactions

### DOM Demo (`dom_demo/`)
DOM builder: elements, attributes, forms, nested structures

### HashMap Demo (`hashmap_demo/`)  
HashMap operations: basic usage, objects, iteration

### Collection Demo (`collection_demo/`)
Collection: filtering, Processor chaining

### Plugin Demo (`plugin_demo/`)
Plugin system: Template, Collection plugins

### Mailer Demo (`mailer_demo/`)
Email: SMTP, HTML emails, attachments

### Profiler Demo (`profiler_demo/`)
Performance profiling: checkpoints, memory tracking

### Message Demo (`message_demo/`)
SimplifiedMessage: STOMP-like protocol messaging

### SSE Demo (`sse_demo/`)
Server-Sent Events: streaming patterns

### XHR Demo (`xhr_demo/`)
XHR responses: JSON API, CORS headers

### YAML Demo (`yaml_demo/`)
YAML parsing and dumping

### Event Demo (`event_demo/`)
Event system: firing events with `$this->trigger()`

### Event Receiver (`event_receiver/`)
Event system: listening to events with `$agent->listen()`

### Route Demo (`route_demo/`)
URL routing: `addRoute()` with parameter capture patterns (`:d`, `:a`, `:w`, `{n}`, `{min,max}`, `:[regex]`)

### Thread Demo (`thread_demo/`)
ThreadManager: inline mode, process mode, parallel task execution, `spawnPHPCode()`

---

## Related Documentation

- [ADVANCED-FEATURES-EXAMPLES.md](../docs/usage/ADVANCED-FEATURES-EXAMPLES.md) - Full documentation
- [Razy.Agent.md](../docs/usage/Razy.Agent.md) - Agent class reference
- [Razy.Module.md](../docs/usage/Razy.Module.md) - Module class reference
