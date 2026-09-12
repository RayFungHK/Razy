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
| Index signature | `index.sig` at the root — hex Ed25519 signature over the **exact bytes** of `index.json` | `Razy\PackageSignature` vs pinned key `src/asset/keys/official-repo.pub` |

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

## Publisher signature (`index.sig`)

The index is **signed** (Ed25519, detached, over the exact `index.json` bytes).
This closes the gap checksums can't: an attacker who compromises this repository
could rewrite `index.json` **and** its `releases.*.sha256` together — but cannot
forge the publisher signature without the offline private key.

| Piece | Home |
|---|---|
| Public key (pinned) | `src/asset/keys/official-repo.pub` in the **framework** phar + `keys/official-repo.pub` here (mirror) |
| Signature | `index.sig` here — one line of hex (`128` chars + newline; clients trim) |
| Private key | OFFLINE. Never in this repo, the framework repo, or any CI log |

Client behaviour (`RepositoryManager::fetchIndex`, before any JSON parsing):

- valid signature → `[SIGNED]` — index trusted against the pinned key;
- present-but-invalid signature → index **REFUSED** (never parsed), `[SIGNATURE INVALID]`;
- no `index.sig` (or no pinned key) → `[UNVERIFIED]` — checksum-only integrity, loudly.

### Re-signing after ANY index.json change

Any byte change (new version, description typo, GitHub web-editor newline)
invalidates `index.sig`. Re-sign and push both files together:

```bash
# 1. fetch the exact live bytes (what clients will verify against)
curl -s https://raw.githubusercontent.com/RayFungHK/Razy-Repository/main/index.json > index.json

# 2. sign with the offline secret (or set RAZY_REGISTRY_SIGNKEY)
php Razy.phar sign index.json --key=/path/offline/registry-sign.key

# 3. push both, atomically-ish (index first, sig immediately after)
gh api -X PUT repos/RayFungHK/Razy-Repository/contents/index.json \
    -f sha=<current-blob-sha> -f content="$(base64 -w0 index.json)" -f message="..."
gh api -X PUT repos/RayFungHK/Razy-Repository/contents/index.sig \
    -f sha=<current-blob-sha> -f content="$(base64 -w0 index.sig)" -f message="..."

# 4. prove it (uses the pinned key baked into the phar)
php Razy.phar sign verify index.json --sig=index.sig
```

`pkg publish --push` signs automatically when `RAZY_REGISTRY_SIGNKEY` is set,
and prints `[UNVERIFIED]` when it isn't — an unsigned push is never silent.

Key rotation: generate (`php Razy.phar sign keygen`), replace the pinned asset +
`keys/official-repo.pub`, re-sign the index, release the framework. Old phars
verify against the OLD key — keep signing with the old secret until the
replacement framework release is widespread, or accept the fail-closed window.

Integrity and transport rules are enforced client-side by
`Razy\PackageVerifier` + `Razy\ArchiveSafety` (HTTPS-only downloads; zip-slip
validated extraction). Index signing is live (`Razy\PackageSignature`,
dossier S5/G4): **signed** is the default posture; unsigned registries still
work but are bannered UNVERIFIED, never silently trusted.
