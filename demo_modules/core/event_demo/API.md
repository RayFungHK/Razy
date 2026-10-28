# core/event_demo — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

This is a shipped demo module: its surface exists to teach the pattern, not for production callers.

## Events

- `user_registered` — fired as `core/event_demo:user_registered`; listen with `$agent->listen('core/event_demo:user_registered', ...)`.
- `order_placed` — fired as `core/event_demo:order_placed`; listen with `$agent->listen('core/event_demo:order_placed', ...)`.
- `data_changed` — fired as `core/event_demo:data_changed`; listen with `$agent->listen('core/event_demo:data_changed', ...)`.
