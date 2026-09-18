# RELEASE-V1.2.md — the v1.2.0 cut: facts, shape, and the one decision left

> **Status: RATIFIED & EXECUTED — owner approved "1.2.0 stable 全套" 2026-09-18; the release
> commit carries steps 1–6, the tag follows per §4 (owner-side act).**
> Errata found mid-execution: a FOURTH version constant exists — root `VERSION:1` (read by
> `packages/dashboard/controller/router.php:158` and `SkillsGenerator.php:207`), last synced
> by a NON-release commit (e35e647) — exactly the skew `manual/README.md:140` once diagnosed.
> The trio (bootstrap/composer/VERSION) is bumped together here and the ritual table reads
> 2 constants + VERSION.
> Everything FActual below was verified against the tree on 2026-09-18; file:line cited.

## 1. Why this release exists, in one paragraph

The manual (`manual/07-security-guide.md:106` "The armed door (v1.2+)",
`manual/03-routing-and-requests.md:220`, `manual/01-getting-started.md:266`),
`architecture/CSRF-RAIL.md` (Status: SHIPPED v1.2+), and the ERP exposure page
all describe **shipped code** under the label "v1.2" — but `RAZY_VERSION` is
still `1.1.0-beta.2` (`src/system/bootstrap.inc.php:40`). The CSRF rail,
compile-on-deploy, RZ-009 enforcement, the queue door, FormRequest — 378
changelog lines — sit in `[Unreleased]` (`changelog/CHANGELOG.md:10`). An ERP
running 1.0.3 is being asked to "upgrade to the door" and cannot name a real
release to upgrade to. The label and the constant have to meet.

## 2. Why `1.2.0` stable (semver argument, not taste)

- **Minor, not patch**: features shipped (CSRF door, compiled boot, OAuth module
  surface since beta.2) — `changelog/CHANGELOG.md` Keep-a-Changelog law.
- **Not major**: every new door defaults closed/back-compat (`'csrf' => 'off'`
  until armed, `compiled_boot` opt-in per dist); no removed public surface
  (queue-admin's hand-roll was *internal* to the shipped module).
- **Stable, not beta.3**: the release's headline feature is a SECURITY door.
  Asking a 270-route production ERP to adopt a *beta* to fix an active
  CSRF exposure sells the fix worse than the fix deserves. The 1.1.0 line has
  already banked two beta cycles of the same core.

## 3. The release ritual — precedent `1f277ab` ("release: v1.1.0-beta.2"), each item verified

| # | Step | File(s) | Verified fact |
|---|------|---------|---------------|
| 1 | Bump constant | `src/system/bootstrap.inc.php:40` | `'1.1.0-beta.2'` → `'1.2.0'` |
| 2 | Bump composer | `composer.json:3` | `"version": "1.1.0-beta.2"` → `"1.2.0"` |
| 3 | Release changelog | new `changelog/v1.2.0.md` | precedent: `changelog/v1.1.0-beta.2.md` (105 lines) |
| 4 | Index entry | `changelog/CHANGELOG.md:10` | `## [Unreleased]` → `## [v1.2.0](changelog/v1.2.0.md) — <date>`; Unreleased body moves into #3 |
| 5 | Deploy refs (10 spots, 5 files — `git grep -c` exact) | `deploy/README.md:44-45`, `deploy/Dockerfile.worker:23,27`, `deploy/k8s/deployment.yaml:132`, `deploy/k8s/kustomization.yaml:62,79`, `deploy/k8s/README.md:38-39,60` | all carry `1.1.0-beta.2` today; health-payload example string (`k8s/README.md:60`) included |
| 6 | Rebuild phar, verify from archive | `Razy.phar` | precedent commit text: "RAZY_VERSION inside verified from the archive itself" |
| 7 | Pre-gates | composer quality · lint trio · `validate` | cs 0/529 · phpstan OK · phpunit 5565/10612 · demos+demo_modules+modules lint · sample dists validate |
| 8 | Single commit | `release: v1.2.0 — …` | precedent: release commit is ONE commit, tag sits on it |
| 9 | Annotated tag | `git tag -a v1.2.0` | `v1.1.0-beta.2` is annotated (tag object ≠ commit object, confirmed) |
| 10 | **Push tag = the release moment** | `.github/workflows/docker-publish.yml:11` | `tags: ['v*']` trigger → image gets semver tags `1.2.0`/`1.2`/`1` automatically (`:72-74`); `latest` stays default-branch-fed (`:70`) |

## 4. Proposed split of labour (kills day-of risk)

**My side, once owner GO on the shape:** one commit doing steps 1–6 (tag absent),
push → watch CI (10 jobs) + Docker Publish green → report with the tag command
staged. **Owner's side:** eyeball the release commit, then the tag push (or
delegate it explicitly in the same breath). Pushing `v1.2.0` publishes the
`1.2.0` image tags — irreversible in public history the way every tag is;
that line is exactly where "agent never pushes unprompted" earns its keep.

## 5. The three decisions actually on the table

1. **Confirm `1.2.0`** (vs `1.2.0-beta.3` — §2 argues stable).
2. **Release date/label** (proposed: date = day CI+publish go green).
3. **Tag-push authority** (owner-only default per §4).

*If all three come back "as proposed", the entire release is: I prepare the
commit, one push, owner watches CI green, owner (or their word) pushes the tag,
CI paints the image semver tags, `manual/07:106` finally points at a version
that exists.*
