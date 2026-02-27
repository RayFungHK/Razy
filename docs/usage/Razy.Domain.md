# Razy\Domain

## Summary
- Domain-to-distributor mapping for a host.
- Provides distributor autoload and cleanup.

## Construction
- Created by `Application::matchDomain()`.

## Key methods
- `matchQuery(string $urlQuery)`: resolve distributor and initialize it.
- `autoload(string $className)`: distributor autoload bridge.
- `dispose()`: trigger distributor dispose.

## Usage notes
- Mapping comes from `sites.inc.php` and optional aliases.
