# Razy\API

## Summary
- Distributor-scoped API access helper.
- Returns `Emitter` instances to call other modules' API methods.

## Construction
- Created by `Distributor::createAPI()`.

## Key methods
- `request(string $moduleCode)`: returns `Emitter` for module API access.

## Usage notes
- API visibility depends on module `api_name` and handshake/load state.
