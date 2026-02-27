# Razy\Database\Query

## Summary
- Wrapper for PDOStatement results.
- Provides fetch helpers and access to originating Statement.

## Construction
- Returned from `Database::execute()` or `Statement::query()`.

## Key methods
- `fetch($mapping)`: single row, optional column binding.
- `fetchAll($type)`: all rows, group or keypair modes.
- `affected()`: rows affected.
- `getStatement()`: underlying Statement.
