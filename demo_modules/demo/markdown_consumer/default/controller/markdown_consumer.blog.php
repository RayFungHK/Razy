<?php

/**
 * Simulated Blog Demo
 * 
 * Shows how a blog module would use the markdown service.
 * 
 * URL: /demo/markdown_consumer/blog
 */

return function (): void {
    // Simulated blog posts stored in markdown
    $posts = [
        [
            'title' => 'Getting Started with Razy',
            'date' => '2026-02-10',
            'content' => <<<'MD'
## Welcome to Razy!

Razy is a **PHP framework** for manageable development environments.

### Key Features

1. Module-based architecture
2. Built-in Composer integration
3. Cross-module API system

```php
// Example: Call another module's API
$result = $this->api('user')->getProfile($userId);
```

Try it today!
MD
        ],
        [
            'title' => 'Managing Composer Dependencies',
            'date' => '2026-02-09',
            'content' => <<<'MD'
## The Version Conflict Problem

When **ModuleA** needs `library@1.0` and **ModuleB** needs `library@2.0`, you have a conflict!

### Solution: Shared Service Pattern

| Approach | Result |
|----------|--------|
| Direct dependency | ❌ Version conflict |
| Shared service | ✅ Works perfectly |

Create a service module that wraps the library:

```php
// In service module's package.php
'prerequisite' => [
    'vendor/library' => '^2.0',  // ONE place
]

// In consumer module's package.php
'required' => [
    'system/library_service' => '*',  // Depend on service
]
```
MD
        ],
    ];
    
    // Render posts using markdown service
    $renderedPosts = [];
    foreach ($posts as $post) {
        $result = $this->api('markdown')->parse($post['content']);
        $renderedPosts[] = [
            'title' => $post['title'],
            'date' => $post['date'],
            'html' => $result['success'] ? $result['html'] : '<p>Error parsing markdown</p>',
        ];
    }
    
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Blog Demo - Markdown Consumer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .post { border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px; overflow: hidden; }
        .post-header { background: #f4f4f4; padding: 15px 20px; border-bottom: 1px solid #ddd; }
        .post-header h2 { margin: 0 0 5px 0; }
        .post-date { color: #666; font-size: 14px; }
        .post-content { padding: 20px; }
        .post-content pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .post-content code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .post-content pre code { padding: 0; background: none; }
        .post-content table { border-collapse: collapse; width: 100%; margin: 15px 0; }
        .post-content th, .post-content td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
        .post-content th { background: #f4f4f4; }
        h1 { border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
        .info { background: #e7f3ff; border: 1px solid #b3d7ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>📝 Blog Demo</h1>
    
    <div class="info">
        This simulated blog stores posts in markdown format and renders them using the 
        <code>markdown_service</code> via cross-module API.
    </div>
HTML;

    foreach ($renderedPosts as $post) {
        echo <<<HTML
    <article class="post">
        <div class="post-header">
            <h2>{$post['title']}</h2>
            <div class="post-date">Published: {$post['date']}</div>
        </div>
        <div class="post-content">
            {$post['html']}
        </div>
    </article>
HTML;
    }
    
    echo <<<HTML
</body>
</html>
HTML;
};
