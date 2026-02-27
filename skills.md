# Razy Framework Skills

**Type**: Coder Assistant Skills (LLM Navigation Hub) | **Framework**: Razy v1.0.1-beta | **Updated**: February 27, 2026

---

## What is Skills?

Meta-documentation system helping LLM agents and developers navigate the Razy framework. Use this to find the right document and understand the architecture. It exports comprehensive `skills.md` so LLMs don't need to walk through the entire project to summarize.

---

## Quick Navigation — I'm Trying To...

| Task | Document |
|------|----------|
| **Browse rendered docs** | `documentation/` (HTML — 61 pages) or `Razy.wiki/` (GitHub Wiki — 63 pages) |
| **View work history/log** | `LLM_LOGBOOK.md` → all work tracked by session (MANDATORY) |
| Build a module with APIs | `Razy.wiki/Cross-Module-API.md` + `Razy.wiki/Module-System.md` |
| Create templates/views | `Razy.wiki/Template-Engine.md` + `demo_modules/core/template_demo/` |
| Write a plugin | `Razy.wiki/Plugin-System.md` |
| Implement event system | `Razy.wiki/Event-System.md` + `demo_modules/core/event_demo/` |
| Add URL parameter routes | `Razy.wiki/Routing.md` + `demo_modules/core/route_demo/` |
| Use async threads/tasks | `Razy.wiki/Thread-ThreadManager.md` + `demo_modules/core/thread_demo/` |
| Use template engine | `Razy.wiki/Template-Engine.md` + `demo_modules/core/template_demo/` |
| Debug database queries | `Razy.wiki/Database.md` + `Razy.wiki/Simple-Syntax.md` |
| Look up a class API | `Razy.wiki/{ClassName}.md` (e.g., `Database.md`, `Template-Engine.md`, `Collection.md`) |
| Find demo module examples | `demo_modules/` (permanent storage) + `playground/` (testing) |
| Write unit tests | `Razy.wiki/Testing.md` |
| Install GitHub modules | `Razy.wiki/Repository-Publishing.md` |
| Run distributor interactively | `php Razy.phar runapp <dist>` (no config needed) |
| Set up Distributor/Site | `Razy.wiki/Sites-Configuration.md` |
| View what's new | `changelog/CHANGELOG.md` |
| Generate LLM context docs | `php Razy.phar generate-skills` (auto-generates module/distribution docs) |
| Use ORM / Active Record | `Razy.wiki/ORM.md` |
| Use HTTP client | `Razy.wiki/HttpClient.md` |
| Set up caching | `Razy.wiki/Cache.md` |
| Configure middleware | `Razy.wiki/Middleware.md` |

---

## Documentation Structure

```
Root Files:
├── skills.md              # Framework architecture & rules (this file)
├── LLM_LOGBOOK.md        # Work history log (MANDATORY - update after each session)
├── readme.md              # Public-facing project README

documentation/             # Rendered HTML docs site (61 pages + CSS + JS)
├── index.html            # Landing page
├── pages/                # All doc pages (getting-started, architecture, modules, etc.)
├── css/style.css         # Stylesheet
└── js/docs.js            # Theme toggle, copy buttons, ToC scroll spy

Razy.wiki/                 # GitHub Wiki (63 markdown files, pushed to GitHub)
├── Home.md, _Sidebar.md  # Wiki index + navigation
├── Installation.md, Architecture.md, ...
└── (mirrors documentation/ topics as Markdown)

changelog/                 # Release history
├── CHANGELOG.md          # Master changelog
└── v*.md                 # Per-version release notes

demo_modules/              # Reference demo modules (by category)
├── core/                 # Events, routes, templates, threads, bridge
├── data/                 # Database, collection, hashmap, yaml
├── demo/                 # demo_index, hello_world, markdown_consumer
├── io/                   # API, bridge, dom, mailer, message, sse, xhr
└── system/               # Advanced features, markdown_service, plugin, profiler

src/asset/prompt/          # Skills auto-generation templates
├── skills.md.tpl          # Application-level context template
├── skills-module.md.tpl        # Per-module context template
└── skills-distribution.md.tpl  # Per-distribution context template
```

### Skills Auto-Generation (`generate-skills`)

The `generate-skills` CLI command auto-generates contextual documentation for LLM agents working on Razy-based projects:

```bash
php Razy.phar generate-skills            # Generate all (framework + distributions + modules)
php Razy.phar generate-skills --root-only # Generate only root skills.md
```

**Generated files:**
| File | Purpose |
|------|---------|
| `skills.md` | Framework overview for the project |
| `skills/{dist_code}.md` | Distribution context (domains, modules, config) |
| `skills/{dist_code}/{module}.md` | Module context (APIs, events, files, `@llm` prompts) |

**Templates** in `src/asset/prompt/` use Razy Template Engine syntax (`{$app_name}`, `{$version}`, block tags) to generate structured documentation. Module templates support `@llm prompt:` comments in PHP/TPL files for LLM-readable code annotations.

---

## Framework Architecture (Compact)

**Directory Structure**:
```
sites/{distributor}/        # Distribution (site/app)
├── dist.php                # Configuration (enabled modules, domains, data_mapping)
├── modules/                # Installed modules
│   └── {vendor}/{module}/
│       ├── module.php      # Metadata
│       └── default/        # Version folder
│           ├── package.php # Config & dependencies
│           ├── controller/ # API & route handlers
│           ├── view/       # Templates
│           ├── model/      # ORM Model classes
│           ├── migration/  # Database migrations
│           ├── plugin/     # Custom plugins
│           └── src/        # Source code
└── data/                   # Storage

shared/module/              # Cross-distribution modules
data/                       # Global storage
```

**Key Concepts**:
- **Distribution** - Standalone app with own config, modules, domains
- **Standalone Mode** - Ultra-flat single-module mode (DEFAULT); no dist.php, no domain restrictions. Activates when `standalone/` exists and `multiple_site` config is not enabled. See `Razy.wiki/Standalone-Mode.md`.
- **Module** - Reusable package with controller, views, plugins, APIs
- **Module Versioning** - Multiple versions coexist; distribution picks version
- **Cross-Module APIs** - Provider/Consumer pattern for inter-module communication
- **Plugin System** - Extend Template, Collection, Pipeline, Statement
- **Template Engine** - Separates content logic with blocks and modifiers

---

## Core Lifecycle

### Normal (Multisite) Mode
```
Bootstrap (main.php + bootstrap.inc.php)
  ↓
Domain Resolution (Application.host → matchDomain → Domain)
  ↓
Distributor Selection (Domain.matchQuery → Distributor)
  ↓
Module Loading (dependency-ordered)
  - __onInit()   (register routes, APIs, events)
  - __onLoad()   (module-to-module preloading)
  - __onRequire() (validation)
  - __onReady()  (post-async setup)
  ↓
Routing (WEB mode) / Script Execution (CLI)
  ↓
Handler/Command Execution
  ↓
Shutdown
```

### Standalone Mode
```
Bootstrap (main.php + bootstrap.inc.php)
  ↓
Standalone Detection (standalone/ exists, multisite not enabled)
  ↓
Application.standalone($path) → Standalone (no Domain, no dist.php)
  ↓
Single Module Loading (synthesized config, ultra-flat layout)
  - controller/app.php loaded directly
  - __onInit() registers routes
  ↓
Routing (Standalone.matchRoute)
  ↓
Handler Execution
  ↓
Shutdown
```

---

## Testing & Quality

| Need | Document |
|------|----------|
| Test framework basics | `Razy.wiki/Testing.md` |
| PSR-12 standards | `documentation/pages/architecture.html` |
| Database query syntax | `Razy.wiki/Database.md` + `Razy.wiki/Simple-Syntax.md` |

---

## Framework Development Rules

Applies to Razy framework development (not projects that use Razy).

### Mandatory Documentation Updates

After any framework modification, new feature, or demo module:

1. **Changelog** - Update `changelog/CHANGELOG.md` for any framework change.
2. **Wiki** - Update relevant `Razy.wiki/*.md` pages when features or behavior change.
3. **HTML Docs** - Update corresponding `documentation/pages/*.html` to keep in sync with wiki.
4. **Playground-First Development** - Always develop and test demo modules in `playground/` first. After successful testing, copy to `demo_modules/{category}/` for permanent storage (categories: `core/`, `data/`, `demo/`, `io/`, `system/`).
5. **demo_modules/README.md** - Update when demo modules are added, removed, or structure changes.
6. **skills.md** - Update Quick Navigation, Demo Modules, and Important Commands tables when relevant features change.
7. **LLM_LOGBOOK.md** - **MANDATORY**: Record all work by time in the logbook. Document features implemented, bugs fixed, modules created, and documentation updates. This is a MUST after every work session.

### Demo Module Development Workflow

```
1. Build Razy.phar using RazyProject-Building.ipynb
2. Initialize playground/ as Razy environment
3. Create module in playground/modules/workbook/{module_name}/
4. Test thoroughly in playground/
5. When tests pass, copy to demo_modules/{category}/{module_name}/ for permanent storage
   Categories: core/, data/, demo/, io/, system/
```

---

## Class & API Reference

**Available in** `Razy.wiki/` — each class has its own wiki page:
- Agent.md, API-Reference.md, Architecture.md, Collection.md
- Database.md, Template-Engine.md, Pipeline.md, ORM.md
- Routing.md, Crypt.md, Mailer.md, HttpClient.md
- + 50 more wiki pages (see Published Documentation table above)

**HTML equivalent**: `documentation/pages/` — same content rendered as HTML.

### Database Statement API (Critical — Common Source of Bugs)

**Statement Creation**: Always use `$db->prepare()` to get a Statement, then chain methods.

| Method | Signature | Notes |
|--------|-----------|-------|
| `select()` | `select(string $columns)` | Single comma-separated string, NOT variadic |
| `from()` | `from(string $syntax)` | Table Join Syntax: `'u.users<p.posts[user_id]'` |
| `where()` | `where(string $syntax)` | Where Simple Syntax: `'id=1,active=1'` |
| `order()` | `order(string $syntax)` | `'>col'` = DESC, `'<col'` = ASC. **NOT `orderBy()`** |
| `group()` | `group(string $syntax)` | **NOT `groupBy()`** |
| `limit()` | `limit(int $position, int $fetchLength = 0)` | Position is offset or max rows |
| `insert()` | `insert(string $table, array $columns, array $dupKeys = [])` | `$columns` = column name strings |
| `update()` | `update(string $table, array $updateSyntax)` | `$updateSyntax` = string array: `['col=val', 'count++']` |
| `delete()` | `delete(string $table, array $params = [], string $where = '')` | Auto-generates WHERE from params |
| `assign()` | `assign(array $parameters)` | Bind key-value pairs to named parameters |
| `query()` | `query(array $params = [])` | Execute and return Query. **NOT `fetch()`** |
| `lazy()` | `lazy(array $params = [])` | Execute and fetch first row. **NOT `lazyFetch()`** |
| `getSyntax()` | `getSyntax()` | Get generated SQL string (no DB execution needed) |

**Common Mistakes**:

| Wrong | Correct | Why |
|-------|---------|-----|
| `$stmt->fetch()` | `$stmt->query()` or `$stmt->lazy()` | `fetch()` does not exist |
| `$stmt->orderBy('col')` | `$stmt->order('>col')` | Method is `order()`, not `orderBy()` |
| `$stmt->groupBy('col')` | `$stmt->group('col')` | Method is `group()`, not `groupBy()` |
| `$stmt->select('a', 'b')` | `$stmt->select('a, b')` | Single comma-separated string, not variadic |
| `$db->insert('t')` | `$db->prepare()->insert('t', ['col1', 'col2'])` | `Database::insert()` requires columns array |
| `$db->update('t')` | `$db->prepare()->update('t', ['col=val'])` | `Database::update()` requires update syntax |
| `$stmt->set(['k'=>'v'])` | `$stmt->update('t', ['k=v'])` | No `set()` method exists |

**Cross-Distributor API** (`Distributor::executeInternalAPI()`):
- Uses CLI process isolation via `php Razy.phar bridge <payload>`
- Returns `null` if `proc_open` is unavailable (no exception)
- Bridge command defined in `src/system/terminal/bridge.inc.php`

### ORM (Active Record Pattern)

**Core Classes**: `Model`, `ModelQuery`, `ModelCollection` in `src/library/Razy/ORM/`

**Model**: Abstract base class — subclass with `$table`, `$fillable`, `$casts`, optional `$primaryKey`.

| Static Method | Description |
|---------------|-------------|
| `query($db)` | Create a `ModelQuery` builder (boots model if needed) |
| `find($db, $id)` | Find by primary key (returns `Model\|null`) |
| `findOrFail($db, $id)` | Find or throw `ModelNotFoundException` |
| `all($db)` | Get all records |
| `create($db, $attrs)` | Insert new record, return hydrated model |
| `destroy($db, $id)` | Delete by primary key |
| `firstOrCreate($db, $search, $extra)` | Find by attrs or create (merged with extra) |
| `firstOrNew($db, $search, $extra)` | Find by attrs or instantiate unsaved |
| `updateOrCreate($db, $search, $update)` | Find and update, or create |
| `addGlobalScope($name, $closure)` | Register a global query scope |
| `removeGlobalScope($name)` | Remove a registered global scope |
| `getGlobalScopes()` | Get all global scopes for this class |
| `clearBootedModels()` | Reset all boot state (for tests) |

**Model Instance Methods**:

| Method | Description |
|--------|-------------|
| `save()` | INSERT or UPDATE (fires model events) |
| `delete()` | DELETE row (fires deleting/deleted events) |
| `refresh()` | Re-fetch from DB, discard in-memory changes |
| `increment($col, $amt, $extra)` | Atomic `col = col + amt`; optional extra column updates |
| `decrement($col, $amt, $extra)` | Atomic `col = col - amt` |
| `replicate($except)` | Clone without PK/timestamps; unsaved |
| `touch()` | Update `updated_at` only (requires `$timestamps = true`) |
| `fill($attrs)` | Mass-assign respecting fillable/guarded |
| `isDirty($attr)` / `getDirty()` | Dirty tracking |
| `toArray()` / `toJson()` | Serialization (with accessors + hidden/visible) |

**ModelQuery** (Fluent Builder):

| Method | Description |
|--------|-------------|
| `where($syntax, $params)` | WHERE via Simple Syntax (`col=:param`, `,`=AND, `\|`=OR) |
| `orWhere($syntax, $params)` | WHERE joined with OR instead of AND |
| `whereIn($col, $vals)` | WHERE col IN (...) |
| `whereNotIn($col, $vals)` | WHERE col NOT IN (...) |
| `whereBetween($col, $min, $max)` | WHERE col BETWEEN min AND max |
| `whereNotBetween($col, $min, $max)` | WHERE col NOT BETWEEN min AND max |
| `whereNull($col)` | WHERE col IS NULL |
| `whereNotNull($col)` | WHERE col IS NOT NULL |
| `orderBy($col, $dir)` | ORDER BY column |
| `limit($n)` / `offset($n)` | Pagination |
| `select($columns)` | Column selection (comma-separated) |
| `get()` | Execute → `ModelCollection` |
| `first()` | Execute → first `Model\|null` |
| `find($id)` | WHERE pk = id → first `Model\|null` |
| `count()` | COUNT(*) → int |
| `paginate($page, $perPage)` | Returns `Paginator` with typed getters, URL generation, backward-compatible ArrayAccess |
| `simplePaginate($page, $perPage)` | Efficient pagination without COUNT query (uses perPage+1 fetch) |
| `bulkUpdate($attrs)` | Mass UPDATE → affected rows |
| `bulkDelete()` | Mass DELETE → affected rows |
| `create($attributes)` | Insert row → hydrated `Model` |
| `with(...$relations)` | Eager load relations (solves N+1) |
| `withoutGlobalScope(...$names)` | Exclude specific global scopes |
| `withoutGlobalScopes()` | Exclude ALL global scopes |
| `chunk($size, $callback)` | Process results in fixed-size batches (LIMIT/OFFSET); callback `fn(ModelCollection, int $page)`; return `false` to stop |
| `cursor()` | Returns `Generator` yielding one `Model` at a time (memory-efficient) |
| `__call($method, $args)` | Proxies to `scope{Method}()` on model (local scopes) |

**Scopes**:
- **Local Scopes**: Define `scopeXyz(ModelQuery $query, ...$params)` on model → call as `->xyz()` on query
- **Global Scopes**: Register in `boot()` via `addGlobalScope('name', fn)` → auto-applied to all queries
- **Boot mechanism**: `protected static function boot()` runs once per class on first `query()` call

**Soft Deletes** (`SoftDeletes` trait):
- `use SoftDeletes` on a model → `delete()` sets `deleted_at`, global scope filters them out
- `forceDelete()` for permanent removal, `restore()` to un-delete
- `trashed()` checks if soft-deleted, `withTrashed()` / `onlyTrashed()` query scopes
- Custom column: override `getDeletedAtColumn()` (default: `deleted_at`)
- Boot mechanism: traits with `boot{TraitName}()` methods are auto-called

**Accessors/Mutators**:
- **Accessors**: Define `get{StudlyName}Attribute($rawValue)` → transforms value on `__get` and `toArray()`
- **Mutators**: Define `set{StudlyName}Attribute($value)` → transforms value on `__set` and `fill()`
- Accessors take precedence over casts; mutators take precedence over casts
- Supports computed/virtual attributes (accessor on key not in DB)
- `getRawAttribute($name)` bypasses accessor, returns underlying value

**Hidden/Visible Serialization**:
- `protected static array $hidden = [...]` — attributes excluded from `toArray()`/`toJson()`
- `protected static array $visible = [...]` — whitelist; only these appear (takes precedence over `$hidden`)
- Direct property access (`__get`) still works for hidden attributes

**toJson**:
- `$model->toJson(int $options = 0)` — JSON-encode via `toArray()` (respects accessors + hidden/visible)
- `$collection->toJson(int $options = 0)` — JSON-encode the collection array

**ModelCollection** (rich array wrapper, implements `ArrayAccess`, `Countable`, `IteratorAggregate`):

| Method | Description |
|--------|-------------|
| `first()` / `last()` | First/last model or null |
| `isEmpty()` / `isNotEmpty()` | Empty checks |
| `all()` / `toArray()` | Get underlying array |
| `pluck($attr)` | Extract attribute values as plain array |
| `map($fn)` / `filter($fn)` / `each($fn)` | Iterate/transform |
| `contains($attr, $val)` | Check if any model matches |
| `reduce($fn, $initial)` | Reduce to single value |
| `sum($attr\|$fn)` | Sum attribute (string or callback) |
| `avg($attr\|$fn)` | Average (null if empty) |
| `min($attr\|$fn)` / `max($attr\|$fn)` | Min/max (null if empty) |
| `sortBy($attr\|$fn, $dir)` | Returns new sorted collection (asc/desc) |
| `unique($attr\|$fn)` | Deduplicate by attribute |
| `groupBy($attr\|$fn)` | Returns `array<key, ModelCollection>` |
| `keyBy($attr\|$fn)` | Returns `array<key, Model>` |
| `flatMap($fn)` | Map + flatten one level |
| `firstWhere($attr, $val)` | Find first model where attribute == value |
| `chunk($size)` | Split into array of sub-collections |

**Model Events** (lifecycle hooks):
- Register listeners: `Model::creating(fn)`, `Model::created(fn)`, `Model::updating(fn)`, `Model::updated(fn)`, `Model::saving(fn)`, `Model::saved(fn)`, `Model::deleting(fn)`, `Model::deleted(fn)`, `Model::restoring(fn)`, `Model::restored(fn)`
- "Before" events (`creating`, `updating`, `saving`, `deleting`, `restoring`) — return `false` to cancel the operation
- Event order on INSERT: `saving` → `creating` → INSERT → `created` → `saved`
- Event order on UPDATE: `saving` → `updating` → UPDATE → `updated` → `saved`
- SoftDeletes: `delete()` fires `deleting`/`deleted`; `forceDelete()` fires `forceDeleting`/`forceDeleted`; `restore()` fires `restoring`/`restored`
- `clearBootedModels()` also clears all registered events (for test isolation)
- Listeners receive the model instance as their single argument

**Relations** (`src/library/Razy/ORM/Relation/`): `HasOne`, `HasMany`, `BelongsTo`, `BelongsToMany`

**Paginator** (`src/library/Razy/ORM/Paginator.php`):
- Returned by `ModelQuery::paginate()` and `ModelQuery::simplePaginate()`
- Implements `ArrayAccess` for backward compatibility (`$page['data']`, `$page['total']`, `$page['page']`, `$page['per_page']`, `$page['last_page']`) + `Countable`, `IteratorAggregate`, `JsonSerializable`
- **Typed getters**: `items()`, `total()`, `currentPage()`, `perPage()`, `lastPage()`
- **Convenience booleans**: `hasMorePages()`, `onFirstPage()`, `onLastPage()`, `hasPages()`, `isEmpty()`, `isNotEmpty()`
- **URL generation**: `url(int $page)`, `firstPageUrl()`, `lastPageUrl()`, `previousPageUrl()`, `nextPageUrl()` — configured via `setPath(string)`, `setPageName(string)`, `appends(array)`
- **Page range**: `getPageRange(int $onEachSide)` → `list<int>|null`, `links(int $onEachSide)` → `{first, last, prev, next, pages[{page, url, active}]}`
- **Serialization**: `toArray()` (includes `data`, `total`, `page`, `per_page`, `last_page`, `from`, `to`, `links`), `toJson(int $options)`, `jsonSerialize()`
- **Simple pagination** (`simplePaginate()`): `total()` = null, `lastPage()` = null, `getPageRange()` = null; uses `hasMore` flag from fetching `perPage + 1` rows

**RouteGroup** (`src/library/Razy/Routing/RouteGroup.php`):
- Groups routes under shared prefix, middleware, name prefix, and method constraint
- Static factory `RouteGroup::create(string $prefix)`, fluent config: `middleware()`, `namePrefix()`, `method()`, `routes(Closure)`
- Route registration: `addRoute(string $path, string|Route $handler)`, `addLazyRoute()`, nested `group()`
- Resolution: `resolve()` flattens nested groups into flat `list<{path, handler, routeType}>` — "flatten at registration" design
- `decorateHandler()` wraps string handlers in Route objects when group has middleware/method/name; clones Route objects and prepends group middleware
- Agent integration: `$agent->group('/prefix', fn(RouteGroup $g) => ...)` — creates group, calls callback, registers resolved routes

**HttpClient** (`src/library/Razy/Http/`):
- `HttpClient` — Fluent HTTP client wrapping cURL. Static factory `create()`. Config: `baseUrl()`, `withHeaders()`, `withToken()`, `withBasicAuth()`, `timeout()`, `connectTimeout()`, `withoutVerifying()`, `asJson()`, `asForm()`, `asMultipart()`, `withQuery()`, `retry()`. HTTP verbs: `get()`, `post()`, `put()`, `patch()`, `delete()`, `head()`, `options()`. Interceptors: `beforeSending()`, `afterResponse()`.
- `HttpResponse` — Response wrapper. Status: `status()`, `successful()`, `ok()`, `failed()`, `redirect()`, `clientError()`, `serverError()`. Body: `body()`, `json()`, `jsonGet(string $dotKey)`. Headers: `headers()`, `header()`, `hasHeader()`, `contentType()`. Error: `throw()`, `throwIf()`. Conversion: `toArray()`, `__toString()`.
- `HttpException` — `RuntimeException` with `getResponse()` accessor, auto-generates message from status code

---

## Demo Modules

**Location**: `demo_modules/` directory, organized by category subdirectories.

**Available Demo Modules**:

| Category | Module | Description |
|----------|--------|-------------|
| `core/` | `bridge_provider` | Cross-distributor bridge: provider-side API for bridge communication |
| `core/` | `event_demo` | Event system: firing events with trigger() |
| `core/` | `event_receiver` | Event system: listening to events with listen() |
| `core/` | `route_demo` | URL routing: addRoute() with parameter capture patterns |
| `core/` | `template_demo` | Template engine: variables, modifiers, function tags, blocks, Entity API, RECURSION |
| `core/` | `thread_demo` | ThreadManager: inline, process, parallel task execution |
| `data/` | `collection_demo` | Collection: filtering, Processor chaining |
| `data/` | `database_demo` | Database operations: SELECT, INSERT, UPDATE, DELETE, Joins, Transactions |
| `data/` | `hashmap_demo` | HashMap operations: basic usage, objects, iteration |
| `data/` | `yaml_demo` | YAML parsing and dumping |
| `demo/` | `demo_index` | Central index page + shared header/footer/styles API for all demos |
| `demo/` | `hello_world` | Basic hello world module for quick onboarding |
| `demo/` | `markdown_consumer` | Consumes markdown_service via API (version-conflict-free) |
| `io/` | `api_demo` | API command demonstration: call/response patterns |
| `io/` | `api_provider` | API provider: exposes callable commands for cross-module use |
| `io/` | `bridge_demo` | Cross-distributor bridge: consumer-side bridge communication demo |
| `io/` | `dom_demo` | DOM builder: elements, attributes, forms, nested structures |
| `io/` | `mailer_demo` | Email: SMTP, HTML emails, attachments |
| `io/` | `message_demo` | SimplifiedMessage: STOMP-like protocol messaging |
| `io/` | `sse_demo` | Server-Sent Events: streaming patterns |
| `io/` | `xhr_demo` | XHR responses: JSON API, CORS headers |
| `system/` | `advanced_features` | Agent await/await method demos, shadow routes, internal API bindings |
| `system/` | `helper_module` | Helper module for advanced features demo (await targets, shadow routes) |
| `system/` | `markdown_service` | Shared service pattern: wraps league/commonmark, solves version conflicts |
| `system/` | `plugin_demo` | Plugin system: Template, Collection plugins |
| `system/` | `profiler_demo` | Performance profiling: checkpoints, memory tracking |

**Playground Demo Modules** (`playground/sites/appdemo/`):
| Module | Description |
|--------|-------------|
| `demo/hello` | Basic routes: index, hello, greet with parameter, info, time, JSON response |
| `demo/api` | API module: getData, calculate, echo commands for `runapp call` testing |

**Playground**: `playground/` folder — Razy development environment for testing demo modules. Use `Razy-Building.ipynb` to set it up.

**Development Workflow**:
1. Run `Razy-Building.ipynb` to build Razy.phar and initialize playground/
2. Run `php Razy.phar rewrite <distributor_code>` to generate .htaccess rewrite rules
3. Create and test demos in playground/
4. When tests pass, copy to `demo_modules/{category}/{module_name}/`

**Important Commands**:
| Command | Purpose |
|---------|---------|
| `php Razy.phar init dist <name>` | Initialize a new distributor |
| `php Razy.phar rewrite <dist>` | Generate .htaccess rewrite rules (required for Apache/Nginx) |
| `php Razy.phar validate <dist>` | Validate all modules in a distributor |
| `php Razy.phar runapp <dist[@tag]>` | Interactive shell for testing distributor (no sites.inc.php needed) |
| `php Razy.phar generate-skills` | Generate skills.md files for framework and modules |
| `php Razy.phar install owner/repo` | Install module from GitHub repository |

> **Note**: Always run `php Razy.phar rewrite <distributor_code>` after creating a new distributor, adding/removing sites, domains, or aliases.

**runapp Shell Commands**:
| Command | Description |
|---------|-------------|
| `help` | Show available commands |
| `info` | Show distributor info |
| `routes` | List all registered routes |
| `modules` | List loaded modules |
| `api` | List API modules |
| `run <path>` | Execute a route (e.g., `run /hello/World`) |
| `call <api> <cmd>` | Call API command |
| `exit` | Exit the shell |

**Test Site**: `test-razy-cli/` - Full site for integration testing (not for examples).

**Event System Quick Snippet**:
```php
// Listening (register in __onInit)
$agent->listen('notify:user_registered', function($userData) {
    echo "User: " . $userData['name'];
    return "Event handled";
});

// Firing (call from controller method)
$emitter = $this->trigger('user_registered');
$emitter->resolve($userData);
$responses = $emitter->getAllResponse();
```

**API Reference**: `Razy.wiki/Event-System.md`

---

## For Framework Contributors

1. **Code Standards** — PSR-4, PSR-12, PHP 8.2+ type declarations
2. **Changes** — Update all usages + documentation + tests
3. **Testing** — PHPUnit 10.5, 4,564 tests / 8,178 assertions / 0 skipped, strict mode (failOnRisky, failOnWarning, requireCoverageMetadata)
4. **Plugins** — Extend Template, Collection, Statement, Pipeline
5. **Documentation** — Update skills.md if architecture changes
6. **PHPDoc** — All 262 library source files have comprehensive PHPDoc + inline comments
7. **Docs sync** — Keep `documentation/` (HTML) and `Razy.wiki/` (GitHub Wiki) in sync

### Published Documentation

**HTML Docs Site** (`documentation/`): 61 pages covering all framework topics. Self-contained with CSS/JS — serves as the primary web-browsable documentation.

**GitHub Wiki** (`Razy.wiki/`): 63 Markdown files mirroring the HTML docs site. Pushed to `https://github.com/RayFungHK/Razy/wiki`.

**Wiki Pages** (by topic):

| Category | Pages |
|----------|-------|
| **Getting Started** | Home, Installation, Quick-Start, Getting-Started-Tutorial, Architecture, Roadmap |
| **Module System** | Module-System, Module-Structure, Controller, Agent, ModuleInfo, Packaging-Distribution |
| **Routing & Events** | Routing, Event-System, Cross-Module-API, Middleware |
| **Data & Storage** | Database, Simple-Syntax, ORM, Collection, HashMap, YAML, Cache |
| **Template & UI** | Template-Engine, DOM-Builder, Simple-Syntax |
| **I/O & Network** | HttpClient, XHR, SSE, WebSocket, Mailer, SimplifiedMessage, FTP-SFTP |
| **Security & Auth** | Authenticator, AuthManager, OAuth2, Office365SSO, CSRF, Crypt, Session |
| **Infrastructure** | Pipeline, Plugin-System, Container, Env, Logging, Profiler, Queue, Notification, RateLimiter, Validation, Error-Handling |
| **Server & Ops** | Sites-Configuration, Caddy-Worker-Mode, Worker-Lifecycle, Standalone-Mode, Thread-ThreadManager, Repository-Publishing |
| **Reference** | CLI-Commands, API-Reference, Utility-Functions, Testing, FileReader |

---

## Using Skills in Your Project

**Auto-generation**: Run `php Razy.phar generate-skills` to automatically generate skills files for your project, distributions, and modules using the templates in `src/asset/prompt/`.

**Manual**: Copy this as a template for your framework/project:

1. Replace **What is Skills?** with your project purpose
2. Update **Quick Navigation** table with your docs paths
3. Replace **Framework Architecture** sections with your structure
4. Update **Example** section with your real code
5. Maintain as you develop (update when adding features)

**Benefits**: Faster LLM onboarding, consistent documentation, clear navigation hub.

---

## Common Functions (bootstrap.inc.php)

Razy provides many utility functions globally available in `src/system/bootstrap.inc.php`. Use these instead of reimplementing.

### Path Functions
| Function | Description | Example |
|----------|-------------|---------|
| `tidy($path, $ending, $separator)` | Clean path, remove duplicate slashes | `tidy('/a//b\\c')` → `/a/b/c` |
| `append($path, ...$extra)` | Join path segments | `append('/var', 'www', 'html')` → `/var/www/html` |
| `fix_path($path)` | Fix relative path (handles `..` and `.`) | `fix_path('a/b/../c')` → `a/c` |
| `getRelativePath($path, $root)` | Get relative path from root | `getRelativePath('/a/b/c', '/a')` → `/b/c` |
| `is_dir_path($path)` | Check if path ends with separator | `is_dir_path('folder/')` → `true` |

### File Operations
| Function | Description |
|----------|-------------|
| `xcopy($source, $dest, $pattern)` | Copy directory/file recursively |
| `xremove($path)` | Remove directory/file recursively |
| `getFilesizeString($size, $dec)` | Format size with unit: `1048576` → `1.00mb` |

### Network/IP
| Function | Description |
|----------|-------------|
| `is_ssl()` | Check if HTTPS is used |
| `getIP()` | Get visitor IP address |
| `ipInRange($ip, $cidr)` | Check if IP is in CIDR range |
| `is_fqdn($domain)` | Validate fully qualified domain name |

### Data/Array
| Function | Description |
|----------|-------------|
| `collect($data)` | Convert to Collection object |
| `construct($structure, ...$sources)` | Merge arrays by structure |
| `refactor($source, ...$keys)` | Refactor array data by keys |
| `comparison($a, $b, $op)` | Compare values with operators (`=`, `!=`, `>`, `>=`, `<`, `<=`, `|=`, `^=`, `$=`, `*=`) |
| `guid($length)` | Generate GUID |
| `is_json($string)` | Validate JSON string |

### Date/Time
| Function | Description |
|----------|-------------|
| `getFutureWeekday($start, $days)` | Get future weekday excluding weekends/holidays |
| `getWeekdayDiff($start, $end)` | Get weekday difference |
| `getDayDiff($start, $end)` | Get day difference |

### Version
| Function | Description |
|----------|-------------|
| `vc($requirement, $version)` | Version compare (supports `^`, `~`, `>=`, ranges) |
| `versionStandardize($version)` | Standardize version to `x.x.x.x` format |

---

**For**: LLM agents and developers learning Razy  
**Adaptable Template**: Use as skills documentation for any PHP framework

---

## Module Development Pipeline

### Step 1: Create Module Structure

**Correct Path Pattern**:
```
sites/{dist}/{vendor}/{module_code}/
├── module.php                    # REQUIRED: Module metadata
└── {version}/                    # e.g., "default", "1.0.0"
    ├── package.php               # REQUIRED: Version entry point
    ├── controller/
    │   ├── {module_code}.php     # REQUIRED: Main controller
    │   ├── {module_code}.{route}.php  # Route handlers
    │   └── {subfolder}/          # Nested route handlers
    ├── model/                    # ORM Model files (anonymous classes extending Model)
    │   └── {ModelName}.php       # Loaded via $this->loadModel('ModelName')
    ├── migration/                # Database migration files
    │   └── YYYY_MM_DD_HHMMSS_Description.php  # Loaded via $this->getMigrationManager($db)
    └── webassets/                # Static files (CSS, JS, images)
                                  # Served via: {siteURL}/webassets/{alias}/{version}/{file}
                                  # Use Controller::getAssetPath() for the URL base
```

**Common Mistake**: Do NOT use `sites/{dist}/modules/...` - the `modules/` folder is incorrect.

**module.php Template**:
```php
<?php
return [
    'module_code' => 'vendor/module_name',
    'name'        => 'Module Display Name',
    'author'      => 'Author Name',
    'description' => 'Module description',
    'version'     => '1.0.0',
];
```

### Step 2: Controller Implementation

**Main Controller Pattern** (`controller/{module_code}.php`):
```php
<?php
namespace Razy\Module\{module_code};

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register routes
        $agent->addLazyRoute([
            '/'      => 'main',      // → {module_code}.main.php
            'action' => 'action',    // → {module_code}.action.php
        ]);
        
        // Register event listeners (format: 'vendor/module:event')
        $agent->listen('vendor/other_module:event_name', function($data) {
            return ['handled' => true];
        });
        
        return true;
    }
    
    // Methods callable from route handlers via $this->methodName()
    public function fireEvent(array $data): array
    {
        $emitter = $this->trigger('event_name');
        $emitter->resolve($data);
        return $emitter->getAllResponse();
    }
};
```

**Key API Notes**:
| Want To | Use | NOT |
|---------|-----|-----|
| Fire event | `$this->trigger('event')` | `$this->getModule()->createEmitter()` |
| Listen to event | `$agent->listen()` in `__onInit()` | `$module->listen()` |
| Get module info | `$this->getModuleInfo()` | `$this->getModule()` |
| Load ORM Model | `$this->loadModel('User')` | Manual `require` |
| Run migrations | `$this->getMigrationManager($db)` | Manual `MigrationManager` construction |

### Model & Migration Loading

**Loading Models** from `model/` directory:
```php
// model/User.php returns: new class extends Model { ... }
$User = $this->loadModel('User');        // Returns FQCN string
$user = $User::find($db, 1);            // Use static Model API
$users = $User::query($db)->where('active=:a', ['a' => 1])->get();
```

**Running Migrations** from `migration/` directory:
```php
$db = $this->resolve(Database::class);
$manager = $this->getMigrationManager($db);  // Returns MigrationManager

$manager->migrate();         // Run all pending migrations
$manager->rollback();        // Rollback last batch
$manager->reset();           // Rollback everything
$status = $manager->getStatus(); // Check migration status
```

### Step 3: Event System Format

**Event Name Format**: `'vendor/module_code:event_name'`

```php
// Listening (in __onInit) - returns bool indicating if target module is loaded
$isLoaded = $agent->listen('demo/event_demo:user_registered', function($userData) {
    return ['status' => 'received', 'user' => $userData['name']];
});
// $isLoaded = true if demo/event_demo module is loaded, false otherwise
// Listener is always registered regardless of return value

// Firing (in controller method)
$emitter = $this->trigger('user_registered');
$emitter->resolve($userData);
$responses = $emitter->getAllResponse();  // Array of listener responses
```

### Step 4: addRoute for URL Parameter Capture

**Use addRoute() when you need to capture URL parameters (IDs, slugs, etc.)**

**Critical Requirements**:
1. **Leading slash required** - absolute path from site root
2. **Parentheses for capture** - `(:d)` captures, `:d` just matches

**Pattern Tokens**:
| Token | Matches | Example |
|-------|---------|---------|
| `:a` | Any non-slash chars | `hello-world_123` |
| `:d` | Digits 0-9 | `42` |
| `:w` | Alphabets a-zA-Z | `Widget` |
| `:[regex]` | Custom char class | `:[a-z0-9-]` |
| `{n}` | Exactly n chars | `:a{6}` |
| `{min,max}` | Length range | `:a{3,10}` |

**Route Registration**:
```php
public function __onInit(Agent $agent): bool
{
    // Static routes - use addLazyRoute (relative to module)
    $agent->addLazyRoute(['/' => 'main']);
    
    // Dynamic routes - use addRoute (ABSOLUTE path with leading /)
    $agent->addRoute('/route_demo/user/(:d)', 'user');           // Captures ID
    $agent->addRoute('/route_demo/article/(:a)', 'article');     // Captures slug
    $agent->addRoute('/route_demo/tag/(:[a-z0-9-]{1,30})', 'tag'); // Custom regex
    
    return true;
}
```

**Handler receives captured values**:
```php
// route_demo.user.php
return function (string $id): void {
    header('Content-Type: application/json');
    echo json_encode(['user_id' => $id]);
};
```

**Common Mistakes**:
| Wrong | Correct | Issue |
|-------|---------|-------|
| `'route_demo/user/(:d)'` | `'/route_demo/user/(:d)'` | Missing leading slash |
| `'/module/user/:d'` | `'/module/user/(:d)'` | Missing parentheses = no capture |
| Multiple `:a/:a` in pattern | Use single `:a` per segment | Known framework issue |

**Reference Module**: `demo_modules/` or `test-razy-cli/sites/mysite/` for integration tests

### Step 4b: Route Groups (Shared Prefix/Middleware)

Group routes under a shared prefix and/or middleware stack:

```php
use Razy\Routing\RouteGroup;

$agent->group('/api', function (RouteGroup $group) {
    $group->middleware(new AuthMiddleware());
    $group->namePrefix('api.');

    $group->addRoute('/users', 'api/users');
    $group->addRoute('/posts', 'api/posts');

    $group->group('/admin', function (RouteGroup $admin) {
        $admin->middleware(new AdminMiddleware());
        $admin->method('POST');
        $admin->addRoute('/settings', 'admin/settings');
    });
});
// Produces: api/users, api/posts, api/admin/settings
// Middleware stacks: AuthMW on all; AuthMW+AdminMW on admin
```

### Step 5: Shadow Routes (Route Delegation)

Shadow routes delegate URL handling to **another module's** closure. The URL is still matched — only the executor changes:

```php
public function __onInit(Agent $agent): bool
{
    // Delegate /admin to admin_panel module's 'dashboard' closure
    $agent->addShadowRoute('/admin', 'vendor/admin_panel', 'dashboard');
    return true;
}
```

| Parameter | Purpose |
|-----------|--------|
| `$route` | URL path to match (prefixed with current module alias) |
| `$moduleCode` | Target module to delegate to (cannot be self) |
| `$path` | Closure path in target module (defaults to `$route`) |

At dispatch time, `routedInfo['is_shadow']` is `true`.

### Step 6: Data Path & Data Mapping

**Data path** is a per-distributor writable directory for file storage:

```php
// Filesystem: data/{domain}-{dist_code}/{module}
$path = $this->getDataPath('my_module');

// URL: {siteURL}/data/{module}
$url = $this->getDataPathURL('my_module');
```

The `data/` directory lives at the project root. Each distributor gets its own sub-folder named `{domain}-{dist_code}`.

**Cross-site data mapping** in `dist.php` allows serving data from another distributor's folder:

```php
'data_mapping' => [
    '/' => 'other.com:othersite',  // Redirect data URLs to another site's data folder
],
```

---

## Testing Pipeline

### Step 1: Start Test Server

```powershell
cd test-razy-cli
C:\path\to\php.exe -S localhost:8080 -t .
```

### Step 2: Test Endpoints

```powershell
# Test main page (HTML)
Invoke-WebRequest -Uri "http://localhost:8080/{module}/" -UseBasicParsing | Select-Object -ExpandProperty Content

# Test API endpoint (JSON)
Invoke-WebRequest -Uri "http://localhost:8080/{module}/action?param=value" -UseBasicParsing | Select-Object -ExpandProperty Content
```

### Step 3: Validate Response Content (CRITICAL)

**Testing is NOT just checking HTTP 200 status.** You MUST validate the actual response content:

1. **Valid JSON** — Response must parse as JSON (not HTML error page)
2. **No PHP errors** — Body must not contain `Fatal error`, `Warning:`, `preg_match()`, `undefined method`, `must not be accessed before initialization`
3. **Expected keys present** — Top-level JSON keys must match the demo's expected structure
4. **SQL strings non-empty** — Entries with `"sql"` key must have non-empty values
5. **Correct Content-Type** — Must be `application/json` for JSON endpoints

**Content Validation Script** (`test-workbook-validate.php`):

Use the validation script in project root to test all database demo endpoints:
```powershell
C:\MAMP\bin\php\php8.3.1\php.exe test-workbook-validate.php
```

**Manual Content Validation Example**:
```powershell
# BAD: Only checks status code — INSUFFICIENT
(Invoke-WebRequest -Uri "http://localhost:8080/database_demo/select").StatusCode
# Returns 200 even when response contains PHP errors!

# GOOD: Validates response body content
$r = Invoke-WebRequest -Uri "http://localhost:8080/database_demo/select" -UseBasicParsing
$json = $r.Content | ConvertFrom-Json

# Check 1: Is it valid JSON? (not HTML error page)
if ($json -eq $null) { Write-Error "Not valid JSON" }

# Check 2: No PHP errors in body?
if ($r.Content -match 'Fatal|Warning|error-message') { Write-Error "PHP error in response" }

# Check 3: Expected keys present?
$expectedKeys = @('basic', 'columns', 'alias', 'aggregate', 'groupby', 'orderby', 'join_select')
$actualKeys = $json.PSObject.Properties.Name
$missing = $expectedKeys | Where-Object { $_ -notin $actualKeys }
if ($missing) { Write-Error "Missing keys: $($missing -join ', ')" }

# Check 4: SQL values non-empty?
if ([string]::IsNullOrWhiteSpace($json.basic.sql)) { Write-Error "Empty SQL in 'basic'" }
```

**Database Demo Expected Response Keys**:

| Endpoint | Expected Top-Level Keys |
|----------|------------------------|
| `select` | `basic`, `columns`, `alias`, `aggregate`, `groupby`, `orderby`, `join_select`, `execution_methods` |
| `advanced` | `complex_join`, `correlated`, `subquery`, `query_handling`, `pagination`, `debugging`, `raw_sql`, `plugins` |
| `where` | `equals`, `greater`, `less_equal`, `starts_with`, `ends_with`, `contains`, `is_null`, `not_null`, `in`, `in_numbers`, `between`, `not_in`, `and`, `or`, `grouped`, `negation`, `multi_negation`, `parameters`, `complex`, `export_where` |
| `joins` | `left_join`, `inner_join`, `right_join`, `multi_join`, `export_syntax` |
| `insert` | `basic`, `placeholders`, `partial`, `expression`, `on_duplicate`, `shortcut_info` |
| `update` | `basic`, `parameters`, `increment`, `arithmetic`, `limit`, `multi_where`, `shortcut_info` |
| `delete` | `basic`, `auto_where`, `multi_condition`, `custom_where`, `in_clause`, `soft_delete`, `shortcut_info` |
| `transaction` | `basic`, `savepoints`, `status_check`, `nested_pattern` |
| `connect` | `error` (expected — no DB configured) |
| `drivers` | `title`, `drivers`, `summary` |
| `table_helper` | `rename_table`, `add_column`, `add_column_after`, `drop_column`, `add_unique_index`, + more |
| `column_helper` | `varchar`, `rename`, `int`, `bigint`, `tinyint`, `decimal`, `text`, + more |

**Common Pitfalls**:
- HTTP 200 does NOT mean success — Razy error pages return 400/500 but PHP built-in server may still show 200
- HTML `<title>Error</title>` in response body = PHP/framework error, even if status is 200
- Empty response body usually means PHP fatal error before output
- `"sql": null` or empty SQL = wrong API method used in demo

**Expected Event Response Structure**:
```json
{
    "success": true,
    "event": "vendor/module:event_name",
    "data": { ... },
    "listeners": 1,
    "responses": [
        { "receiver": "other_module", "action": "handled", ... }
    ]
}
```

**Validation Checklist**:
- [ ] Response is valid JSON (not HTML error page)
- [ ] No PHP errors in response body (`Fatal`, `Warning`, `preg_match`, `undefined method`)
- [ ] All expected top-level keys are present
- [ ] SQL strings are non-empty where expected
- [ ] `listeners` count > 0 (event endpoints only)
- [ ] `responses` array contains listener outputs (event endpoints only)

---

## Troubleshooting Guide

### Error: "Method `getModule` is not defined"

**Cause**: Controller doesn't expose `getModule()` - it's private.

**Solution**: Use Controller's public methods:
```php
// Instead of:
$this->getModule()->createEmitter('event');

// Use:
$this->trigger('event');
```

### Error: "Invalid event name format"

**Cause**: Event name must match pattern `vendor/module:event`.

**Solution**: Use full module code with vendor:
```php
// Wrong:
$agent->listen('event_demo:user_registered', ...);

// Correct:
$agent->listen('demo/event_demo:user_registered', ...);
```

### Error: Listener returns `null`

**Cause**: File-based event handlers may not load correctly (framework bug in `fireEvent`).

**Solution**: Use inline closures instead of file paths:
```php
// Instead of:
$agent->listen('vendor/module:event', 'events/handler');

// Use:
$agent->listen('vendor/module:event', function($data) {
    return ['handled' => true];
});
```

### Error: 404 Not Found

**Checklist**:
1. Module path correct? `sites/{dist}/{vendor}/{module}/` (no `modules/` folder)
2. `module.php` exists at module level?
3. `package.php` exists in version folder?
4. Controller file named `{module_code}.php`?
5. Module registered in `dist.php` under `modules['*']`?

---

## Self-Evaluation Pipeline

### Before Completion Checklist

| Step | Check | How to Verify |
|------|-------|---------------|
| 1 | PHP syntax valid | `php -l controller/{module}.php` |
| 2 | Module structure correct | `tree /F sites/{dist}/{vendor}/{module}` |
| 3 | Server starts without errors | Start PHP server, check console |
| 4 | Main page loads | `GET /{module}/` returns HTML |
| 5 | Event fires successfully | `listeners > 0` in JSON response |
| 6 | Listeners respond | `responses` array has data |
| 7 | All routes work | Test each registered route |

### Quick Validation Commands

```powershell
# 0. Generate .htaccess rewrite rules (required for Apache/Nginx)
php Razy.phar rewrite testsite

# 1. Check PHP syntax
C:\path\to\php.exe -l "path\to\controller\{module}.php"

# 2. Verify structure
tree /F "sites\{dist}\{vendor}\{module}"

# 3. Validate all modules in distributor
php Razy.phar validate testsite

# 4. Start server (background)
Start-Process -NoNewWindow php -ArgumentList "-S","localhost:8080","-t","."

# 5. Test endpoint
Invoke-WebRequest -Uri "http://localhost:8080/{module}/" -UseBasicParsing

# 6. Parse JSON response
$response = Invoke-WebRequest -Uri "http://localhost:8080/{module}/action" -UseBasicParsing
$json = $response.Content | ConvertFrom-Json
$json.listeners  # Should be > 0
```

### Module Development Checklist

```
□ sites/{dist}/{vendor}/{module}/module.php created
□ sites/{dist}/{vendor}/{module}/default/package.php created
□ sites/{dist}/{vendor}/{module}/default/controller/{module}.php created
□ Routes registered in __onInit() via $agent->addLazyRoute()
□ Events listening via $agent->listen('vendor/module:event', closure)
□ Events firing via $this->trigger('event')->resolve($data)
□ Module added to dist.php modules['*'] array
□ All endpoints tested and returning expected data
□ Issues documented in OPTIMIZATION-SUGGESTIONS.md (if any)
```

---

## Optimization Suggestions Workflow

**Purpose**: Document issues, bugs, and friction discovered during testing for Razy developers to prioritize fixes.

**When to document**: If testing or emulation reveals:
- Silent failures (no error but doesn't work)
- Confusing API patterns (multiple ways, only one works)
- Missing validation (bad input accepted silently)
- Documentation gaps (undocumented requirements)
- Developer experience issues (excessive debugging needed)

**How to document** (use GitHub Issues or inline code comments):

```markdown
### 🔴 Issue XX: [Brief Title]

**Location**: `src/library/Razy/ClassName.php` → `methodName()`

**Problem**: [What happens vs what should happen]

**Current Behavior**:
```php
// Code that fails or causes confusion
```

**Expected Behavior**: [What developer expects]

**Workaround**: [How to work around it now]

**Suggested Fix**: [Recommended implementation change]
```

**Priority Levels**:
| Level | Meaning |
|-------|---------|
| 🔴 Critical | Blocks functionality, causes confusion |
| 🟠 High | Significant friction, workaround available |
| 🟡 Medium | Inconvenient but manageable |
| 🟢 Low | Enhancement/polish |

**Reference**: Report issues via GitHub Issues or document in the project's issue tracker.

---

## Demo Module Structure

**Permanent Storage**: `demo_modules/{category}/{module_name}/`

**Categories**: `core/` (bridge_provider, events, routes, templates, threads), `data/` (database, collection, hashmap, yaml), `demo/` (demo_index, hello_world, markdown_consumer), `io/` (api_demo, api_provider, bridge_demo, dom, mailer, message, sse, xhr), `system/` (advanced_features, helper_module, markdown_service, plugin, profiler)

**Development Location**: `playground/modules/workbook/{module_name}/`

**Standard Structure**:
```
demo_modules/
├── core/
│   ├── bridge_provider/       # Cross-distributor bridge provider
│   ├── event_demo/
│   ├── event_receiver/
│   ├── route_demo/
│   ├── template_demo/        # Template engine: variables, modifiers, blocks, Entity API
│   │   ├── module.php
│   │   └── default/
│   │       ├── package.php
│   │       ├── controller/
│   │       │   ├── template_demo.php       # Main controller (6 lazy routes)
│   │       │   ├── template_demo.main.php  # Overview page with XHR demo loader
│   │       │   └── demo/                   # Sub-routes: variables, blocks, functions, entity, advanced
│   │       └── view/                       # 31 .tpl files demonstrating each feature
│   └── thread_demo/
├── data/
│   ├── collection_demo/
│   ├── database_demo/
│   ├── hashmap_demo/
│   └── yaml_demo/
├── demo/
│   ├── demo_index/            # Shared header/footer/styles API + event-driven demo registration
│   ├── hello_world/           # Basic hello world module for quick onboarding
│   └── markdown_consumer/
├── io/
│   ├── api_demo/              # API command demonstration
│   ├── api_provider/          # API provider: exposes callable commands
│   ├── bridge_demo/           # Cross-distributor bridge consumer demo
│   ├── dom_demo/
│   ├── mailer_demo/
│   ├── message_demo/
│   ├── sse_demo/
│   └── xhr_demo/
└── system/
    ├── advanced_features/     # Agent await, internal API (#), shadow routes, @self routes
    ├── helper_module/         # Await targets and shadow route targets for advanced_features
    ├── markdown_service/
    ├── plugin_demo/
    └── profiler_demo/
```

**Each demo module includes**:
- `@llm` documentation comments for LLM understanding
- Multiple demo endpoints showcasing different features
- Index page with links to all demos
- "Classes Used" table documenting classes/namespaces used
- Shared styling via `$this->api('demo/demo_index')->header()` and `footer()`
- Event-based registration with `demo/demo_index:register_demo` for central listing

**Demo Development Workflow**:
```
1. Build Razy.phar using RazyProject-Building.ipynb
2. Initialize playground/ folder
3. Run: php Razy.phar rewrite workbook
4. Create module in playground/modules/workbook/{module_name}/
5. Test at http://localhost/Razy/playground/{module_name}/
6. When tests pass, copy to demo_modules/{category}/{module_name}/
```

**Deploying Demo Modules to Playground**:
```powershell
cd playground
# Copy all demo modules (preserving category structure → flat into workbook)
Get-ChildItem ..\demo_modules -Directory -Recurse |
    Where-Object { Test-Path "$($_.FullName)\module.php" } |
    ForEach-Object { Copy-Item -Path $_.FullName -Destination "modules\workbook\$($_.Name)" -Recurse -Force }
```

