<?php

/**
 * Markdown Service Demo Route
 * 
 * URL: /system/markdown_service/demo
 * 
 * Demonstrates the markdown parsing service with sample content.
 */

return function (): void {
    // Sample markdown with GFM features
    $sampleMarkdown = <<<'MARKDOWN'
# Markdown Service Demo

This demonstrates the **league/commonmark** library wrapped in a Razy service.

## Features

- **Bold** and *italic* text
- [Links](https://example.com)
- Code: `inline code`

### Code Block

```php
<?php
// Use the markdown service from any module
$result = $this->api('markdown')->parse($text);
echo $result['html'];
```

### Table (GFM)

| Module | Depends On | Notes |
|--------|------------|-------|
| markdown_consumer | markdown_service | Uses API |
| blog_module | markdown_service | Uses API |
| docs_module | markdown_service | Uses API |

### Task List (GFM)

- [x] Create markdown_service module
- [x] Implement parse API
- [x] Implement parseFile API
- [ ] Add more extensions

### Version Conflict Solution

> **Problem**: ModuleA wants commonmark@1.0, ModuleB wants commonmark@2.0
> 
> **Solution**: Create a shared service that declares the dependency ONCE.
> All modules depend on the service, not the library directly.

---

*This pattern ensures only ONE version is loaded, avoiding conflicts.*
MARKDOWN;

    // Use the service to parse (demonstrating self-use)
    $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]);
    
    $html = $converter->convert($sampleMarkdown)->getContent();
    
    // Output with basic styling
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Markdown Service Demo</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        pre code { padding: 0; background: none; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
        th { background: #f4f4f4; }
        blockquote { border-left: 4px solid #0066cc; margin: 20px 0; padding: 10px 20px; background: #f9f9f9; }
        .source { margin-top: 40px; padding-top: 20px; border-top: 1px solid #eee; }
        .source-code { background: #2d2d2d; color: #f8f8f2; padding: 20px; border-radius: 5px; white-space: pre-wrap; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    {$html}
    
    <div class="source">
        <h2>Source Markdown</h2>
        <div class="source-code">{$sampleMarkdown}</div>
    </div>
</body>
</html>
HTML;
};
