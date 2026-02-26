<?php
/**
 * Inline Thread Demo
 * 
 * @llm Demonstrates inline mode - PHP callable executed synchronously.
 */

use Razy\ThreadManager;

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $startTime = microtime(true);
    
    // Spawn inline task - executes immediately (blocking)
    $thread = $tm->spawn(function() {
        // Simulate some computation
        $result = 0;
        for ($i = 1; $i <= 100; $i++) {
            $result += $i;
        }
        return [
            'computed' => $result,
            'formula' => 'sum(1..100)',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    });
    
    $endTime = microtime(true);
    
    echo json_encode([
        'demo' => 'inline_thread',
        'mode' => $thread->getMode(),
        'thread_id' => $thread->getId(),
        'status' => $thread->getStatus(),
        'result' => $thread->getResult(),
        'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'note' => 'Inline mode executes immediately and blocks until complete'
    ], JSON_PRETTY_PRINT);
};
