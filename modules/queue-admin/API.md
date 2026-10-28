# razymod/queue-admin — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0). A breaking change to anything below is a new major
version folder (RZ-012).

## Gate

`__onAPICall` admits only the commands below. Every command reads the shared database connection
(`Database::getSharedInstance()`) and returns `['ok' => false, 'error' => ...]` when there is none.

## Commands

Call with `$this->api('razymod/queue-admin')->name(...)`.

| Name | Signature | Returns |
|---|---|---|
| `status` | `(array $queues = ['default']): array` | `['ok' => true, 'status' => [...]]`, per-queue counts |
| `job` | `(int\|string $id): array` | `['ok' => bool, 'job' => ?array]`, `'ok'` is false when the job does not exist |
| `act` | `(int\|string $id, string $kind, int $retryDelay = 0): array` | Mutating: `$kind` is `release`, `bury` or `delete` (allow-listed before the store is touched) |
| `purge` | `(string $queue = 'default'): array` | Mutating: clears the finished and buried jobs of one queue |

## Events

None.
