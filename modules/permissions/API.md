# razymod/permissions — API

Owner: Razy Framework (see `module.php`). This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0). A breaking change to anything below is a new major
version folder (RZ-012).

## Gate

`__onAPICall` admits the four public commands from any module. The four governance commands are admitted
only for the module named in this module's config `'governor'`; with no governor configured they are
admitted for nobody.

## Commands

Call with `$this->api('razymod/permissions')->name(...)`.

| Name | Access | Signature | Returns |
|---|---|---|---|
| `can` | public | `(string $ability, ?string $actorId = null): bool` | A plain bool and never throws; treat anything but `true` as a denial |
| `canAny` | public | `(array $abilities, ?string $actorId = null): bool` | True when any ability is granted (mirrors `Gate::any`) |
| `abilities` | public | `(): array` | `['ok' => true, 'abilities' => [...]]`, the catalog, for UIs |
| `defineAbility` | public | `(string $code, string $label = '', string $moduleCode = 'razymod/permissions'): array` | Idempotent catalog insert; call it from your own `__onReady`. `$moduleCode` is advisory and never read by a decision |
| `rolesOf` | governor | `(string $actorType, string $actorId): array` | `['ok' => true, 'roles' => [...]]` |
| `assignRole` | governor | `(string $actorType, string $actorId, string $roleCode): array` | Refuses an unknown role code (roles are never auto-created) |
| `revokeRole` | governor | `(string $actorType, string $actorId, string $roleCode): array` | Idempotent: revoking an absent grant succeeds |
| `auditActor` | governor | `(string $actorKey, int $limit = 50): array` | Newest-first denial history for one `"type:id"` actor, from the opt-in audit table (config `'audit'`) |

## Events

- `permission.denied` — fired as `razymod/permissions:permission.denied` when the Gate denies an ability,
  payload `['actor_key' => string, 'ability' => string, 'source' => 'gate']`. This event is the primary
  audit surface; `auditActor` answers after the fact only when config `'audit'` is on.
