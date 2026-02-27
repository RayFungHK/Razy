# Razy\Database\Preset

## Summary
- Base class for TableJoin presets.
- Receives a `Statement` to configure sub-queries.

## Construction
- `new Preset($statement, $table, $alias)`.

## Key methods
- `init(array $params)`: assign preset parameters.
- `getStatement()`: access sub-query statement.

## Usage notes
- Used by `TableJoinSyntax` when `->PresetName()` appears.
