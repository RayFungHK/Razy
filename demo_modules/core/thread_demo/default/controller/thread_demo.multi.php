<?php
/**
 * Multi-Task Thread Demo
 * 
 * @llm Demonstrates multiple task execution with concurrency control.
 */

use Razy\ThreadManager;

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $tm->setMaxConcurrency(4); // Max 4 concurrent processes
    
    $startTime = microtime(true);
    
    // Determine PHP path
    $phpPath = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
    if (!file_exists($phpPath)) {
        $phpPath = 'php';
    }
    
    // Spawn 5 tasks (will queue when > maxConcurrency)
    $threads = [];
    $taskCount = 5;
    
    for ($i = 1; $i <= $taskCount; $i++) {
        // Simple: sleep briefly and output task number + PID
        // Using comma between echo args to avoid string concat escaping issues
        $script = sprintf('usleep(50000); echo %d, getmypid();', $i);
        
        $threads[] = $tm->spawn(fn() => null, [
            'command' => $phpPath,
            'args' => ['-r', $script]
        ]);
    }
    
    // Wait for all tasks to complete
    $results = $tm->joinAll($threads);
    
    $endTime = microtime(true);
    
    // Build task summary
    $taskResults = [];
    foreach ($threads as $index => $thread) {
        $stdout = trim($thread->getStdout());
        // Output is like "167128" (task + pid concatenated)
        $taskResults[] = [
            'task_number' => $index + 1,
            'thread_id' => $thread->getId(),
            'status' => $thread->getStatus(),
            'exit_code' => $thread->getExitCode(),
            'raw_output' => $stdout,
            'error' => $thread->getStderr() ?: null
        ];
    }
    
    echo json_encode([
        'demo' => 'multi_task',
        'total_tasks' => $taskCount,
        'max_concurrency' => 4,
        'total_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'tasks' => $taskResults,
        'note' => 'Tasks beyond maxConcurrency are queued and start as others complete'
    ], JSON_PRETTY_PRINT);
};
