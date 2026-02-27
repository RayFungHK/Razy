# Razy\Database\WhereSyntax

## Summary
- Parses Razy Where Simple Syntax into SQL predicates.
- Supports JSON functions and custom operators.

## Construction
- Created internally by `Statement::where()`.

## Key methods
- `parseSyntax($syntax)`: parse string into internal tokens.
- `getSyntax()`: compile to SQL WHERE clause.
- `VerifySyntax($syntax, $prefix)`: validate/normalize syntax.

## Usage notes
- Operators include `=`, `!=`, `|=`, `*=` `^=` `$=`, `~=` `:=` `@=` `&=`.
