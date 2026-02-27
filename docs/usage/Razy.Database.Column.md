# Razy\Database\Column

## Summary
- Column definition builder for `Table`.
- Supports type/length/default/keys and foreign keys.

## Construction
- `new Column($name, $configSyntax, ?Table $table)`.

## Key methods
- `setType()`, `setLength()`, `setDefault()`, `setNullable()`.
- `setKey()`, `setCharset()`, `setCollation()`, `setComment()`.
- `insertAfter($columnName)`, `bindTo(Table $table)`.
- `getSyntax()`: SQL column definition.
- `getForeignKeySyntax()`.

## Usage notes
- `type(auto)` maps to INT AUTO_INCREMENT with primary key.
- `exportConfig()` emits a reusable column syntax string.
