<?php
/**
 * Complex PHP Thread Demo
 *
 * @llm Demonstrates spawnPHPFile() for complex PHP with nested quotes.
 * Preferred over spawnPHPCode() (RZ-011): spawnPHPFile writes the code to a
 * cryptographically-named temp file (0600, private dir, atomic rename) instead of
 * the base64 command-line execution path. Never feed input-derived code to either.
 */

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $startTime = microtime(true);
    
    // Complex PHP code with nested quotes - would fail with regular spawn().
    // spawnPHPFile() runs it via a private temp file (sanctioned per RZ-011).
    $phpCode = <<<'PHP'
$data = [
    "message" => "Hello from subprocess",
    "pid" => getmypid(),
    "time" => date("Y-m-d H:i:s"),
    "features" => ["nested", "quotes", "work"]
];
echo json_encode($data);
PHP;
    
    // Spawn using spawnPHPFile — writes a private 0600 temp file (ThreadManager.php:174-201),
    // the sanctioned replacement for spawnPHPCode (RZ-011). Code below is static, never input-derived.
    $thread = $tm->spawnPHPFile($phpCode);
    
    // Wait for process to complete
    $result = $tm->await($thread->getId());
    
    $endTime = microtime(true);
    
    // Parse JSON from subprocess
    $stdout = trim($thread->getStdout());
    $parsed = json_decode($stdout, true);
    
    echo json_encode([
        'demo' => 'complex_php_thread',
        'method' => 'spawnPHPFile()',
        'description' => 'Writes code to a private 0600 temp file (sanctioned per RZ-011)',
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),
        'exit_code' => $thread->getExitCode(),
        'raw_stdout' => $stdout,
        'parsed_output' => $parsed,
        'stderr' => $thread->getStderr() ?: null,
        'command_preview' => substr($thread->getCommand(), 0, 100) . '...',
        'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'note' => 'Complex PHP with nested quotes works via temp-file execution (spawnPHPFile)'
    ], JSON_PRETTY_PRINT);
};
