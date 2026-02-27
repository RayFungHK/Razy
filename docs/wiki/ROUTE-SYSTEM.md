# Route System Guide

**Reference Module**: `test-razy-cli/sites/mysite/demo/route_demo/`

---

## Overview

Razy uses `addRoute()` to define URL patterns with parameter capture. Routes map URL segments to controller methods with automatic parameter extraction.

---

## Basic Syntax

```php
$this->getAgent()->addRoute('/module/path/(:token)', 'handler_method');
```

**Critical Requirements**:
- ✅ Leading slash: `/route_demo/user/(:d)`
- ✅ Parentheses for capture: `(:d)` captures, `:d` just matches
- ❌ Wrong: `route_demo/user/:d` (no leading slash, no parentheses)

---

## Token Types

| Token | Matches | Capture | Example URL | Captured Value |
|-------|---------|---------|-------------|----------------|
| `:a` | Any character | `(:a)` | `/item/abc-123` | `abc-123` |
| `:d` | Digits only | `(:d)` | `/user/456` | `456` |
| `:w` | Alpha only | `(:w)` | `/product/shoes` | `shoes` |
| `:D` | Non-digits | `(:D)` | `/tag/hello` | `hello` |
| `:W` | Non-alpha | `(:W)` | `/code/123-456` | `123-456` |
| `:[regex]` | Custom regex | `(:[a-z0-9-])` | `/tag/my-tag` | `my-tag` |

---

## Length Constraints

| Syntax | Description | Example |
|--------|-------------|---------|
| `{n}` | Exact length | `(:a{6})` = exactly 6 chars |
| `{min,max}` | Length range | `(:a{3,10})` = 3-10 chars |
| `{min,}` | Minimum length | `(:a{3,})` = 3+ chars |

---

## Route Examples

### User ID (digits only)
```php
// Route: /route_demo/user/123
$agent->addRoute('/route_demo/user/(:d)', 'user');

public function user(int $id): string {
    return "User ID: $id";
}
```

### Article Slug (any characters)
```php
// Route: /route_demo/article/my-article-title
$agent->addRoute('/route_demo/article/(:a)', 'article');

public function article(string $slug): string {
    return "Article: $slug";
}
```

### Product Name (alpha only)
```php
// Route: /route_demo/product/shoes
$agent->addRoute('/route_demo/product/(:w)', 'product');

public function product(string $name): string {
    return "Product: $name";
}
```

### Invite Code (exact length)
```php
// Route: /route_demo/code/ABC123
$agent->addRoute('/route_demo/code/(:a{6})', 'code');

public function code(string $code): string {
    return "Code: $code (6 chars)";
}
```

### Search Query (length range)
```php
// Route: /route_demo/search/keyword
$agent->addRoute('/route_demo/search/(:a{3,10})', 'search');

public function search(string $query): string {
    return "Search: $query (3-10 chars)";
}
```

### Custom Regex
```php
// Route: /route_demo/tag/my-tag-name
$agent->addRoute('/route_demo/tag/(:[a-z0-9-]+)', 'tag');

public function tag(string $tag): string {
    return "Tag: $tag";
}
```

---

## Complete Example Controller

```php
class Route_demo extends Controller
{
    public function __onInit(): int
    {
        $agent = $this->getAgent();
        
        $agent->addRoute('/route_demo/user/(:d)', 'user');
        $agent->addRoute('/route_demo/article/(:a)', 'article');
        $agent->addRoute('/route_demo/product/(:w)', 'product');
        $agent->addRoute('/route_demo/code/(:a{6})', 'code');
        $agent->addRoute('/route_demo/search/(:a{3,10})', 'search');
        $agent->addRoute('/route_demo/tag/(:[a-z0-9-]+)', 'tag');
        
        return Controller::PLUGIN_LOADED;
    }

    public function main(): string {
        return 'Route Demo - visit /route_demo/user/123';
    }

    public function user(int $id): string {
        return json_encode(['type' => 'user', 'id' => $id]);
    }

    public function article(string $slug): string {
        return json_encode(['type' => 'article', 'slug' => $slug]);
    }
    // ... more handlers
}
```

---

## Testing Routes

```powershell
cd test-razy-cli
C:\MAMP\bin\php\php8.3.1\php.exe -S localhost:8080

$wc = New-Object System.Net.WebClient

# Test each pattern
$wc.DownloadString("http://localhost:8080/route_demo/user/123")
$wc.DownloadString("http://localhost:8080/route_demo/article/my-post")
$wc.DownloadString("http://localhost:8080/route_demo/product/shoes")
$wc.DownloadString("http://localhost:8080/route_demo/code/ABC123")
$wc.DownloadString("http://localhost:8080/route_demo/search/hello")
$wc.DownloadString("http://localhost:8080/route_demo/tag/my-tag")
```

---

## Common Mistakes

| Mistake | Correct |
|---------|---------|
| `'route/path/:d'` | `'/route/path/(:d)'` |
| `$this->addRoute(...)` | `$this->getAgent()->addRoute(...)` |
| Function param not typed | Always type: `function($id)` → `function(int $id)` |
| Multiple `:a` in sequence | May fail - use separate routes |

---

## Handler File Pattern

For complex routes, use separate handler files:

**File**: `route_demo.user.php`
```php
class Route_demo_user extends Controller
{
    public function main(int $id): string {
        return "User: $id";
    }
}
```

**Route registration**:
```php
$agent->addRoute('/route_demo/user/(:d)', 'user.main');
```
