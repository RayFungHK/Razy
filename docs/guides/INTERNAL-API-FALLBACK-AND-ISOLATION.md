# Internal API Execution with Fallback Mechanism

## Overview

When executing internal API calls between distributors in a single hosting environment, Razy v0.5.4 implements a **smart fallback mechanism** that automatically tries multiple execution methods in order of preference:

1. **CLI Process Isolation** (Safest - separate PHP process)
2. **HTTP Bridge** (Medium - same process but cleaner)
3. **Direct Execution** (Unsafe - in-process, risk of class conflicts)

This ensures your application works on shared hosting where certain PHP functions may be disabled.

---

## Problem: Class Namespace Conflicts

### The Scenario

When different distributors load different versions of the same Composer package:

```
Distribution A:  composer require abc/cccc:^1.0
Distribution B:  composer require abc/cccc:^1.2

// Both define: Abc\Cccc\MyClass
// When Dist A calls Dist B's API in same process:
// PHP Error: Class "Abc\Cccc\MyClass" cannot be redeclared
```

### Why It Happens

PHP's autoloader loads class files and registers the class definition in memory. Once `Abc\Cccc\MyClass` from v1.0 is loaded, attempting to load the same class name from v1.2 causes a fatal error because PHP doesn't allow two class definitions with the same fully-qualified name.

---

## The Solution: Automatic Fallback

### Method 1: CLI Process Isolation (Recommended)

**Requirements:**
- `proc_open()` function enabled on host
- `PHP_BINARY` available

**How it works:**
1. Serialize the API call payload to JSON
2. Spawn a separate PHP process via `proc_open()`
3. The new process loads **only** the target distributor's code
4. Execute the API command in complete isolation
5. Return result as JSON

**Advantages:**
- ✅ Complete class namespace isolation
- ✅ No "already declared" errors
- ✅ Each distributor has its own autoloader state
- ✅ Best for distributed architectures

**Config:**
```php
// dist.php - No special config needed
// Just ensure proc_open is enabled on host
```

**Example:**
```php
// Distribution A calling Distribution B's API
$result = $distributor->executeInternalAPI(
    'module_b',
    'get_data',
    ['id' => 123]
);
// Automatically uses CLI process if available
```

---

### Method 2: HTTP Bridge (Fallback)

**Requirements:**
- `allow_url_fopen` = On (or cURL installed)
- Internal bridge enabled in dist.php

**How it works:**
1. Serialize API call as JSON POST request
2. Send to local HTTP endpoint: `/__internal/bridge`
3. Bridge validates caller (allowlist + HMAC signature)
4. Bridge executes API command
5. Return result as JSON

**Advantages:**
- ✅ Works when `proc_open` is disabled
- ✅ Requests go through normal HTTP handling
- ✅ Can add authentication layer

**Config:**
```php
// dist.php
'internal_bridge' => [
    'enabled' => true,
    'allow' => ['dist_a', 'dist_c'],  // Which distributors can call
    'secret' => 'your-shared-secret',  // HMAC signing key
    'path' => '/__internal/bridge',     // Endpoint path
]
```

**Note:** Still executes in same process, so class conflicts can occur if not careful.

---

### Method 3: Direct Execution (Last Resort)

**When used:**
- When both CLI and HTTP bridge fail/disabled
- Acts as emergency fallback

**Behavior:**
- ⚠️ **Triggers E_USER_WARNING** with detailed message
- Executes command directly in-process
- May cause "class already declared" errors

**Warning Message:**
```
Executing 'module_b::get_data' in-process. This may cause class namespace conflicts 
if distributors use different versions of shared libraries. 
Consider enabling ThreadManager (proc_open) or internal_bridge in dist.php config.
```

---

## Configuration: Enable All Methods

### For Maximum Compatibility

**dist.php:**
```php
<?php
return [
    'dist' => 'my_dist',
    'modules' => [...],
    
    // Enable CLI process execution (Method 1)
    // No config needed - uses default PHP_BINARY and proc_open
    
    // Enable HTTP bridge (Method 2)
    'internal_bridge' => [
        'enabled' => true,
        'allow' => ['other_dist_a', 'other_dist_b'],
        'secret' => getenv('RAZY_BRIDGE_SECRET') ?? 'dev-secret-key',
        'path' => '/__internal/bridge',
    ],
];
```

### Hosting Check

```bash
# Test if proc_open is available
php -r "echo extension_loaded('standard') && !in_array('proc_open', explode(',', ini_get('disable_functions'))) ? 'Available' : 'Disabled';"

# Test if allow_url_fopen is available
php -r "echo ini_get('allow_url_fopen') ? 'Enabled' : 'Disabled';"
```

---

## Usage Examples

### Basic Fallback Execution

```php
// In Distributor A's module
public function getData() {
    // Automatically tries: CLI → HTTP Bridge → Direct
    $result = $this->distributor->executeInternalAPI(
        'api_module',     // Module code in Distributor B
        'fetch_users',    // API command name
        ['limit' => 10],  // Arguments
    );
    
    return $result;  // Mixed type - whatever the API returns
}
```

### With Error Handling

```php
try {
    $data = $this->distributor->executeInternalAPI(
        'user_module',
        'get_user',
        ['id' => $userId]
    );
} catch (Throwable $e) {
    // Handle failure gracefully
    trigger_error("Failed to fetch user: " . $e->getMessage());
    return null;
}
```

### CLI Process with Options

```php
// Optionally pass AUTH_KEY for additional security
$result = $this->distributor->executeInternalAPI(
    'module_name',
    'command_name',
    ['key' => 'value']
);
```

---

## Composer Prefix: Understanding Class Isolation

### What is a Composer Prefix?

A Prefix allows you to **rename** a vendor's namespace when including it. This creates multiple isolated copies of the same package with different class names.

### Example: Solving Version Conflicts

**Without prefix** (breaks):
```json
{
  "require": {
    "abc/cccc": "^1.0",       // Creates Abc\Cccc\MyClass
    "other/lib": "^2.0"       // Also requires Abc\Cccc... v1.2
  }
}
// PHP ERROR: Class already declared
```

**With prefix** (works):
```json
{
  "require": {
    "abc/cccc": "^1.0",
    "other/lib": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "Abc\\Cccc\\": "vendor/abc/cccc/src/",
      "Internal\\V1_0\\Abc\\Cccc\\": "vendor/abc/cccc/src/"
    }
  }
}
```

Now you have:
- `Abc\Cccc\MyClass` (original, v1.0)
- `Internal\V1_0\Abc\Cccc\MyClass` (prefixed copy, also v1.0)

### The Key Insight

**Important:** A prefix doesn't actually allow different versions of the same class to coexist. It merely creates an **alias** with a different name.

```
Original Class File: vendor/abc/cccc/src/MyClass.php
                          ↓
define Abc\Cccc\MyClass
             ↓
ALSO available as: Internal\V1_0\Abc\Cccc\MyClass (alias)
```

Both names point to the **same class definition**. You still cannot have two different versions in memory.

### Real Solution for Razy

Instead of relying on Composer prefixes for version separation, Razy uses **process isolation**:

```
Distribution A Process (v1.0):
  ├─ Abc\Cccc\MyClass (v1.0)
  └─ Only loaded in this process

Distribution B Process (v1.2):
  ├─ Abc\Cccc\MyClass (v1.2)
  └─ Only loaded in this process

Separate processes = Separate PHP memory spaces
```

This is why Razy's **CLI Process Isolation** (Method 1) is the best approach.

### When to Use Composer Prefix

Prefixes ARE useful for:
- Bundling dependencies with your library without conflicts
- Creating "namespaced versions" of third-party code
- Private/internal package distribution

Example: Laravel uses prefixes for its dependencies:
```php
// Laravel bundles PHPMailer as:
use Swift_Message;  // Original
use Illuminate\Mail\Message;  // Wrapped/namespaced
```

---

## Fallback Decision Tree

```
Call executeInternalAPI("module", "command", [args])
  │
  ├─ Is proc_open() available?
  │  ├─ YES → Execute via CLI bridge process
  │  │  ├─ SUCCESS → Return result
  │  │  └─ FAIL → Throw Error
  │  └─ NO → Return null
  │
  └─ Done
```

---

## Performance Considerations

1. **CLI Process** (slowest but safest)
   - ~100-500ms overhead per call
   - Process spawn + bootstrap + execution + exit
   - Use for: Critical cross-distributor calls, different vendor versions

2. **HTTP Bridge** (medium speed)
   - ~20-100ms overhead per call
   - HTTP round-trip within same server
   - Use for: Medium-frequency calls, same vendor versions okay

3. **Direct Execution** (fastest but unsafe)
   - ~1-5ms overhead
   - No isolation, pure in-process
   - Use for: Only when classes cannot conflict

---

## Migration Path for Legacy Code

If you have existing HTTP bridge code:

```php
// Old way (direct HTTP):
$result = $this->executeInternalAPIViaBridge($module, $command, $args);

// New way (CLI bridge):
$result = $this->executeInternalAPI($moduleCode, $command, $args);
```

The new method is backward compatible and automatically chooses the best method.

---

## Troubleshooting

### "proc_open not available"
```
Solution: Ask host to enable or allow in php.ini
Check: phpinfo() → disable_functions empty
```

### "HTTP bridge request failed"
```
Possible causes:
1. allow_url_fopen = Off → Ask host to enable
2. Bridge endpoint not configured → Check dist.php internal_bridge
3. Caller not in allowlist → Add caller_dist to 'allow' array
4. HMAC signature mismatch → Ensure 'secret' matches across distributors
```

### "Class already declared" error
```
1. Enable CLI process execution (proc_open must be enabled)
2. Or ensure distributors use same Composer versions
3. Or use Composer prefix to isolate (creates aliases, not perfect)
```

---

## v0.5.4 Summary

| Feature | Status | Method |
|---------|--------|--------|
| CLI Process Isolation | ✅ Implemented | `executeInternalAPI()` |
| HTTP Bridge | ✅ Implemented | `handleInternalBridge()` |
| Automatic Detection | ✅ Implemented | Checks availability automatically |
| Warning System | ✅ Implemented | E_USER_WARNING when in-process fallback |
| HMAC Signing | ✅ Implemented | X-Razy-Signature header |
| Allowlist Control | ✅ Implemented | `internal_bridge['allow']` config |

---

## See Also

- [CROSS-DISTRIBUTOR-COMMUNICATION.md](./CROSS-DISTRIBUTOR-COMMUNICATION.md) - HTTP bridge design
- [THREAD-SYSTEM.md](./THREAD-SYSTEM.md) - Process pooling and concurrency
- [RELEASE-NOTES.md](../RELEASE-NOTES.md) - v0.5.4 features
