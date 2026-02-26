<?php
/**
 * Checkpoint Comparison Demo
 * 
 * @llm Demonstrates multi-checkpoint profiling for comparing stages.
 */

use Razy\Controller;
use Razy\Profiler;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    $results = [];
    
    // === Multiple Checkpoints ===
    $profiler = new Profiler();
    
    // Stage 1: Data preparation
    $users = [];
    for ($i = 0; $i < 100; $i++) {
        $users[] = ['id' => $i, 'name' => 'User ' . $i];
    }
    $profiler->checkpoint('data_prepared');
    
    // Stage 2: Processing
    $processed = array_map(function ($user) {
        return strtoupper($user['name']);
    }, $users);
    $profiler->checkpoint('data_processed');
    
    // Stage 3: Output generation
    $output = implode(', ', $processed);
    $profiler->checkpoint('output_generated');
    
    // Compare all stages
    $fullReport = $profiler->report(true);
    
    $results['stages'] = [
        'report' => $fullReport,
        'description' => 'Stage-by-stage comparison',
    ];
    
    // === Compare Specific Checkpoints ===
    $specificReport = $profiler->report(false, 'data_prepared', 'output_generated');
    
    $results['specific'] = [
        'report' => $specificReport,
        'description' => 'Compare specific checkpoints only',
    ];
    
    // === Function Tracking ===
    $profiler = new Profiler();
    
    // Define a function after profiler init
    // (Can't do dynamically in this context, showing pattern)
    
    $profiler->checkpoint('after_definitions');
    
    $report = $profiler->report(true);
    
    $results['function_tracking'] = [
        'new_classes' => $report['after_definitions']['declared_classes'] ?? [],
        'description' => 'Track newly declared classes (difference from init)',
    ];
    
    // === Production Pattern ===
    $results['production_pattern'] = [
        'code' => <<<'PHP'
// From production: razit module profiling
$profiler = new Profiler();

// Bootstrap
$this->loadConfig();
$profiler->checkpoint('config_loaded');

// Database
$this->initDatabase();
$profiler->checkpoint('database_ready');

// Route
$this->handleRequest();
$profiler->checkpoint('request_handled');

// Generate report
$report = $profiler->report(true);

// Log performance
foreach ($report as $stage => $metrics) {
    $time = round($metrics['execution_time'] * 1000, 2);
    $memory = round($metrics['memory_usage'] / 1024, 2);
    error_log("[Profile] {$stage}: {$time}ms, {$memory}KB");
}
PHP,
    ];
    echo json_encode($results, JSON_PRETTY_PRINT);
};
