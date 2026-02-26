<?php

/**
 * Markdown Render Demo
 * 
 * Interactive markdown editor that uses the markdown service.
 * 
 * URL: /demo/markdown_consumer/render
 */

return function (): void {
    $defaultMarkdown = <<<'MARKDOWN'
# Hello World

This is a **markdown** editor demo.

## Features

- Uses `markdown_service` via API
- No direct library dependency
- Version-conflict free!

```php
// How this page works:
$result = $this->api('markdown')->parse($text);
```
MARKDOWN;

    $inputMarkdown = $_POST['markdown'] ?? $defaultMarkdown;
    
    // Call the markdown service via cross-module API
    // This is the KEY - we don't use the library directly!
    $result = $this->api('markdown')->parse($inputMarkdown);
    
    $html = $result['success'] ? $result['html'] : '<p class="error">' . htmlspecialchars($result['error']) . '</p>';
    $escaped = htmlspecialchars($inputMarkdown);
    
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Markdown Consumer - Render Demo</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; }
        .container { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; max-width: 1400px; margin: 0 auto; }
        .panel { border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
        .panel-header { background: #f4f4f4; padding: 10px 15px; font-weight: bold; border-bottom: 1px solid #ddd; }
        textarea { width: 100%; height: 400px; border: none; padding: 15px; font-family: monospace; font-size: 14px; resize: vertical; box-sizing: border-box; }
        .preview { padding: 15px; min-height: 400px; }
        .preview pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .preview code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .preview pre code { padding: 0; background: none; }
        button { background: #0066cc; color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 5px; font-size: 14px; }
        button:hover { background: #0052a3; }
        .info { background: #e7f3ff; border: 1px solid #b3d7ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .error { color: #cc0000; background: #ffeeee; padding: 10px; border-radius: 5px; }
        h1 { margin-top: 0; }
    </style>
</head>
<body>
    <h1>Markdown Consumer Demo</h1>
    
    <div class="info">
        <strong>How it works:</strong> This module uses <code>\$this->api('markdown')->parse()</code> to convert markdown.
        It does NOT depend on the library directly - only on the <code>markdown_service</code> module.
        This pattern prevents version conflicts when multiple modules need the same library.
    </div>
    
    <form method="post" class="container">
        <div class="panel">
            <div class="panel-header">Markdown Input</div>
            <textarea name="markdown">{$escaped}</textarea>
        </div>
        <div class="panel">
            <div class="panel-header">HTML Preview</div>
            <div class="preview">{$html}</div>
        </div>
    </form>
    
    <p><button type="submit" form="form" onclick="this.form = this.closest('form'); this.form.submit();">Render Markdown</button></p>
    
    <script>
        document.querySelector('textarea').addEventListener('input', function() {
            // Auto-submit on change (debounced)
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.form.submit(), 500);
        });
    </script>
</body>
</html>
HTML;
};
