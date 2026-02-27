# Razy\Profiler

## Summary
- Runtime profiler that captures checkpoints for memory/time deltas.

## Construction
- `new Profiler()` captures init sample.

## Key methods
- `checkpoint($label)`: record a sample.
- `report($compareWithInit, ...$labels)`: diff samples.
- `reportTo($label)`: compare label to init.

## Usage notes
- Diffs include memory, time, defined functions, and classes.
