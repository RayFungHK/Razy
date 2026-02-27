# Razy\Template\Source

## Summary
- Represents one template file with its own parameters and root block.
- Scope level: **Source** — shared across all Blocks and Entities in this file.

## Scope Position

```
Entity → Block → **Source** → Template
```

Parameters assigned here override Template (manager) scope and are visible to all Blocks and Entities within this template file, but do not affect other loaded Sources.

## Construction
- Created by `Template::load()` with file path.

## Key methods
- `assign()`, `bind()`: source-level parameters.
  - `assign()` copies the value immediately.
  - `bind()` stores a reference pointer — the value is **not resolved until render time** (`output()`), so later changes to the original variable are reflected in the output.
- `getValue($name, $recursion)`: get parameter; when `$recursion = true`, falls back to Template scope.
- `getRoot()`: root `Entity` for block operations.
- `getRootBlock()`: root `Block` tree.
- `queue($name)`: add to template output queue.
- `output()`: render the source.

## Usage notes
- `getValue($name, true)` resolves upward: Source → Template.
- Parameters set here are file-scoped — they don't leak to other Source files loaded by the same Template.
