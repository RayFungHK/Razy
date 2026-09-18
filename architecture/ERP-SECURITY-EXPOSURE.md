# ERP-SECURITY-EXPOSURE — The Only Active Risk in the Survey, and What a Phar Alone Cannot Close

**Status: PROPOSAL — owner-gated.** Companion to [ERP-COMPILE-PROPOSAL.md](ERP-COMPILE-PROPOSAL.md);
same standing rule: this side never touches the ERP repo without a per-step go. Written because
the epoch's doctrine work keeps converging on one fact that deserves its own page rather than a
row in a table. Date: 2026-09-17.

## The finding (re-verified against the framework before this page was written)

- The production ERP has **zero CSRF defense**: 4 case-insensitive `csrf` matches app-wide,
  all four OAuth `state` comments; **~270 mutating routes unguarded**
  ([ERP-GENERALIZATION.md §4](ERP-GENERALIZATION.md), §0 ground truth).
- The framework's own security guide, in today's release, states it plainly
  (`manual/07-security-guide.md:103`): the wiring snippet is *illustrative* —
  *"no shipped demo wires it"*. The engine exists; **nothing enforces it anywhere**.
- Doctrine's ranking, from the same survey: C5 (CSRF) is **the only active exposure** on
  the entire candidate list — everything else there is quality-of-life or hygiene. Every
  quarter without it is described, in the framework's own words, as a compounding risk on
  a production ERP.

## The uncomfortable part, stated honestly

An auth hub (login, session, SSO) is **exactly** the place a framework has no legitimate
way to know what should be un-protected:

- A **default-protect rail** would break exactly the routes that must verify statelessly —
  the OAuth `state` callbacks the survey found are the app's ONLY csrf-adjacent code, plus
  webhooks, login itself, and the mobile/desktop clients ERP desktop builds may carry
  (`C:\Users\RayFung\Desktop\erp desktop`).
- Which is precisely how Laravel — the ecosystem with CSRF on by default — ends up with
  CSRF disabled on the auth surface of most of its production apps. Shipping "on by
  default" ships protection that gets switched off under pressure, with a bigger BC blast
  radius than today's nothing.

So the honest shape is **explicit declaration, not global default**: the framework's
precedent for exactly this dilemma is the route gate (L3) — default-off, but *naming the
sites that opt out*, so silence becomes a visible declaration (`'csrf' => 'off'` printed
by `validate <dist>`, per [CSRF-RAIL.md](CSRF-RAIL.md) §Q1). A phar upgrade changes none
of this: with an unwired rail, 1.0.3 → latest moves the zero from one version to another.
**ERP's exposure does not decay with adoption — only a decision does.**

## Proposal — three decisions, separated from every other adoption question

1. **Readout first, zero behavior change (owner decision: approve one audit run).** A
   read-only sweep classifying all ~270 mutating routes: session-authed stateful (must
   protect), stateless-by-design (state/webhook/client — must *name themselves* as
   exempt), unauthenticated (login/register). Output = the real opt-out list, which is
   also the acceptance test any rail ships against. This side can produce the sweep
   script (read-only against a staging copy); no framework feature is required for the
   readout to exist.
2. **Then, and only then, choose the rail shape** — framework-side (CSRF-RAIL.md Q1–Q6
   sign-off, which this page argues should go **explicit-declare, not default-protect**,
   for the reasons above) or app-side wiring snippet per the manual. The audit's opt-out
   list is the input either way; doing 2 before 1 is how defaults get switched off later.
3. **Client posture as part of the audit** — stateless/desktop/mobile clients are the
   reason the default-protect fantasy dies; the sweep names them, and whatever
   non-browser posture ERP wants (tokens, signing) gets declared in the same pass.

## What this page deliberately does not do

It does not fold CSRF into the phar upgrade in [ERP-COMPILE-PROPOSAL.md](ERP-COMPILE-PROPOSAL.md).
That page's step 1 ("take the upgrade anyway") is worth doing on its own merits — bug fixes,
classmap — but **nothing on this page should be read as "upgrade solves it"**, and the CSRF
decision should not be used as leverage to push the upgrade. Separate decisions, separate
yes/no's, same discipline as everywhere: the claim and the ask are the same size, and the
size here is: one audit, then one decision, against the only active exposure this map holds.
