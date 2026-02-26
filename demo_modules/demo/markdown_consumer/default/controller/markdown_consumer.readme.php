<?php

/**
 * README Parser Demo
 * 
 * Parses the project's README.md file using the markdown service.
 * 
 * URL: /demo/markdown_consumer/readme
 */

return function (): void {
    // Get the README.md path
    $readmePath = $this->getModuleSystemPath() . '/../../../../README.md';
    
    // Normalize path
    $readmePath = realpath($readmePath);
    
    if (!$readmePath) {
        // Fallback: use demo content
        $demoReadme = <<<'MD'
# Demo README

The actual README.md file was not found. This is demo content.

## About This Demo

This route demonstrates parsing markdown files using:

```php
$result = $this->api('markdown')->parseFile($filePath);
```

## Benefits of Shared Service Pattern

1. **No version conflicts** - Library version managed in ONE place
2. **Stable API** - Consumer modules don't break when library updates
3. **Easy testing** - Can mock the service API
4. **Centralized updates** - Update service module to upgrade library
MD;
        
        $result = $this->api('markdown')->parse($demoReadme);
    } else {
        // Parse the actual README file
        $result = $this->api('markdown')->parseFile($readmePath);
    }
    
    $html = $result['success'] ? $result['html'] : '<p class="error">Error: ' . htmlspecialchars($result['error']) . '</p>';
    $filePath = $result['file'] ?? 'Demo content';
    
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>README Parser - Markdown Consumer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        h1 { border-bottom: 2px solid #0066cc; padding-bottom: 10px; }
        .meta { background: #f4f4f4; padding: 15px; border-radius: 5px; margin-bottom: 20px; font-size: 14px; }
        .meta code { background: #e4e4e4; padding: 2px 6px; border-radius: 3px; }
        .content { border: 1px solid #ddd; border-radius: 8px; padding: 30px; }
        .content pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .content code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .content pre code { padding: 0; background: none; }
        .content table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        .content th, .content td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
        .content th { background: #f4f4f4; }
        .content img { max-width: 100%; }
        .error { color: #cc0000; background: #ffeeee; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>📄 README Parser Demo</h1>
    
    <div class="meta">
        <strong>File:</strong> <code>{$filePath}</code><br>
        <strong>Parsed using:</strong> <code>\$this->api('markdown')->parseFile()</code>
    </div>
    
    <div class="content">
        {$html}
    </div>
</body>
</html>
HTML;
};
