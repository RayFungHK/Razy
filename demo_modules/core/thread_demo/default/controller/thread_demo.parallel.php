<?php
/**
 * Parallel Thread Demo
 * 
 * @llm Demonstrates parallel processing of independent tasks.
 */

use Razy\ThreadManager;

return function (): void {
    header('Content-Type: application/json');
    
    $tm = $this->getThreadManager();
    $tm->setMaxConcurrency(3); // All 3 can run in parallel
    
    $startTime = microtime(true);
    
    // Determine PHP path
    $phpPath = 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe';
    if (!file_exists($phpPath)) {
        $phpPath = 'php';
    }
    
    // Spawn 3 independent PHP processes in parallel
    // Each calculates a simple value and returns PID + result
    $threads = [];
    
    // Task A: Calculate factorial of 5
    $threads[] = $tm->spawn(fn() => null, [
        'command' => $phpPath,
        'args' => ['-r', 'usleep(20000); $f=1; for($i=1;$i<=5;$i++)$f*=$i; echo getmypid(), $f;']
    ]);
    
    // Task B: Sum 1 to 10
    $threads[] = $tm->spawn(fn() => null, [
        'command' => $phpPath,
        'args' => ['-r', 'usleep(20000); $s=0; for($i=1;$i<=10;$i++)$s+=$i; echo getmypid(), $s;']
    ]);
    
    // Task C: Fibonacci 8th term
    $threads[] = $tm->spawn(fn() => null, [
        'command' => $phpPath,
        'args' => ['-r', 'usleep(20000); $a=0;$b=1;for($i=0;$i<8;$i++){$t=$a+$b;$a=$b;$b=$t;} echo getmypid(), $a;']
    ]);
    
    // Wait for all parallel tasks
    $results = $tm->joinAll($threads);
    
    $endTime = microtime(true);
    
    // Collect results
    $taskLabels = ['A: Factorial(5)', 'B: Sum(1-10)', 'C: Fib(8)'];
    $expectedValues = [120, 55, 21];
    
    $parallelResults = [];
    foreach ($threads as $index => $thread) {
        $stdout = trim($thread->getStdout());
        // Parse PID and result from output like "12345120"
        $expected = $expectedValues[$index];
        $result = null;
        $pid = null;
        if (!empty($stdout) && strlen($stdout) > strlen((string)$expected)) {
            $resultPart = substr($stdout, -strlen((string)$expected));
            $pidPart = substr($stdout, 0, -strlen((string)$expected));
            if (is_numeric($resultPart) && is_numeric($pidPart)) {
                $result = (int)$resultPart;
                $pid = (int)$pidPart;
            }
        }
        
        $parallelResults[] = [
            'task' => $taskLabels[$index],
            'thread_id' => $thread->getId(),
            'status' => $thread->getStatus(),
            'pid' => $pid,
            'computed_value' => $result,
            'expected_value' => $expected,
            'match' => $result === $expected,
            'exit_code' => $thread->getExitCode()
        ];
    }
    
    echo json_encode([
        'demo' => 'parallel_processing',
        'concurrent_tasks' => count($threads),
        'total_time_ms' => round(($endTime - $startTime) * 1000, 2),
        'results' => $parallelResults,
        'note' => 'All tasks run in parallel (not sequentially), reducing total time'
    ], JSON_PRETTY_PRINT);
};
