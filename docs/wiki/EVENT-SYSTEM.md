# Event System Guide

**Reference Modules**: 
- `test-razy-cli/sites/mysite/demo/event_demo/` (fires events)
- `test-razy-cli/sites/mysite/demo/event_receiver/` (listens to events)

---

## Overview

Razy's event system enables cross-module communication through:
1. **Firing**: Module triggers an event with optional data
2. **Listening**: Other modules receive and respond to events

---

## Firing Events

### Basic Pattern

```php
// In controller method
$responses = $this->trigger('event_name')
    ->resolve()
    ->getAllResponse();
```

### With Data

```php
$data = ['user_id' => 123, 'action' => 'created'];

$responses = $this->trigger('user_registered', $data)
    ->resolve()
    ->getAllResponse();
```

### Response Handling

```php
public function fireEvent(): string
{
    $eventManager = $this->trigger('my_event', ['key' => 'value']);
    $eventManager->resolve();
    
    $responses = $eventManager->getAllResponse();
    // $responses is array of listener return values
    
    return json_encode([
        'event' => 'my_event',
        'listener_count' => count($responses),
        'responses' => $responses
    ]);
}
```

---

## Listening to Events

### Event Name Format

```
vendor/module:event_name
```

| Part | Description |
|------|-------------|
| `vendor` | Module vendor (folder name) |
| `module` | Module name (folder name) |
| `event_name` | Event identifier |

### Register Listener

**In `__onInit()` method using inline closure:**

```php
public function __onInit(): int
{
    $agent = $this->getAgent();
    
    // Listen to event - returns true if target module is loaded
    $isLoaded = $agent->listen('demo/event_demo:user_registered', function($data) {
        // Process event data
        return [
            'handler' => 'event_receiver',
            'received' => $data,
            'processed_at' => date('Y-m-d H:i:s')
        ];
    });
    
    // Optional: Check if target module is loaded
    if (!$isLoaded) {
        // Target module not loaded - listener registered but may never fire
        // Useful for optional event dependencies
    }
    
    return Controller::PLUGIN_LOADED;
}
```

**Return Value:**
- `true`: Target module is loaded, listener will fire when event triggers
- `false`: Target module not loaded yet (may load later or never)
- Listener is **always registered** regardless of return value

**⚠️ Important**: Always use inline closures for event handlers.

---

## Complete Example

### Event Firer (event_demo)

```php
class Event_demo extends Controller
{
    public function fire(): string
    {
        header('Content-Type: application/json');
        
        $eventData = ['id' => 1, 'name' => 'Test User', 'timestamp' => time()];
        
        $responses = $this->trigger('user_registered', $eventData)
            ->resolve()
            ->getAllResponse();
        
        return json_encode([
            'event' => 'user_registered',
            'data' => $eventData,
            'responses' => $responses
        ], JSON_PRETTY_PRINT);
    }
}
```

### Event Listener (event_receiver)

```php
class Event_receiver extends Controller
{
    public function __onInit(): int
    {
        $this->getAgent()->listen('demo/event_demo:user_registered', function($data) {
            return [
                'handler' => 'event_receiver',
                'received' => $data,
                'status' => 'processed'
            ];
        });
        
        return Controller::PLUGIN_LOADED;
    }
}
```

---

## Testing Events

```powershell
# Start server
cd test-razy-cli
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080

# Fire event and check listeners respond
$wc = New-Object System.Net.WebClient
$wc.DownloadString("http://localhost:8080/event_demo/fire")
```

**Expected Response**:
```json
{
    "event": "user_registered",
    "data": {"id": 1, "name": "Test User"},
    "responses": [
        {
            "handler": "event_receiver",
            "received": {"id": 1, "name": "Test User"},
            "status": "processed"
        }
    ]
}
```

---

## Common Mistakes

| Mistake | Correct Approach |
|---------|------------------|
| `$this->getModule()->createEmitter()` | Use `$this->trigger()` |
| `listen('event_name', ...)` | Use `listen('vendor/module:event', ...)` |
| `listen(..., 'handler_file')` | Use inline closure |
| Forgetting `->resolve()` | Chain: `trigger()->resolve()->getAllResponse()` |

---

## Event Debugging

If events don't fire:
1. Check event name format: `vendor/module:event_name`
2. Verify listener module is registered in `dist.php`
3. Ensure listener registered in `__onInit()` (not elsewhere)
4. Use inline closures, not file references
5. Check `->resolve()` is called before `->getAllResponse()`
