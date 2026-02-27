# Razy\FlowManager\Transmitter

## Summary
- Broadcasts method calls to all flows in a manager.

## Construction
- Returned by `FlowManager::getTransmitter()`.

## Key methods
- `__call($method, $args)`: call method on each flow.

## Usage notes
- Swallows exceptions during broadcast.
