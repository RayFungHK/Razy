# Razy\Template\Entity

## Summary
- Renderable instance of a `Block` with parameters and child entities.
- Parses function tags, modifiers, and parameter paths.
- Scope level: **Entity** — the narrowest scope, applies only to this instance.

## Scope Position

```
**Entity** → Block → Source → Template
```

Parameters assigned here override all wider scopes (Block, Source, Template). Each entity instance has its own independent parameter set.

## Construction
- Created via `Block::newEntity()` or `Entity::newBlock()`.

## Key methods
- `assign()`, `bind()`: entity-level parameters (instance-specific).
  - `assign()` copies the value immediately.
  - `bind()` stores a reference pointer — the value is **not resolved until render time** (`process()`), so later changes to the original variable are reflected in the output.
- `getValue($name, $recursion)`: get parameter; when `$recursion = true`, resolves Entity → Block → Source → Template.
- `newBlock($name, $id)`: create child entity.
- `find($path)`: search entities by block path.
- `process()`: render content.
- `parseValue()`: evaluate parameter or literal.

## Usage notes
- Wrapper blocks link entity creation to wrapper structure.
- Entity parameters only apply to this specific entity — sibling entities of the same block each have their own scope.
- Use `assign()` here for per-row or per-item data when iterating.
