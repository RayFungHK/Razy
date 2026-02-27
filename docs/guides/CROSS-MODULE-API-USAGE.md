# Cross-Module API Usage in Razy v0.5

## Overview

Razy modules can communicate with each other through an API system. One module can call methods/commands exposed by another module using the `api()` method from the Controller class.

## Key Concepts

### 1. **API Commands (Module.addAPICommand)**

Each module can expose API commands that other modules can call. API commands are registered in the module's controller `__onInit()` method:

```php
public function __onInit(Agent $agent): bool
{
    // Register an API command 
    // Syntax: addAPICommand(command_name, path_to_handler_file)
    $agent->addAPICommand('greet', 'api/greet.php');
    return true;
}
```

### 2. **API Command Handler**

An API command handler is a simple PHP file that returns a closure:

```php
<?php
// File: api/greet.php
return function (string $name = 'Guest') {
    return [
        'greeting' => "Hello, $name",
        'timestamp' => date('Y-m-d H:i:s'),
        'source' => 'api_handler',
    ];
};
```

### 3. **Calling Cross-Module APIs**

From any module's route handler or controller method, use the `api()` method:

```php
<?php
// In a route handler or controller method
return function () {
    // Call another module's API command
    // Pattern: $this->api('module/code')->command_name($args)
    $result = $this->api('test/hello')->greet('John');
    
    return [
        'calling_module' => 'demo/demo_module',
        'api_response' => $result,
        'status' => 'success',
    ];
};
```

## Basic Example: Same Distributor

### Step 1: Module A - Expose an API Command

**File**: `test/module_a/default/controller/module_a.php`

```php
<?php
namespace Razy\Module\module_a;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register API command
        $agent->addAPICommand('getData', 'api/get_data.php');
        return true;
    }
};
```

**File**: `test/module_a/default/api/get_data.php`

```php
<?php
return function (int $id = 0) {
    return [
        'data_id' => $id,
        'data_value' => 'Sample data from Module A',
        'source_module' => 'test/module_a',
    ];
};
```

### Step 2: Module B - Call Module A's API

**File**: `test/module_b/default/controller/module_b.php`

```php
<?php
namespace Razy\Module\module_b;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register route that calls Module A's API
        $agent->addLazyRoute([
            'call_api' => 'call_module_a',
        ]);
        return true;
    }
};
```

**File**: `test/module_b/default/controller/module_b.call_module_a.php`

```php
<?php
return function (int $dataId = 5) {
    try {
        // Call Module A's getData API command
        $data = $this->api('test/module_a')->getData($dataId);
        
        return [
            'calling_module' => 'test/module_b',
            'called_api' => 'test/module_a::getData',
            'request_id' => $dataId,
            'response' => $data,
            'status' => 'success',
        ];
    } catch (Throwable $e) {
        return [
            'calling_module' => 'test/module_b',
            'status' => 'error',
            'message' => $e->getMessage(),
        ];
    }
};
```

### Step 3: Access the Cross-Module API Call

```
GET /module_b/call_api/10
```

Response:
```json
{
    "calling_module": "test/module_b",
    "called_api": "test/module_a::getData",
    "request_id": 10,
    "response": {
        "data_id": 10,
        "data_value": "Sample data from Module A",
        "source_module": "test/module_a"
    },
    "status": "success"
}
```

## Advanced: Shared Modules (Cross-Distributor)

When a module is loaded as a *shared* module, it can be accessed by any distributor:

### Configuration in `dist.php`

```php
return [
    'global_module' => true,      // Enable shared module loading
    'autoload_shared' => true,    // Auto-load from shared folder
    'greedy' => true,             // Greedy load all available modules
    
    'modules' => [
        '*' => [
            'demo/shared_api' => 'default',   // Shared module available to all
        ],
    ],
];
```

### Using the Shared Module API

```php
// From any module in any distributor
$result = $this->api('demo/shared_api')->someCommand($arg);
```

## API Access Control

### Module-Level Control

Modules can control which other modules can access their APIs via the `__onAPICall` hook:

```php
<?php
namespace Razy\Module\protected_api;

return new class extends Controller {
    /**
     * Control API access from other modules
     * Return false to deny access
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        // Only allow test/module_b to access
        if ($module->getCode() === 'test/module_b') {
            return true;
        }
        
        // Deny all other modules
        return false;
    }
};
```

### Handshake Pattern

Modules can explicitly handshake with other modules to acknowledge API access:

```php
<?php
namespace Razy\Module\consumer;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Handshake with provider module
        $this->handshake('project/provider', 'Access request from consumer');
        return true;
    }
};
```

## Best Practices

1. **Keep API Commands Simple**: API commands should be atomic and focused on single responsibilities
2. **Handle Errors Gracefully**: Always wrap API calls in try-catch blocks
3. **Document API Interfaces**: Document expected parameters and return values in API handlers
4. **Use Type Hints**: Add type hints to API command handlers for clarity
5. **Route Parameters**: Extract parameters from route before passing to API calls
6. **Response Formats**: Standardize response format (arrays/objects) across all API commands

## Common Patterns

### Pattern 1: API With Parameter Extraction

```php
<?php
// Route: /module_a/users/[user_id]/profile

return function (?string $user_id = null) {
    if (!$user_id) {
        return ['error' => 'User ID required', 'status' => 400];
    }
    
    try {
        $profile = $this->api('user/service')->getProfile((int)$user_id);
        return ['data' => $profile, 'status' => 'success'];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'status' => 500];
    }
};
```

### Pattern 2: API Chaining

```php
<?php
// Call multiple module APIs in sequence

return function (int $userId = 0) {
    try {
        // Get user data
        $user = $this->api('user/module')->getUser($userId);
        
        // Get user permissions based on user data
        $perms = $this->api('auth/module')->getPermissions($user['role']);
        
        // Get user preferences
        $prefs = $this->api('preference/module')->getPreferences($userId);
        
        return [
            'user' => $user,
            'permissions' => $perms,
            'preferences' => $prefs,
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
};
```

### Pattern 3: Fallback API Calls

```php
<?php
// Try primary API, fall back to secondary

return function ($id = 0) {
    try {
        // Try primary data source
        return $this->api('data/primary')->fetch($id);
    } catch (Throwable $e) {
        // Fall back to secondary
        return $this->api('data/secondary')->fetch($id);
    }
};
```

## Real-World Example: Distribution Module API Pattern

This example demonstrates a realistic use case where the **user** module exposes user-related APIs, and the **profile** module consumes them to build user profiles.

### Directory Structure

```
sites/mysite/
?œâ??€ vendor/user/
??  ?œâ??€ module.php
??  ?”â??€ default/
??      ?œâ??€ package.php
??      ?œâ??€ api/
??      ??  ?œâ??€ get_user.php
??      ??  ?œâ??€ get_user_email.php
??      ??  ?”â??€ authenticate.php
??      ?”â??€ controller/
??          ?”â??€ user.php
?”â??€ vendor/profile/
    ?œâ??€ module.php
    ?”â??€ default/
        ?œâ??€ package.php
        ?”â??€ controller/
            ?œâ??€ profile.php
            ?œâ??€ profile.view.php
            ?œâ??€ profile.email.php
            ?œâ??€ profile.login.php
            ?”â??€ profile.full_profile.php
```

**Note**: Modules are placed directly under `sites/{sitename}/{vendor}/{module}/` - do NOT create a `modules/` subfolder.

### User Module - API Provider

**File**: `sites/mysite/vendor/user/default/controller/user.php`

```php
<?php
namespace Razy\Module\user;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Expose APIs for other modules to call
        $agent->addAPICommand('get_user', 'api/get_user.php');
        $agent->addAPICommand('get_user_email', 'api/get_user_email.php');
        $agent->addAPICommand('authenticate', 'api/authenticate.php');
        
        return true;
    }
};
```

**File**: `sites/mysite/vendor/user/default/api/get_user.php`

```php
<?php
return function (int $userId) {
    $users = [
        1 => ['id' => 1, 'name' => 'John Doe', 'role' => 'admin'],
        2 => ['id' => 2, 'name' => 'Jane Smith', 'role' => 'user'],
    ];
    
    return $users[$userId] ?? ['status' => 'error', 'message' => 'User not found'];
};
```

**File**: `sites/mysite/vendor/user/default/api/get_user_email.php`

```php
<?php
return function (int $userId) {
    $emails = [1 => 'john@example.com', 2 => 'jane@example.com'];
    
    return [
        'user_id' => $userId,
        'email' => $emails[$userId] ?? 'N/A',
    ];
};
```

**File**: `sites/mysite/vendor/user/default/api/authenticate.php`

```php
<?php
return function (string $username, string $password) {
    $credentials = [
        'john' => ['password' => 'pass123', 'user_id' => 1],
        'jane' => ['password' => 'pass456', 'user_id' => 2],
    ];
    
    $auth = $credentials[$username] ?? null;
    if (!$auth || $auth['password'] !== $password) {
        return ['status' => 'error', 'authenticated' => false];
    }
    
    return [
        'status' => 'success',
        'authenticated' => true,
        'user_id' => $auth['user_id'],
    ];
};
```

### Profile Module - API Consumer

**File**: `sites/mysite/vendor/profile/default/package.php`

```php
<?php
return [
    'package_name' => 'profile',
    'version' => '1.0.0',
    'required_modules' => [
        'vendor/user',  // Depend on user module for its APIs
    ],
];
```

**File**: `sites/mysite/vendor/profile/default/controller/profile.view.php`

```php
<?php
// Route: /profile/view/1
return function (?string $userIdStr = null) {
    $userId = (int)($userIdStr ?? 1);
    
    try {
        // Call user module's get_user API
        $userResponse = $this->api('vendor/user')->get_user($userId);
        
        if (!isset($userResponse['id'])) {
            return ['status' => 'error', 'message' => 'User not found'];
        }
        
        return [
            'status' => 'success',
            'calling_module' => 'vendor/profile',
            'api_called' => 'vendor/user->get_user',
            'user' => $userResponse,
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
};
```

**File**: `sites/mysite/vendor/profile/default/controller/profile.login.php`

```php
<?php
// Route: /profile/login/john/pass123
return function (?string $username = null, ?string $password = null) {
    try {
        // Call user module's authenticate API
        $result = $this->api('vendor/user')->authenticate($username ?? 'john', $password ?? '');
        
        return [
            'status' => $result['authenticated'] ? 'success' : 'error',
            'message' => $result['message'] ?? 'Authentication result',
            'authenticated' => $result['authenticated'] ?? false,
            'user_id' => $result['user_id'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
};
```

**File**: `sites/mysite/vendor/profile/default/controller/profile.full_profile.php`

```php
<?php
// Route: /profile/full_profile/1
// Demonstrates calling MULTIPLE APIs from same module

return function (?string $userIdStr = null) {
    $userId = (int)($userIdStr ?? 1);
    
    try {
        // Call multiple APIs from user module
        $userResponse = $this->api('vendor/user')->get_user($userId);
        $emailResponse = $this->api('vendor/user')->get_user_email($userId);
        
        return [
            'status' => 'success',
            'apis_called' => [
                'vendor/user->get_user',
                'vendor/user->get_user_email',
            ],
            'profile' => [
                'id' => $userResponse['id'] ?? null,
                'name' => $userResponse['name'] ?? null,
                'role' => $userResponse['role'] ?? null,
                'email' => $emailResponse['email'] ?? 'N/A',
            ],
        ];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
};
```

### Testing the APIs

```
# Get user profile
GET /profile/view/1
??Calls vendor/user->get_user(1)

# Get user email
GET /profile/email/1
??Calls vendor/user->get_user_email(1)

# Login user
GET /profile/login/john/pass123
??Calls vendor/user->authenticate('john', 'pass123')

# Get full profile (multiple API calls)
GET /profile/full_profile/1
??Calls vendor/user->get_user(1)
??Calls vendor/user->get_user_email(1)
```

## Limitations & Considerations

- **Same Request Context**: API calls execute within the same request context
- **No Async**: API calls are synchronous - they block until complete
- **Error Propagation**: Exceptions in API handlers propagate to the caller
- **Data Serialization**: Return values are NOT automatically serialized - return arrays/objects that can be JSON-encoded
- **Shared Module Routing**: Be cautious with naming to avoid .htaccess conflicts (e.g., module name shouldn't conflict with system folders)

## Testing Cross-Module APIs

Test endpoint example:

```
GET /demo_module/api_test

Response (JSON):
{
    "status": "success",
    "calling_module": "demo/demo_module",
    "api_called": "test/hello::greet",
    "api_result": {...},
    "result_type": "array"
}
```
