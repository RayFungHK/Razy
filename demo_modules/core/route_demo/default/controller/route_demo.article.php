<?php
/**
 * Article handler - Captures any characters (slug)
 * Pattern: article/(:a)
 * 
 * @llm :a matches any characters (most permissive pattern)
 */
return function (string $slug): void {
    header('Content-Type: application/json');
    
    echo json_encode([
        'handler' => 'article',
        'pattern' => 'article/(:a)',
        'captured' => [
            'slug' => $slug,
            'length' => strlen($slug),
        ],
        'explanation' => ':a matches ANY characters - letters, numbers, dashes, underscores, etc.',
        'test_urls' => [
            'article/hello-world' => 'slug = hello-world',
            'article/my_post_123' => 'slug = my_post_123',
            'article/Über-Café' => 'slug = Über-Café (unicode)',
        ],
    ], JSON_PRETTY_PRINT);
};
