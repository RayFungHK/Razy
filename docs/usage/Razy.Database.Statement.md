# Razy\Database\Statement

## Summary
- Fluent SQL builder for select/insert/update/delete.
- Supports Where/TableJoin simple syntax and JSON operators.

## Construction
- Created via `Database::prepare()` or `new Statement($db)`.

## Key methods
- `select($columns)`, `from($syntax)`, `where($syntax)`.
- `insert($table, $columns, $dupKeys)`, `update($table, $syntax)`, `delete($table, $params, $where)`.
- `assign($params)`: bind values for parsing.
- `order($syntax)`, `group($syntax)`, `limit($pos, $len)`.
- `query()`, `lazy()`, `lazyGroup()`, `lazyKeyValuePair()`.
- `builder($name, ...$args)`: plugin-based builders.

## Usage notes
- Use `TableJoinSyntax` and `WhereSyntax` tokens like `~=` and `:=`.
- `getSyntax()` returns the built SQL string.
