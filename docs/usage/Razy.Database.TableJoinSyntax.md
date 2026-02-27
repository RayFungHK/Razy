# Razy\Database\TableJoinSyntax

## Summary
- Parses join syntax into SQL FROM/JOIN chains.
- Supports alias sub-queries and presets.

## Construction
- Created internally by `Statement::from()`.

## Key methods
- `parseSyntax($syntax)`: build join token tree.
- `getSyntax()`: compile to SQL join string.
- `getAlias($tableName)`: sub-query builder for alias.

## Usage notes
- Join operators: `-`, `<`, `>`, `<<`, `>>`, `*`.
- Join conditions support `[...]` syntax and Where Simple Syntax.
