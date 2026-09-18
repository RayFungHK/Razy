# ERP-SECURITY-EXPOSURE — The Only Active Risk in the Survey, and What a Phar Alone Cannot Close

**Status: PROPOSAL — owner-gated.** Companion to [ERP-COMPILE-PROPOSAL.md](ERP-COMPILE-PROPOSAL.md);
same standing rule: this side never touches the ERP repo without a per-step go. Date: 2026-09-17.
**Corrected same day**: the first version of this page cited `manual/07:103` as "nothing
enforces it anywhere" — that sentence, read in current context (:103–:108), refers to the
*v1.1 manual wiring snippet*; the **door itself has since shipped** (v1.2+, below). The
exposure claim stands — its mechanism just moved from "the framework has nothing" to "the
flagship runs none of what the framework now has", which is a smaller ask and a worse
excuse. Honest errata, kept visible rather than quietly rewritten.

## The finding (re-verified against the framework before this page was written)

- The production ERP has **zero CSRF defense**: 4 case-insensitive `csrf` matches app-wide,
  all four OAuth `state` comments; **~270 mutating routes unguarded**
  ([ERP-GENERALIZATION.md §4](ERP-GENERALIZATION.md), §0 ground truth).
- **The framework has since shipped the door** (CSRF-RAIL L0–L4, v1.2+; verified in code
  before this correction shipped: `Csrf/CsrfDoor.php`, `Controller::csrfToken()/csrfField()`,
  `Route::csrfExempt(reason)` that *throws* at registration on a blank reason, session
  cookie emission in `Session::emitCookie()`, the `csrf.failed` event, 107 green Csrf
  tests, and queue-admin migrated onto it with its hand-roll deleted). The engine is no
  longer "shipped unplugged" — it is armed by one `dist.php` key.
- Doctrine's ranking still holds: C5 (CSRF) was and is **the only active exposure** on the
  candidate list. What changed is the shape of the debt: it is now purely an adoption
  decision sitting on the ERP side, not an unbuilt capability.

## The uncomfortable part, stated honestly

An auth hub (login, session, SSO) is **exactly** the place a framework has no legitimate
way to know what should be un-protected — which is why the shipped door is deliberately
**explicit-declare, not default-protect** (the route-gate precedent):

- A **default-protect rail** would break exactly the routes that must verify statelessly —
  the OAuth `state` callbacks the survey found are the app's ONLY csrf-adjacent code, plus
  webhooks, login itself, and the mobile/desktop clients ERP desktop builds may carry
  (`C:\Users\RayFung\Desktop\erp desktop`).
- Which is precisely how Laravel — the ecosystem with CSRF on by default — ends up with
  CSRF disabled on the auth surface of most of its production apps. Shipping "on by
  default" ships protection that gets switched off under pressure.
- So the shipped shape is: upgrades sit `off` until armed (`validate` prints **UNARMED**,
  refusing silence); every exemption *names itself with a reason* at registration
  (`->csrfExempt('webhook: signature-verified upstream X …')` — blank reasons throw); new
  scaffolds are armed from day one. Silence is no longer representable.

**A phar upgrade alone still closes nothing**: ERP runs 1.0.3, and arming ~270 routes is a
client-pass, not a version bump. **ERP's exposure does not decay with adoption — only a
decision does.**

## Proposal — three decisions, separated from every other adoption question

1. **Readout first, zero behavior change (owner decision: approve one audit run).** A
   read-only sweep classifying all ~270 mutating routes: session-authed stateful (needs
   `csrfField()`/header), machine doors (get `->csrfExempt('<scheme + runbook>')` — the
   only legitimate exempt candidates), unauthenticated (login/register), dead (delete).
   Output = the real exemption list, which doubles as the acceptance test for the arming
   commit. This side can produce the sweep script (read-only against a staging copy).
2. **Client pass while still unarmed (appendix L4-era playbook).** Forms gain
   `<?= $this->csrfField() ?>`; the shared fetch/XHR helper gains one `X-CSRF-TOKEN`
   header from a `<meta>` the layout adds. Unarmed dists accept these requests either
   way — the deploy is behavior-neutral and reviewable **before** any arming commit exists.
3. **Arm per-dist, one dist per deploy.** `'csrf' => 'on'` + one `csrf.failed` listener
   (audit-log shaped) names the exact route that missed the header within hours; rotation
   is the door's default. The exemption PR review *is* the security review, because every
   exemption line carries its reason by construction.

Framework-side, nothing remains to build — which is the point of publishing the correction
rather than an optimistic draft: the ask of this page is three owner decisions on ERP, not
a framework milestone. (The framework's own sign-off — CSRF-RAIL §5, Q1–Q6 + milestone
shape — was exercised by shipping L0–L4 with the owner gating each push; the checkboxes are
being ratified to reflect as-shipped reality.)

## What this page deliberately does not do

It does not fold CSRF into the phar upgrade in [ERP-COMPILE-PROPOSAL.md](ERP-COMPILE-PROPOSAL.md).
That page's step 1 ("take the upgrade anyway") is worth doing on its own merits — bug fixes,
classmap, *and now the door itself* — but **arming is its own decision sequence above**;
an upgrade without steps 1–3 changes the phar, not the posture. Separate decisions, claims
the size of their asks: one audit, one client pass, per-dist flips — against the only
active exposure this map holds.
