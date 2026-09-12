# Razy-Repository

The **official package registry** for the [Razy](https://github.com/RayFungHK/Razy)
framework. Clients (`php Razy.phar search|install|sync|pkg`) resolve modules from
this repository with **zero configuration** — it is the built-in default registry
(`Razy\RepositoryManager::DEFAULT_OFFICIAL_*`).

## Layout contract (client-pinned)

| What | Where | Pinned by |
|---|---|---|
| Index | `index.json` at the root of the **`main`** branch | `RepositoryManager::buildRawUrl()` → `raw.githubusercontent.com/{owner}/{repo}/{branch}/index.json` |
| Manifest | `{module}/manifest.json` (module-code directory) | `RepositoryManager::getManifest()` |
| Release asset | GitHub Release with tag **`{vendor}-{module}-v{version}`** and asset filename **`{version}.phar`** | `RepositoryManager::buildReleaseAssetUrl()` |

Example: module `dashboard` version `1.0.0` → tag `dashboard-v1.0.0`, asset
`1.0.0.phar`, downloadable at
`https://github.com/RayFungHK/Razy-Repository/releases/download/dashboard-v1.0.0/1.0.0.phar`.

## index.json schema

Top-level object keyed by **module code** (`vendor/module` or bare `name`).
Each entry:

```json
{
    "description": "one-line summary",
    "author": "Name <email>",
    "type": "module | package",
    "latest": "1.0.0",
    "versions": ["1.0.0"],
    "releases": {
        "1.0.0": { "sha256": "<hex digest of the .phar>" }
    }
}
```

### Checksum semantics (v2 — fail-closed)

- An entry that **claims** a checksum (`releases.<v>.sha256`, or the legacy flat
  `sha256` key) makes verification **mandatory**: install/sync/pkg download the
  artifact, hash it, and **abort before extracting** on any mismatch.
- A claimed-but-**empty** digest aborts too (`PackageVerifier` treats it as a
  broken promise, not an absence of one).
- v1 entries with **no** checksum keys install with a loud `CANNOT BE VERIFIED`
  warning — publish digests, don't rely on the warning path.
- Compute the digest of the exact `.phar` you upload:
  `sha256sum 1.0.0.phar` (or `Get-FileHash 1.0.0.phar -Algorithm SHA256`).

## Publishing a new version

1. Build the module's `.phar` (`php Razy.phar package <module>`).
2. `gh release create {vendor}-{module}-v{version} path/to/{version}.phar --repo RayFungHK/Razy-Repository`
   (the filename must already be `{version}.phar`).
3. Add/extend the entry in `index.json` (`versions[]`, `latest`,
   `releases.{version}.sha256`) and, for a new module, its `{module}/manifest.json`.
4. Commit to `main` — clients pick the index up immediately (raw caching is a
   few minutes at most).

Integrity and transport rules are enforced client-side by
`Razy\PackageVerifier` + `Razy\ArchiveSafety` (HTTPS-only downloads; zip-slip
validated extraction). Index **signing** is planned (dossier S2); until then
the HTTPS+checksum chain is the strongest available claim.
