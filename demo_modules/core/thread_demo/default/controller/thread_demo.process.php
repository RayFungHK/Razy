<?php
/**
 * Process Thread Demo
 * 
 * @llm Demonstrates process mode - shell command execution.
 */

use Razy\ThreadManager;

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $startTime = microtime(true);
    
    // Determine PHP path (Windows MAMP)
    $phpPath = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
    if (!file_exists($phpPath)) {
        $phpPath = 'php'; // Fallback to PATH
    }
    
    // Use simple PHP code - single line to avoid escaping issues
    // Avoid quotes inside the code since Windows cmd strips them
    $phpCode = 'echo getmypid();';
    
    // Spawn process command
    $thread = $tm->spawn(fn() => null, [
        'command' => $phpPath,
        'args' => ['-r', $phpCode]
    ]);
    
    // Wait for process to complete
    $result = $tm->await($thread->getId());
    
    $endTime = microtime(true);
    
    // Parse subprocess output
    $stdout = trim($thread->getStdout());
    $parsed = null;
    if (!empty($stdout) && is_numeric($stdout)) {
        $parsed = [
            'subprocess_pid' => (int)$stdout,
            'parent_pid' => getmypid()
        ];
    }
    
    echo json_encode([
        'demo' => 'process_thread',
        'mode' => $thread->getMode(),
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),
        'exit_code' => $thread->getExitCode(),
        'stdout' => $stdout,
        'stderr' => $thread->getStderr(),
        'parsed_output' => $parsed,
        'command' => $thread->getCommand(),
        'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'note' => 'Process mode spawns external process and captures output'
    ], JSON_PRETTY_PRINT);
};
