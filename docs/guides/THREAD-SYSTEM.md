# Thread System (Planned)

The Thread system provides lightweight concurrent task execution within a request or worker cycle. It uses a pluggable backend (native threads when available, process fallback otherwise) and exposes a simple API that fits Razy modules.

Current implementation supports in-process execution and a process backend for running external commands. Process threads run with a concurrency limit and support timeouts.

## Goals

- Spawn concurrent tasks with explicit result handling
- Support timeouts and error propagation
- Keep safe defaults for shared state

## Design Overview

The Thread system is built around three parts:

1. **ThreadManager**: lifecycle and orchestration (`spawn`, `await`, `joinAll`).
2. **Thread**: per-task object with status, result, and timing.
3. **Backend**: runtime implementation (native threads if available; process fallback otherwise).

## Proposed API

```php
$thread = $agent->thread()->spawn(function () {
    return ['status' => 'ok'];
});

$result = $agent->thread()->await($thread->getId(), 1500);
```

### Core Methods

- `spawn(callable $task, array $options = []): Thread` - Execute inline callable
- `spawnProcessCommand(string $command, array $args = [], array $options = []): Thread` - Run external command
- `spawnPHPCode(string $code, array $options = []): Thread` - Execute PHP code via base64 encoding
- `spawnPHPFile(string $code, array $options = []): Thread` - Execute PHP code via temp file
- `await(string $id, ?int $timeoutMs = null): mixed` - Wait for thread completion
- `joinAll(array $threads, ?int $timeoutMs = null): array` - Wait for multiple threads
- `status(string $id): string` - Get thread status

## Sample Usage

```php
// Spawn a task and wait for result
$thread = $agent->thread()->spawn(function () {
    // Heavy work here
    return [
        'stats' => [
            'users' => 120,
            'orders' => 43,
        ],
    ];
});

$result = $agent->thread()->await($thread->getId(), 1500);
if ($result === null) {
    // Timeout handling
}

// Fire-and-join multiple tasks
$threads = [
    $agent->thread()->spawn(fn () => $this->getRecentOrders()),
    $agent->thread()->spawn(fn () => $this->getRecentUsers()),
];

$agent->thread()->joinAll($threads, 2000);
```

### Process Backend

```php
$thread = $agent->thread()->spawnProcessCommand(
    PHP_BINARY,
    ['-r', 'echo "hello";'],
    ['cwd' => __DIR__]
);

$result = $agent->thread()->await($thread->getId(), 1000);
// $result = ['stdout' => 'hello', 'stderr' => '', 'exit_code' => 0, 'command' => '...']
```

### PHP Code Execution

For executing PHP code in a subprocess, use the dedicated methods that handle shell escaping automatically:

#### spawnPHPCode() - Base64 Encoding (Recommended)

Best for short to medium PHP code. Uses base64 encoding to avoid all shell escaping issues:

```php
$tm = $agent->thread();

// Simple code
$thread = $tm->spawnPHPCode('echo json_encode(["result" => 42]);');

// Complex code with nested quotes (works on Windows!)
$code = '
    $data = ["message" => "Hello", "items" => ["a", "b", "c"]];
    echo json_encode($data);
';
$thread = $tm->spawnPHPCode($code);

$result = $tm->await($thread->getId(), 5000);
// $result['stdout'] = '{"message":"Hello","items":["a","b","c"]}'
```

#### spawnPHPFile() - Temporary File

Best for very long PHP code that might exceed command-line limits:

```php
$tm = $agent->thread();

$longCode = '
    // Complex multi-line PHP code
    $results = [];
    for ($i = 0; $i < 1000; $i++) {
        $results[] = process_item($i);
    }
    echo json_encode($results);
';

$thread = $tm->spawnPHPFile($longCode, ['timeout' => 30000]);
$result = $tm->await($thread->getId(), 30000);
```

**Note:** Temporary files are automatically cleaned up after execution.

### Windows Shell Escaping

On Windows, `escapeshellarg()` wraps arguments in double quotes, and nested double quotes are stripped by `cmd.exe`. This causes issues with PHP `-r` commands containing quotes.

**Problem:**
```php
// This FAILS on Windows - quotes get stripped
$thread = $tm->spawnProcessCommand(
    'php',
    ['-r', 'echo json_encode(["key" => "value"]);']
);
// Actual command: php -r "echo json_encode([key => value]);"
// Result: Parse error
```

**Solutions:**

1. **Use `spawnPHPCode()`** (recommended):
   ```php
   $thread = $tm->spawnPHPCode('echo json_encode(["key" => "value"]);');
   // Uses base64: php -r "eval(base64_decode('...'));"
   ```

2. **Use `spawnPHPFile()`** for very long code:
   ```php
   $thread = $tm->spawnPHPFile($veryLongCode);
   // Creates temp file and runs: php /tmp/razy_xxxxx.php
   ```

3. **Avoid quotes** in simple cases:
   ```php
   // Use heredoc or simple expressions without quotes
   $thread = $tm->spawnProcessCommand('php', ['-r', 'echo 1+2;']);
   ```

## Configuration (Planned)

```php
return [
    'thread' => [
        'enabled' => false,
        'backend' => 'auto',
        'max_concurrency' => 4,
        'timeout_ms' => 2000,
    ],
];
```

## Notes

- Default is disabled for safety.
- Fallback backend uses processes to keep compatibility.
- Return values should be JSON-serializable (scalar, array).
