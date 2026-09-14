# 11 — Permissions (`razymod/permissions`)

The RBAC policy layer over the **app-provided** database. Ships as a module
(`modules/permissions/`, code `razymod/permissions`), not as framework core:
the framework ships the *seam* (`Razy\Auth`: SessionGuard/Gate/GateFactory,
manual/07 territory), this module ships *policy* — tables, decisions, the
template plugin, the audit event.

Design dossier: [`architecture/PERMISSION-MODULE.md`](../architecture/PERMISSION-MODULE.md)
(the S1–S5 story, phantoms caught, maintainer decisions Q1/Q2/Q4/Q5 on record).

## 1. One rule to remember

**Every surface denies by default.** No session actor → deny. No DB → deny
(never an exception, never an allow). Unknown ability → deny. Unknown caller
method → deny (`__onAPICall` allow-list). Absent module (null api) → deny.
Allowing is the thing data must prove.

## 2. Module config (per distributor)

Read via `$config['key'] ?? default` — `Configuration` is ArrayAccess
(extends `Collection` extends `ArrayObject`); there is **no `->get()`**
method (manual/04 ledger P8).

| key | meaning |
|-----|---------|
| `database` | config-connect block `['type' => …, 'connection' => […], 'name' => …]` — the module connects its OWN Database handle; false/absent/broken → every DB-backed check denies |
| `governor` | module code of the single module allowed to call `roles-of` / `assign-role` / `revoke-role` (Q4); absent/empty = nobody |
| `super_actors` | list of actor keys (`"type:id"`) that pass every check |
| `system_actors` | CLI/service-posture actors (§7.5): consulted **only when `CLI_MODE`** — a web request can never wear one |
| `session_key` | `$_SESSION` key holding the actor (`"type:id"` string or int id → `"user:{id}"`); default `__auth_actor` |
| `cache_ttl` | seconds; **0 (default) = no cross-request cache at all**. >0 memoizes per-actor ability sets in `Razy\Cache` (writes through the API invalidate instantly — §7.6 mandate; out-of-band DB edits recover when the TTL expires) |
| `audit` | bool, default off — persists gated denials into `permission_audit_log` for the `audit-actor` read side (event stays primary, §7) |

Super is `super_actors` ∪ env `RAZY_SUPER_ADMINS` (comma-separated) — the
escape hatch extends config, it never replaces it (ops break-glass).

## 3. Decision grammar (`support/Service.php`)

```
guest (no actor key)                      → deny   (super never applies)
actor ∈ super_actors ∪ RAZY_SUPER_ADMINS  → allow
otherwise                                  → DB join actor→roles→permissions
any failure anywhere                       → deny, never throw
```

## 4. The API surface (RZ-010)

`$this->api('razymod/permissions')->command(…)` — the one sanctioned path.

| command | who may call | returns |
|---------|--------------|---------|
| `can('group.action')` | any module | `bool` |
| `can-any(['a.b','c.d'])` | any module | `bool` |
| `abilities()` | any module | `list<string>` catalog codes |
| `define-ability('group.action', 'label')` | any module (catalog = declare-as-used) | `array{ok,…}` |
| `roles-of(type, id)` | governor only | `array` |
| `assign-role(type, id, role)` | governor only | `array` (idempotent) |
| `revoke-role(type, id, role)` | governor only | `array` (idempotent) |
| `audit-actor(actorKey, limit?)` | governor only | `list` newest-first denial rows (needs `audit`) |

Ability codes: `^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)+$` (dot-grouped, the
razit `$module/$action` pair flattened into one code). `assign-role` refuses
unknown roles; ungranted-but-undefined codes still deny (the DB join is the
decision; the catalog is the UI registry).

## 5. Route gating — the loop, fail-closed

```php
// handler first line (the razit production loop, security debt paid):
$gate = $this->api('razymod/permissions');            // NULLABLE, Controller.php:510
if ($gate === null || $gate->can('golden.publish') !== true) {
    $this->xhr()->responseCode(403)->responseAsBody(['ok' => false, 'error' => 'forbidden']);
    return;
}
```

`!== true` catches false, null and any future shape in one comparison.
Working demo: [`demos/golden/policy/`](../demos/golden/policy) (declares the
module in `package.php` `require` per RZ-007).

## 6. Templates — `{@can}` (S4)

```html
{@can 'demo.publish'}<a href="/compose">Compose</a>{/can}
{@can 'demo.publish' 'demo.publish.all'}either grants{/can}
{@can $abilityFromTemplateData}…{/can}
```

* Function-tag syntax with `@` prefix (§05 §3.4), closer `{/can}`.
* The plugin ships inside the module and registers itself
  (`registerPluginLoader(PLUGIN_TEMPLATE)` at its `__onInit`) — consumers
  write the tag, zero glue (shape precedent: razit-multilang `function.ml.php`).
* Deny is **server-side**: the enclosed markup never ships.
* RZ-004 stays with the author: the plugin adds no escaping and no new
  interpolation; escape at the HTML boundary as everywhere else
  ([05 §4](05-templates.md)).
* A dead database hides the block instead of breaking the render (the
  never-throw hot-path contract, `Service.php` catches Throwable → deny).

## 7. Audit — `permission.denied` (event, not a table)

```php
$agent->listen('razymod/permissions:permission.denied', function (array $p): array {
    // ['actor_key' => …, 'ability' => …, 'source' => 'gate']
});
```

Fires on gated denials that reached the DB layer (`via === 'db'`); plain
`api()->can()` reads stay silent (razit-loop volume would bury evidence).

**S5 added the opt-in read side.** With config `audit` on, the module
listens to its own event and logs rows to `permission_audit_log`
(ships as the module's second migration); the governor can then ask:

```php
$denials = $this->api('razymod/permissions')->audit-actor('user:7'); // newest first
```

Default off keeps the §8 doctrine exactly: event-only, empty table, no
storage promises. The log is best-effort by contract (an audit surface
that can break the decision path it observes is worse than none);
retention is an ops concern. The razit-loop's plain `can()` reads still
never reach this table — it records gated denials, not traffic.

## 8. Schema and deploy

* Four tables (`permissions`, `roles`, `permission_role`, `actor_role`) arrive
  via the module's own `migration/` (module-scoped tracking row, M0+M1).
* `package.php` declares `'migration' => 'deploy'` — schema moves with
  `php Razy.phar migrate <dist>` (M2+M3); `--status` is the read-only gate.
* **Web requests never migrate** (MIGRATION-GOVERNANCE Q-M2): no lifecycle
  hook in this module touches DDL.
* `actor_role.actor_type` defaults to `'user'`; api-client/service actors are
  explicit types (§7.5).

## 9. Migrating from razit (`razit-group` → this module)

| razit (production-sample) | razymod/permissions |
|---------------------------|---------------------|
| `$this->api('razit_group')->auth($module, $action)` | `$this->api('razymod/permissions')->can($module . '.' . $action)` |
| superuser flag on the user row (`auth.php:9`) | `super_actors` config ∪ `RAZY_SUPER_ADMINS` (env extends) |
| `checkSession()` then auth, in every handler | same loop, but identity is resolved by the module from `session_key` — handlers stop passing ids |
| `razit/api/getDB` ambient db | `database` config-connect block (explicit or deny) |
| module+action pair columns | one dot-grouped ability code |
| user table inside razit | users stay app-side forever (Q1); this module only stores the `type:id` key |

The loop shape is deliberately identical — the razit sample is the proof
that user rows living elsewhere still work; what changed is the security
debt (ambient db, flag ambiguity, no audit, no gate).
