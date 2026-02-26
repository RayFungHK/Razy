<?php
/**
 * Basic Profiler Demo
 * 
 * @llm Demonstrates basic Profiler usage for performance monitoring.
 * 
 * ## Metrics Tracked
 * 
 * - `memory_usage` - Current memory usage
 * - `memory_allocated` - Total memory allocated
 * - `output_buffer` - Output buffer size
 * - `user_mode_time` - CPU user mode time
 * - `system_mode_time` - CPU system mode time
 * - `execution_time` - Wall clock time
 * - `defined_functions` - User-defined functions
 * - `declared_classes` - Declared classes
 */

use Razy\Controller;
use Razy\Profiler;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Basic Usage ===
    $profiler = new Profiler();  // Records initial state
    
    // Do some work
    $data = range(1, 1000);
    $sum = array_sum($data);
    
    // Create checkpoint
    $profiler->checkpoint('after_work');
    
    // Get report comparing init to checkpoint
    $report = $profiler->report(true);  // true = compare with init
    
    $results['basic'] = [
        'report' => $report,
        'description' => 'Basic checkpoint and report',
    ];
    
    // === Memory Tracking ===
    $profiler = new Profiler();
    
    // Allocate memory
    $bigArray = array_fill(0, 10000, 'test data');
    
    $profiler->checkpoint('after_allocation');
    
    unset($bigArray);
    
    $profiler->checkpoint('after_cleanup');
    
    $memoryReport = $profiler->report(true, 'after_allocation', 'after_cleanup');
    
    $results['memory'] = [
        'report' => $memoryReport,
        'description' => 'Memory usage tracking',
    ];
    
    // === Execution Time ===
    $profiler = new Profiler();
    
    // Simulate slow operation
    usleep(10000);  // 10ms
    
    $profiler->checkpoint('after_sleep');
    
    $timeReport = $profiler->report(true);
    
    $results['timing'] = [
        'execution_time_ms' => ($timeReport['after_sleep']['execution_time'] ?? 0) * 1000,
        'description' => 'Execution time tracking (in milliseconds)',
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
