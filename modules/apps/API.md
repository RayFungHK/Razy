# razymod/apps — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0). A breaking change to anything below is a new major
version folder (RZ-012).

## Gate

`__onAPICall` admits a command only if it is listed below **and** the calling module's code is in this
module's config `'callers'` (default: none, so nothing is admitted until a dist names its callers).
Every command is pure: no IO, no database, no state kept between calls.

## Commands

Call with `$this->api('razymod/apps')->name(...)`. Every command returns an array with `'ok'`.

| Name | Signature | Returns |
|---|---|---|
| `validate` | `(array $definition, array $options = []): array` | `['ok' => bool, 'issues' => [['severity', 'path', 'code', 'message'], ...]]`. Options: `'dependencies'`, `'previous'`, `'system'` |
| `compile` | `(array $definition, array $options = []): array` | Validates first; `['ok' => bool, 'plan' => ?array, 'issues' => [...]]`, `'plan'` is null when the definition has errors |
| `hash` | `(array $definition): array` | `['ok' => bool, 'hash' => 'sha256:…'\|null, 'bytes' => int, 'error'?: string]` over canonical JSON |
| `assign-ids` | `(array $definition, ?array $previous = null): array` | Fills missing element ids; an element matching `$previous` (same kind, parent and code) keeps its id. `['ok' => true, 'definition' => …, 'issues' => []]` or `['ok' => false, 'issues' => …]` when an input is over its cap |
| `weight` | `(array $definition, array $options = []): array` | Validates first; `['ok' => true, 'issues', 'weight', 'wf_nodes', 'counts', 'coefficients', 'coefficients_source' => 'config'\|'reference']`. Coefficients come from config `'weight'`, else the reference values |

## Events

None.
