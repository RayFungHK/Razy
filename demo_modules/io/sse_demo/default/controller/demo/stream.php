<?php
/**
 * SSE Stream Demo
 * 
 * @llm Demonstrates SSE streaming patterns (code examples).
 * 
 * NOTE: This returns code examples, not actual streams.
 * Actual SSE streaming exits the script after output.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'basic_stream' => [
            'description' => 'Simple counter stream',
            'code' => <<<'PHP'
use Razy\SSE;

$sse = new SSE(3000);  // 3 second retry
$sse->start();

for ($i = 1; $i <= 5; $i++) {
    $sse->send("Count: $i", 'counter', "count-$i");
    sleep(1);
}
PHP,
        ],
        
        'json_data' => [
            'description' => 'Stream JSON data',
            'code' => <<<'PHP'
$sse = new SSE();
$sse->start();

$updates = [
    ['type' => 'user_online', 'user' => 'John'],
    ['type' => 'message', 'text' => 'Hello'],
    ['type' => 'user_offline', 'user' => 'Jane'],
];

foreach ($updates as $i => $update) {
    $sse->send(json_encode($update), $update['type'], "event-$i");
    usleep(500000);  // 500ms
}
PHP,
        ],
        
        'heartbeat' => [
            'description' => 'Keep connection alive',
            'code' => <<<'PHP'
$sse = new SSE();
$sse->start();

$lastData = time();

while (true) {
    // Check for new data
    $newData = checkForUpdates();
    
    if ($newData) {
        $sse->send(json_encode($newData), 'update');
        $lastData = time();
    } elseif (time() - $lastData > 30) {
        // Send heartbeat every 30s to keep connection
        $sse->comment('heartbeat');
        $lastData = time();
    }
    
    usleep(100000);  // 100ms
}
PHP,
        ],
        
        'proxy_ai' => [
            'description' => 'Proxy AI streaming endpoint',
            'code' => <<<'PHP'
use Razy\SSE;

$sse = new SSE();

// Proxy OpenAI-style streaming response
$sse->proxy(
    'https://api.openai.com/v1/chat/completions',
    [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    'POST',
    json_encode([
        'model' => 'gpt-4',
        'messages' => $messages,
        'stream' => true,
    ]),
    60  // 60 second timeout
);
PHP,
        ],
        
        'progress_updates' => [
            'description' => 'File upload progress',
            'code' => <<<'PHP'
$sse = new SSE();
$sse->start();

$totalSteps = 5;
$steps = [
    'Validating file...',
    'Uploading to server...',
    'Processing image...',
    'Generating thumbnails...',
    'Complete!',
];

foreach ($steps as $i => $step) {
    $progress = [
        'step' => $i + 1,
        'total' => $totalSteps,
        'percent' => round(($i + 1) / $totalSteps * 100),
        'message' => $step,
    ];
    
    $sse->send(json_encode($progress), 'progress');
    sleep(1);  // Simulate work
}
PHP,
        ],
        
        'client_example' => [
            'description' => 'JavaScript client code',
            'code' => <<<'JS'
const source = new EventSource('/api/notifications');

source.addEventListener('message', (e) => {
    const data = JSON.parse(e.data);
    showNotification(data);
});

source.addEventListener('progress', (e) => {
    const progress = JSON.parse(e.data);
    updateProgressBar(progress.percent);
});

source.addEventListener('error', (e) => {
    console.error('SSE connection error');
    // Auto-reconnects after retry interval
});

// Close connection when done
source.close();
JS,
        ],
    ], JSON_PRETTY_PRINT);
};
