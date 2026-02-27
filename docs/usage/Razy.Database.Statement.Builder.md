# Razy\Database\Statement\Builder

## Summary
- Plugin base for Statement builders.
- Used by `Statement::builder()` and TableJoin aliases.

## Construction
- Instantiated by plugin loader.

## Key methods
- `init(Statement $statement)`: capture statement context.
- `build(string $tableName)`: override to shape the query.

## Usage notes
- Extend this class to encapsulate complex query patterns.
