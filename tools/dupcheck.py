#!/usr/bin/env python3
"""Razy copy of the SKL-P duplicate-code check (TODO 13w12c).

A copy of the workspace duplicate-code checker, bin/dupcheck.py; this repo's
CI cannot check out the workspace repo. Behavior tests for the windows live
with that copy. This copy scans only src/.
Baseline: tools/dupcheck-baseline.json. Counts may only go down.

    python3 tools/dupcheck.py --base origin/master
    python3 tools/dupcheck.py --seed          # first baseline only
    python3 tools/dupcheck.py --update        # lower counts; never raises them

Why our own: it must run on PHP, JS and Python with no network and no install
step at CI time, and it has to know which lines a PR added. It uses the method
of PMD CPD and jscpd: tokenize (tools/dupcheck_lex.py), normalise names,
numbers and strings, hash token windows, and merge matching windows into clone
fragments. Renamed copies are found, and so are copies with one inserted,
removed or swapped statement: seeds of WINDOW tokens up to GAP tokens apart, in
either order, merge into one fragment of at least MIN_TOKENS matched tokens.
Rewritten logic is the Reuse-first gate's job.

Not counted: import lines (tools/dupcheck_lex.py), periodic data rows (i18n
catalogues, tables of registrations), fixtures, and vendored or generated paths
(the baseline's `ignore`).

Modes (one command does both):
  ratchet    tracked and untracked source under src/; no file above its
             baseline count, and no baseline key for a file that is gone.
             --update lowers counts and moves them along renames (with --base);
             it never raises one or adds a file. --seed writes a first baseline
             and refuses a non-empty one.
  new code   --base REF: a clone pair (two files) with more fragments than at
             merge-base(REF, HEAD) fails when a new fragment touches an added
             line, unless an added line inside it carries a
             `dupcheck: allow <reason>` comment. An edit inside a pair that
             already existed does not fail.
Name hints (advisory, exit code unchanged): new definitions whose name shares
most words with an existing one.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from array import array
from dataclasses import dataclass, field
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from dupcheck_lex import LANG, Source, load  # noqa: E402

WS = Path(__file__).resolve().parents[1]
DEFAULT_BASELINE = Path(__file__).resolve().parent / "dupcheck-baseline.json"
ROOTS = ("src/",)
MIN_TOKENS, WINDOW, GAP, MIN_LINES = 70, 30, 30, 6   # GAP: about one statement
PERIOD = 16          # a fragment repeating a pattern of at most this many tokens is a data table, not code
FILTER_BITS = 24     # counting filter for windows: 16 M one-byte cells, fixed memory
ALLOW_RE = re.compile(r"dupcheck:\s*allow\s+\S")
DEFAULT_IGNORE = [r"(^|/)(node_modules|vendor|dist|\.ws|link)/", r"\.min\.js$", r"(^|/)webassets/razyui/",
                  r"(^|/)docs/design/mockups/", r"(^|/)(fixtures?|__fixtures__|selftests?)/", r"\.fixtures?\."]
DEF_RES = [re.compile(r"^\s*(?:async\s+)?def\s+([A-Za-z_]\w*)"), re.compile(r"^\s*class\s+([A-Za-z_]\w*)"),
           re.compile(r"\bfunction\s+([A-Za-z_$][\w$]*)"),
           re.compile(r"^\s*(?:export\s+)?(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:\([^)]*\)|\w+)\s*=>")]


@dataclass(frozen=True)
class Fragment:
    a: str
    a_lines: tuple[int, int]
    b: str
    b_lines: tuple[int, int]
    tokens: int
    # identity for --base (not part of equality): the matched runs of side a, and the token span of each side
    runs: tuple[str, ...] = field(default=(), compare=False, hash=False)
    a_seq: str = field(default="", compare=False, hash=False)
    b_seq: str = field(default="", compare=False, hash=False)


SEP = "\x1f"


def seq(tokens: list[tuple[str, int]], lo: int, hi: int) -> str:
    return SEP + SEP.join(k for k, _ in tokens[lo:hi]) + SEP


class Usage(Exception):
    pass


def git(repo: Path, *args: str) -> str:
    return subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True, text=True).stdout


def source_files(repo: Path, ignore: list[str]) -> list[str]:
    """Tracked and untracked source files under src/."""
    skip = [re.compile(p) for p in ignore]
    rels = git(repo, "ls-files", "-z", "-co", "--exclude-standard").split("\0")
    return sorted({
        r for r in rels
        if r.startswith(ROOTS) and Path(r).suffix in LANG and (repo / r).is_file()
        and not any(p.search(r) for p in skip)
    })


def seeds(files: dict[str, Source], w: int) -> dict[int, list[tuple[str, int]]]:
    """Occurrences of every w-token window seen more than once. Pass 1 counts hashes in a fixed-size filter;
    pass 2 keeps only windows whose cell saw two or more, so memory follows the repeats, not the code size."""
    vocab: dict[str, int] = {}
    base, mod = 1_000_003, (1 << 61) - 1
    top, cells = pow(base, w - 1, mod), (1 << FILTER_BITS) - 1
    counts = bytearray(1 << FILTER_BITS)
    hashes: dict[str, array] = {}
    for rel, src in files.items():
        ids = [vocab.setdefault(k, len(vocab) + 1) for k, _ in src.tokens]
        hs = array("Q")
        if len(ids) >= w:
            h = 0
            for t in ids[:w]:
                h = (h * base + t) % mod
            hs.append(h)
            for i in range(1, len(ids) - w + 1):
                h = ((h - ids[i - 1] * top) * base + ids[i + w - 1]) % mod
                hs.append(h)
        for h in hs:
            if counts[h & cells] < 2:
                counts[h & cells] += 1
        hashes[rel] = hs
    buckets: dict[int, list[tuple[str, int]]] = {}
    for rel, hs in hashes.items():
        for i, h in enumerate(hs):
            if counts[h & cells] > 1:
                buckets.setdefault(h, []).append((rel, i))
    return {h: occ for h, occ in buckets.items() if len(occ) > 1}


def pieces(files: dict[str, Source], w: int) -> dict[tuple[str, str], list[tuple[int, int, int]]]:
    """Exact runs per file pair (start in a, start in b, length); each later copy pairs with the first."""
    runs: dict[tuple[str, str, int], list[int]] = {}
    for occ in seeds(files, w).values():
        fa, ia = occ[0]
        ka = [k for k, _ in files[fa].tokens[ia:ia + w]]
        for fb, ib in occ[1:]:
            if (fa == fb and abs(ib - ia) < w) or ka != [k for k, _ in files[fb].tokens[ib:ib + w]]:
                continue  # self-overlap, or a hash collision
            runs.setdefault((fa, fb, ib - ia), []).append(ia)
    out: dict[tuple[str, str], list[tuple[int, int, int]]] = {}
    for (fa, fb, delta), starts in runs.items():
        starts.sort()
        first = prev = starts[0]
        for s in starts[1:] + [None]:
            if s is not None and s == prev + 1:
                prev = s
                continue
            out.setdefault((fa, fb), []).append((first, first + delta, prev - first + w))
            if s is not None:
                first = prev = s
    return out


def periodic(kinds: list[str]) -> bool:
    """True for a run that repeats a short pattern (i18n rows, data tables, register calls)."""
    for p in range(1, PERIOD + 1):
        if len(kinds) > 2 * p and sum(kinds[i] == kinds[i + p] for i in range(len(kinds) - p)) >= 0.9 * (len(kinds) - p):
            return True
    return False


def cluster(files: dict[str, Source], fa: str, fb: str, runs: list[tuple[int, int, int]], n: int,
            min_lines: int) -> list[Fragment]:
    """Merge runs of one file pair that lie within GAP tokens of each other on both sides, in either order."""
    groups: list[dict] = []
    for a0, b0, length in sorted(runs):
        g = groups[-1] if groups else None
        if g and a0 - g["a1"] <= GAP and g["b0"] - GAP - length <= b0 <= g["b1"] + GAP:
            g.update(a1=max(g["a1"], a0 + length), b0=min(g["b0"], b0), b1=max(g["b1"], b0 + length))
            g["hit"].update(range(a0, a0 + length))
            g["runs"].append((a0, length))
        else:
            groups.append({"a0": a0, "a1": a0 + length, "b0": b0, "b1": b0 + length,
                           "hit": set(range(a0, a0 + length)), "runs": [(a0, length)]})
    ta, tb, out = files[fa].tokens, files[fb].tokens, []
    for g in groups:
        kinds = [ta[i][0] for i in sorted(g["hit"])]
        if len(kinds) < n or periodic(kinds):
            continue
        f = Fragment(fa, (ta[g["a0"]][1], ta[g["a1"] - 1][1]), fb, (tb[g["b0"]][1], tb[g["b1"] - 1][1]), len(kinds),
                     tuple(seq(ta, a0, a0 + length) for a0, length in g["runs"]),
                     seq(ta, g["a0"], g["a1"]), seq(tb, g["b0"], g["b1"]))
        if fa == fb and f.a_lines[1] >= f.b_lines[0] and f.b_lines[1] >= f.a_lines[0]:
            continue  # the two sides overlap: one block repeating its own structure, not a copy
        if f.a_lines[1] - f.a_lines[0] + 1 >= min_lines:
            out.append(f)
    return out


def fragments(files: dict[str, Source], n: int = MIN_TOKENS, min_lines: int = MIN_LINES, w: int = WINDOW) -> list[Fragment]:
    out = []
    for (fa, fb), runs in pieces(files, min(w, n)).items():
        out.extend(cluster(files, fa, fb, runs, n, min_lines))
    return sorted(set(out), key=lambda f: (f.a, f.a_lines, f.b, f.b_lines))


def is_allowed(f: Fragment, files: dict[str, Source], added: dict[str, set[int]] | None = None) -> bool:
    """A `dupcheck: allow <reason>` comment inside the fragment. With `added` (new-code mode) it counts only on an
    added line of a **new copy**: a side whose fragment lines are mostly added, never a line stamped onto the older
    file."""
    for rel, (lo, hi) in ((f.a, f.a_lines), (f.b, f.b_lines)):
        if added is not None:
            new_lines = added.get(rel, set())
            if 2 * sum(1 for n in range(lo, hi + 1) if n in new_lines) < hi - lo + 1:
                continue  # the older copy
        for line, text in files[rel].comments:
            if lo <= line <= hi and ALLOW_RE.search(text) and (added is None or line in added.get(rel, ())):
                return True
    return False


def per_file(frags: list[Fragment]) -> dict[str, int]:
    counts: dict[str, int] = {}
    for f in frags:
        for rel in {f.a, f.b}:
            counts[rel] = counts.get(rel, 0) + 1
    return dict(sorted(counts.items()))


def merge_base(repo: Path, base: str) -> str:
    probe = subprocess.run(["git", "-C", str(repo), "rev-parse", "--verify", "--quiet", f"{base}^{{commit}}"],
                           capture_output=True)
    if probe.returncode != 0:
        raise Usage(f"base ref {base!r} not found; fetch it first (git fetch origin <branch>)")
    return git(repo, "merge-base", base, "HEAD").strip()


def unquote_path(path: str) -> str:
    """git C-quotes a path with a tab, newline, quote or backslash; undo that, octal bytes read as UTF-8.

    A header for a name with a space also ends in a tab, which the caller strips first."""
    if not (path.startswith('"') and path.endswith('"')):
        return path
    raw = path[1:-1].encode("latin-1", "backslashreplace").decode("unicode_escape")
    return raw.encode("latin-1").decode("utf-8", errors="replace")


def changes(repo: Path, mb: str) -> tuple[dict[str, set[int]], dict[str, str]]:
    """Added lines per file since mb (committed, staged, unstaged and untracked), and renames {new: old}."""
    added: dict[str, set[int]] = {}
    rename: dict[str, str] = {}
    for line in git(repo, "-c", "core.quotePath=false", "diff", "--name-status", "-M", mb).splitlines():
        parts = line.split("\t")
        if parts[0].startswith("R") and len(parts) == 3:
            rename[parts[2]] = parts[1]
    current = None
    for line in git(repo, "-c", "core.quotePath=false", "diff", "-U0", "--no-color", "-M", mb).splitlines():
        if line.startswith("+++ "):
            name = line[4:].rstrip("\t")
            current = None if name.endswith("/dev/null") else unquote_path(name)[2:]
        elif line.startswith("@@") and current:
            m = re.search(r"\+(\d+)(?:,(\d+))?", line)
            start, count = int(m.group(1)), int(m.group(2) or 1)
            added.setdefault(current, set()).update(range(start, start + count))
    for rel in git(repo, "ls-files", "-z", "-o", "--exclude-standard").split("\0"):
        if rel and (repo / rel).is_file():
            total = (repo / rel).read_text(encoding="utf-8", errors="replace").count("\n") + 1
            added[rel] = set(range(1, total + 1))
    return added, rename


def touches(rel: str, span: tuple[int, int], added: dict[str, set[int]]) -> bool:
    return any(n in added.get(rel, ()) for n in range(span[0], span[1] + 1))


def base_fragments(repo: Path, mb: str, paths: set[str], n: int) -> dict[frozenset, list[Fragment]]:
    """Fragments at the merge base among the given base paths, by file pair."""
    files = {}
    for rel in sorted(paths):
        shown = subprocess.run(["git", "-C", str(repo), "show", f"{mb}:{rel}"], capture_output=True, text=True)
        if shown.returncode == 0:  # else: new in this change
            files[rel] = load(shown.stdout, rel)
    out: dict[frozenset, list[Fragment]] = {}
    for f in fragments(files, n):
        out.setdefault(frozenset((f.a, f.b)), []).append(f)
    return out


def existed(f: Fragment, before: list[Fragment]) -> bool:
    """The same clone was at the merge base: every matched run of f lies inside a base fragment of the same pair.
    A split or trimmed old clone stays old; a different token sequence is new even if the count did not change."""
    return bool(before) and all(any(r in b.a_seq or r in b.b_seq for b in before) for r in f.runs)


def new_clones(repo: Path, files: dict[str, Source], frags: list[Fragment], mb: str, n: int,
               added: dict[str, set[int]], rename: dict[str, str]) -> tuple[list[Fragment], list[Fragment]]:
    """Fragments touching added lines that were not at the merge base: (failing, allowed)."""
    touching = [f for f in frags if touches(f.a, f.a_lines, added) or touches(f.b, f.b_lines, added)]
    if not touching:
        return [], []
    before = base_fragments(repo, mb, {rename.get(p, p) for f in touching for p in (f.a, f.b)}, n)
    failing, allowed = [], []
    for f in touching:
        if not existed(f, before.get(frozenset(rename.get(p, p) for p in (f.a, f.b)), [])):
            (allowed if is_allowed(f, files, added) else failing).append(f)
    return failing, allowed


def words(name: str) -> set[str]:
    parts = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", name).replace("_", " ").lower().split()
    return {p for p in parts if len(p) > 2 and p not in {"get", "set", "the", "for", "new"}}


def name_hints(repo: Path, rels: list[str], added: dict[str, set[int]]) -> list[str]:
    """New definitions whose name shares >= 2 words and >= 60 % of words with an existing definition."""
    defs: list[tuple[str, str, int, bool]] = []
    for rel in rels:
        for n, line in enumerate((repo / rel).read_text(encoding="utf-8", errors="replace").splitlines(), 1):
            m = next((rx.search(line) for rx in DEF_RES if rx.search(line)), None)
            if m:
                defs.append((m.group(1), rel, n, n in added.get(rel, ())))
    old = [(words(d[0]), d) for d in defs if not d[3] and len(words(d[0])) >= 2]
    hints = []
    for name, rel, n, new in defs:
        nw = words(name)
        for ow, (oname, orel, on, _) in (old if new and len(nw) >= 2 else []):
            shared = nw & ow
            if oname != name and len(shared) >= 2 and len(shared) / len(nw | ow) >= 0.6:
                hints.append(f"possible twin (name): {name} {rel}:{n} ~ {oname} {orel}:{on}")
    return hints


def ratchet(data: dict, counts: dict[str, int], rels: list[str], rename: dict[str, str]) -> list[str]:
    """Files above their baseline (renames carry their key) and baseline keys for files that are gone."""
    base, moved_from = data.get("files", {}), {old: new for new, old in rename.items()}
    errs = [f"{rel}: {v} clone fragments (baseline {base.get(rel, base.get(rename.get(rel, ''), 0))})"
            for rel, v in counts.items() if v > base.get(rel, base.get(rename.get(rel, ""), 0))]
    live = set(rels)
    hint = "run python3 tools/dupcheck.py --update"
    errs += [f"{key}: baseline key for a file that no longer exists ({hint})"
             for key in base if key not in live and moved_from.get(key) not in live]
    return errs


def write_baseline(path: Path, data: dict, counts: dict[str, int], rename: dict[str, str], seed: bool) -> None:
    if seed:
        files = counts
    else:
        moved = {old: new for new, old in rename.items()}
        files = {}
        for key, v in data.get("files", {}).items():
            target = moved.get(key, key)
            if counts.get(target, 0):
                files[target] = min(v, counts[target], files.get(target, v))
    comment = (
        "SKL-P ratchet (tools/dupcheck.py, TODO 13w12c). "
        "Counts may only go down; --update tightens."
    )
    out = {"$comment": comment,
           "minTokens": data.get("minTokens", MIN_TOKENS), "ignore": data.get("ignore", DEFAULT_IGNORE),
           "files": dict(sorted(files.items()))}
    path.write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")


def fmt(f: Fragment) -> str:
    return f"{f.a}:{f.a_lines[0]}-{f.a_lines[1]} ~ {f.b}:{f.b_lines[0]}-{f.b_lines[1]} ({f.tokens} tokens)"


def parse(argv: list[str] | None) -> argparse.Namespace:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--repo", type=Path, default=WS)
    ap.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    ap.add_argument("--base", help="fail on new clone pairs touching lines added since merge-base(BASE, HEAD)")
    ap.add_argument("--min-tokens", type=int)
    mode = ap.add_mutually_exclusive_group()
    mode.add_argument("--update", action="store_true", help="lower baseline counts; move keys along renames (with --base)")
    mode.add_argument("--seed", action="store_true", help="write a first baseline; refuses a non-empty one")
    return ap.parse_args(argv)


def run(args: argparse.Namespace) -> int:
    data = json.loads(args.baseline.read_text(encoding="utf-8")) if args.baseline.exists() else {"files": {}}
    if args.seed and data.get("files"):
        raise Usage(f"{args.baseline} already has counts; --seed never raises them (use --update)")
    n = args.min_tokens or data.get("minTokens", MIN_TOKENS)
    rels = source_files(args.repo, data.get("ignore", DEFAULT_IGNORE))
    files = {rel: load((args.repo / rel).read_text(encoding="utf-8", errors="replace"), rel) for rel in rels}
    every = fragments(files, n)
    frags = [f for f in every if not is_allowed(f, files)]
    mb = merge_base(args.repo, args.base) if args.base else None
    added, rename = changes(args.repo, mb) if mb else ({}, {})
    counts = per_file(frags)
    if args.update or args.seed:
        write_baseline(args.baseline, data, counts, rename, args.seed)
        print(f"dupcheck: baseline {'seeded' if args.seed else 'tightened'} ({sum(counts.values())} file hits)")
        return 0
    errs = ratchet(data, counts, rels, rename)
    for e in errs:
        print(f"dupcheck: {e}")
    failing = []
    if mb:
        failing, allowed = new_clones(args.repo, files, every, mb, n, added, rename)
        for f in failing:
            print(f"dupcheck: new clone: {fmt(f)}")
        for f in allowed:
            print(f"dupcheck: allowed clone (dupcheck: allow): {fmt(f)}")
        for hint in name_hints(args.repo, rels, added):
            print(f"dupcheck: {hint}")
    rc = 1 if errs or failing else 0
    print(f"dupcheck {'OK' if rc == 0 else 'FAILED'}: {len(rels)} files, {len(frags)} clone fragments "
          f"({len(every) - len(frags)} allowed), min {n} tokens")
    return rc


def main(argv: list[str] | None = None) -> int:
    try:
        return run(parse(argv))
    except Usage as e:
        print(f"dupcheck: {e}", file=sys.stderr)
        return 2
    except subprocess.CalledProcessError as e:  # e.g. an unknown --base ref: exit 2 (usage), never 1 (clones found)
        print(f"dupcheck: git failed: {' '.join(map(str, e.cmd))}\n{(e.stderr or '').strip()}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
