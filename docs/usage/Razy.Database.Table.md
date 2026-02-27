# Razy\Database\Table

## Summary
- Table schema builder and alter generator.
- Supports column definitions, indexing, and foreign keys.

## Construction
- `new Table($name, $configSyntax)` or `Table::import($syntax)`.

## Key methods
- `addColumn($syntax, $after)`, `removeColumn()`, `moveColumnAfter()`.
- `commit($alter = false)`: create or alter SQL.
- `getSyntax()`: CREATE TABLE SQL.
- `groupIndexing($columns, $indexKey)`.
- `alterAddColumn()`, `alterModifyColumn()`, `alterRemoveColumn()`, `alter()`.

## Usage notes
- Use `Column` config syntax like `name=type(int),nullable`.
- `commit(true)` builds ALTER SQL based on previous commit.
