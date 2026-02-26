<?php
/**
 * Complex PHP Thread Demo
 * 
 * @llm Demonstrates spawnPHPCode() for complex PHP with nested quotes.
 * Uses base64 encoding internally to avoid Windows shell escaping issues.
 */

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $startTime = microtime(true);
    
    // Complex PHP code with nested quotes - would fail with regular spawn()
    // But works perfectly with spawnPHPCode() using base64 encoding
    $phpCode = <<<'PHP'
$data = [
    "message" => "Hello from subprocess",
    "pid" => getmypid(),
    "time" => date("Y-m-d H:i:s"),
    "features" => ["nested", "quotes", "work"]
];
echo json_encode($data);
PHP;
    
    // Spawn using spawnPHPCode - uses base64 encoding internally
    $thread = $tm->spawnPHPCode($phpCode);
    
    // Wait for process to complete
    $result = $tm->await($thread->getId());
    
    $endTime = microtime(true);
    
    // Parse JSON from subprocess
    $stdout = trim($thread->getStdout());
    $parsed = json_decode($stdout, true);
    
    echo json_encode([
        'demo' => 'complex_php_thread',
        'method' => 'spawnPHPCode()',
        'description' => 'Uses base64 encoding to avoid Windows shell escaping issues',
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),
        'exit_code' => $thread->getExitCode(),
        'raw_stdout' => $stdout,
        'parsed_output' => $parsed,
        'stderr' => $thread->getStderr() ?: null,
        'command_preview' => substr($thread->getCommand(), 0, 100) . '...',
        'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'note' => 'Complex PHP with nested quotes works via base64 encoding'
    ], JSON_PRETTY_PRINT);
};
