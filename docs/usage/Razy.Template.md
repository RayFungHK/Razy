# Razy\Template

## Summary
- Template engine manager with plugin loading and queued rendering.
- Owns shared parameters at the **manager (global)** scope — the widest scope level.

## Parameter Scope Hierarchy

The template engine uses a 4-level scope for parameter resolution, from narrowest to widest:

```
Entity (narrowest) → Block → Source → Template (widest)
```

- **Template** (this class): Global defaults visible to every Source, Block, and Entity.
- **Source**: File-level parameters shared across all Blocks/Entities in one template file.
- **Block**: Block-level parameters visible to all Entities spawned from that block.
- **Entity**: Instance-specific parameters for a single rendered entity.

When rendering, `getValue()` resolves upward through the chain — if a parameter is not found at the Entity scope, it checks Block, then Source, then Template.

## Construction
- `new Template($pluginFolder = '')`.

## Key methods
- `load($path, ?ModuleInfo $module)`: create `Template\Source`.
- `assign($name, $value)`: set manager-scope parameters (global defaults). Value is copied immediately.
- `bind($name, &$value)`: bind reference variable at manager scope. Value is **not resolved until render time** — changes to the original variable after binding are reflected in the output.
- `getValue($name)`: get parameter from manager scope (final fallback).
- `loadTemplate($name, $path)`: register global template block.
- `addQueue(Source $source, $name)`, `outputQueued($sections)`.
- `loadPlugin($type, $name)`: plugin entity resolver.
- `ParseContent()` and `GetValueByPath()` for static parsing.

## Usage notes
- Use `Template::AddPluginFolder()` to add plugin directories.
- Plugins are cached per `type.name` key.
- Parameters assigned here act as fallbacks — they can be overridden at Source, Block, or Entity scope.
- `bind()` stores a reference pointer — the value is deferred until the template is rendered. This is useful for values that are computed or modified after the template is set up.

## assign() vs bind()

| Method | Timing | Behavior |
|--------|--------|----------|
| `assign($name, $value)` | Immediate | Copies the value at call time |
| `bind($name, &$var)` | Deferred | Stores a reference pointer; value resolves at render time |

```php
$title = 'Draft';
$source->bind('title', $title);
$title = 'Final';  // This change IS reflected in the output
echo $source->output(); // uses 'Final'

$source->assign('title', $title);
$title = 'Changed'; // This change is NOT reflected
echo $source->output(); // still uses 'Final'
```
