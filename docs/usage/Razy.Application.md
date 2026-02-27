# Razy\Application

## Summary
- Owns multisite configuration and distributor registry.
- Matches FQDN to a `Domain`, then dispatches URL queries to a `Distributor`.
- Writes and validates `sites.inc.php` and `.htaccess` when unlocked.

## Construction
- `new Application()` loads `sites.inc.php` and initializes multisite mappings.

## Key methods
- `host(string $fqdn)`: bind application to a domain and register distributor autoload.
- `query(string $urlQuery)`: match distributor, run routing, and return success.
- `updateSites()`: rebuild internal mappings from config.
- `updateRewriteRules()`: generate `.htaccess` from template and module webassets.
- `writeSiteConfig(?array $config)`: persist `sites.inc.php`.
- `compose(string $code, callable $closure)`: run distributor compose step.
- `dispose()`: invoke distributor dispose and unlock.
- `validation()`: restore config/rewrite if modified.
- `Lock()`: prevents config and rewrite writes while running.

## Usage notes
- Call `host()` before `query()` in web or CLI flow.
- `Lock()` is used during request handling to prevent config writes.
- Domain matching uses exact, alias, wildcard, then default `*`.
