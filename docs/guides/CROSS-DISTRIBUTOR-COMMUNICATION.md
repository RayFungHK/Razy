# Cross-Distributor Communication (Planned)

This design enables internal communication between distributors without requiring shared runtime state. It avoids in-process module calls so distributors can use different Composer versions or classmaps without namespace conflicts.

## API vs Bridge Commands

Razy distinguishes between two types of inter-module communication:

### Internal API Commands (`addAPICommand`)

- **Purpose**: Module-to-module communication within the SAME distributor
- **Registration**: `$agent->addAPICommand('commandName', 'path/to/closure')`
- **Hook**: `__onAPICall(ModuleInfo $module, string $method)` validates calls
- **Caller Identity**: Receives `ModuleInfo` of calling module
- **Use Case**: Shared services, data providers within your application

```php
// Provider module registers API command
$agent->addAPICommand('getUserData', 'api/user');

// Consumer module calls via same-distributor API
$data = $this->api('vendor/provider')->getUserData($userId);
```

### Bridge Commands (`addBridgeCommand`)

- **Purpose**: Cross-distributor communication (different projects/distributors)
- **Registration**: `$agent->addBridgeCommand('commandName', 'path/to/closure')`
- **Hook**: `__onBridgeCall(string $sourceDistributor, string $command)` validates calls
- **Caller Identity**: Receives distributor identifier string (e.g., `siteA@dev`)
- **Use Case**: External system integration, microservice communication

```php
// Provider module registers bridge command for external access
$agent->addBridgeCommand('getData', 'bridge/data');
$agent->addBridgeCommand('getConfig', 'bridge/config');

// Called by another distributor via HTTP or CLI transport
```

## Goals

- Allow distributor-to-distributor requests within the same Razy installation
- Avoid in-process API calls across different dependency versions
- Keep access controlled by allowlist in distributor config
- Support both web mode and CLI mode distributors

## Distributor Identification

Distributors are identified by their full identifier: `code@tag`

- `siteA@dev` - siteA distributor with dev tag
- `siteB@1.0.0` - siteB distributor with version 1.0.0 as tag
- `siteB` - shorthand for `siteB@default`
- `siteC@*` - siteC distributor with wildcard tag

The tag can be:
- A label: `dev`, `prod`, `staging`
- A version: `1.0.0`, `2.1.3`
- Default: when omitted, assumes `default`

This ensures the correct distributor instance is targeted, as the same dist code can have different package versions per tag.

## Design Summary

### Transport

**Web Mode Distributors:**
- Use internal HTTP requests routed by distributor URL paths.
- Each distributor exposes a dedicated internal endpoint (e.g., `/__internal/bridge`).

**Non-Web Mode Distributors (CLI only):**
- Use direct file-based or socket communication.
- Execute via CLI subprocess: `php Razy.phar bridge <target> <module> <command> <args>`
- Return JSON response via stdout.

### Security

- Allowlist in `dist.php` to define which distributors may call this distributor.
- Use full identifier format `code@tag` in allowlist for precise control.
- Optional shared secret per distributor for request signing.

## Configuration (Proposed)

```php
return [
    'dist' => 'siteA',
    'internal_bridge' => [
        'enabled' => true,
        // Allowlist format: 'identifier' => true
        'allow' => [
            'siteB' => true,           // siteB@default
            'siteB@1.0.0' => true,     // siteB with version 1.0.0
            'siteB@dev' => true,       // siteB with dev tag
            'siteC@*' => true,         // siteC with any tag
        ],
        'secret' => 'shared-secret',
        'path' => '/__internal/bridge',
    ],
];
```

## Request Flow

### Web Mode

1. Module calls the bridge client in its own distributor.
2. Client builds a signed request and sends it to target distributor bridge URL.
3. Target distributor validates allowlist (checking full identifier) and signature.
4. Target distributor executes local module/API action and returns JSON.

### CLI Mode (Non-Web Distributors)

1. Module calls the bridge client in its own distributor.
2. Client spawns subprocess: `php Razy.phar bridge siteB@1.0.0 vendor/module command '{"arg":"value"}'`
3. Subprocess loads target distributor, validates caller, executes API.
4. Returns JSON via stdout, parsed by caller.

### Endpoint (Web Mode)

```
POST /__internal/bridge
Content-Type: application/json
X-Razy-Signature: <hmac sha256 of raw body>
```

### Payload

```json
{
    "caller_dist": "siteA@dev",
    "module": "vendor/module",
    "command": "doSomething",
    "args": {"id": 123}
}
```

## Proposed API (Distributor)

```php
// Get current distributor identifier
$identifier = $distributor->getIdentifier(); // "siteA@dev"

// Get tag (version or label)
$tag = $distributor->getTag(); // "dev" or "1.0.0"

// Call another distributor's bridge command
$bridge = $distributor->getBridge();
$response = $bridge->call('siteB@1.0.0', 'vendor/module', 'getData', ['id' => 123]);

// Call distributor with default tag
$response = $bridge->call('siteB', 'vendor/module', 'getConfig', []);
```

## Bridge Provider Example

```php
// In target distributor's module controller
return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Register bridge commands for cross-distributor access
        $agent->addBridgeCommand('getData', 'bridge/data');
        $agent->addBridgeCommand('getConfig', 'bridge/config');
        
        return true;
    }
    
    public function __onBridgeCall(string $sourceDistributor, string $command): bool
    {
        // Validate caller - only allow specific distributors
        $allowed = ['siteA', 'siteA@dev', 'siteA@prod'];
        return in_array($sourceDistributor, $allowed);
    }
};
```

## Notes

- API commands (`addAPICommand`) are for same-distributor module communication.
- Bridge commands (`addBridgeCommand`) are for cross-distributor communication.
- Web mode: Uses HTTP bridge endpoint.
- CLI mode: Uses subprocess execution for complete isolation.
- Use JSON payloads; no object transfer.
- Keep internal endpoints hidden from external routing.
- Full identifier format (`code@tag`) enables precise targeting of distributor instances.
- Omitting tag assumes `default` tag.
