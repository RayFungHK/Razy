<?php
/**
 * Transform API Command
 * 
 * @llm String and array transformation utilities.
 */

return function (string $type, mixed $input): array {
    return match ($type) {
        'uppercase' => [
            'original' => $input,
            'result' => is_string($input) ? strtoupper($input) : null,
            'type' => 'uppercase',
        ],
        
        'lowercase' => [
            'original' => $input,
            'result' => is_string($input) ? strtolower($input) : null,
            'type' => 'lowercase',
        ],
        
        'titlecase' => [
            'original' => $input,
            'result' => is_string($input) ? ucwords(strtolower($input)) : null,
            'type' => 'titlecase',
        ],
        
        'reverse' => [
            'original' => $input,
            'result' => is_string($input) ? strrev($input) : (is_array($input) ? array_reverse($input) : null),
            'type' => 'reverse',
        ],
        
        'slugify' => [
            'original' => $input,
            'result' => is_string($input) 
                ? strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($input))) 
                : null,
            'type' => 'slugify',
        ],
        
        'wordcount' => [
            'original' => $input,
            'result' => is_string($input) ? str_word_count($input) : null,
            'type' => 'wordcount',
        ],
        
        'json_encode' => [
            'original' => $input,
            'result' => json_encode($input, JSON_PRETTY_PRINT),
            'type' => 'json_encode',
        ],
        
        default => [
            'error' => "Unknown transform: {$type}",
            'supported' => ['uppercase', 'lowercase', 'titlecase', 'reverse', 'slugify', 'wordcount', 'json_encode'],
        ],
    };
};
