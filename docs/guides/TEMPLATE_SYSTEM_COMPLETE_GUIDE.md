# Razy Template Engine - Complete Documentation Summary

## Overview

This documentation covers the complete Razy template engine system, from basic nested blocks to advanced patterns. The Razy framework provides a powerful block-based templating system that combines HTML structure with PHP-like programming capabilities.

## Template System Architecture

### Core Components

**Template\Entity API** - Programmatic template block management:
- `newBlock(name)` - Create a new block instance
- `assign(data)` - Assign variables to a block
- `getRoot()` - Get root block for structure access
- `output()` - Render the complete template

### Block Types

The template system supports 6 distinct block types:

| Block Type | Delimiter | Purpose | When to Use |
|-----------|-----------|---------|------------|
| **START/END** | `<!-- START BLOCK: name -->...<!-- END BLOCK: name -->` | Repeatable block that creates one instance per `newBlock()` call | Regular repeating elements (list items, table rows) |
| **WRAPPER** | `<!-- WRAPPER BLOCK: name -->wrapper...<!-- START BLOCK: name -->item<!-- END BLOCK: name -->...<!-- END BLOCK: name -->` | Container appears once, inner block repeats | Lists with single container (tag clouds, card grids) |
| **INCLUDE** | `<!-- INCLUDE BLOCK: path/to/file.tpl -->` | Load external template files | Headers, footers, shared components |
| **TEMPLATE** | `<!-- TEMPLATE BLOCK: name -->...<!-- END BLOCK: name -->` | Define reusable read-only template | Blueprint templates for USE blocks |
| **USE** | `<!-- USE template_name BLOCK: instance_name -->` | Reference a TEMPLATE block from parent | DRY pattern for repeated card/item structures |
| **RECURSION** | `<!-- RECURSION BLOCK: name -->` | Self-reference for nesting | Tree menus, nested comments, hierarchies |

## Working Examples

### Example 1: Nested Blocks (Endpoint: /navigation/render)

**Handler:**
```php
$source = $this->loadTemplate('menu')->queue('menu');
$root = $source->getRoot();

$categories = ['products' => 'Products', 'services' => 'Services'];
$menuItems = [
    'products' => [
        ['name' => 'Software', 'path' => '/products/software'],
        ['name' => 'Hardware', 'path' => '/products/hardware'],
    ],
    'services' => [
        ['name' => 'Consulting', 'path' => '/services/consulting'],
    ],
];

foreach ($categories as $catCode => $catName) {
    $categoryBlock = $root->newBlock('category')->assign(['name' => $catName]);
    
    if (isset($menuItems[$catCode])) {
        foreach ($menuItems[$catCode] as $item) {
            $categoryBlock->newBlock('item')->assign([
                'name' => $item['name'],
                'path' => $item['path'],
            ]);
        }
    }
}

echo $source->output();
```

**Template:**
```html
<!-- START BLOCK: category -->
<div class="category">
    <strong>{$name}</strong>
    <ul>
        <!-- START BLOCK: item -->
        <li><a href="{$path}">{$name}</a></li>
        <!-- END BLOCK: item -->
    </ul>
</div>
<!-- END BLOCK: category -->
```

**Output:**
```html
<div class="category">
    <strong>Products</strong>
    <ul>
        <li><a href="/products/software">Software</a></li>
        <li><a href="/products/hardware">Hardware</a></li>
    </ul>
</div>
<div class="category">
    <strong>Services</strong>
    <ul>
        <li><a href="/services/consulting">Consulting</a></li>
    </ul>
</div>
```

### Example 2: WRAPPER Blocks (Endpoint: /navigation/tags)

**Handler:**
```php
$source = $this->loadTemplate('tags')->queue('tags');
$root = $source->getRoot();

$tags = [
    ['name' => 'PHP', 'count' => 245, 'popularity' => 'high'],
    ['name' => 'JavaScript', 'count' => 198, 'popularity' => 'high'],
];

foreach ($tags as $tag) {
    $root->newBlock('tag')->assign([
        'name' => $tag['name'],
        'count' => $tag['count'],
        'popularity' => $tag['popularity'],
    ]);
}

echo $source->output();
```

**Template:**
```html
<!-- WRAPPER BLOCK: tag -->
<div class="tag-cloud">
    <!-- START BLOCK: tag -->
    <a href="#" class="tag {$popularity}">
        {$name}
        <span class="tag-count">{$count}</span>
    </a>
    <!-- END BLOCK: tag -->
</div>
<!-- END BLOCK: tag -->
```

**Key Difference:** The `<div class="tag-cloud">` wrapper renders only ONCE, while each `<a class="tag">` renders for every `newBlock('tag')` call.

### Example 3: Advanced Blocks (Endpoint: /navigation/advanced)

#### INCLUDE Block - External Files

**Handler Variable:**
```php
$this->getTemplate()->assign(['current_date' => date('Y-m-d')]);
```

**Main Template:**
```html
<!-- INCLUDE BLOCK: include/header.tpl -->
<div class="info-box">
    The header above was included from include/header.tpl
</div>
```

**External File (include/header.tpl):**
```html
<div class="header-component">
    <div class="logo">Razy Framework</div>
    <div class="tagline">Advanced Template System</div>
    <div class="date">Current Date: {$current_date}</div>
</div>
```

#### RECURSION Block - Nested Structures

**Handler:**
```php
$navigation = [
    ['name' => 'Products', 'url' => '/products', 'children' => [
        ['name' => 'Software', 'url' => '/products/software', 'children' => [
            ['name' => 'Web Apps', 'url' => '/products/software/web'],
            ['name' => 'Mobile Apps', 'url' => '/products/software/mobile'],
        ]],
    ]],
    ['name' => 'Services', 'url' => '/services', 'children' => [
        ['name' => 'Consulting', 'url' => '/services/consulting'],
    ]],
];

$buildRecursive = function($parent, $items) use (&$buildRecursive) {
    foreach ($items as $item) {
        $menuBlock = $parent->newBlock('menu')->assign([
            'name' => $item['name'],
            'url' => $item['url'],
            'has_children' => !empty($item['children']),
        ]);
        
        if (!empty($item['children'])) {
            $buildRecursive($menuBlock, $item['children']);
        }
    }
};

$buildRecursive($root, $navigation);
```

**Template:**
```html
<ul class="nav-menu">
    <!-- START BLOCK: menu -->
    <li>
        <a href="{$url}">{$name}</a>
        {@if $has_children}
        <ul class="nav-menu">
            <!-- RECURSION BLOCK: menu -->
        </ul>
        {/if}
    </li>
    <!-- END BLOCK: menu -->
</ul>
```

**Output:**
```html
<ul class="nav-menu">
    <li>
        <a href="/products">Products</a>
        <ul class="nav-menu">
            <li>
                <a href="/products/software">Software</a>
                <ul class="nav-menu">
                    <li>
                        <a href="/products/software/web">Web Apps</a>
                    </li>
                    <li>
                        <a href="/products/software/mobile">Mobile Apps</a>
                    </li>
                </ul>
            </li>
        </ul>
    </li>
    <li>
        <a href="/services">Services</a>
        <ul class="nav-menu">
            <li>
                <a href="/services/consulting">Consulting</a>
            </li>
        </ul>
    </li>
</ul>
```

## Block Processing Rules

### Block Hierarchy and Variable Scope

The template engine uses a **4-level scope hierarchy** for parameter resolution. When rendering, if a parameter is not found at the current scope, it is resolved upward through the chain:

```
Entity (narrowest) → Block → Source → Template (widest)
```

| Scope | Class | Visibility | Typical Use |
|-------|-------|------------|-------------|
| **Template** | `Template` | All Sources, Blocks, and Entities | Site name, global config |
| **Source** | `Source` | All Blocks/Entities in one template file | Page title, layout data |
| **Block** | `Block` | All Entities spawned from that block | Shared column headers |
| **Entity** | `Entity` | This single entity instance only | Per-row data in a loop |

**Key rules:**
1. **Narrower scope wins**: An Entity parameter overrides the same name at Block, Source, or Template scope.
2. **Upward resolution**: `getValue($name, true)` checks the current scope, then walks up to the next wider scope until found or returns null.
3. **No cross-Source leaking**: Source-level parameters are file-scoped — they don't affect other Source files loaded by the same Template.
4. **Block inheritance**: Child blocks inherit from parent blocks within the same Source.

### assign() vs bind()

Both `assign()` and `bind()` are available at all 4 scope levels, but they differ in when the value is resolved:

| Method | Timing | Behavior |
|--------|--------|----------|
| `assign($name, $value)` | **Immediate** | Copies the value at call time |
| `bind($name, &$var)` | **Deferred** | Stores a reference pointer; value is not resolved until `output()` / `process()` |

`bind()` is useful when the value is computed or modified after the template structure is set up:

```php
$total = 0;
$source->bind('total', $total);

foreach ($items as $item) {
    $root->newBlock('row')->assign($item);
    $total += $item['price'];
}

// At render time, {$total} reflects the final accumulated value
echo $source->output();
```

### Best Practices

1. **Use WRAPPER for containers**: When you need a single wrapper around multiple items
2. **Use nested START blocks**: For hierarchical data with multiple nesting levels
3. **Use RECURSION sparingly**: Only for self-similar nested structures
4. **Use INCLUDE for components**: Separate reusable elements (headers, footers)
5. **Assign global vars**: Use `getTemplate()->assign()` for page-level variables

## Template Variable Reference

### Scope Levels

#### Template (Manager) Scope — Global Defaults

Variables assigned at the template level are available in all Sources, Blocks, and Entities as fallback defaults:

```php
$this->getTemplate()->assign([
    'site_name' => 'My Site',
    'current_user' => 'John',
]);
```

#### Source Scope — File-Level

Variables assigned at the source level apply to all Blocks and Entities within one template file:

```php
$source = $tpl->load('page.tpl');
$source->assign(['page_title' => 'About Us']);
```

#### Block Scope — Block-Level

Variables assigned to a block are shared across all Entities spawned from it:

```php
$block = $source->getRootBlock()->getBlock('item');
$block->assign(['currency' => 'USD']);
```

#### Entity Scope — Instance-Level

Variables assigned to a specific entity apply only to that instance:

```php
$categoryBlock->newBlock('item')->assign([
    'name' => 'Item Name',
    'price' => 99.99,
]);
```

### Template Filters (Modifiers)

```html
{$name|upper}          <!-- Convert to uppercase -->
{$email|lower}         <!-- Convert to lowercase -->
{$text|nl2br}          <!-- Convert newlines to <br> -->
{$array|join:', '}     <!-- Join array with separator -->
{$text|trim}           <!-- Remove whitespace -->
{$price|gettype}       <!-- Get variable type -->
```

## Complete Test Module Structure

```
sites/mysite/test/navigation/
├── default/
│   ├── controller/
│   │   ├── navigation.php              # Route definitions
│   │   ├── navigation.render.php       # Nested blocks handler
│   │   ├── navigation.tags.php         # WRAPPER block handler
│   │   └── navigation.advanced.php     # INCLUDE + RECURSION handler
│   └── view/
│       ├── menu.tpl                    # Nested category/item template
│       ├── tags.tpl                    # WRAPPER tag cloud template
│       ├── advanced.tpl                # Complete demo template
│       └── include/
│           └── header.tpl              # External INCLUDE component
```

## Shared Modules and Loading Strategy

Shared modules live under the top-level `shared/` folder and can be loaded by **any distributor**. Use `autoload_shared` to enable shared module autoloading, and choose `greedy` based on how aggressively you want modules loaded.

**Greedy vs On-Demand**
- **Greedy** (`greedy => true`): auto-loads every module in the distributor folder.
- **On-Demand** (`greedy => false`): loads only the modules listed in `modules` (plus dependencies).

**Example dist.php with Shared Modules**
```php
return [
        'dist' => 'myapp',
        'autoload_shared' => true,
        'greedy' => false,
        'modules' => [
                '*' => [
                        'shared/api-service' => 'default',      // Shared APIs for all dists
                        'shared/template-renderer' => 'default', // Shared template service
                        'vendor/app' => 'default',
                ],
        ],
];
```

**Shared Module Layout**
```
shared/
    vendor/
        api-service/
            module.php
            default/
                package.php
                controller/
                    api_service.php
                api/
                    getData.php
                    process.php
        template-renderer/
            module.php
            default/
                package.php
                controller/
                    renderer.php
                view/
                    layout.tpl
```

**Shared Module APIs (Cross-Distributor)**

Shared modules can expose APIs that are accessible from **any distributor**:

```php
<?php
// shared/vendor/api-service/default/controller/api_service.php

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // These APIs accessible from ALL distributors
        $agent->addAPICommand('getData', 'api/getData.php');
        $agent->addAPICommand('process', 'api/process.php');
        return true;
    }
};
```

**Using Shared APIs with Templates**

Fetch data from shared services and populate templates across all distributors:

```php
<?php
// Route handler in any distributor calling shared API

return function ($id = 0) {
    // Call shared service API
    $data = $this->api('shared/api-service')->getData($id);
    
    // Load template and populate from shared API data
    $template = $this->loadTemplate('display')->queue('list');
    $root = $template->getRoot();
    
    foreach ($data['items'] as $item) {
        $root->newBlock('item')->assign([
            'id' => $item['id'],
            'title' => $item['title'],
            'source' => 'shared API service',
        ]);
    }
    
    return $template->output();
};
```

**Benefits of Shared Modules:**

✓ Code reuse across all distributors  
✓ Centralized business logic (data access, validation, processing)  
✓ Consistent APIs and interfaces for all apps  
✓ Easier to maintain shared functionality  
✓ Single source of truth for shared services

## Running the Examples

### Start PHP Development Server

```bash
cd C:\Users\RayFung\VSCode-Projects\Razy\test-razy-cli
php -S localhost:80
```

### Test Endpoints

- **Nested Blocks**: http://localhost/Razy/test-razy-cli/navigation/render
  - 3 categories with items
  - Shows Template\Entity API usage
  
- **WRAPPER Blocks**: http://localhost/Razy/test-razy-cli/navigation/tags
  - 6 tags with popularity classes
  - Demonstrates single wrapper with repeated items
  
- **Advanced Blocks**: http://localhost/Razy/test-razy-cli/navigation/advanced
    - External header included
    - 3 feature cards
    - Recursive menu (5 levels deep with nested `<ul>` wrappers)
    - Complete block syntax reference table

- **Modifiers & Function Tags**: http://localhost/Razy/test-razy-cli/navigation/modifiers
    - Built-in modifiers in action (upper, lower, capitalize, trim, addslashes, nl2br, alphabet, join, gettype)
    - Function tag example using `@if`

## Troubleshooting

### Common Issues and Solutions

**Problem:** Blocks not rendering (empty output)
- **Cause**: Mismatched block names between handler and template
- **Solution**: Ensure `newBlock('name')` exactly matches `<!-- START BLOCK: name -->`

**Problem:** RECURSION block not nesting
- **Cause**: Using different block names for parent and recursive call
- **Solution**: Both must have same name (e.g., both 'menu')

**Problem:** Variables not showing in blocks
- **Cause**: Variables assigned at wrong scope level
- **Solution**: Use the correct scope — Entity for per-item data, Block for shared block data, Source for file-wide data, Template for globals. Resolution order: Entity → Block → Source → Template.

**Problem:** INCLUDE block not finding file
- **Cause**: Incorrect relative path
- **Solution**: Use paths relative to current template directory (e.g., `include/header.tpl`)

## Additional Resources

- [ADVANCED_BLOCKS_DOCUMENTATION.md](ADVANCED_BLOCKS_DOCUMENTATION.md) - Detailed advanced block guide
- [readme.md](readme.md) - Main Razy framework documentation
- [RazyProject-Building.ipynb](RazyProject-Building.ipynb) - Step-by-step Jupyter notebook walkthrough

## Cross-Module API Integration with Templates

When using templates with cross-module APIs, you can fetch dynamic data from other modules and populate templates:

### Pattern: Fetch Data via API, Render with Template

```php
<?php
// Route handler: Fetch product catalog from another module and render

return function ($categoryId = 0) {
    try {
        // Call Product API module to get data
        $products = $this->api('catalog/products')->list($categoryId);
        
        // Load template and populate with API data
        $template = $this->loadTemplate('product-list')->queue('products');
        $root = $template->getRoot();
        
        // Create template blocks from API response
        foreach ($products as $product) {
            $root->newBlock('item')->assign([
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'image' => $product['image'],
            ]);
        }
        
        return $template->output();
    } catch (Throwable $e) {
        return '<div class="error">Failed to load products: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
};
```

### Template Example

```html
<!-- START BLOCK: item -->
<div class="product-card">
    <img src="{$image}" alt="{$name}" />
    <h3>{$name}</h3>
    <div class="price">${$price|gettype}</div>
    <button onclick="addToCart({$id})">Add to Cart</button>
</div>
<!-- END BLOCK: item -->
```

### Best Practices for Template + API

1. **Separate Concerns**: Use APIs for data fetching, templates for presentation
2. **Error Handling**: Always wrap API calls in try-catch
3. **Data Validation**: Validate API responses before populating templates
4. **Performance**: Cache API results when appropriate to avoid repeated calls
5. **Templating Logic**: Keep complex logic in handler, templates should be simple data binding

## Summary

The Razy template system provides:
- ✓ Clean, HTML-based block syntax
- ✓ Powerful programmatic block creation
- ✓ Support for complex hierarchical structures
- ✓ External template inclusion
- ✓ Recursive nesting for trees and hierarchies
- ✓ DRY template patterns with WRAPPER and RECURSION
- ✓ Complete variable scope management
- ✓ Seamless integration with cross-module APIs

This makes it an excellent choice for building complex, maintainable web applications where templates need to handle sophisticated data structures while remaining readable and easy to modify.
