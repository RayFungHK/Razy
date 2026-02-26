<?php
/**
 * Code handler - Captures exactly 6 characters
 * Pattern: code/(:a{6})
 * 
 * @llm {n} specifies exact character count
 */
return function (string $code): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'code',
        'pattern' => 'code/(:a{6})',
        'captured' => [
            'code' => $code,
            'length' => strlen($code),
            'valid_length' => strlen($code) === 6,
        ],
        'explanation' => '{6} requires EXACTLY 6 characters. :a{6} means any 6 characters.',
        'test_urls' => [
            'code/ABC123' => 'code = ABC123 (valid)',
            'code/XY9876' => 'code = XY9876 (valid)',
            'code/ABC12' => 'FAILS - only 5 characters',
            'code/ABC1234' => 'FAILS - 7 characters',
        ],
    ], JSON_PRETTY_PRINT);
};
