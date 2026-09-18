# ERP-COMPILE-PROPOSAL — One Page for the Owner's Decision

**Status: PROPOSAL — owner-gated.** Nothing here is started; the ERP repo is read-only to
this side by standing rule. This page exists because compile-on-deploy (see
[COMPILE-ON-DEPLOY.md](COMPILE-ON-DEPLOY.md)) now has published, paired measurements —
proposals without numbers stopped being worth the owner's time this epoch.
Date: 2026-09-17. Evidence: [../benchmark/EPOCH-2026-09.md](../benchmark/EPOCH-2026-09.md).

## What the framework already does (ERP gets nothing new to maintain)

| Layer | What ERP does | What ERP gets | Measured (this side, published) |
|---|---|---|---|
| **M1 classmap** | nothing — it rides the phar | autoload stops the multi-root ladder | fpm static plateau 229.1 → 370–396 RPS (**+73% band**, control-banded; host noise floor ±2× documented) |
| **M2 boot snapshot** | one dist.php flag + `compile` at deploy | boot replays a snapshot instead of rescanning | 60-module fpm, PAIRED, order-alternating: **+10…+23%, never lost a pair** (three independent runs) |
| safety rails | nothing | stale→full boot; foreign artifact→full boot (`FOREIGN` floor, no opt-out); compile refuses impure `__onInit` **by name**; replay self-proves against legacy boot before any artifact ships | gate trio + live-fire published |

**Honest non-claims:** worker steady-state is boot-once — the replay is worker-NEUTRAL there
(cold-boot deflation published in EPOCH; we do not sell cold starts); the DI bucket (M3) is
deferred, unmeasurable at 5%; every number is this host's band, ERP's own hardware must
re-run its own pair (the paired harness `benchmark/pair_ab.py` + `benchmark/scale/` is
committed precisely so that re-run is cheap).

## The proposal — three steps, each independently reversible

1. **Adopt the phar (decision: upgrade ERP 1.0.3 → current release).** M1 applies with
   zero ERP changes. The upgrade is an ordinary minor-version step — its own BC review is
   out of this page's scope and is the real cost of step 1. Note ERP currently runs
   phar 1.0.3 ([ERP-GENERALIZATION.md §0](ERP-GENERALIZATION.md)); it is missing not just
   the classmap but two epochs of fixes — that is the stronger half of the argument.
2. **Dry-run audit, zero behavior change (decision: run one command per dist).**
   `php Razy.phar compile <dist>` on a staging clone. Multi-site boot ignores artifacts
   without the dist.php flag — so compiling is a **pure report**: success means the dist's
   `__onInit` set is declarative-pure; refusal prints every offending item by file
   (`await()` callbacks, IO in `__onInit`, non-serializable manifests…). The ERP survey
   never counted `__onInit` purity, so **nobody guesses — the refusal list is the audit**.
   Delete the artifact afterwards (or just don't set the flag) and nothing changed.
3. **Flag the winners (decision: per-dist).** For dists whose compile passes and whose
   traffic justifies it: `'compiled_boot' => true` in dist.php, `compile` inside the
   existing deploy rebuild (clear artifacts before the first boot — the gate's live-fire
   lesson ships as a one-line rule), rollback = delete one file. Ship posture needs no
   env var: the fingerprint auto-shortens where opcache is frozen (vt=0) and keeps full
   stats in dev automatically.

## Why now

The M2 claim was built backwards on purpose — instrument before persuasion — and its own
instrument has already caught three real things (fingerprint -22%, the flat-key gate bug,
the foreign-artifact floor). The machinery is at the rarest state a proposal can ask for:
shipped, adversarially measured, with its failure modes published and each one now guarded.

## What the owner is actually deciding

Not "trust compile-on-deploy". Three separable yes/no's: (1) does ERP take the phar
upgrade anyway; (2) does staging run the zero-risk refusal-list audit; (3) later, per
dist, the flag. Each step's evidence and reversibility above; this side will not touch the
ERP repo without a per-step go.

> **And one decision this page deliberately does not carry:** the survey's only *active*
> exposure (zero CSRF defense across ~270 mutating routes) is a separate, larger question —
> a phar upgrade changes none of it. It has its own page and its own ask:
> [ERP-SECURITY-EXPOSURE.md](ERP-SECURITY-EXPOSURE.md).
