# Thread System Guide

## Overview

Razy's **ThreadManager** enables async task execution with two modes:
- **Inline Mode**: PHP callable executed synchronously (blocking)
- **Process Mode**: Shell command executed asynchronously (non-blocking)

## Quick Start

```php
use Razy\ThreadManager;

// Create manager
$tm = new ThreadManager();

// Inline mode (synchronous)
$thread = $tm->spawn(function() {
    return ['computed' => 42];
});
$result = $thread->getResult();  // Available immediately

// Process mode (async subprocess)
$thread = $tm->spawn(fn() => null, [
    'command' => 'php',
    'args' => ['-r', 'echo getmypid();']
]);
$result = $tm->await($thread->getId());
```

## ThreadManager Class

| Method | Arguments | Returns | Description |
|--------|-----------|---------|-------------|
| `spawn()` | `callable $callback, array $options = []` | `Thread` | Create and start a thread |
| `await()` | `string $threadId, float $timeout = 0` | `?array` | Wait for specific thread |
| `joinAll()` | `array $threads, float $timeout = 0` | `array` | Wait for all threads |
| `setMaxConcurrency()` | `int $max` | `void` | Set max concurrent processes |
| `getThread()` | `string $id` | `?Thread` | Get thread by ID |

## Thread Class Properties

| Method | Returns | Description |
|--------|---------|-------------|
| `getId()` | `string` | Unique thread identifier |
| `getStatus()` | `string` | pending\|running\|completed\|failed |
| `getMode()` | `string` | inline\|process |
| `getResult()` | `mixed` | Task return value |
| `getError()` | `?Throwable` | Exception if failed |
| `getStdout()` | `string` | Process stdout (process mode) |
| `getStderr()` | `string` | Process stderr (process mode) |
| `getExitCode()` | `?int` | Process exit code |
| `getCommand()` | `string` | Constructed command string |
| `isFinished()` | `bool` | True if completed or failed |

## Thread States

```
pending -> running -> completed
                   -> failed
```

## Usage Patterns

### 1. Inline Mode (Synchronous Calculation)

```php
public function calculateSum(): array
{
    $tm = $this->getThreadManager();
    
    $thread = $tm->spawn(function() {
        $sum = 0;
        for ($i = 1; $i <= 100; $i++) {
            $sum += $i;
        }
        return ['sum' => $sum, 'formula' => 'sum(1..100)'];
    });
    
    return [
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),  // 'completed'
        'result' => $thread->getResult()   // ['sum' => 5050, ...]
    ];
}
```

### 2. Process Mode (External Command)

```php
public function runSubprocess(): array
{
    $tm = $this->getThreadManager();
    
    $phpPath = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
    
    $thread = $tm->spawn(fn() => null, [
        'command' => $phpPath,
        'args' => ['-r', 'echo getmypid();']
    ]);
    
    // Wait for completion
    $tm->await($thread->getId());
    
    return [
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),
        'stdout' => $thread->getStdout(),
        'exit_code' => $thread->getExitCode()
    ];
}
```

### 3. Multi-Task with Concurrency Control

```php
public function runMultipleTasks(): array
{
    $tm = $this->getThreadManager();
    $tm->setMaxConcurrency(4);  // Max 4 concurrent
    
    $threads = [];
    for ($i = 1; $i <= 5; $i++) {
        $threads[] = $tm->spawn(fn() => null, [
            'command' => 'php',
            'args' => ['-r', sprintf('usleep(50000); echo %d, getmypid();', $i)]
        ]);
    }
    
    // Wait for all to complete
    $results = $tm->joinAll($threads);
    
    return array_map(fn($t) => [
        'id' => $t->getId(),
        'status' => $t->getStatus(),
        'output' => $t->getStdout()
    ], $threads);
}
```

### 4. Parallel Processing

```php
public function parallelCalculation(): array
{
    $tm = $this->getThreadManager();
    $tm->setMaxConcurrency(3);
    
    // Three independent calculations
    $threads[] = $tm->spawn(fn() => null, [
        'command' => 'php',
        'args' => ['-r', '$f=1;for($i=1;$i<=5;$i++)$f*=$i;echo $f;']  // 5! = 120
    ]);
    
    $threads[] = $tm->spawn(fn() => null, [
        'command' => 'php',
        'args' => ['-r', '$s=0;for($i=1;$i<=10;$i++)$s+=$i;echo $s;']  // Sum 1-10 = 55
    ]);
    
    $threads[] = $tm->spawn(fn() => null, [
        'command' => 'php',
        'args' => ['-r', '$a=0;$b=1;for($i=0;$i<8;$i++){$t=$a+$b;$a=$b;$b=$t;}echo $a;']  // Fib(8) = 21
    ]);
    
    $tm->joinAll($threads);
    
    return array_map(fn($t) => [
        'result' => trim($t->getStdout()),
        'status' => $t->getStatus()
    ], $threads);
}
```

## Windows Shell Escaping Notes

When using process mode on Windows, avoid complex quoting:

**DO:**
```php
// Simple code without nested quotes
'args' => ['-r', 'echo getmypid();']
'args' => ['-r', '$sum=0;for($i=1;$i<=10;$i++)$sum+=$i;echo $sum;']
'args' => ['-r', 'echo 1, 2, 3;']  // Use comma to concatenate
```

**DON'T:**
```php
// Complex quoting that gets mangled by Windows cmd.exe
'args' => ['-r', 'echo json_encode(["key" => "value"]);']  // Quotes stripped
'args' => ['-r', "echo 'Hello World';"]  // Single quotes problematic
```

## Controller Integration

ThreadManager is automatically available in controllers:

```php
return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        $agent->addLazyRoute([
            '/' => 'main',
            'process' => 'runProcess'
        ]);
        return true;
    }
    
    // ThreadManager provided by Module
    public function getThreadManager(): ThreadManager
    {
        return $this->getModule()->getThreadManager();
    }
};
```

## Demo Module

Reference implementation: `demo/thread_demo`

| Endpoint | Demo |
|----------|------|
| `/thread_demo/` | Overview page |
| `/thread_demo/inline` | Inline mode (sum 1-100) |
| `/thread_demo/process` | Process mode (subprocess PID) |
| `/thread_demo/multi` | Multi-task with concurrency |
| `/thread_demo/parallel` | Parallel calculations |

## Related

- [CLASS-REFERENCE.md](CLASS-REFERENCE.md) - Thread & ThreadManager API
- [MODULE-DEVELOPMENT.md](MODULE-DEVELOPMENT.md) - Module patterns
