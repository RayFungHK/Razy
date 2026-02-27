# Razy Framework - Class Library Reference

**Purpose**: Complete overview of all classes in `src/library/Razy/` with example task checklists.

---

## Class Categories

### Core Classes (Framework Foundation)
| Class | File | Purpose |
|-------|------|---------|
| `Application` | Application.php | Bootstrap, domain matching, autoloader registration |
| `Distributor` | Distributor.php | Site distribution manager, module loader |
| `Domain` | Domain.php | FQDN parsing and domain configuration |
| `Module` | Module.php | Module lifecycle, route binding, event management |
| `ModuleInfo` | ModuleInfo.php | Module metadata (code, version, path, author) |
| `Agent` | Agent.php | Module API for route/event/API registration |
| `Controller` | Controller.php | Base controller with lifecycle hooks |

### Data & Configuration
| Class | File | Purpose |
|-------|------|---------|
| `Configuration` | Configuration.php | Config file loader (PHP/JSON/INI/YAML), extends Collection |
| `Collection` | Collection.php | Enhanced ArrayObject with filter syntax plugins |
| `Collection\Processor` | Collection/Processor.php | Collection filter result processor |
| `HashMap` | HashMap.php | Object hash-based ordered collection |
| `YAML` | YAML.php | Native YAML parser/dumper (no ext required) |

### Database Layer
| Class | File | Purpose |
|-------|------|---------|
| `Database` | Database.php | PDO wrapper, connection management |
| `Database\Statement` | Database/Statement.php | Query builder with plugin support |
| `Database\Query` | Database/Query.php | Query result wrapper |
| `Database\WhereSyntax` | Database/WhereSyntax.php | WHERE clause builder |
| `Database\TableJoinSyntax` | Database/TableJoinSyntax.php | JOIN clause builder |
| `Database\Column` | Database/Column.php | Column definition |
| `Database\Table` | Database/Table.php | Table schema operations |
| `Database\Preset` | Database/Preset.php | Predefined query patterns |
| `Database\Statement\Builder` | Database/Statement/Builder.php | Statement builder plugin |
| `Database\Table\Alter` | Database/Table/Alter.php | ALTER TABLE operations |

### ORM Layer
| Class / Trait | File | Purpose |
|---------------|------|---------|
| `ORM\Model` | ORM/Model.php | Active-record base: CRUD, scopes, events, relationships |
| `ORM\ModelQuery` | ORM/ModelQuery.php | Fluent query builder for Model (where, order, paginate, chunk) |
| `ORM\ModelCollection` | ORM/ModelCollection.php | Typed collection of Model instances with map/filter/pluck |
| `ORM\Paginator` | ORM/Paginator.php | Pagination result with URL generation & JSON serialisation |
| `ORM\SoftDeletes` | ORM/SoftDeletes.php | Trait adding soft-delete / restore / trash scoping |
| `ORM\Relation\Relation` | ORM/Relation/Relation.php | Abstract base for all relationship types |
| `ORM\Relation\HasOne` | ORM/Relation/HasOne.php | One-to-one relationship |
| `ORM\Relation\HasMany` | ORM/Relation/HasMany.php | One-to-many relationship |
| `ORM\Relation\BelongsTo` | ORM/Relation/BelongsTo.php | Inverse one-to-one / one-to-many |
| `ORM\Relation\BelongsToMany` | ORM/Relation/BelongsToMany.php | Many-to-many with pivot (attach/detach/sync) |

### Template Engine
| Class | File | Purpose |
|-------|------|---------|
| `Template` | Template.php | Template engine with plugin system |
| `Template\Source` | Template/Source.php | Loaded template with parameter assignment |
| `Template\Block` | Template/Block.php | Template block parser (START/END/WRAPPER) |
| `Template\Entity` | Template/Entity.php | Block instance with data binding |
| `Template\Plugin\TFunction` | Template/Plugin/TFunction.php | Template function plugin base |
| `Template\Plugin\TFunctionCustom` | Template/Plugin/TFunctionCustom.php | Custom function plugin |
| `Template\Plugin\TModifier` | Template/Plugin/TModifier.php | Value modifier plugin base |

### Event & Communication
| Class | File | Purpose |
|-------|------|---------|
| `EventEmitter` | EventEmitter.php | Event broadcast and listener aggregation |
| `Emitter` | Emitter.php | Cross-module API caller proxy |
| `API` | API.php | API request factory |
| `XHR` | XHR.php | AJAX response builder with CORS |
| `SSE` | SSE.php | Server-Sent Events streaming |

### Security & Crypto
| Class | File | Purpose |
|-------|------|---------|
| `Crypt` | Crypt.php | AES-256-CBC encryption/decryption |
| `OAuth2` | OAuth2.php | OAuth2 authentication flow |
| `Office365SSO` | Office365SSO.php | Microsoft 365 SSO integration |

### Routing & Request
| Class | File | Purpose |
|-------|------|---------|
| `Route` | Route.php | Route definition with data container |
| `SimpleSyntax` | SimpleSyntax.php | Simple expression parser |

### Workflow & Threading
| Class | File | Purpose |
|-------|------|---------|
| `FlowManager` | FlowManager.php | Workflow pipeline with plugin support |
| `FlowManager\Flow` | FlowManager/Flow.php | Single workflow step |
| `FlowManager\Transmitter` | FlowManager/Transmitter.php | Flow data transmitter |
| `Thread` | Thread.php | Thread/process representation |
| `ThreadManager` | ThreadManager.php | Async thread pool manager |

### Utilities
| Class | File | Purpose |
|-------|------|---------|
| `Error` | Error.php | Framework exception class |
| `FileReader` | FileReader.php | Template file reader with prepend support |
| `Profiler` | Profiler.php | Performance profiling |
| `Terminal` | Terminal.php | CLI interaction |
| `Mailer` | Mailer.php | Email sending utility |
| `DOM` | DOM.php | HTML DOM manipulation |
| `DOM\Input` | DOM/Input.php | Form input element |
| `DOM\Select` | DOM/Select.php | Select dropdown element |
| `SimplifiedMessage` | SimplifiedMessage.php | Message formatting |
| `RepoInstaller` | RepoInstaller.php | Install modules from repositories |
| `RepositoryManager` | RepositoryManager.php | Repository index management and module search |
| `PackageManager` | PackageManager.php | Module package management |

### Traits
| Trait | File | Purpose |
|-------|------|---------|
| `PluginTrait` | PluginTrait.php | Plugin loading for Template/Collection/FlowManager/Statement |

### Tools (Development)
| Class | File | Purpose |
|-------|------|---------|
| `Tool\LLMCASGenerator` | Tool/LLMCASGenerator.php | Generate LLM-CAS documentation |
| `Tool\ModuleMetadataExtractor` | Tool/ModuleMetadataExtractor.php | Extract module metadata for docs |

---

## Example Task Checklists

### Checklist 1: Create Basic Module with Route

```markdown
- [ ] Create module folder: `sites/{dist}/{vendor}/{module}/`
- [ ] Create `module.php` with module_code
- [ ] Create `default/package.php` with version
- [ ] Create `default/controller/{module}.php` extending Controller
- [ ] Implement `__onInit(Agent $agent)` → return PLUGIN_LOADED
- [ ] Implement `main()` method for default route
- [ ] Register in `dist.php`: `'vendor/module' => ['autoload' => false]`
- [ ] Test: Start server, hit `localhost:8080/{module}/`
```

**Key Classes**: `Controller`, `Agent`, `Module`

---

### Checklist 2: Add URL Parameter Routes

```markdown
- [ ] In `__onInit()`, get agent: `$agent = $this->getAgent()`
- [ ] Add route with capture: `$agent->addRoute('/module/user/(:d)', 'user')`
  - ✅ Leading slash required
  - ✅ Parentheses for capture: `(:d)` not `:d`
- [ ] Create handler method with typed param: `public function user(int $id): string`
- [ ] Return response (string or JSON)
- [ ] Test with valid/invalid parameters
```

**Key Classes**: `Agent`, `Route`, `Controller`

**Token Reference**:
| Token | Matches | Example |
|-------|---------|---------|
| `:a` | Any chars | `abc-123` |
| `:d` | Digits | `456` |
| `:w` | Alpha | `hello` |
| `{n}` | Exact length | `(:a{6})` = 6 chars |
| `{m,n}` | Range | `(:a{3,10})` = 3-10 chars |
| `:[regex]` | Custom | `(:[a-z0-9-]+)` |

---

### Checklist 3: Implement Event System

```markdown
**Firing Module:**
- [ ] Create event firing method in controller
- [ ] Use `$this->trigger('event_name')` → NOT getModule()->propagate()
- [ ] Chain: `->resolve()` → `->getAllResponse()`
- [ ] Return aggregated responses

**Listening Module:**
- [ ] In `__onInit()`, register listener with inline closure:
      `$agent->listen('vendor/module:event_name', function($data) { ... })`
- [ ] Event format: `'vendor/module:event_name'`
- [ ] Return data from closure (will appear in responses)
- [ ] ⚠️ Use inline closures, NOT file-based handlers
```

**Key Classes**: `EventEmitter`, `Agent`, `Controller`

---

### Checklist 4: Template Rendering

```markdown
- [ ] Create template file: `default/view/mytemplate.tpl`
- [ ] Use block syntax: `<!-- START BLOCK: items -->...<!-- END BLOCK: items -->`
- [ ] In controller, load template: `$source = $this->loadTemplate('mytemplate')`
- [ ] Assign parameters: `$source->assign('title', 'My Title')`
- [ ] Assign array: `$source->assign(['key1' => 'val1', 'key2' => 'val2'])`
- [ ] Get block: `$block = $source->getBlock('items')`
- [ ] For loops: `$block->newEntity()->assign('item', $value)`
- [ ] Output: `return $source->output()`
```

**Key Classes**: `Template`, `Template\Source`, `Template\Block`, `Template\Entity`

**Template Syntax**:
```html
{$variable}                     <!-- Parameter -->
{$object.property}              <!-- Nested access -->
{$value|modifier}               <!-- Apply modifier -->
{@function param="value"}       <!-- Template function -->
<!-- START BLOCK: name -->      <!-- Repeatable block -->
<!-- END BLOCK: name -->
<!-- INCLUDE BLOCK: path.tpl --> <!-- Include file -->
```

---

### Checklist 5: Database Operations

```markdown
- [ ] Get database instance: `$db = Database::GetInstance('mydb')`
- [ ] Connect: `$db->connect($host, $user, $pass, $dbname)`
- [ ] Create statement: `$stmt = (new Statement($db))->from('table')`
- [ ] Add WHERE: `$stmt->where('column=:param')->set('param', $value)`
- [ ] Execute: `$query = $db->execute($stmt)`
- [ ] Fetch: `$rows = $query->fetchAll()` or `$row = $query->fetch()`
- [ ] Insert: `$stmt->insert(['col1' => $val1])`
- [ ] Update: `$stmt->update(['col1' => $val1])->where('id=:id')`
- [ ] Delete: `$stmt->delete()->where('id=:id')`
```

**Key Classes**: `Database`, `Database\Statement`, `Database\Query`, `Database\WhereSyntax`

---

### Checklist 5b: ORM / Model Operations

```markdown
- [ ] Define model: extend `Razy\ORM\Model`, set `$table` and `$primaryKey`
- [ ] Get query builder: `$query = User::query($db)`
- [ ] Add WHERE (Simple Syntax): `$query->where('name=:n', ['n' => $name])`
- [ ] Ordering / limit: `$query->orderBy('created_at', 'DESC')->limit(10)`
- [ ] Fetch all: `$users = $query->get()` → `ModelCollection`
- [ ] Fetch single: `$user = $query->first()` or `User::find($db, $id)`
- [ ] Create: `$user = User::create($db, ['name' => 'Ray', 'email' => '...'])`
- [ ] Update: `$user->name = 'New'; $user->save($db)`
- [ ] Delete: `$user->delete($db)` or `User::destroy($db, $id)`
- [ ] Paginate: `$page = $query->paginate(1, 15)` → `Paginator`
- [ ] Chunk: `$query->chunk(100, function ($chunk) { ... })`
- [ ] Eager load: `$query->with('posts', 'profile')->get()`
- [ ] Relationships: define `posts()` returning `$this->hasMany(Post::class, 'user_id')`
```

**Key Classes**: `ORM\Model`, `ORM\ModelQuery`, `ORM\ModelCollection`, `ORM\Paginator`

**WHERE Simple Syntax** (same as Database layer):
```php
'column=:param'              // Equal
'column!=:param'             // Not equal
'column>:param'              // Greater
'column*=:param'             // LIKE %value%
'col1=:p1&col2=:p2'          // AND
'col1=:p1|col2=:p2'          // OR
```

**WHERE Syntax**:
```php
'column=:param'              // Equal
'column!=:param'             // Not equal
'column>:param'              // Greater
'column<:param'              // Less
'column*=:param'             // LIKE %value%
'column^=:param'             // LIKE value%
'column$=:param'             // LIKE %value
'col1=:p1&col2=:p2'          // AND
'col1=:p1|col2=:p2'          // OR
```

---

### Checklist 6: Cross-Module API

```markdown
**Provider Module:**
- [ ] In `__onInit()`, register API: `$agent->addAPICommand('methodName', 'path/to/handler')`
- [ ] Or use array: `$agent->addAPICommand(['method1' => 'path1', 'method2' => 'path2'])`
- [ ] Create handler file returning closure
- [ ] Handler receives caller ModuleInfo

**Consumer Module:**
- [ ] Check availability: `$this->handshake('vendor/provider')`
- [ ] Get emitter: `$emitter = $this->api('vendor/provider')`
- [ ] Call method: `$result = $emitter->methodName($arg1, $arg2)`
- [ ] Handle null if module unavailable
```

**Key Classes**: `Agent`, `API`, `Emitter`, `Controller`

---

### Checklist 7: XHR/AJAX Response

```markdown
- [ ] Get XHR instance: `$xhr = $this->xhr()`
- [ ] Set CORS: `$xhr->allowOrigin('*')` or specific origin
- [ ] Set data: `$xhr->data(['status' => 'ok', 'data' => $result])`
- [ ] Optional CORP: `$xhr->corp(XHR::CORP_CROSS_ORIGIN)`
- [ ] Output: `return $xhr->output()`
```

**Key Classes**: `XHR`, `Controller`

---

### Checklist 8: Configuration Management

```markdown
- [ ] Get config: `$config = $this->getModuleConfig()`
- [ ] Read value: `$value = $config['key']` or `$config['nested']['key']`
- [ ] Set value: `$config['key'] = 'new_value'`
- [ ] Save changes: `$config->save()` (writes to config file)
- [ ] Supports: `.php`, `.json`, `.ini`, `.yaml`
```

**Key Classes**: `Configuration`, `Controller`

---

### Checklist 9: Collection Filtering

```markdown
- [ ] Create collection: `$coll = new Collection($array)`
- [ ] Filter syntax: `$processor = $coll('users.*.name')`
- [ ] With filter: `$coll('items.*:is_string')` - filter by type
- [ ] Get values: `$processor->toArray()`
- [ ] Nested path: `$coll('data.users.0.profile.name')`
```

**Key Classes**: `Collection`, `Collection\Processor`

**Filter Syntax**:
```php
'key'              // Direct access
'*'                // All elements
'key.*'            // All in key
'key.*:filterName' // Filter applied
'a.b.c'            // Nested path
'a,b,c'            // Multiple selectors
```

---

### Checklist 10: Server-Sent Events (SSE)

```markdown
- [ ] Create SSE: `$sse = new SSE(3000)` (retry ms)
- [ ] Start stream: `$sse->start()`
- [ ] Send data: `$sse->send($jsonData, 'event_type', 'event_id')`
- [ ] Heartbeat: `$sse->comment('ping')`
- [ ] Loop with sleep for real-time updates
- [ ] Client must use EventSource API
```

**Key Classes**: `SSE`

---

### Checklist 11: FlowManager Workflow

```markdown
- [ ] Create manager: `$fm = new FlowManager()`
- [ ] Create flow: `$flow = new Flow('step_name', function($args) { ... })`
- [ ] Append flow: `$fm->append($flow)`
- [ ] Set storage: `$fm->setStorage('key', $value)`
- [ ] Resolve all: `$success = $fm->resolve($args)`
- [ ] Get transmitter: `$fm->getTransmitter()` for inter-flow data
```

**Key Classes**: `FlowManager`, `FlowManager\Flow`, `FlowManager\Transmitter`

---

### Checklist 12: ThreadManager (Async Tasks)

```markdown
- [ ] Get manager: `$tm = $this->getAgent()->thread()`
- [ ] Create thread: `$thread = $tm->spawn('task_id', $callable)`
- [ ] Check status: `$thread->getStatus()` (pending/running/completed/failed)
- [ ] Get result: `$thread->getResult()` after completion
- [ ] Wait all: `$tm->await()` blocks until all complete
```

**Key Classes**: `ThreadManager`, `Thread`, `Agent`

---

### Checklist 13: Encryption/Decryption

```markdown
- [ ] Encrypt: `$encrypted = Crypt::Encrypt($plaintext, $key)`
- [ ] Encrypt to hex: `$hex = Crypt::Encrypt($plaintext, $key, true)`
- [ ] Decrypt: `$decrypted = Crypt::Decrypt($encrypted, $key)`
- [ ] Uses AES-256-CBC with HMAC verification
```

**Key Classes**: `Crypt`

---

## Plugin System

Four classes support plugins via `PluginTrait`:

| Class | Plugin Folder | Purpose |
|-------|---------------|---------|
| `Template` | `plugins/Template/` | Functions, modifiers |
| `Collection` | `plugins/Collection/` | Filter functions |
| `FlowManager` | `plugins/FlowManager/` | Flow processors |
| `Statement` | `plugins/Statement/` | Query builders |

**Register in Controller**:
```php
$this->registerPluginLoader(
    Controller::PLUGIN_TEMPLATE | 
    Controller::PLUGIN_COLLECTION
);
```

---

## Controller Lifecycle Hooks

| Hook | When Called | Return |
|------|-------------|--------|
| `__onInit(Agent)` | Module scanned, ready to load | `bool` (false = failed) |
| `__onDispatch()` | Before module require verification | `bool` (false = remove from queue) |
| `__onLoad(Agent)` | After all modules loaded | `bool` |
| `__onReady()` | All modules ready | `void` |
| `__onEntry(array)` | Route matched, before execute | `void` |
| `__onRouted(ModuleInfo)` | Other module matched route | `void` |
| `__onScriptReady(ModuleInfo)` | Other module script ready | `void` |
| `__onTouch(ModuleInfo, ver, msg)` | API handshake received | `bool` |
| `__onRequire()` | Module requirement check | `bool` |
| `__onAPICall(ModuleInfo, method, fqdn)` | API access attempt | `bool` (false = refuse) |
| `__onDispose()` | After route/script complete | `void` |
| `__onError(path, Throwable)` | Closure error | `void` |

---

## Quick Method Reference

### Controller Methods
```php
$this->trigger('event')           // Fire event
$this->loadTemplate('file')       // Load template
$this->getModuleConfig()          // Get configuration
$this->api('vendor/module')       // Get API emitter
$this->handshake('vendor/module') // Check API availability
$this->xhr()                      // Create XHR response
$this->getModuleURL()             // Get module base URL
$this->getAssetPath()             // Get asset URL
$this->getDataPath()              // Get data folder path
$this->getModuleInfo()            // Get ModuleInfo
$this->getTemplate()              // Get global Template
$this->getAgent()                 // Get Agent (in __onInit)
$this->goto('/path')              // Redirect
$this->fork('path', ...$args)     // Call internal closure
```

### Agent Methods
```php
$agent->addRoute('/path/(:d)', 'handler')    // Add route
$agent->addLazyRoute($array, $path)          // Add nested routes
$agent->addScript('/path', 'handler')        // Add script route
$agent->addShadowRoute('/path', 'module', 'target')  // Shadow route
$agent->addAPICommand('cmd', 'path')         // Register API
$agent->listen('vendor/mod:event', $closure) // Listen event
$agent->await('vendor/mod', $callable)       // Wait for module
$agent->thread()                             // Get ThreadManager
```
