# Advanced Template Block Types Documentation

The template system supports advanced block patterns for more complex scenarios. These include **INCLUDE**, **TEMPLATE**, **USE**, and **RECURSION** blocks, which allow you to include external files, define reusable templates, reference templates in child blocks, and create self-referencing recursive structures.

## 1. INCLUDE Block - External File Inclusion

The `INCLUDE` block loads and inserts an external template file into the current template. The path is relative to the current template directory.

**Syntax:**
```html
<!-- INCLUDE BLOCK: path/to/file.tpl -->
```

**Example - External header component (`include/header.tpl`):**
```html
<div class="header-component">
    <div class="logo">Razy Framework</div>
    <div class="tagline">Advanced Template System</div>
    <div class="date">Current Date: {$current_date}</div>
</div>
```

**Main template using INCLUDE:**
```html
<!-- INCLUDE BLOCK: include/header.tpl -->
<div class="info-box">
    The header above was included from an external file.
</div>
```

**Use Case:** Reuse common components across multiple templates (headers, footers, navigation).

## 2. RECURSION Block - Self-Referencing for Nested Structures

The `RECURSION` block allows a block to reference itself within its own structure, enabling recursive rendering for hierarchical data like tree menus, nested comments, or categorized hierarchies.

**Syntax:**
```html
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
```

**Controller Example:**
```php
$navigation = [
    [
        'name' => 'Products',
        'url' => '/products',
        'children' => [
            [
                'name' => 'Software',
                'url' => '/products/software',
                'children' => [
                    ['name' => 'Web Apps', 'url' => '/products/software/web', 'children' => []],
                    ['name' => 'Mobile Apps', 'url' => '/products/software/mobile', 'children' => []],
                ],
            ],
        ],
    ],
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

**Rendered Output (trimmed):**
```html
<li>
    <a href="/products">Products</a>
    <ul class="nav-menu">
        <li>
            <a href="/products/software">Software</a>
            <ul class="nav-menu">
                <li>
                    <a href="/products/software/web">Web Apps</a>
                </li>
            </ul>
        </li>
    </ul>
</li>
```

**Use Case:** Tree menus, nested file structures, organizational hierarchies, and any hierarchical data.

## 3. TEMPLATE & USE Blocks - Reusable Template Patterns

The `TEMPLATE` block defines a reusable read-only template. The `USE` block references a TEMPLATE block defined in a parent, enabling DRY (Don't Repeat Yourself) patterns.

**Template Definition:**
```html
<!-- TEMPLATE BLOCK: card_template -->
<div class="feature-card">
    <h3>{$title}</h3>
    <p>{$description}</p>
</div>
<!-- END BLOCK: card_template -->
```

**Using the Template:**
```html
<!-- START BLOCK: advanced -->
    <!-- TEMPLATE BLOCK: card_template (defined once) -->
    <div class="feature-card">
        <h3>{$title}</h3>
        <p>{$description}</p>
    </div>
    <!-- END BLOCK: card_template -->

    <!-- Each card references the template -->
    <!-- START BLOCK: card -->
        <!-- USE card_template BLOCK: card_instance -->
    <!-- END BLOCK: card -->
<!-- END BLOCK: advanced -->
```

**Controller Code:**
```php
// Template defined automatically by parsing, then used by child blocks
$cards = [
    ['title' => 'Feature A', 'desc' => 'Advanced routing'],
    ['title' => 'Feature B', 'desc' => 'Template engine'],
    ['title' => 'Feature C', 'desc' => 'Database layer'],
];

foreach ($cards as $card) {
    $root->newBlock('card')->assign([
        'title' => $card['title'],
        'description' => $card['desc'],
    ]);
}
```

**Use Case:** Create DRY templates for cards, list items, table rows, and any repeated elements.

## Working Example: Advanced Blocks Demo

The test application demonstrates all advanced block types in action:

**Route:** `http://localhost/Razy/test-razy-cli/navigation/advanced`

**Handler:** `sites/mysite/test/navigation/default/controller/navigation.advanced.php`
- Builds recursive 5-level navigation menu structure
- Creates 3 cards with different title and description data
- Demonstrates recursive nesting with conditional rendering
- Handler uses both inline block creation and recursive processing

**Template:** `sites/mysite/test/navigation/default/view/advanced.tpl`
- Section 1: INCLUDE block loading `include/header.tpl` (gradient header with logo and date)
- Section 2: Regular START blocks for rendering cards
- Section 3: RECURSION block rendering nested menu with 5 levels deep
- Summary table documenting all block syntax patterns and use cases

**Actual Rendered Output includes:**
- ✓ External header file included with styled component (Razy Framework logo, tagline, date)
- ✓ Recursive menu rendering up to 5 levels deep:
    - Products
        - Software
            - Web Apps
                - Frontend Frameworks
                    - React
            - Mobile Apps
        - Hardware
    - Services
        - Consulting
        - Support
- ✓ 3 feature cards (Feature A, Feature B, Feature C) each with title and description
- ✓ Complete CSS styling with hover effects and responsive grid layout
- ✓ Styled recursive menu with indentation and hierarchical visual structure

This demonstrates the full power of Razy's block system for creating complex, reusable, and maintainable templates.

## Block Type Summary

| Block Type | Syntax | Purpose |
|-----------|--------|---------|
| **START/END** | `<!-- START BLOCK: name -->...<!-- END BLOCK: name -->` | Regular repeatable block for creating multiple instances |
| **WRAPPER** | `<!-- WRAPPER BLOCK: name -->wrapper content...<!-- START BLOCK: name -->item<!-- END BLOCK: name -->...<!-- END BLOCK: name -->` | Container that wraps repeated blocks, appears once while items repeat |
| **INCLUDE** | `<!-- INCLUDE BLOCK: path/to/file.tpl -->` | Loads and inserts external template files |
| **TEMPLATE** | `<!-- TEMPLATE BLOCK: name -->...<!-- END BLOCK: name -->` | Defines a reusable read-only template for USE blocks |
| **USE** | `<!-- USE template_name BLOCK: instance_name -->` | References a TEMPLATE block from a parent, inherits its structure |
| **RECURSION** | `<!-- RECURSION BLOCK: name -->` | References the parent block with the same name for nested structures |
