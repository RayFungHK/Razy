# 06 — Packages & Deployment

Dependency management (`package.php` → `compose` → `autoload/`), phar packaging, standalone
packages, and the **uncomfortable Docker truth** — then a deployment checklist you can act
on today.

Verified sources: `src/library/Razy/ModuleInfo.php`, `Distributor/PrerequisiteResolver.php`,
`PackageManager.php`, `Package/PackageManifest.php`, `Package/PackageRunner.php`,
`PackageTrait.php`, the command files under `src/system/terminal/`,
`benchmark/docker/Caddyfile.razy`, `.docker/*`.

---

## 1. `package.php` keys (composer-compatible, but not composer)

File split: **module identity** lives in `<module>/module.php` (`module_code`, `name`,
`author`, `description`, `version` — `demo_modules/data/database_demo/module.php:14-20`);
**per-version dependency + API metadata** lives in `<module>/<tag>/package.php`
(`name`, `version`, `author`, `description` — `database_demo/default/package.php:4-9`).

| Key | Meaning | Verified where |
|---|---|---|
| `api_name` | How `api()` addresses you; must match `^[a-z]\w*$` | `ModuleInfo.php:237-244` |
| `require` | **Module-to-module dependencies**: `module_code => version` (validated against the module-code regex, incl. implicit parent-namespace requirements) | `ModuleInfo.php:250-263` |
| `prerequisite` | **External package constraints** (composer-style `package => constraint`) collected at init | `ModuleInfo.php:227-235` |

```php
// <module>/<tag>/package.php
return [
    'name'        => 'Hello',
    'version'     => '1.0.0',
    'author'      => 'You',
    'description' => 'My first module',
    'api_name'    => 'hello',
    // 'require'      => ['acme/shared' => '^1.0'],        // module dep (code => constraint)
    // 'prerequisite' => ['monolog/monolog' => '^2.0'],    // external package constraint
];
```

## 2. `compose`, `autoload/`, `lock.json` (RZ-007)

```bash
php Razy.phar compose <distributor_code>    # usage: compose.inc.php:13
```

- Resolution of `prerequisite` constraints happens through the **PrerequisiteResolver**,
  which reads installed packages from the global lock file
  **`autoload/lock.json`** (`Distributor/PrerequisiteResolver.php:44,68`) and resolves
  version conflicts across modules registered in the distributor.
- Installed package trees land under **`autoload/`** — per-distributor vendor dirs
  (`autoload/<dist>/`) with the shared global lock at `autoload/lock.json`; the per-dist
  autoload chain is a verified audit finding (`RAZY-ANALYSIS-REPORT.md`; composer install
  and lock write/read in `PackageManager.php:139` / `:197`).

**RZ-007 — the one hard rule:** never edit `autoload/` or `autoload/lock.json` by hand;
change `package.php`/`prerequisite` and re-`compose`. Hand edits desync the resolver and
get silently overwritten.

## 3. Repositories, transports, `install`

Endpoints are configured in **`repository.inc.php`** (shipped by `build`; the sample
`playground/repository.inc.php` is live config). The installer reaches repositories through
the transport classes shipped in `src/library/Razy/PackageManager/`:
`HttpTransport`, `FtpTransport`, `SftpTransport`, `SmbTransport`, `LocalTransport` (file
listing — the format menu below is what `install` actually accepts).

```bash
php Razy.phar install <repository> [target_path] [options]   # install.inc.php usage
php Razy.phar install owner/repo                     # GitHub shorthand (@version supported)
php Razy.phar install owner/repo -v v1.0.0           # pin tag        -s stable  -l latest
php Razy.phar install owner/repo -d mysite           # into a distributor's modules dir
php Razy.phar install <zip-url>                      # direct archive URL
php Razy.phar install name -r                        # from configured repositories
```

**Trust caveat — read it.** `install` downloads an archive and extracts it. The 2026 audit
recorded a **zip-slip** weakness in the extraction path — now **fixed** in-framework
(`Razy\ArchiveSafety`: hostile entry names rejected pre-extraction, symlinked output
purged, plain-HTTP dist URLs refused unless `RAZY_ALLOW_INSECURE_TRANSPORT=1`). What
remains true: the framework cannot tell you whether a repo is malicious — **only install
from repositories you trust**, pin versions (`-v`), and review diffs in `vendor/module/`
like any dependency. Treat `autoload/` + `vendor/module/` as third-party code with your
module's runtime privileges.

### Official registry & publisher signatures (trust model)

With no `repository.inc.php`, `search/install/sync/pkg` resolve the built-in official
registry (`RayFungHK/Razy-Repository@main`, `RepositoryManager::defaultRepositories()`).
Integrity there is layered, and the layer you're on is **never silent** — every lookup
prints one banner per registry:

| Banner | Meaning | Enforced by |
|---|---|---|
| 🟢 `[SIGNED]` | `index.sig` (detached Ed25519 over the exact `index.json` bytes) verified against the key pinned in the phar (`src/asset/keys/official-repo.pub`) | `Razy\PackageSignature` in `fetchIndex`, **before** `json_decode` |
| 🟡 `[UNVERIFIED]` | no `index.sig` / no pinned key → integrity is checksum-only | `Razy\PackageVerifier` per-artifact SHA-256 (fail-closed once claimed) |
| 🔴 `[SIGNATURE INVALID]` | signature mismatch → the index is **REFUSED** (never parsed, no results) | same as SIGNED row; fail-closed |

Checksums catch corruption and drift; the signature catches a **compromised registry
repository** (an attacker who rewrites `index.json` can also rewrite its checksums —
not the signature). Sign/verify/rotate: `php Razy.phar sign` (`keygen` / `sign <file>`
/ `verify <file>`), full runbook in `tools/registry-seed/README.md`. Override the
pinned key with `RAZY_REGISTRY_PUBKEY` (hex or `.pub` path) when hosting a private
signed registry.

## 4. phar packaging: `pack`, `pkg`, `publish`


```bash
php Razy.phar pack <vendor/module> <version> [output_path]   # pack.inc.php usage
# → phar archive (GZIP by default; --no-compress / --no-assets),
#   plus manifest.json + latest.json for repository publishing (default ./packages/)

php Razy.phar pkg <package> [args...]        # run a packaged standalone (pkg.inc.php usage)
php Razy.phar pkg -d <dist/module> [args...] # run via Distributor (dist mode)
php Razy.phar pkg list                       # list installed packages
php Razy.phar pkg publish                    # publish to a configured repository
```

⚠️ Drift notice: a top-level `publish` command file exists but announces that standalone
`publish` **has been removed — use `pkg publish`** (`publish.inc.php:16-17`). The readme's
command list still shows `publish` as standalone.

Framework-binary rebuild: `php build.php` at the repo root (needs `phar.readonly=0`).
`php Razy.phar update` appears in `help.inc.php` but has **no command file** — do not script
against it (drift list in [README](README.md)).

## 5. Standalone packages (`PackageTrait` + manifest)

Scaffold: `php Razy.phar standalone` (or `-f /path`) → ultra-flat app skeleton
(`standalone.inc.php` usage). The manifest template is
`src/asset/setup/razy.pkg.json.tpl`:

```json
{
  "package_name": "my-app",
  "version": "1.0.0",
  "mode": "exec",
  "strict": false,
  "on_depend": [
    {"package": "db-setup",      "wait": "complete"},
    {"package": "cache-service", "wait": "healthcheck"},
    {"package": "shared-lib",    "wait": "load"}
  ],
  "healthcheck": { "url": "", "interval": 2, "timeout": 30, "start_period": 5 },
  "prerequisite": {}
}
```

Validation facts (`Package/PackageManifest.php`): required keys `package_name`, `version`,
`mode`; `mode` ∈ `serve|exec` (`:110-114`); `strict` (`:240`); `on_depend[].wait` ∈
`complete|healthcheck|load` (`:263`) — semantics: run the exec dep to completion / poll its
health endpoint / load only (`:33-34`). Healthcheck defaults: `interval 2`, `timeout 30`,
`start_period 5` (`:38-40`).

Entry class: `use Razy\PackageTrait;` (trait at `src/library/Razy/PackageTrait.php`) —
hooks `__onPackageStart(array): bool` (`:110`), `__onPackageExec(array): int` (`:128`),
plus package-scoped RPC helpers `registerPackageAPI` / `callPackageAPI` /
`onPackageEvent` / `emitPackageEvent` (trait docblock `:58-84`). Snippets composing them are
*illustrative*; each name is verified in the trait.

## 6. Deployment

### FrankenPHP worker (the benchmark-proven pattern)

`benchmark/docker/Caddyfile.razy`, verbatim core:

```
{
	frankenphp {
		worker /app/site/index.php
	}
	order php_server before file_server
}

:8080 {
	root * /app/site

	php_server

	log {
		output stderr
		format console
		level WARN
	}
}
```
(`benchmark/docker/Caddyfile.razy`, 23 lines — quoted verbatim)

Worker-mode rules for your code are in [03 §5](03-routing-and-requests.md) (boot-once,
be-stateless, `WORKER_MAX_REQUESTS`). Persistent MySQL PDO is the driver default — fine in
workers, just close your per-request transactions.

### The Docker reality table (plainly)

| Image | What it really is | Verified |
|---|---|---|
| `.docker/Dockerfile` (shipped) | multi-stage build, **runtime CMD is the PHP built-in dev server**; now **non-root** (`razy` uid 1000) + OPcache + `/_razy/health` HEALTHCHECK — dev/smoke only | `.docker/Dockerfile` |
| `.docker/docker-compose.yml` | dev loop: Caddy `:8080 → php:9000`, repo mounted into `/app` | `.docker/docker-compose.yml`, `.docker/Caddyfile` |
| `.docker/docker-compose.test.yml` | test-suite env (Redis etc.) | file exists |
| `benchmark/` images | FrankenPHP + Caddy (`Caddyfile.razy`) used for the 2026 benchmarks | `benchmark/docker/Caddyfile.razy` |
| `deploy/Dockerfile.worker` | production **FrankenPHP worker** image (non-root, `/_razy/health` probes) — see `deploy/` | `deploy/Dockerfile.worker`, `deploy/Caddyfile` |
| Kubernetes manifests | namespace/RBAC-free set with probes + HPA sizing for 15k TPS in `deploy/k8s/` | `deploy/k8s/` |

The `.docker/Dockerfile` is now non-root + health-checked but still runs the **dev
built-in server** — use `deploy/Dockerfile.worker` (FrankenPHP worker) for production.

### Production checklist

- [ ] **Non-root container user** — provided by `.docker/Dockerfile` (`razy` uid 1000)
      and `deploy/Dockerfile.worker` today; verify `USER` is present in any derived image
- [ ] **Real webserver** (FrankenPHP/Caddy pattern above), HTTPS terminated at Caddy, HSTS
- [ ] `config.inc.php` `'debug' => false` (the shipped default is already `false` — keep it)
- [ ] Secrets from environment via the global `env()` helper
      (`src/system/bootstrap.inc.php:294`) — note there is **no `RZY__*` auto-override
      layer** despite the readme security-table claim (drift, see [README](README.md));
      config stays PHP `return`-arrays, so generate them at container entrypoint if needed
- [ ] **Bridge discipline**: implement `__onBridgeCall` allow-lists on every bridge module
      (RZ-002) — the CLI transport is local IPC, shell access ≈ full bridge access; for
      signed cross-process calls set `RAZY_BRIDGE_SECRET` (`Razy\BridgeSignature`) so
      `executeBridgeCommand` rejects unsigned/self-declared forgeries ([07](07-security-guide.md))
- [ ] **Liveness + metrics are built in** (unreleased): `GET /_razy/health` and
      `GET /_razy/metrics` are answered pre-dispatch (`Razy\Health` / `Razy\Metrics`);
      wire probes to health, Prometheus to metrics (`razy_http_requests_total` drives
      the HPA — see `deploy/k8s/hpa.yaml`); keep `RAZY_HEALTH_TOKEN` set so deep tiers
      stay private
- [ ] **Scheduled jobs**: one crontab line (`* * * * * cd /app && php Razy.phar schedule run`)
      drives `scheduler.inc.php` (cron expressions, `withoutOverlapping` locks); multi-node
      setups must use `RedisLock` — `FileLock` is host-local
- [ ] **Sessions on shared storage**: multi-node needs `Session\Driver\RedisDriver`
      (FileDriver locks per session file and does not cross nodes)
- [ ] `compose` + `validate <dist>` in CI; `validate` green before release
- [ ] `php Razy.phar rewrite <dist>` output (`.htaccess`/Caddyfile) is **generated** —
      don't hand-edit (RZ-013)
- [ ] Logs/monitoring: `serve -debug -p <file>` exists for dev CLI work; production log
      wiring is app-level (no core APM) — tell stakeholders honestly

Next: [07-security-guide.md](07-security-guide.md).
