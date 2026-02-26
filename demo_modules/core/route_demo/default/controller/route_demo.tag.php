<?php
/**
 * Tag handler - Captures with custom regex character class
 * Pattern: tag/(:[\w\d-]{1,30})
 * 
 * @llm :[regex] allows custom regex pattern inside brackets
 *      \w = word chars, \d = digits, - = hyphen
 */
return function (string $tag): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'tag',
        'pattern' => 'tag/(:[\w\d-]{1,30})',
        'captured' => [
            'tag' => $tag,
            'length' => strlen($tag),
            'matches_pattern' => (bool) preg_match('/^[\w\d-]{1,30}$/', $tag),
        ],
        'explanation' => 'Custom regex: [\w\d-] allows word chars, digits, and hyphens. {1,30} limits to 1-30 chars.',
        'regex_breakdown' => [
            '[\w\d-]' => 'Character class: word chars (a-zA-Z_), digits (0-9), hyphen (-)',
            '{1,30}' => 'Length constraint: 1 to 30 characters',
            ':' => 'Prefix indicating custom regex follows',
        ],
        'test_urls' => [
            'tag/web-dev' => 'tag = web-dev (valid)',
            'tag/php_8' => 'tag = php_8 (valid)',
            'tag/JavaScript' => 'tag = JavaScript (valid)',
            'tag/too@special!' => 'FAILS - @ and ! not in character class',
        ],
    ], JSON_PRETTY_PRINT);
};
