# Template Engine Guide

Complete guide to the Razy Template Engine: rendering templates, block system, entities, and data binding.

---

## Table of Contents

1. [Core Concepts](#core-concepts)
2. [Templates](#templates)
3. [Entities](#entities)
4. [Blocks](#blocks)
5. [Source](#source)
6. [Working with Templates](#working-with-templates)
7. [Block Isolation](#block-isolation)
8. [Advanced Patterns](#advanced-patterns)

---

### Core Concepts

#### Templates

A template file containing placeholders and block markers that get replaced with actual values during rendering.

**Placeholder syntax**: `{$variable_name}`

```markdown
# Hello {$name}

Welcome to {$app_name}!
Version: {$version}
```

#### Entities

Template structure represented as a tree of entities with:
- **Properties** (variables, data)
- **Children** (nested entities)
- **Blocks** (named sections for isolated content rendering)

#### Blocks

Blocks are named sections within a template marked with HTML comments:

```markdown
<!-- START BLOCK: section_name -->
Block content here with {$placeholder}
<!-- END BLOCK: section_name -->
```

Blocks allow you to:
- Isolate content for targeted data binding
- Render specific sections independently
- Structure complex templates into logical units

The entire template itself is treated as a block (the root block).

```markdown
<!-- START BLOCK: section_name -->
Block content here with {$placeholder}
    <!-- START BLOCK: sub_section_name -->
   Sub Block content here with {$placeholder}
    <!-- END BLOCK: sub_section_name -->
<!-- END BLOCK: section_name -->
```

#### Source

The `Source` object represents parsed templates with entity trees and:
- References to the root entity
- Manages data assignment
- Handles output rendering

## Basic Usage

### Loading and Rendering a Template

```php
use Razy\Template;

// Load template file
$source = Template::LoadFile('/path/to/template.md');

// Get the root entity
$root = $source->getRoot();

// Assign variables to root entity
$root->assign([
    'version' => '1.0.0',
    'date' => 'February 9, 2026',
    'name' => 'John Doe'
]);

// Render and output
echo $source->output();
```

### Output

The `output()` method:
- Traverses the entity tree
- Replaces all placeholders with assigned values
- Returns the fully rendered template as a string

```php
$rendered = $source->output();
file_put_contents('output.md', $rendered);
```

## Working with Blocks

### Check if Block Exists

```php
$root = $source->getRoot();

if ($root->hasBlock('section_name')) {
    // Block exists
}
```

### Access and Assign to a Block

```php
// Create or get a block reference (newBlock)
$block = $root->newBlock('doc');

// Assign data to the block
$block->assign([
    'title' => 'Documentation',
    'author' => 'Team'
]);
```

When you call `newBlock('blockName')`:
- It finds the named block in the template
- Returns an entity reference to that block
- Allows you to assign data specifically to that block

### Conditional Block Assignment

```php
$root = $source->getRoot();

// Assign to block if it exists
if ($root->hasBlock('doc')) {
    $root->newBlock('doc')->assign($params);
} else {
    // Fallback to root-level assignment
    $root->assign($params);
}
```

## Practical Examples

### Example 1: Simple Document Template

**Template file** (`document.md`):
```markdown
# {$title}

By {$author}

{$content}
```

**PHP Code**:
```php
$source = Template::LoadFile('document.md');
$source->getRoot()->assign([
    'title' => 'My Article',
    'author' => 'Jane Smith',
    'content' => 'This is the article body.'
]);

echo $source->output();
```

**Output**:
```markdown
# My Article

By Jane Smith

This is the article body.
```

### Example 2: Multi-Block Template

**Template file** (`report.md`):
```markdown
# {$report_title}

<!-- START BLOCK: summary -->
Summary: {$summary_text}
<!-- END BLOCK: summary -->

<!-- START BLOCK: details -->
Details: {$details_text}
<!-- END BLOCK: details -->
```

**PHP Code**:
```php
$source = Template::LoadFile('report.md');
$root = $source->getRoot();

// Assign to specific block
$root->newBlock('summary')->assign([
    'summary_text' => 'Quick overview'
]);

$root->newBlock('details')->assign([
    'details_text' => 'Full information'
]);

echo $source->output();
```

### Example 3: Root Block Assignment

When the entire template content needs parametrization, treat it as a root block:

**Template file** (`config.md`):
```markdown
# Configuration: {$config_name}

Version: {$version}
Generated: {$timestamp}
```

**PHP Code**:
```php
$source = Template::LoadFile('config.md');
$root = $source->getRoot();

// Check if root has block 'doc' (or use root directly)
if ($root->hasBlock('doc')) {
    $root->newBlock('doc')->assign([
        'config_name' => 'Production',
        'version' => '2.0.0',
        'timestamp' => '2026-02-09'
    ]);
} else {
    $root->assign([
        'config_name' => 'Production',
        'version' => '2.0.0',
        'timestamp' => '2026-02-09'
    ]);
}

echo $source->output();
```

## UTF-8 BOM Handling

### The Problem

UTF-8 files may contain a Byte Order Mark (BOM) at the beginning. If a template file has a BOM, the template parser might fail or produce unexpected output, especially when the template content is processed as PHP code.

### The Solution

Strip the BOM before processing:

```php
public static function stripUtf8Bom($content)
{
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        return substr($content, 3);
    }
    return $content;
}

// Usage
$content = file_get_contents('template.md');
$content = self::stripUtf8Bom($content);
$source = Template::LoadFile($tempFile); // After writing cleaned content
```

### Best Practice

Always strip BOM from template files before passing them to the Template Engine:

```php
$rawContent = file_get_contents($templatePath);
$cleanedContent = $this->stripUtf8Bom($rawContent);
file_put_contents($tempPath, $cleanedContent);
$source = Template::LoadFile($tempPath);
```

## Common Patterns

### Pattern 1: Conditional Root Block Usage

```php
$source = Template::LoadFile($templatePath);
$root = $source->getRoot();

$params = [
    'key1' => 'value1',
    'key2' => 'value2'
];

if ($root->hasBlock('doc')) {
    $root->newBlock('doc')->assign($params);
} else {
    $root->assign($params);
}

return $source->output();
```

**Use case**: Flexible template structure where some templates have explicit blocks and others don't.

### Pattern 2: Multiple Block Assignment

```php
$source = Template::LoadFile($templatePath);
$root = $source->getRoot();

// Assign different data to different blocks
foreach ($blockData as $blockName => $blockParams) {
    if ($root->hasBlock($blockName)) {
        $root->newBlock($blockName)->assign($blockParams);
    }
}

return $source->output();
```

**Use case**: Template with multiple named sections, each receiving independent data.

### Pattern 3: Template with BOM Stripping

```php
$templatePath = '/path/to/template.md';
$content = file_get_contents($templatePath);
$content = $this->stripUtf8Bom($content);

$tempPath = tempnam(sys_get_temp_dir(), 'tpl_');
file_put_contents($tempPath, $content);

try {
    $source = Template::LoadFile($tempPath);
    $root = $source->getRoot();
    
    if ($root->hasBlock('doc')) {
        $root->newBlock('doc')->assign($params);
    } else {
        $root->assign($params);
    }
    
    return $source->output();
} finally {
    @unlink($tempPath);
}
```

**Use case**: Production rendering with safety measures for BOM-contaminated files.

## Advanced Features

### Parameter Modifiers

Parameters can be processed through modifier chains to transform values:

```markdown
{$parameter|modifier1:"arg1":"arg2"|modifier2}
```

**Example**:
```markdown
Price: {$price|format:"USD":"2"}
// If modifiers are defined, this pipes $price through format() with arguments "USD" and "2"
```

### Function Tags

Function tags provide dynamic operations within templates:

#### @if - Conditional Rendering

```markdown
{@if $condition}
Content shown when condition is true
{/if}

{@if $user->gettype="admin"}
Admin panel
{/if}

{@if $value="hello"|$value="world"}
One of the values matches
{/if}
```

#### @each - Iteration

```markdown
<!-- Simple iteration -->
{@each $items}
Item: {$kvp.key} = {$kvp.value}
{/each}

<!-- With custom variable names -->
{@each source=$items as="current"}
Current: {$current.key} = {$current.value}
{/each}
```

#### @def - Variable Definition

```markdown
{@def "appName" "My Application"}
Application: {$appName}

<!-- Copy from another variable -->
{@def "userEmail" $user.email}
Email: {$userEmail}
```

#### @template - Template Loading

Load external templates with parameters:

```markdown
{@template:templateName paramA="value" paramB=$variable.path}
```

### Advanced Block Types

Beyond simple `START/END BLOCK` markers, the Template Engine supports specialized block types:

#### TEMPLATE Blocks

Define reusable template content within a block:

```markdown
<!-- START BLOCK: container -->
    <!-- TEMPLATE BLOCK: card -->
    <div class="card">
        <h3>{$title}</h3>
        <p>{$description}</p>
    </div>
    <!-- END BLOCK: card -->
<!-- END BLOCK: container -->
```

#### USE Blocks

Reference and use a TEMPLATE block:

```markdown
<!-- START BLOCK: list -->
    <!-- USE template BLOCK: card -->
<!-- END BLOCK: list -->
```

Note: Templates can only be used within the same block scope.

#### INCLUDE Blocks

Include external template files:

```markdown
<!-- START BLOCK: main -->
    <!-- INCLUDE BLOCK: partials/header.tpl -->
    <!-- INCLUDE BLOCK: components/sidebar.tpl -->
<!-- END BLOCK: main -->
```

Included templates are loaded relative to the current template file's location.

#### WRAPPER Blocks

Wrap a block's content with HTML structure:

```markdown
<!-- WRAPPER BLOCK: items -->
<div class="wrapper">
    <ul>
        <!-- START BLOCK: items -->
        <li>{$item}</li>
        <!-- END BLOCK: items -->
    </ul>
</div>
<!-- END BLOCK: items -->
```

### Parameter Paths

Access nested data using dot notation:

```markdown
<!-- Accessing nested values -->
{$user.profile.name}
{$settings.database.host}
{$data.path.to.value}
```

Paths traverse the entity's property tree:
```php
$entity->assign([
    'user' => [
        'profile' => [
            'name' => 'John'
        ]
    ]
]);

// Template: {$user.profile.name} outputs: John
```

### Pipe Operator

Use `|` for alternation in conditions:

```markdown
{@if $status="active"|$status="pending"|$status="review"}
Item is in a valid state
{/if}
```

### Complex Conditions

Combine conditions with operators:

```markdown
{@if $value->gettype="array",($data.type="product"|$data.type="service")}
Complex condition passed to plugin
{/if}
```

## Template Engine API Reference

### Template Class

- **`Template::LoadFile($filePath)`**: Load and parse a template file, return a Source object.

### Source Class

- **`getRoot()`**: Get the root entity of the template.
- **`output()`**: Render the template and return the output string.

### Entity Class

- **`assign($data)`**: Assign variables to this entity. `$data` is an associative array.
- **`getRoot()`**: Get the root entity.
- **`hasBlock($blockName)`**: Check if a named block exists.
- **`newBlock($blockName)`**: Get or create a named block entity and return it for assignment.

## Best Practices

1. **Always strip BOM** before processing template files that may contain UTF-8 BOM.
2. **Use named blocks** for complex templates with multiple sections.
3. **Check block existence** before calling `newBlock()` for defensive programming.
4. **Keep templates simple** – avoid deep nesting of logic in templates; use modifiers and function tags for processing.
5. **Use meaningful variable names** in templates for clarity.
6. **Document block purposes** with comments in template files.
7. **Separate content from logic** – templates should focus on structure, not business logic.
8. **Use path notation** for nested data access instead of flattening variables during assignment.
9. **Leverage function tags** (`@if`, `@each`, `@def`) for dynamic content rather than complex conditionals in PHP.
10. **Prefer INCLUDE blocks** over template file concatenation for external content inclusion.
11. **Use TEMPLATE blocks** for reusable patterns within the same scope.
12. **Be careful with scope** - TEMPLATE blocks and their USE references must be in the same parent block.

## Troubleshooting

### Placeholders Not Replaced

**Issue**: `{$variable}` appears in output unchanged.

**Cause**: Variable not assigned or misnamed.

**Fix**: Verify the variable name in `assign()` matches the placeholder name exactly.

```php
// Template: Hello {$name}
$entity->assign(['name' => 'John']); // Correct
$entity->assign(['username' => 'John']); // Wrong
```

### Block Not Found

**Issue**: Block assignment does nothing.

**Cause**: Block name doesn't exist or is misspelled.

**Fix**: Verify block name using `hasBlock()` first.

```php
if ($root->hasBlock('section')) {
    $root->newBlock('section')->assign($data);
}
```

### UTF-8 BOM Errors

**Issue**: Template rendering fails or produces garbled output.

**Cause**: Template file contains UTF-8 BOM.

**Fix**: Ensure BOM is stripped before loading.

```php
$content = $this->stripUtf8Bom(file_get_contents($path));
```

## Advanced Resources

For comprehensive coverage of all Template Engine features including modifiers, function tag plugins, and block processor implementations, refer to the source code:

- **Template Engine Core**: [src/library/Razy/Template.php](../../../src/library/Razy/Template.php) - Main Template class
- **Entity & Block Handling**: [src/library/Razy/Template/Entity.php](../../../src/library/Razy/Template/Entity.php) - Entity class with block and property management
- **Template Rendering**: [src/library/Razy/Template/Source.php](../../../src/library/Razy/Template/Source.php) - Source class for output rendering
- **Block Management**: [src/library/Razy/Template/Block.php](../../../src/library/Razy/Template/Block.php) - Block entity implementation
- **Function Tag Plugins**: [src/plugins/Template/](../../../src/plugins/Template/) - Built-in function tags (`@if`, `@each`, `@def`, `@template`, etc.)
- **Template Modifiers**: [src/plugins/Template/modifier.*](../../../src/plugins/Template/) - Available parameter modifiers

For real-world examples, see:
- [Razy Framework README](../../../readme.md) - Section on "Template Engine"
- [LLM Prompt Templates](../../../src/asset/prompt/) - Production template examples using the Template Engine
