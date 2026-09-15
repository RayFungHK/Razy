# AGENTS.md — Rules for AI Coding Agents Working on Razy

You are working on a **Razy** project (PHP 8.2+ multi-distributor module platform).
Before writing a single line, read **[`skills/RAZY-AI-RULES.md`](skills/RAZY-AI-RULES.md)**.
The Golden Rules below are the short form; rule IDs are stable and quotable.

## Prime directives

1. **Code beats docs.** Verify any API against `src/library/Razy/` before use; cite
   `file:line` in rationales. Generated docs may be stale; the repo README/manual are
   code-verified as of 2026-07.
2. **Modules are separate legal entities.** The only sanctioned cross-module surfaces
   are: `addAPICommand` + `$this->api('vendor/mod')`, events (`listen`/`observe` +
   `trigger`), and gated bridge commands. Anything else crossing a module boundary is a
   defect.
3. **Never violate a Golden Rule to "make it work".** Propose the violation → get the
   rule ID back → rewrite correctly.

## Golden Rules (short form)

| ID | Never | Instead |
|----|-------|---------|
| RZ-001 | `require`/`include`/`file_get_contents` into another module's files | Published API: `$this->api('vendor/mod')->cmd()` |
| RZ-002 | Cross-distributor calls without a gate | `addBridgeCommand` + implement `__onBridgeCall` allow-list |
| RZ-003 | Raw SQL with interpolated input, `new PDO`, `getDB()->prepare('SELECT…')`, `getSearchTextSyntax($userText)` | `$db->prepare()->select()->where('x=:x')->assign([...])`, ORM named params |
| RZ-004 | Raw `{$var}` of user data in templates (engine does **not** auto-escape) | `->escape` modifier (built-in v1.0.3-beta+), `htmlspecialchars()`, DOM builder |
| RZ-005 | Resolving `Razy\Application/Container/Module/...` via DI (SecurityException = fence) | Controller helpers + your module's own bindings |
| RZ-006 | File writes outside your module paths | `getDataPath()` / `getAssetPath()` roots |
| RZ-007 | Editing `autoload/` or `autoload/lock.json` by hand | `package.php` → `require` / `prerequisite` + `php Razy.phar compose` |
| RZ-008 | Cross-module shared state (static props, singletons, shared temp files) | API calls, events, per-distributor config |
| RZ-009 | IO/cross-module calls in `__onInit`; ignoring `false` lifecycle returns | Register in `__onInit`; act in `__onReady`/`__onRouted`/`__onEntry` |
| RZ-010 | Calling a peer's internal closure files directly | Public `addAPICommand` vs private `$agent->bind()` (public API = contract) |
| RZ-011 | `eval`/`exec`/`shell_exec`/`passthru`/input-derived `spawnPHPCode()` (now **deprecated**) | `ThreadManager` callables, `spawnPHPFile()`, `Razy\WorkerPool` (persistent file-job workers) |
| RZ-012 | Changing a released module's API/event surface without version discipline | semver in `module.php`/`package.php`; new major dir for breaking |
| RZ-013 | Hand-editing generated rewrite/Caddyfile output; routes bypassing `Agent` | `addRoute`/`addLazyRoute`/`group` + `php Razy.phar rewrite` |
| RZ-014 | Shipping an API command with no test; weakening `phpstan.neon`/fixer config to pass | Tests for every published command; `composer quality` |
| RZ-015 | Third-party runtime dependency in framework core (`src/`) | Harden the in-house primitive, or ship the dependency in a module/package (RZ-007 manifest) — core stays dependency-free |
| RZ-016 | Migration execution from module/web paths (`getMigrationManager()` in a handler) | `php Razy.phar migrate <dist>` at deploy (or the declared wizard door once MODULE-LIFECYCLE L1 lands); never an install_action handler, never `__onReady` |
| RZ-017 | Cross-module class imports (`use erp\user\Helper;` / FQCN of a sibling module) | `addAPICommand` + `api()` or events; probe with `api('vendor/mod')->has('cmd')`, never `method_exists()` |

## Definition of done (every change)

```bash
composer quality                                    # cs + phpstan + phpunit
php tools/lint-module-discipline.php sites/<dist> vendor/module shared/module --format=json
php Razy.phar validate <dist>
```

All three must be green (or the failure explained). Never mark work complete while a
Golden Rule violation exists — the lint tool reports rule IDs; fix them, don't suppress
them. Use `// lint-allow: RZ-00X` only with a written justification in the same diff,
and flag it for human review.

## Known framework traps (do not "fix" code around these silently)

- Template `|` is a fallback chain, not a modifier pipe; modifiers are `->name:arg`.
- `__onBridgeCall` default **allows all** — you must implement the gate (RZ-002).
  Defense-in-depth now available: set `RAZY_BRIDGE_SECRET` and `Module::executeBridgeCommand`
  will deny calls lacking a valid HMAC (`Razy\BridgeSignature`) — but the secret gates
  `executeBridgeCommand`, not the CLI `bridge` command (local IPC stays operator-trust).
- `__onAPICall` must be implemented for sensitive commands — no gate means open.
- `{$var}` outputs raw — there is **no auto-escaping**; use the built-in `->escape`
  modifier (shipped v1.0.3-beta+; older installs: RZ-004 patch in the rules doc).
- `ThreadManager::spawnPHPCode()` uses `eval(base64_decode())` in the child — never feed
  it input-derived code (RZ-011). It is **deprecated** as of v1.0.3-beta; migrate to
  `spawnPHPFile()` (one-shot) or `Razy\WorkerPool::submitCode()` (boot/CPU-heavy repeats).
- `api('vendor/mod')` returns an `Emitter` that dispatches through `__call` — **`method_exists()`
  on it is always false** (a production integration died silently this way). Probe with
  `->has('command')`; never `method_exists()`.

If a task requires violating a rule, **stop and ask the human** with the rule ID and
the reason — do not improvise around the architecture.
