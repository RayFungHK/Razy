# Razy\Database

## Summary
- PDO wrapper with Statement builder and query execution.
- Tracks executed SQL and supports prefixing.

## Construction
- `new Database($name)` or `Database::GetInstance($name)`.

## Key methods
- `connect($host, $user, $pass, $db)`: establish PDO connection.
- `prepare($sql = '')`: create `Database\Statement`.
- `insert()`, `update()`, `delete()`: shortcut builders.
- `execute(Statement $statement)`: run SQL and return `Query`.
- `setTimezone()`, `setPrefix()`.
- `getCharset()`, `getCollation()`.

## Usage notes
- `execute()` throws `Error` with SQL context on failure.
- `getQueried()` returns history for debugging.
