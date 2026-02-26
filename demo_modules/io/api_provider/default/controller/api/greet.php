<?php
/**
 * Greet API Command
 * 
 * @llm Simple greeting API that returns personalized messages.
 */

return function (string $name = 'Guest', string $language = 'en'): array {
    $greetings = [
        'en' => 'Hello',
        'es' => 'Hola',
        'fr' => 'Bonjour',
        'de' => 'Hallo',
        'jp' => 'こんにちは',
        'cn' => '你好',
    ];
    
    $greeting = $greetings[$language] ?? $greetings['en'];
    
    return [
        'message' => "{$greeting}, {$name}!",
        'name' => $name,
        'language' => $language,
        'timestamp' => date('Y-m-d H:i:s'),
        'source' => 'demo/api_provider::greet',
    ];
};
