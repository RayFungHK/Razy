<?php
/**
 * calculate API Command
 * 
 * @llm Performs calculation in siteB and returns result.
 * Demonstrates remote computation via bridge.
 */

use Razy\Controller;

return function (string $operation, float $a, float $b): array {
    /** @var Controller $this */
    
    $result = match ($operation) {
        'add' => $a + $b,
        'subtract' => $a - $b,
        'multiply' => $a * $b,
        'divide' => $b !== 0.0 ? $a / $b : null,
        'power' => pow($a, $b),
        'modulo' => $b !== 0.0 ? fmod($a, $b) : null,
        default => null,
    };
    
    return [
        'success' => $result !== null,
        'source' => 'siteB@' . ($_ENV['RAZY_DIST_TAG'] ?? 'default'),
        'operation' => $operation,
        'operands' => ['a' => $a, 'b' => $b],
        'result' => $result,
        'error' => $result === null ? 'Invalid operation or division by zero' : null,
        'timestamp' => date('Y-m-d H:i:s'),
    ];
};
