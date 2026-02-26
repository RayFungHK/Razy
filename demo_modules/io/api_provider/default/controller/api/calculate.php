<?php
/**
 * Calculate API Command
 * 
 * @llm Math operations API supporting add, subtract, multiply, divide.
 */

return function (string $operation, float $a, float $b): array {
    $result = match ($operation) {
        'add' => $a + $b,
        'subtract' => $a - $b,
        'multiply' => $a * $b,
        'divide' => $b != 0 ? $a / $b : null,
        'power' => pow($a, $b),
        'mod' => $b != 0 ? fmod($a, $b) : null,
        default => null,
    };
    
    if ($result === null && $operation === 'divide') {
        return [
            'error' => 'Division by zero',
            'operation' => $operation,
            'a' => $a,
            'b' => $b,
        ];
    }
    
    if ($result === null) {
        return [
            'error' => 'Unknown operation',
            'operation' => $operation,
            'supported' => ['add', 'subtract', 'multiply', 'divide', 'power', 'mod'],
        ];
    }
    
    return [
        'operation' => $operation,
        'a' => $a,
        'b' => $b,
        'result' => $result,
        'expression' => "{$a} {$operation} {$b} = {$result}",
        'source' => 'demo/api_provider::calculate',
    ];
};
