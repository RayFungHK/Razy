# Optimization Suggestions for Razy Framework

**Purpose**: Issues discovered during module testing and recommended optimizations for Razy developers.

**Source**: Testing sessions February 9-10, 2026 - Event Demo, Event Receiver, Route Demo modules, CLI runapp command

---

## Priority Legend

| Priority | Meaning |
|----------|---------|
| 🔴 **Critical** | Blocks functionality, causes confusion |
| 🟠 **High** | Significant friction, workaround available |
| 🟡 **Medium** | Inconvenient but manageable |
| 🟢 **Low** | Enhancement/polish |

---

## Event System Issues

### ✅ Issue E1: listen() Return Value Enhancement [IMPLEMENTED]

**Location**: `src/library/Razy/Module.php` → `listen()` method

**Previous State**: `listen()` returned `$this` for fluent chaining.

**New Behavior**: `listen()` now returns `bool` indicating whether the target module is currently loaded:

```php
// Returns true if demo/event_demo module is loaded
$isLoaded = $agent->listen('demo/event_demo:user_registered', function($data) {
    return ['received' => $data];
});

if (!$isLoaded) {
    // Target module not loaded - listener registered but may never fire
    // This is useful for optional dependencies
}
```

**For Array of Events**:
```php
$results = $agent->listen([
    'demo/event_demo:user_registered' => fn($data) => handleUser($data),
    'demo/event_demo:order_placed' => fn($data) => handleOrder($data),
]);
// $results = ['demo/event_demo:user_registered' => true, 'demo/event_demo:order_placed' => true]
```

**Key Points**:
- Listener is **always registered** regardless of return value
- `true` = target module loaded, listener will fire when event triggered
- `false` = target module not loaded yet (may load later, or may never load)
- Prevents breaking when optional modules aren't loaded

---

### ✅ Issue E2: Confusing API - trigger() vs propagate() [RESOLVED]

**Problem**: Controller has private `$module` property, so `$this->getModule()->propagate()` fails. Only `$this->trigger()` works, but this isn't immediately obvious.

**Resolution**: Renamed `Module::propagate()` to `Module::createEmitter()` for clarity.

**API Flow**:
```
Controller::trigger() → Module::createEmitter() → EventEmitter::resolve() → Module::fireEvent() → Listeners
```

**Correct Usage**:
```php
// ✅ From controller - use trigger()
$emitter = $this->trigger('event_name');
$emitter->resolve($data);
$responses = $emitter->getAllResponse();
```

**Note**: `trigger()` is the preferred method from controllers. `createEmitter()` is the underlying Module method.

---

### 🟡 Issue E3: Event Name Format Not Validated Early

**Problem**: Invalid event name format (e.g., `'user_registered'` without vendor prefix) silently fails with no listeners found, instead of throwing a helpful error.

**Current Behavior**: No listeners match, empty response.

**Suggested Fix**: Validate event name format in `listen()` and throw descriptive error:
```
Error: Event name must be in format 'vendor/module:event_name', got 'user_registered'
```

---

## Route System Issues

### ✅ Issue R1: Leading Slash Required But Not Enforced [RESOLVED]

**Location**: `src/library/Razy/Module.php` → `addRoute()` method

**Resolution**: Auto-prepend leading slash if missing.

**New Behavior**:
```php
// ✅ Both now work - leading slash is auto-prepended
$agent->addRoute('route_demo/user/(:d)', 'user');  // Becomes: /route_demo/user/(:d)
$agent->addRoute('/route_demo/user/(:d)', 'user'); // Unchanged
```

---

### ℹ️ Issue R2: Parentheses Required for Parameter Capture [NOT A BUG]

**Location**: `src/library/Razy/Module.php` → `addRoute()` method

**Status**: This is intentional behavior, not a bug.

**Explanation**: Tokens without parentheses (`:d`, `:a`, etc.) are used for **matching only without capturing**. This is useful for SEO-friendly URLs:

```php
// ✅ Valid: :a matches the slug but doesn't capture, (:d) captures the ID
$agent->addRoute('/article/:a-(:d)', 'article');
// URL: /article/my-article-title-123
// Only "123" is passed to handler, "my-article-title" is matched but discarded

// ✅ Capture the parameter
$agent->addRoute('/user/(:d)', 'user');
// URL: /user/42 -> handler receives "42"

// ✅ Match but don't capture
$agent->addRoute('/user/:d', 'user');
// URL: /user/42 -> handler receives no parameters (matched but not captured)
```

---

### ✅ Issue R3: Multiple Consecutive :a Tokens Fail (RESOLVED)

**Problem**: Routes with multiple `:a` tokens on consecutive segments generated inefficient regex patterns with duplicated character classes.

**Root Cause**: Bug in `Distributor.php` regex callback - using `$regex .= '+'` instead of just `'+'`:
```php
// BUG: This caused pattern duplication
return $regex . ((0 !== strlen($matches[3] ?? '')) ? $matches[3] : $regex .= '+');
// Generated: [^\/]+[^\/]+ instead of [^\/]+
```

**Resolution**: Fixed in `Distributor::matchRoute()`:
```php
// FIXED: Use '+' directly
return $regex . ((0 !== strlen($matches[3] ?? '')) ? $matches[3] : '+');
// Now generates: [^\/]+ correctly
```

**Test Result**:
```php
// ✅ Now works correctly
$agent->addRoute('/demo/(:a)/(:a)', 'handler');
// Regex: /^(\/demo\/([^\/]+)\/([^\/]+)\/)((?:.+)?)/
```

---

### ✅ Issue R4: Route Debugging Difficult (RESOLVED)

**Problem**: When routes don't match, there's no way to see what routes are registered or why matching failed.

**Resolution**: Added CLI command `php Razy.phar routes <distributor> [options]`:

```bash
# List all routes
php Razy.phar routes mysite

# Filter by module
php Razy.phar routes mysite demo/route_demo

# Show generated regex patterns
php Razy.phar routes mysite -r

# Verbose output
php Razy.phar routes mysite -v
```

**Output includes**:
- All registered routes grouped by module
- Route type: `[lazy]`, `[std]`, `[cli]`
- Linked closure path
- Missing closure detection
- Generated regex pattern (with `-r` flag)

---

## Developer Experience Issues

### ✅ Issue D1: Module Path Confusion (RESOLVED)

**Problem**: Documentation sometimes suggests `modules/` subfolder inside module directory, but correct structure is flat.

**Correct Structure**:
```
sites/mysite/demo/my_module/module.php
sites/mysite/demo/my_module/default/package.php
sites/mysite/demo/my_module/default/controller/my_module.php
```

**Resolution**:
1. **Documentation Fixed**: Corrected all documentation files (readme.md, LLM-CAS.md, CROSS-MODULE-API-USAGE.md, CROSS-MODULE-API-TESTING.md) to show correct flat structure
2. **Validation Command**: New `php Razy.phar validate <distributor> [module] [-g]` command to detect and report structure issues
3. **Auto-Generate**: Validate command with `--generate` flag creates missing files with correct templates

---

### ✅ Issue D2: Controller Class Naming Not Enforced (RESOLVED)

**Problem**: If controller class name doesn't match expected pattern, module loads but routes fail silently.

**Expected**: `{module}.php` → class `{Module}` (PascalCase of module name)

**Resolution**: Added descriptive error message in `Module::initialize()` when main controller file is not found:
```php
throw new Error(
    "Main controller file not found for module '{$moduleCode}'.\n" .
    "Expected file: {$controllerPath}\n" .
    "The controller filename must match the module class name (e.g., 'MyModule' class requires 'MyModule.php')."
);
```

**Additional**: Added `strict` mode in `dist.php` configuration to enforce file validation:
```php
return [
    'dist' => 'mysite',
    'strict' => true, // Throws error for missing closure files
    // ...
];
```

---

### 🟡 Issue D3: No Hot Reload for Development

**Problem**: Must restart PHP server after every code change.

**Suggested Feature**: Add development mode with file watching and auto-reload, similar to:
- `php razy serve --watch`
- Or integration with tools like `watchexec`

---

## Documentation Issues

### 🟠 Issue DOC1: API Reference vs Working Examples Gap

**Problem**: `docs/usage/Razy.*.md` files describe APIs but don't show complete working examples. Testing revealed several undocumented patterns.

**Suggested Fix**: 
1. Add "Complete Example" section to each usage doc
2. Link to reference modules (event_demo, route_demo, thread_demo) as working examples
3. Add `@example` tags in source code pointing to working demos

---

### 🟡 Issue DOC2: No Troubleshooting Section

**Problem**: Common issues like "route not matching" or "event not firing" have non-obvious causes.

**Suggested Fix**: Create `docs/guides/TROUBLESHOOTING.md` with:
- Route not matching? → Check leading slash, parentheses
- Event not firing? → Check vendor prefix, use inline closures
- Module not loading? → Check dist.php registration
- Thread process failing? → Check Windows shell escaping

---

## Thread System Issues

### 🟡 Issue T1: Windows Shell Escaping Strips Quotes in Process Mode

**Location**: `src/library/Razy/ThreadManager.php` → `buildCommand()` method

**Problem**: When using process mode on Windows with PHP `-r` arguments that contain quoted strings, the Windows cmd.exe shell strips or mangles the quotes, causing parse errors.

**Current Behavior**:
```php
// This fails on Windows
$thread = $tm->spawn(fn() => null, [
    'command' => 'php',
    'args' => ['-r', 'echo json_encode(["key" => "value"]);']
]);
// Error: syntax error, unexpected identifier "key"
```

**Cause**: `escapeshellarg()` uses double quotes on Windows, but nested double quotes inside the PHP code are stripped by cmd.exe.

**Workaround**: Avoid complex quoting in PHP `-r` code:
```php
// ✅ Works - no nested quotes
'args' => ['-r', 'echo getmypid();']

// ✅ Works - use comma concatenation in echo
'args' => ['-r', 'echo 1, 2, 3;']

// ✅ Works - calculations without strings
'args' => ['-r', '$sum=0;for($i=1;$i<=10;$i++)$sum+=$i;echo $sum;']

// ❌ Fails - nested quotes
'args' => ['-r', 'echo "Hello World";']
'args' => ['-r', 'echo json_encode(["a" => 1]);']
```

**Suggested Fix Options**:
1. Use a temporary PHP file for complex scripts instead of `-r`
2. Use base64 encoding for complex code: `base64_decode('...')`
3. Update `buildCommand()` to handle Windows escaping differently
4. Document limitation clearly in ThreadManager class

---

### 🟢 Issue T2: Backup Folders in Sites Dir Get Scanned

**Location**: `src/library/Razy/Distributor.php` → `scanModule()` method

**Problem**: When `autoload: true` in dist.php, Razy scans all folders in the sites directory. If a backup folder (e.g., `demo_backup_20260209`) exists, its modules get scanned, causing duplicate module errors.

**Workaround**: 
- Keep backup folders outside of `sites/` directory
- Or use naming that doesn't match vendor/module pattern

**Suggested Fix**: 
- Ignore folders matching backup patterns (e.g., `*_backup_*`)
- Or add `ignore` config option in dist.php

---

## CLI System Issues

### ✅ Issue CLI1: runapp Command for Standalone Distributor Testing [IMPLEMENTED]

**Location**: `src/system/terminal/runapp.inc.php` (new file)

**Problem**: Testing distributors required setting up `sites.inc.php` configuration even for quick local testing.

**Resolution**: Added `runapp` command for interactive distributor shell:

```bash
# Start interactive shell for a distributor
php Razy.phar runapp appdemo

# With tag
php Razy.phar runapp mysite@dev
```

**Shell Commands**:
| Command | Description |
|---------|-------------|
| `help` | Show available commands |
| `info` | Show distributor info |
| `routes` | List all registered routes |
| `modules` | List loaded modules |
| `api` | List API modules |
| `run <path>` | Execute a route (e.g., `run /hello/World`) |
| `call <api> <cmd>` | Call API command |
| `clear` | Clear screen |
| `exit` | Exit the shell |

**Features**:
- Bash-like interactive prompt: `[distCode]>` or `[distCode@tag]>`
- Supports piped input for scripting: `@("routes", "exit") | php Razy.phar runapp appdemo`
- No `sites.inc.php` configuration needed

---

### ✅ Issue CLI2: Windows PowerShell Compatibility [RESOLVED]

**Location**: `src/system/terminal/runapp.inc.php`

**Problem**: Two issues with Windows PowerShell:
1. `posix_isatty()` function doesn't exist on Windows, causing fatal errors
2. PowerShell adds UTF-8 BOM (`\xEF\xBB\xBF`) to piped input, causing command parsing failures

**Resolution**:

1. **Cross-platform TTY detection**: Replaced `posix_isatty(STDIN)` with `stream_isatty(STDIN)` (PHP 7.2+):
```php
// Cross-platform TTY detection
$isInteractive = function_exists('stream_isatty') ? stream_isatty(STDIN) : true;
```

2. **BOM stripping**: Strip UTF-8 BOM from input before processing:
```php
// Strip UTF-8 BOM if present (PowerShell adds this to piped input)
if (str_starts_with($input, "\xEF\xBB\xBF")) {
    $input = substr($input, 3);
}
```

3. **Better EOF handling**: Use `fgets(STDIN)` with proper `feof()` checks:
```php
if (feof(STDIN)) {
    break;
}
$input = fgets(STDIN);
if ($input === false || feof(STDIN)) {
    echo "\n";
    break;
}
```

---

## Summary: Recommended Priority Order

### Phase 1: Critical Fixes
1. **R1**: Auto-prepend leading slash to routes
2. ~~**E1**: listen() return value~~ ✅ **IMPLEMENTED** - Returns bool for module load status
3. **R2**: Add warning for tokens without parentheses

### Phase 2: Developer Experience
4. **D1**: Add module path validation with helpful errors
5. **R4**: Add route debugging/listing capability
6. **E3**: Validate event name format early
7. **T1**: Document Windows shell escaping limitation

### Phase 3: Documentation & Polish
8. **DOC1**: Add working examples to API docs
9. **DOC2**: Create troubleshooting guide
10. ~~**E2**: Clarify trigger() vs propagate() in docs~~ ✅ **RESOLVED** - Renamed to `createEmitter()`
11. **D2**: Validate controller class naming

### Phase 4: Enhancement
12. **D3**: Development server with watch mode
13. **R3**: Fix multiple consecutive :a tokens
14. **T2**: Ignore backup folders during module scan

---

## Reference: Test Modules

Working examples demonstrating correct patterns:

| Module | Path | Demonstrates |
|--------|------|--------------|
| event_demo | `test-razy-cli/sites/mysite/demo/event_demo/` | Event firing with `$this->trigger()` |
| event_receiver | `test-razy-cli/sites/mysite/demo/event_receiver/` | Event listening with inline closures |
| route_demo | `test-razy-cli/sites/mysite/demo/route_demo/` | All route token patterns |
| thread_demo | `test-razy-cli/sites/mysite/demo/thread_demo/` | ThreadManager inline/process modes |
| demo/hello | `playground/sites/appdemo/demo/hello/` | Basic routes, runapp testing |
| demo/api | `playground/sites/appdemo/demo/api/` | API module commands |

---

## Feedback

Add issue reports or suggestions to this document following the format above. Include:
- Priority level
- Location in codebase
- Current vs expected behavior
- Workaround (if any)
- Suggested fix
