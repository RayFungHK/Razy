# Razy Framework v0.5.0-237 — Comprehensive Codebase Analysis

**Generated**: Comprehensive analysis of all PHP source code files in the Razy framework  
**Total Files Analyzed**: ~119 PHP files across `src/library/Razy/`, `src/system/`, `src/plugins/`, `src/asset/`

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Core System (Bootstrap & Application)](#2-core-system)
3. [Distributor & Domain Routing](#3-distributor--domain-routing)
4. [Module System](#4-module-system)
5. [Database Subsystem](#5-database-subsystem)
6. [Template Engine](#6-template-engine)
7. [Collection System](#7-collection-system)
8. [Event System](#8-event-system)
9. [FlowManager System](#9-flowmanager-system)
10. [Authentication & Security](#10-authentication--security)
11. [Package & Repository Management](#11-package--repository-management)
12. [Support & Utility Classes](#12-support--utility-classes)
13. [Plugin Architecture](#13-plugin-architecture)
14. [CLI Terminal System](#14-cli-terminal-system)
15. [Tool Subsystem](#15-tool-subsystem)
16. [Cross-Cutting Patterns](#16-cross-cutting-patterns)
17. [File Index](#17-file-index)

---

## 1. Architecture Overview

Razy is a **multi-site, distributor-based modular PHP framework** supporting:

- **Web mode** (Apache with `.htaccess` rewriting)
- **CLI mode** (Terminal commands via `php Razy.phar <command>`)
- **FrankenPHP Worker mode** (persistent worker with `frankenphp_handle_request()`)
- **PHAR packaging** (entire framework distributed as `Razy.phar`)

### High-Level Flow

```
main.php → bootstrap.inc.php → Application → Domain → Distributor → Module lifecycle
                                                                      ↓
                                                              Controller (__onInit → __onLoad → __onReady → route)
```

### Key Design Principles

- **Vendor/Package module structure** with semantic versioning
- **Closure-based method loading** from external PHP files
- **Plugin trait** shared across Template, Collection, Statement, FlowManager
- **Simple Syntax parsers** for WHERE clauses, version comparison, update expressions
- **Cross-distributor data mapping** for shared storage
- **Anonymous class controllers** loaded at runtime

---

## 2. Core System

### `main.php` (~170 lines)
- **Purpose**: Entry point for both Web and CLI modes
- **Constants defined**: `SYSTEM_ROOT`, `PHAR_FILE`, `PHAR_PATH`
- **Web mode**: Creates `Application`, calls `host(HOSTNAME:PORT)`, queries URL
- **FrankenPHP**: Uses `frankenphp_handle_request()` in a while loop
- **CLI mode**: Parses `$argv`, loads terminal command from `system/terminal/{command}.inc.php`
- **Flag**: `-f` for custom system path location

### `bootstrap.inc.php` (949 lines)
- **Namespace**: `Razy`
- **Constant**: `RAZY_VERSION = '0.5.0-237'`
- **Folder constants**: `PLUGIN_FOLDER`, `PHAR_PLUGIN_FOLDER`, `SITES_FOLDER`, `DATA_FOLDER`, `SHARED_FOLDER`
- **Initialization**:
  - Sets up exception handler, SPL autoloader (checks `SYSTEM_ROOT/library` then `PHAR_PATH/library`)
  - Detects `CLI_MODE` / `WEB_MODE` / `WORKER_MODE`
  - Web mode: defines `HOSTNAME`, `RELATIVE_ROOT`, `PORT`, `SITE_URL_ROOT`, `RAZY_URL_ROOT`, `URL_QUERY`, `FULL_URL_QUERY`
  - Registers plugin folders for Collection, Template, Statement, FlowManager

#### Global Utility Functions (defined in bootstrap.inc.php):

| Function | Purpose |
|----------|---------|
| `is_fqdn()` | Validate fully qualified domain name |
| `format_fqdn()` | Normalize FQDN format |
| `tidy()` | Normalize path separators |
| `append()` | Join path segments |
| `sort_path_level()` | Sort paths by depth level |
| `versionStandardize()` | Normalize version strings |
| `vc()` | Version comparison with semver, caret, tilde, range via `SimpleSyntax` |
| `is_ssl()` | Detect HTTPS via headers |
| `getFilesizeString()` | Format file size with unit |
| `getIP()` | Get visitor IP from various headers |
| `ipInRange()` | CIDR range check |
| `construct()` | Deep array merge by structure |
| `refactor()` / `urefactor()` | Array data restructuring |
| `comparison()` | Generic comparison with operators (`=`, `!=`, `>`, `<`, `|=`, `^=`, `$=`, `*=`) |
| `guid()` | Generate GUID segments |
| `collect()` | Wrap data as `Collection` |
| `getRelativePath()` | Compute relative path |
| `fix_path()` | Normalize relative path with `..` resolution |
| `is_dir_path()` | Check if path ends with separator |
| `xremove()` | Recursive directory/file removal |
| `xcopy()` | Recursive copy with pattern matching |
| `autoload()` | Custom autoloader (PSR-4 + PSR-0 fallback) |
| `getFutureWeekday()` | Business day calculation |
| `getWeekdayDiff()` / `getDayDiff()` | Date difference calculation |
| `is_json()` | JSON validation (polyfill for `json_validate`) |

### `Application.php` (595 lines)
- **Namespace**: `Razy`
- **Purpose**: Manages multi-site configuration and domain-to-distributor routing
- **Properties**: `$locked` (static), `$alias`, `$config`, `$distributors`, `$multisite`, `$guid`, `$domain`, `$protection`
- **Key Methods**:
  - `host($fqdn)` — Matches domain, registers autoloader
  - `query($urlQuery)` — Routes URL to matched distributor
  - `matchDomain($fqdn)` — Wildcard/exact/alias domain matching
  - `updateSites()` — Parses domains/alias config from `sites.inc.php`
  - `loadSiteConfig()` / `writeSiteConfig()` — Site configuration I/O
  - `updateRewriteRules()` — `.htaccess` generation
  - `validation()` — Checksums protection
  - `Lock()` / `UnlockForWorker()` — Singleton-like lock mechanism
  - `compose()` / `dispose()` — Lifecycle management

### `Domain.php` (~120 lines)
- **Namespace**: `Razy`
- **Purpose**: Intermediary connecting Application to Distributor
- **Key Method**: `matchQuery($urlQuery)` — Creates Distributor by URL path matching using `sort_path_level`
- Also provides `autoload()` and `dispose()`

---

## 3. Distributor & Domain Routing

### `Distributor.php` (1137 lines)
- **Namespace**: `Razy`
- **Purpose**: Central orchestrator for module loading, routing, and lifecycle management
- **Properties**: `$code`, `$tag`, `$requires`, `$modules`, `$queue`, `$APIModules`, `$CLIScripts`, `$awaitList`, `$routes`, `$prerequisites`, `$globalTemplate`, `$dataMapping`, `$autoload`, `$globalModule`, `$strict`, `$folderPath`, `$moduleSourcePath`

#### Module Lifecycle States:
```
STATUS_PENDING → standby → initialize (__onInit) → IN_QUEUE → prepare (__onLoad)
  → LOADED → validate (__onRequire) → notify (__onReady) → route matching → dispose
```

#### Key Methods:

| Method | Purpose |
|--------|---------|
| `__construct()` | Initialize with distCode, tag, domain, urlPath, urlQuery |
| `initialize($initialOnly)` | Full lifecycle: scanModule → require → prepare → validate |
| `scanModule($path)` | Discover modules in vendor/package structure |
| `require($module)` | Dependency resolution |
| `matchRoute()` | Regex/lazy route matching, session setup, await execution |
| `setRoute()` / `setLazyRoute()` / `setShadowRoute()` | Route registration |
| `setScript()` | CLI script route registration |
| `handshakeTo()` | Cross-module handshake |
| `addAwait()` | Register async wait conditions |
| `registerAPI()` | Register module for API exposure |
| `createEmitter()` | Create EventEmitter for inter-module events |
| `createAPI()` | Create API proxy instance |
| `autoload()` | Module library class loading |
| `getDataPath()` | Data directory resolution |
| `executeInternalAPI()` | CLI process isolation via `proc_open` bridge |
| `compose()` | Validate prerequisites via PackageManager |
| `prerequisite()` | Register prerequisite package with version constraint |
| `getLoadedAPIModule()` / `getLoadedModule()` | Retrieve module by code |
| `getLoadedModulesInfo()` | Full module metadata (internal CLI use) |
| `dispose()` | Cleanup all modules |

#### Features:
- Custom `module_path` configuration for shared module locations
- `data_mapping` for cross-distributor data sharing
- Global modules from `shared/` folder
- Distributor tagging (`code@tag` identifier system)
- Prerequisite conflict detection and reporting

---

## 4. Module System

### `Module.php` (864 lines)
- **Namespace**: `Razy`
- **Purpose**: Represents a single loadable module with full lifecycle

#### Status Constants:
| Constant | Value | Meaning |
|----------|-------|---------|
| `DISABLED` | -2 | Module disabled |
| `PENDING` | 0 | Awaiting initialization |
| `PROCESSING` / `INITIALING` | 2 | Being initialized |
| `IN_QUEUE` | 3 | In loading queue |
| `LOADED` | 4 | Fully loaded |
| `UNLOADED` | -1 | Unloaded |
| `FAILED` | -3 | Failed to load |

#### Properties:
`$routable`, `$binding`, `$closures`, `$apiCommands`, `$bridgeCommands`, `$controller`, `$events`, `$moduleInfo`, `$agent`, `$threadManager`, `$status`

#### Key Methods:

| Method | Purpose |
|--------|---------|
| `initialize()` | Load controller PHP file as anonymous class, call `__onInit` |
| `execute()` | Execute API commands |
| `executeInternalCommand()` | Internal command execution |
| `executeBridgeCommand()` | Bridge command execution |
| `touch()` / `handshake()` | Cross-module interaction |
| `addAPICommand()` / `addBridgeCommand()` | Register commands |
| `listen()` | Register event listener |
| `createEmitter()` / `fireEvent()` | Event system |
| `addRoute()` / `addLazyRoute()` / `addShadowRoute()` | Route registration |
| `getClosure()` | Load closure from `controller/` folder files |
| `loadConfig()` | Load module configuration |
| `prepare()` | Call `__onLoad` |
| `validate()` | Call `__onRequire` |
| `notify()` | Call `__onReady` |
| `dispose()` | Call `__onDispose` |
| `resetForWorker()` | Reset for FrankenPHP worker mode |

#### Closure Loading Pattern:
Method name maps to `controller/ClassName.methodName.php` file. The closure is loaded, cached, and bound to the controller instance via `Closure::bind()`.

### `Agent.php` (~280 lines)
- **Namespace**: `Razy`
- **Purpose**: Facade for Module, passed to controller during `__onInit` and `__onLoad`
- **Methods**: `addAPICommand()`, `addBridgeCommand()`, `listen()`, `addShadowRoute()`, `addScript()`, `addRoute()`, `addLazyRoute()`, `await()`, `thread()`
- **Route syntax**: `:a` (any), `:d` (digits), `:D` (non-digit), `:w` (alpha), `:W` (non-alpha), `:[\\regex]`, `{min,max}` length

### `Controller.php` (478 lines)
- **Namespace**: `Razy`
- **Purpose**: Abstract base class for module controllers

#### Plugin Flags:
`PLUGIN_ALL`, `PLUGIN_TEMPLATE`, `PLUGIN_COLLECTION`, `PLUGIN_FLOWMANAGER`, `PLUGIN_STATEMENT`

#### Lifecycle Events (overridable):

| Event | When Called |
|-------|------------|
| `__onInit(Agent)` | Module initialization |
| `__onLoad(Agent)` | Module loading (register routes, APIs) |
| `__onReady()` | All modules loaded |
| `__onDispose()` | Shutdown |
| `__onDispatch()` | Before route dispatch |
| `__onRouted(ModuleInfo)` | Another module matched a route |
| `__onScriptReady(ModuleInfo)` | CLI script ready |
| `__onEntry(array)` | Route entry point |
| `__onError(string, Throwable)` | Error handler |
| `__onAPICall(ModuleInfo, string, string)` | Incoming API call |
| `__onBridgeCall(string, string)` | Bridge command call |
| `__onTouch(ModuleInfo, string, string)` | Touch event |
| `__onRequire()` | Dependency validation |

#### Utility Methods (final):
`getAssetPath()`, `getDataPath()`, `getModuleConfig()`, `getModuleURL()`, `goto()`, `trigger()`, `loadTemplate()`, `xhr()`, `getTemplateFilePath()`, `getModuleInfo()`, `getSiteURL()`, `getTemplate()`, `getRoutedInfo()`, `api()`, `view()`, `registerPluginLoader()`, `handshake()`, `fork()`

#### Magic Method:
`__call()` — Loads closure file from `controller/ClassName.methodName.php`, caches and binds to controller.

### `ModuleInfo.php` (349 lines)
- **Namespace**: `Razy`
- **Purpose**: Value object for module metadata
- **Regex**: `REGEX_MODULE_CODE` validates `vendor/package` format
- **Properties**: `$alias`, `$apiName`, `$assets`, `$description`, `$author`, `$code`, `$modulePath`, `$className`, `$prerequisite`, `$relativePath`, `$require`, `$shadowAsset`, `$pharArchive`, `$moduleMetadata`
- **Features**: PHAR archives (`app.phar`), version directories, auto-require parent namespaces

### `Route.php` (~60 lines)
- **Namespace**: `Razy`
- **Purpose**: Simple value object holding `$closurePath` and `$data`

### `API.php` (~45 lines)
- **Namespace**: `Razy`
- **Purpose**: API proxy factory — creates `Emitter` instances for cross-module API calls
- **Method**: `request($moduleCode)` → returns `Emitter` wrapping target module

---

## 5. Database Subsystem

### `Database.php` (533 lines)
- **Namespace**: `Razy`
- **Purpose**: PDO wrapper with multi-driver support and statement builder
- **Driver Constants**: `DRIVER_MYSQL`, `DRIVER_PGSQL`, `DRIVER_SQLITE`
- **Static instances registry** with `GetInstance()` and `CreateDriver()`
- **Key Methods**:
  - `connect()` — Legacy MySQL connection
  - `connectWithDriver($type, $config)` — Multi-driver connection
  - `execute(Statement)` → `Query` — Execute SQL
  - `insert()`, `update()`, `delete()`, `prepare()` → `Statement`
  - `createViewTable()`, `isTableExists()`
  - `getCollation()`, `getCharset()`, `setTimezone()`, `setPrefix()`, `lastID()`, `affectedRows()`

### `Database\Driver.php` (~160 lines)
- **Abstract class** for database driver implementations
- **Abstract methods**: `getType()`, `connect()`, `getConnectionOptions()`, `tableExists()`, `getCharset()`, `getCollation()`, `setTimezone()`, `getLimitSyntax()`, `getAutoIncrementSyntax()`, `getUpsertSyntax()`, `getConcatSyntax()`
- **Concrete**: `getAdapter()`, `isConnected()`, `quoteIdentifier()`, `lastInsertId()`

### `Database\Driver\MySQL.php` (~190 lines)
- MySQL-specific implementation
- DSN: `mysql:host={host};port={port};dbname={database};charset={charset}`
- Options: `PDO::ATTR_PERSISTENT`, `MYSQL_ATTR_FOUND_ROWS`
- LIMIT syntax: `LIMIT position, length`
- Upsert: `ON DUPLICATE KEY UPDATE`
- Concat: `CONCAT()`
- Identifier quoting: backticks `` ` ``

### `Database\Driver\PostgreSQL.php` (~224 lines)
- PostgreSQL-specific implementation
- DSN: `pgsql:host={host};port={port};dbname={database}`
- Sets `client_encoding` after connection
- LIMIT syntax: `LIMIT length OFFSET position`
- Auto-increment: `SERIAL` / `BIGSERIAL`
- Upsert: `ON CONFLICT ... DO UPDATE SET ... = EXCLUDED.column`
- Concat: `CONCAT()`
- Identifier quoting: double quotes `"`

### `Database\Driver\SQLite.php` (~212 lines)
- SQLite-specific implementation
- DSN: `sqlite:{path}` (supports `:memory:`)
- Enables `PRAGMA foreign_keys = ON`
- Auto-increment: `INTEGER PRIMARY KEY AUTOINCREMENT`
- Upsert: `ON CONFLICT(...) DO UPDATE SET ... = excluded.column`
- Concat: `||` operator
- Identifier quoting: double quotes `"`
- No persistent connections, limited collation (BINARY, NOCASE, RTRIM)

### `Database\Statement.php` (972 lines)
- **Uses `PluginTrait`**
- **Purpose**: Comprehensive SQL builder with Simple Syntax support
- **Types**: `select`, `insert`, `replace`, `update`, `delete`
- **Key Methods**:

| Method | Purpose |
|--------|---------|
| `alias()` | Table alias |
| `builder()` | Attach Statement\Builder plugin |
| `assign()` | Bind parameter values |
| `from()` | Table join syntax via `TableJoinSyntax` |
| `select()` | SELECT columns |
| `where()` | WHERE clause via `WhereSyntax` |
| `group()` | GROUP BY |
| `order()` | ORDER BY with `<`/`>` prefix for ASC/DESC |
| `limit()` | LIMIT/OFFSET |
| `insert()` | INSERT with duplicate key handling |
| `update()` | UPDATE with Update Simple Syntax |
| `delete()` | DELETE |
| `query()` | Execute and return `Query` |
| `lazy()` | Execute and fetch first result |
| `lazyGroup()` | Execute and group results by column |
| `lazyKeyValuePair()` | Execute and return key-value pairs |
| `collect()` | Collect column values during fetch |
| `setParser()` | Post-processing callback per row |
| `createViewTable()` | Create SQL VIEW |
| `getSyntax()` | Generate final SQL string |
| `StandardizeColumn()` | Static column name normalization |
| `GetSearchTextSyntax()` | Full-text search LIKE generation |

### `Database\Query.php` (~90 lines)
- **Purpose**: Wraps `PDOStatement` for result fetching
- **Methods**: `fetch($mapping)`, `fetchAll($type)` (group/keypair/default), `affected()`, `getStatement()`

### `Database\WhereSyntax.php` (624 lines)
- **Purpose**: Parses WHERE Simple Syntax into SQL WHERE clauses
- **Syntax features**:
  - `,` = AND, `|` = OR
  - `!` = negation
  - `?` = auto-reference column value
  - `:param` = named parameter reference
  - Operators: `=`, `!=`, `>`, `<`, `>=`, `<=`, `|=` (IN), `^=` (starts with), `$=` (ends with), `*=` (contains), `#=` (IS NULL), `@=`, `~=`, `&=`
  - `<>` / `><` = BETWEEN / NOT BETWEEN
  - Parenthesized grouping
  - Column reference with JSON path support (`column->>'$.path'`)
  - Sub-query support via Statement objects as values

### `Database\TableJoinSyntax.php` (252 lines)
- **Purpose**: Parses table join Simple Syntax into SQL FROM/JOIN clauses
- **Join operators**: `-` (JOIN), `<` (LEFT JOIN), `>` (RIGHT JOIN), `<<` (LEFT OUTER), `>>` (RIGHT OUTER), `*` (CROSS JOIN), `+` (unknown)
- **Condition syntax**: `[column1,column2]` (ON matching), `[:column]` (USING), `[?expr]` (custom WHERE)
- **Features**: Sub-query aliases, table prefix injection, driver-specific identifier quoting

### `Database\Column.php` (798 lines)
- **Purpose**: Column definition with type system
- **Types**: `auto_id`, `text`, `full_text`, `long_text`, `int`, `bool`, `decimal`/`money`/`float`, `timestamp`, `datetime`, `date`
- **Configuration**: type, length, nullable, charset, collation, zerofill, key, oncreate, onupdate, default, reference
- **Method**: `parseSyntax()` for column config strings

### `Database\Table.php` (746 lines)
- **Purpose**: Table definition and schema management
- **Methods**: `Import()` (static factory from syntax), `addColumn()`, `moveColumnAfter()`, `validate()`, `commit()` (generates CREATE/ALTER TABLE SQL), `createHelper()` → `TableHelper`, `columnHelper()` → `ColumnHelper`
- **Properties**: `$charset`, `$collation`, `$columns`, `$committed`, `$reordered`, `$groupIndexingList`

### `Database\Table\TableHelper.php` (778 lines)
- **Purpose**: Fluent API for ALTER TABLE statement generation
- **Operations**: `addColumn()`, `modifyColumn()`, `dropColumn()`, `renameColumn()`, `addIndex()`, `dropIndex()`, `addForeignKey()`, `dropForeignKey()`, `rename()`, `charset()`, `collation()`, `engine()`, `comment()`
- **Returns** `Column` objects for further chaining

### `Database\Table\ColumnHelper.php` (583 lines)
- **Purpose**: Fluent API for column-specific ALTER statements
- **Type methods**: `varchar()`, `int()`, `bigint()`, `tinyint()`, `decimal()`, `float()`, `text()`, `longtext()`, `mediumtext()`, `datetime()`
- **Modifiers**: `rename()`, `nullable()`, `default()`, `charset()`, `collation()`, `autoIncrement()`, `zerofill()`, `position()`, `comment()`

### `Database\Preset.php` (~55 lines)
- **Abstract class** for reusable statement presets
- **Properties**: `$statement`, `$table`, `$alias`, `$params`
- **Methods**: `init($params)`, `getStatement()`

### `Database\Statement\Builder.php` (~40 lines)
- **Purpose**: Extensible statement builder base class
- **Method**: `build($tableName)` — Override to customize statement generation
- **Properties**: `$postProcess`, `$statement`

---

## 6. Template Engine

### `Template.php` (407 lines)
- **Namespace**: `Razy`
- **Uses `PluginTrait`**
- **Purpose**: Manages template sources, plugins, queue, parameters

#### Static Methods:
| Method | Purpose |
|--------|---------|
| `ParseContent()` | Regex-based parameter interpolation `{$var\|fallback}` |
| `ParseValue()` | Parse value expressions |
| `GetValueByPath()` | Dot-notation path resolution |
| `LoadFile()` | Load template file → `Source` |
| `ReadComment()` | Extract `{# ... }` comments |

#### Instance Methods:
`load($path)` → `Source`, `assign()`, `bind()`, `loadPlugin()`, `loadTemplate()`, `addQueue()`, `outputQueued()`, `insert()`, `getValue()`, `getTemplate()`

**Scope**: Manager (global) — widest scope. Parameters set here are fallback defaults visible to all Sources, Blocks, and Entities.

- `assign()`: Copies the value immediately at call time.
- `bind()`: Stores a reference pointer — the value is **deferred** and not resolved until render time (`output()` / `process()`). Changes to the original variable after binding are reflected in the output.

### `Template\Source.php` (~270 lines)
- **Purpose**: Wraps a template file, creates Block→Entity hierarchy
- **Scope**: Source (file-level) — visible to all Blocks/Entities in this template file, no cross-Source leaking.
- **Properties**: `$fileDirectory`, `$parameters`, `$rootBlock`, `$rootEntity`, `$template`, `$module`
- **Methods**: `assign()`, `bind()`, `getRoot()` → `Entity`, `getRootBlock()` → `Block`, `getValue()`, `loadPlugin()`, `output()`, `queue()`
- `assign()` copies immediately; `bind()` stores a reference (deferred until render time)

### `Template\Entity.php` (594 lines)
- **Purpose**: Represents an instance of a Block with parameter state
- **Properties**: `$caches`, `$entities`, `$parameters`, `$linkedEntity`
- **Key Methods**:

| Method | Purpose |
|--------|---------|
| `assign()` / `bind()` | Parameter assignment |
| `find($path)` | XPath-like entity search |
| `hasBlock()` / `hasEntity()` | Block existence checks |
| `getEntity()` / `getEntities()` | Entity retrieval |
| `getBlockCount()` | Count block instances |
| `newBlock($name, $id)` → `Entity` | Create sub-block instance |
| `remove()` / `detach()` | Entity removal |
| `process()` | Render output — iterates structure, processes blocks and text |
| `parseText()` | Replace parameter tags and function tags |
| `parseFunctionTag()` | Process `{@functionName ...}...{/functionName}` tags |
| `parseValue()` | Parse text/parameter patterns |
| `parseParameter()` | Resolve `$var.path->modifier:args` chains |
| `getValue()` | Recursive parameter lookup (entity → block → source → template) |

### `Template\Block.php` (379 lines)
- **Purpose**: Parses template structure from `FileReader`
- **Template syntax**:
  - `<!-- START BLOCK: name -->` / `<!-- END BLOCK: name -->`
  - `<!-- WRAPPER BLOCK: name -->`
  - `<!-- TEMPLATE BLOCK: name -->`
  - `<!-- INCLUDE BLOCK: path -->`
  - `<!-- RECURSION BLOCK: name -->`
  - `<!-- USE blockname BLOCK: name -->`
- **Methods**: `getClosest()`, `hasBlock()`, `getBlock()`, `assign()`, `bind()`, `getStructure()`, `getName()`, `getPath()`, `getTemplate()`
- **Scope**: Block-level — visible to all Entities spawned from this block. `assign()` copies immediately; `bind()` stores a reference (deferred until render).

### Template Plugins

#### `Template\Plugin\TFunction.php` (~140 lines)
- **Base class** for function plugins
- **Properties**: `$encloseContent`, `$extendedParameter`, `$allowedParameters`, `$controller`
- **Abstract**: `processor(Entity, parameters, arguments, wrappedText)` → `string`
- **Method**: `parse()` — parameter parsing with named/positional params and `:argument` syntax

#### `Template\Plugin\TModifier.php` (~80 lines)
- **Base class** for modifier plugins
- **Abstract**: `process(value, ...args)` → `string`
- **Method**: `modify()` — parses `:param1:param2` syntax

#### `Template\Plugin\TFunctionCustom.php` (~80 lines)
- **Custom function plugin** with raw syntax access (no automatic parameter parsing)

#### Built-in Plugins (28 files in `src/plugins/Template/`):

**Modifiers**: `addslashes`, `capitalize`, `alphabet`, `gettype`, `lower`, `upper`, `trim`, `join`, `nl2br`

**Functions**: `template` (include sub-template), `repeat` (loop N times), `if` (conditional), `each` (iterate arrays), `def` (default value)

---

## 7. Collection System

### `Collection.php` (~250 lines)
- **Namespace**: `Razy`
- **Extends `ArrayObject`**, uses `PluginTrait`
- **Invocation**: `$collection($filter)` → `Processor` — CSS-like selector syntax
- **Selector features**:
  - Dot-notation paths: `path.to.value`
  - Wildcards: `*`
  - Filters: `:filterName(args)`
- **Methods**: `parseSelector()`, `filter()`, `array()` (recursive export), serialization support

### `Collection\Processor.php` (~80 lines)
- **Purpose**: Applies processor plugins to filtered values via `__call` magic method
- **Methods**: `get()` → `Collection`, `getArray()` → `array`

#### Built-in Plugins (4 files in `src/plugins/Collection/`):
- **Processors**: `int`, `float`, `trim`
- **Filters**: `istype`

---

## 8. Event System

### `EventEmitter.php` (~65 lines)
- **Namespace**: `Razy`
- **Purpose**: Inter-module event broadcasting
- **Created by**: `Module::createEmitter()`
- **Constructor**: Distributor, Module, event name, optional callback
- **Key Method**: `resolve(...$args)` — Iterates all modules, fires event on listeners, collects responses, calls callback
- **Method**: `getAllResponse()` → array of module responses

### `Emitter.php` (~45 lines)
- **Namespace**: `Razy`
- **Purpose**: API command proxy between modules
- **Constructor**: requestedBy Module, target Module
- **Magic**: `__call()` — Delegates to target `Module::execute()` for API invocation

---

## 9. FlowManager System

### `FlowManager.php` (~160 lines)
- **Namespace**: `Razy`
- **Uses `PluginTrait`**
- **Purpose**: Manages chain of Flow objects with shared storage
- **Methods**:
  - `resolve()` — Process all flows
  - `getTransmitter()` → `Transmitter`
  - `append(Flow)` — Add flow to chain
  - `start($method, ...$args)` → `Flow` — Create Flow from plugin
  - `setStorage()` / `getStorage()` — Named storage with optional identifier
  - `getMap()` — Get flow map
- **Static**: `CreateFlowInstance()`, `IsFlow()`

### `FlowManager\Flow.php` (358 lines)
- **Abstract class**, uses `PluginTrait`
- **Purpose**: Individual processing step in a flow chain
- **Properties**: `$flows` (HashMap), `$parent`, `$flowType`, `$resolved`, `$falseFlow`, `$_isRecursive`, `$identifier`
- **Chain methods**: `init()`, `kill()`, `join()`, `connect()`, `eject()`, `detach()`, `getFlowManager()` (walks parent chain)
- **Abstract**: `request()`, `resolve()` — Defined in subclass plugins

#### Built-in FlowManager Plugins (9 files in `src/plugins/FlowManager/`):
| Plugin | Purpose |
|--------|---------|
| `Custom` | Custom processing logic |
| `Fetch` | Data fetching |
| `FetchGreatest` | Fetch maximum value |
| `FormWorker` | Form processing |
| `NoEmpty` | Empty value filtering |
| `Password` | Password validation |
| `Regroup` | Data regrouping |
| `Unique` | Duplicate removal |
| `Validate` | Data validation |

### `FlowManager\Transmitter.php` (~35 lines)
- **Purpose**: Broadcasts method calls to all flows in FlowManager via `__call` magic

---

## 10. Authentication & Security

### `OAuth2.php` (~300 lines)
- **Namespace**: `Razy`
- **Purpose**: Generic OAuth 2.0 flow implementation
- **Properties**: `$clientId`, `$clientSecret`, `$redirectUri`, `$authorizeUrl`, `$tokenUrl`, `$scope`, `$state`, `$additionalParams`, `$tokenData`
- **Key Methods**:
  - `setAuthorizeUrl()`, `setTokenUrl()`, `setScope()`, `setState()`
  - `getAuthorizationUrl()` — Generate authorization URL with CSRF state
  - `getAccessToken($code)` — Exchange auth code for tokens
  - `refreshAccessToken($refreshToken)` — Token refresh
  - `validateState()` — CSRF protection (static, uses `hash_equals`)
  - `httpGet()` — Authenticated API requests
  - `parseJWT()` — JWT decoding (without signature verification)
  - `isJWTExpired()` — JWT expiration check

### `Office365SSO.php` (400 lines)
- **Namespace**: `Razy`
- **Extends**: `OAuth2`
- **Purpose**: Microsoft Entra ID / Azure AD SSO implementation
- **Properties**: `$tenantId`, `$graphScope`, `$prompt`, `$loginHint`, `$domainHint`, `$userInfo`, `$idToken`
- **Microsoft-specific**:
  - Endpoints: `login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize|token`
  - Graph API: `graph.microsoft.com/v1.0/me`
  - Methods: `getUserInfo()`, `getUserPhoto()`, `parseIdToken()`, `validateIdToken()`
  - Session management: `createSession()`, `isSessionExpired()`, `needsRefresh()`
  - Login hints: `setLoginHint()`, `setDomainHint()`, `setPrompt()`
  - Photo sizes: 48x48 through 648x648

### `Crypt.php` (~60 lines)
- **Namespace**: `Razy`
- **Purpose**: Symmetric encryption utility
- **Static Methods**: `Encrypt($text, $key, $toHex)` and `Decrypt($encryptedText, $key)`
- **Algorithm**: AES-256-CBC with HMAC-SHA256 verification
- **Output**: Hex-encoded or base64 ciphertext

---

## 11. Package & Repository Management

### `PackageManager.php` (415 lines)
- **Namespace**: `Razy`
- **Purpose**: Composer-like package manager fetching from Packagist
- **Status**: `PENDING` → `FETCHING` → `READY` → `UPDATED`
- **Key Methods**:
  - `fetch()` — Fetches package info from `repo.packagist.org/p2/{package}.json`
  - `validate()` — Downloads ZIP, extracts PSR-4 autoload mappings to `autoload/{distCode}/`
  - `UpdateLock()` — Writes `autoload/lock.json` version lock file
- **Features**:
  - Stability filtering (stable, RC, beta, alpha, dev)
  - Version constraint matching via `vc()`
  - Recursive dependency resolution
  - cURL download with progress callbacks
  - ZIP extraction with PSR-4 namespace mapping

### `RepositoryManager.php` (472 lines)
- **Namespace**: `Razy`
- **Purpose**: Module repository search and download management
- **Supports**: GitHub, GitLab, custom URLs
- **Key Methods**:
  - `addRepository()`, `getRepositories()`
  - `fetchIndex()` — Fetch `index.json` from repository
  - `search($query)` — Search modules across all repositories
  - `getModuleInfo()`, `getManifest()`
  - `getDownloadUrl()` — Resolve download URL with version
  - `buildRawUrl()` — GitHub/GitLab raw content URL generation
  - `buildReleaseAssetUrl()` — GitHub Releases download URLs
  - `listAll()` — List all available modules

### `RepoInstaller.php` (768 lines)
- **Namespace**: `Razy`
- **Purpose**: Download and install modules from repository sources
- **Supports**: GitHub repos (full URL or `owner/repo` shorthand), custom ZIP URLs, version tags
- **Key Methods**:
  - `install()` — Full download and extraction flow
  - `getRepositoryInfo()` — GitHub API metadata
  - `getLatestRelease()` / `getStableRelease()` — Release resolution
  - `getAvailableTags()` — List all version tags
  - `setVersion()` — `latest`, `stable`, `@tag`, or branch name
- **Features**: Auth token support, progress callbacks, ZIP/tarball handling

---

## 12. Support & Utility Classes

### `PluginTrait.php` (~65 lines)
- **Trait** used by Template, Collection, Statement, FlowManager
- **Static**: `$pluginFolder`, `$pluginsCache`
- **Methods**: `GetPlugin($name)` — loads plugin PHP file from registered folders; `AddPluginFolder($folder, $args)` — registers plugin directory

### `Terminal.php` (384 lines)
- **Namespace**: `Razy`
- **Purpose**: CLI interface with color and formatting
- **Color constants**: ANSI codes (RED, GREEN, BLUE, YELLOW, etc.)
- **Methods**: `read()` (STDIN), `displayHeader()`, `getScreenWidth()`, `length()` (escape-aware), `logging()`, `run($callback, $args, $parameters)`, `saveLog()`
- **Format syntax**: `{@ c:red,b:blue,s:bi }` for color/background/style

### `ThreadManager.php` (431 lines)
- **Namespace**: `Razy`
- **Purpose**: Thread pool manager with two modes
- **Modes**: Inline (sync callable) and Process (async shell command)
- **Methods**: `spawn()`, `spawnPHPCode()` (base64 encoding), `spawnPHPFile()` (temp file), `spawnProcessCommand()`, `await($id, $timeout)`, `joinAll()`
- **Max concurrency**: 4, with queue system

### `Thread.php` (~180 lines)
- **Namespace**: `Razy`
- **Purpose**: Thread value object
- **Status**: `PENDING` → `RUNNING` → `COMPLETED` / `FAILED`
- **Mode**: `INLINE` / `PROCESS`
- **Properties**: id, status, mode, result, error, timestamps, process handle, pipes, stdout, stderr, exitCode, command

### `HashMap.php` (~180 lines)
- **Namespace**: `Razy`
- **Purpose**: Ordered map implementing `ArrayAccess`, `Iterator`, `Countable`
- **Hash types**: `o:` (object hash), `c:` (custom), `i:` (internal GUID)
- **Methods**: `push()`, `remove()`, `has()`, `getGenerator()`

### `YAML.php` (650 lines)
- **Namespace**: `Razy`
- **Purpose**: Native YAML parser/dumper (no `ext-yaml` dependency)
- **Static methods**: `parse()`, `parseFile()`, `dump()`, `dumpFile()`
- **Parser features**: mappings, sequences, scalars, comments, multi-line (`|` and `>`), flow collections, anchors/aliases
- **Dumper**: Recursive structure serialization

### `Configuration.php` (~200 lines)
- **Namespace**: `Razy`
- **Extends `Collection`**
- **Purpose**: Multi-format configuration file handler
- **Formats**: PHP, JSON, INI, YAML (auto-detected by extension)
- **Features**: Change tracking with `save()`, format conversion via `saveAsPHP/INI/JSON/YAML()`

### `Error.php` (~170 lines)
- **Namespace**: `Razy`
- **Extends `Exception`**
- **Static Methods**: `SetDebug()`, `Show404()`, `ShowException()` (loads PHAR asset templates), `DebugConsoleWrite()`
- **Properties**: `$heading`, `$debugMessage`, static `$debug`, `$cached`, `$debugConsole`

### `XHR.php` (212 lines)
- **Namespace**: `Razy`
- **Purpose**: AJAX response helper with CORS/CORP support
- **Methods**: `allowOrigin()`, `corp()`, `data()`, `send($success, $message)`, `set($name, $dataset)`
- **Response format**: `{result, hash, timestamp, response, message, params}`

### `SSE.php` (~180 lines)
- **Namespace**: `Razy`
- **Purpose**: Server-Sent Events helper
- **Methods**: `start()`, `send($data, $event, $id)`, `comment()`, `proxy($url, $headers, $method, $body, $timeout)`, `close()`
- **Proxy**: cURL with `CURLOPT_WRITEFUNCTION` for streaming relay

### `DOM.php` (~370 lines)
- **Namespace**: `Razy`
- **Purpose**: HTML DOM builder
- **Methods**: `saveHTML()`, `addClass()`, `append()`, `setAttribute()`, `setDataset()`, `getHTMLValue()`
- **Properties**: `$attribute`, `$className`, `$dataset`, `$isVoid`, `$nodes`, `$tag`, `$text`
- **Subclasses**: `DOM\Select.php`, `DOM\Input.php`

### `Mailer.php` (625 lines)
- **Namespace**: `Razy`
- **Purpose**: SMTP email sender with direct socket connection
- **SSL/TLS support**: SSLv2 through TLSv1.2
- **Methods**: `from()`, `cc()`, `bcc()`, `replyTo()`, `addAttachment()`, `setHeader()`, `send()`
- **Features**: Multi-part MIME, attachment encoding, SMTP authentication

### `SimpleSyntax.php` (~100 lines)
- **Namespace**: `Razy`
- **Purpose**: Expression parser for delimited/parenthesized syntax
- **Static Methods**:
  - `ParseSyntax()` — Split by delimiters respecting quotes/parens/brackets
  - `ParseParens()` — Extract parenthesized groups into nested arrays
- **Used by**: Version compare (`vc()`), Statement update syntax, WhereSyntax, TableJoinSyntax

### `Profiler.php` (~200 lines)
- **Namespace**: `Razy`
- **Purpose**: Performance profiling
- **Tracks**: memory, CPU time, execution time, defined functions, declared classes
- **Methods**: `checkpoint($label)`, `report()`, `reportTo($label)`

### `FileReader.php` (~90 lines)
- **Namespace**: `Razy`
- **Purpose**: Multi-file line reader using `SplFileObject` generator
- **Methods**: `fetch()` → `?string`, `append()`, `prepend()`
- **Used by**: Template `INCLUDE BLOCK` directives

### `SimplifiedMessage.php` (~170 lines)
- **Namespace**: `Razy`
- **Purpose**: STOMP-like message protocol
- **Format**: `COMMAND\r\n headers\r\n\r\n body\0\r\n`
- **Static methods**: `Fetch()`, `Encode()`, `Decode()`

---

## 13. Plugin Architecture

### Plugin Registration Pattern
- **Trait**: `PluginTrait` (shared by Template, Collection, Statement, FlowManager)
- **Plugin folders** registered at bootstrap via `AddPluginFolder()`
- **Lookup**: Plugin name → file in registered folders → cached in `$pluginsCache`

### Plugin Categories

| Subsystem | Plugin Location | Types |
|-----------|----------------|-------|
| Template | `src/plugins/Template/` | `modifier.*`, `function.*` |
| Collection | `src/plugins/Collection/` | `filter.*`, `processor.*` |
| FlowManager | `src/plugins/FlowManager/` | Flow subclasses (Custom, Fetch, Validate, etc.) |
| Statement | `src/plugins/Statement/` | Builder plugins (Max, etc.) |

### Template Plugins (14 files)
- **Modifiers** (9): addslashes, capitalize, alphabet, gettype, lower, upper, trim, join, nl2br
- **Functions** (5): template, repeat, if, each, def

### Collection Plugins (4 files)
- **Processors** (3): int, float, trim
- **Filters** (1): istype

### FlowManager Plugins (9 files)
- Custom, Fetch, FetchGreatest, FormWorker, NoEmpty, Password, Regroup, Unique, Validate

### Statement Plugins (1 file)
- Max — Maximum statement limit

---

## 14. CLI Terminal System

### Terminal Commands (22 files in `src/system/terminal/`)

| Command File | Purpose |
|-------------|---------|
| `bridge.inc.php` | CLI bridge for cross-process API calls |
| `build.inc.php` | Build PHAR package |
| `compose.inc.php` | Compose prerequisites (Packagist packages) |
| `generate-llm-docs.inc.php` | Generate LLM-CAS documentation |
| `help.inc.php` | Display help information |
| `inspect.inc.php` | Inspect distributions and modules |
| `install.inc.php` | Install modules from repositories |
| `link.inc.php` | Link external module source |
| `pack.inc.php` | Pack module into PHAR |
| `publish.inc.php` | Publish module to repository |
| `remove.inc.php` | Remove installed module |
| `rewrite.inc.php` | Regenerate .htaccess rules |
| `routes.inc.php` | Display registered routes |
| `run.inc.php` | Run module script |
| `runapp.inc.php` | Run distributor interactively |
| `search.inc.php` | Search module repositories |
| `set.inc.php` | Set configuration values |
| `sync.inc.php` | Sync modules |
| `unlink.inc.php` | Unlink external module source |
| `validate.inc.php` | Validate module structure |
| `version.inc.php` | Display version info |

### Console Subdirectory (`terminal/console/`)
- Additional console-specific command files

---

## 15. Tool Subsystem

### `Tool\LLMCASGenerator.php` (696 lines)
- **Namespace**: `Razy\Tool`
- **Purpose**: Generates LLM-CAS (Code Assistant System) documentation at three levels:
  1. Root framework level (`LLM-CAS.md`)
  2. Distribution level (`llm-cas/{dist_code}.md`)
  3. Module level (`llm-cas/{dist_code}/{module}.md`)
- **Uses**: Template Engine with `.tpl` files from `src/asset/prompt/`
- **Methods**: `generate()`, `scanDistributions()`, `generateRootCAS()`, `generateDistributionCAS()`, `generateModuleCAS()`

### `Tool\ModuleMetadataExtractor.php` (~170 lines)
- **Namespace**: `Razy\Tool`
- **Purpose**: Extract module metadata without full bootstrap (for documentation)
- **Extracts**: package.json data, API commands (via regex), lifecycle events, dependencies
- **Methods**: `extract()`, `pretty()`

---

## 16. Cross-Cutting Patterns

### 1. Closure-Based Method Loading
Controllers use `__call()` to dynamically load methods from external PHP files:
```
controller/ClassName.methodName.php → Closure → Closure::bind($closure, $controller)
```

### 2. Simple Syntax Parsers
Multiple custom DSLs parsed via `SimpleSyntax`:
- **WHERE syntax**: `column=value,column2|=?` → SQL WHERE
- **Update syntax**: `column = ? + 1` → SQL SET
- **Table Join syntax**: `table < joined[column1,column2]` → SQL FROM/JOIN
- **Version syntax**: `^1.2.0 | ~2.0` → boolean version match

### 3. Plugin Trait Pattern
`PluginTrait` provides consistent plugin loading across 4 subsystems:
- Registered folder paths → file discovery → class instantiation → caching

### 4. Module Lifecycle Hooks
Standardized controller lifecycle:
```
__onInit → __onLoad → __onRequire → __onReady → route/API → __onDispose
```

### 5. Multi-Driver Database Abstraction
Driver interface normalizes SQL dialect differences:
- Identifier quoting (backticks vs double quotes)
- LIMIT/OFFSET ordering
- UPSERT syntax (ON DUPLICATE KEY vs ON CONFLICT)
- AUTO_INCREMENT vs SERIAL
- Concatenation (CONCAT vs ||)

### 6. Parameter Resolution Chain (Template)

The template engine uses a **4-level scope hierarchy**. When rendering, `getValue()` resolves upward from the narrowest to the widest scope:

```
Entity (narrowest) → Block → Source → Template (widest)
```

| Scope | Class | Visibility | Typical Use |
|-------|-------|------------|-------------|
| **Entity** | `Entity` | This single entity instance only | Per-row/per-item data |
| **Block** | `Block` | All Entities spawned from this block | Shared column headers |
| **Source** | `Source` | All Blocks/Entities in one template file | Page title, layout data |
| **Template** | `Template` | All Sources, Blocks, and Entities | Site name, global config |

**Key rules:**
- Narrower scope wins when same parameter name exists at multiple levels.
- `assign()` copies the value immediately; `bind()` stores a reference pointer that is not resolved until render time (`output()` / `process()`).

### 7. Distributor Tagging
Distributors identified by `code@tag` (e.g., `siteA@dev`, `siteB@1.0.0`)

### 8. PHAR Support
Entire framework distributable as `Razy.phar` with:
- Asset templates in `src/asset/`
- Plugin discovery in both filesystem and PHAR paths
- Autoloader falls back from filesystem to PHAR

---

## 17. File Index

### Core System (6 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `main.php` | ~170 | Entry point |
| `bootstrap.inc.php` | 949 | Global setup + utilities |
| `Application.php` | 595 | `Razy\Application` |
| `Domain.php` | ~120 | `Razy\Domain` |
| `Distributor.php` | 1137 | `Razy\Distributor` |
| `API.php` | ~45 | `Razy\API` |

### Module System (5 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Module.php` | 864 | `Razy\Module` |
| `Agent.php` | ~280 | `Razy\Agent` |
| `Controller.php` | 478 | `Razy\Controller` |
| `Route.php` | ~60 | `Razy\Route` |
| `ModuleInfo.php` | 349 | `Razy\ModuleInfo` |

### Database System (13 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Database.php` | 533 | `Razy\Database` |
| `Database/Statement.php` | 972 | `Razy\Database\Statement` |
| `Database/Query.php` | ~90 | `Razy\Database\Query` |
| `Database/Driver.php` | ~160 | `Razy\Database\Driver` |
| `Database/Driver/MySQL.php` | ~190 | `Razy\Database\Driver\MySQL` |
| `Database/Driver/PostgreSQL.php` | 224 | `Razy\Database\Driver\PostgreSQL` |
| `Database/Driver/SQLite.php` | 212 | `Razy\Database\Driver\SQLite` |
| `Database/Column.php` | 798 | `Razy\Database\Column` |
| `Database/Table.php` | 746 | `Razy\Database\Table` |
| `Database/WhereSyntax.php` | 624 | `Razy\Database\WhereSyntax` |
| `Database/TableJoinSyntax.php` | 252 | `Razy\Database\TableJoinSyntax` |
| `Database/Preset.php` | ~55 | `Razy\Database\Preset` |
| `Database/Statement/Builder.php` | ~40 | `Razy\Database\Statement\Builder` |

### Database Helpers (2 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Database/Table/TableHelper.php` | 778 | `Razy\Database\Table\TableHelper` |
| `Database/Table/ColumnHelper.php` | 583 | `Razy\Database\Table\ColumnHelper` |

### Template System (6 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Template.php` | 407 | `Razy\Template` |
| `Template/Source.php` | ~270 | `Razy\Template\Source` |
| `Template/Entity.php` | 594 | `Razy\Template\Entity` |
| `Template/Block.php` | 379 | `Razy\Template\Block` |
| `Template/Plugin/TFunction.php` | ~140 | `Razy\Template\Plugin\TFunction` |
| `Template/Plugin/TModifier.php` | ~80 | `Razy\Template\Plugin\TModifier` |
| `Template/Plugin/TFunctionCustom.php` | ~80 | `Razy\Template\Plugin\TFunctionCustom` |

### Collection System (2 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Collection.php` | ~250 | `Razy\Collection` |
| `Collection/Processor.php` | ~80 | `Razy\Collection\Processor` |

### Event System (2 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `EventEmitter.php` | ~65 | `Razy\EventEmitter` |
| `Emitter.php` | ~45 | `Razy\Emitter` |

### FlowManager System (3 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `FlowManager.php` | ~160 | `Razy\FlowManager` |
| `FlowManager/Flow.php` | 358 | `Razy\FlowManager\Flow` |
| `FlowManager/Transmitter.php` | ~35 | `Razy\FlowManager\Transmitter` |

### Authentication (2 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `OAuth2.php` | ~300 | `Razy\OAuth2` |
| `Office365SSO.php` | 400 | `Razy\Office365SSO` |

### Package Management (3 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `PackageManager.php` | 415 | `Razy\PackageManager` |
| `RepositoryManager.php` | 472 | `Razy\RepositoryManager` |
| `RepoInstaller.php` | 768 | `Razy\RepoInstaller` |

### Support Classes (15 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `PluginTrait.php` | ~65 | Trait |
| `Terminal.php` | 384 | `Razy\Terminal` |
| `ThreadManager.php` | 431 | `Razy\ThreadManager` |
| `Thread.php` | ~180 | `Razy\Thread` |
| `Crypt.php` | ~60 | `Razy\Crypt` |
| `HashMap.php` | ~180 | `Razy\HashMap` |
| `YAML.php` | 650 | `Razy\YAML` |
| `Configuration.php` | ~200 | `Razy\Configuration` |
| `Error.php` | ~170 | `Razy\Error` |
| `XHR.php` | 212 | `Razy\XHR` |
| `SSE.php` | ~180 | `Razy\SSE` |
| `DOM.php` | ~370 | `Razy\DOM` |
| `Mailer.php` | 625 | `Razy\Mailer` |
| `SimpleSyntax.php` | ~100 | `Razy\SimpleSyntax` |
| `Profiler.php` | ~200 | `Razy\Profiler` |
| `FileReader.php` | ~90 | `Razy\FileReader` |
| `SimplifiedMessage.php` | ~170 | `Razy\SimplifiedMessage` |

### Tools (2 files)
| File | Lines | Key Class |
|------|-------|-----------|
| `Tool/LLMCASGenerator.php` | 696 | `Razy\Tool\LLMCASGenerator` |
| `Tool/ModuleMetadataExtractor.php` | ~170 | `Razy\Tool\ModuleMetadataExtractor` |

### Plugins (28 files)
- Template: 14 (9 modifiers + 5 functions)
- Collection: 4 (3 processors + 1 filter)
- FlowManager: 9 (flow subclasses)
- Statement: 1 (Max)

### Terminal Commands (22 files)
- bridge, build, compose, generate-llm-docs, help, inspect, install, link, pack, publish, remove, rewrite, routes, run, runapp, search, set, sync, unlink, validate, version

---

**Total estimated source lines**: ~15,000+ lines of PHP across the framework library
