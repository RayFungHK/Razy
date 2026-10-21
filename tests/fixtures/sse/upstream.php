<?php

// RZ-16 test upstream, served by `php -S` from SseStreamProxyTest.
$mode = $_GET['mode'] ?? 'events';
if ($mode === 'error') {
    http_response_code(500);
    echo 'internal secret stack trace';

    return;
}
header('Content-Type: text/event-stream');
$emit = static function (string $frame): void {
    echo $frame;
    @ob_flush();
    flush();
};
switch ($mode) {
    case 'echo':
        $emit('data: ' . ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 'none') . "\n\n");
        break;
    case 'idle':
        sleep(3);
        $emit("data: late\n\n");
        break;
    case 'slow':
        for ($i = 0; $i < 20; ++$i) {
            $emit("id: {$i}\ndata: tick {$i}\n\n");
            usleep(250000);
        }
        break;
    default:
        foreach (['one', 'two', 'three'] as $i => $word) {
            $emit("id: {$i}\ndata: {$word}\n\n");
        }
}
