# Razy\Template\Block

## Summary
- Block tree node parsed from template file.
- Manages nested blocks, includes, wrappers, and template reuse.
- Scope level: **Block** — visible to all Entities spawned from this block.

## Scope Position

```
Entity → **Block** → Source → Template
```

Parameters assigned here override Source and Template scope, and are visible to all Entities created from this block and its nested sub-blocks.

## Construction
- Created by `Source` via template parsing.

## Key methods
- `getBlock($name)`, `hasBlock($name)`.
- `getTemplate($name)`: resolve readonly template blocks.
- `assign()`, `bind()`: block-level parameters.
  - `assign()` copies the value immediately.
  - `bind()` stores a reference pointer — the value is **not resolved until render time**, so later changes to the original variable are reflected in the output.
- `getValue($name, $recursion)`: get parameter; when `$recursion = true`, resolves Block → Source → Template.
- `newEntity()`: create an `Entity` for rendering.

## Usage notes
- Block tags include `START`, `END`, `INCLUDE`, `TEMPLATE`, `USE`, `WRAPPER`.
- Block-level parameters affect all entities spawned via `newEntity()` — use this for shared data across multiple entity instances of the same block.
