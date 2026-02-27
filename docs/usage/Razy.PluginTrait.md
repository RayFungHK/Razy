# Razy\PluginTrait

## Summary
- Shared plugin loader for modules like Template, Statement, Collection.

## Key methods
- `AddPluginFolder($folder, $args)`: register plugin dir.

## Usage notes
- Plugins are PHP files returning `Closure`.
- Plugin cache is static per trait user.
