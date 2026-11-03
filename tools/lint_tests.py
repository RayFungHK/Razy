#!/usr/bin/env python3
"""Razy copy of the SKL-V test-home ratchet (TODO 13w32a).

A copy of the workspace test-home checker, bin/lint_tests.py; this repo's
CI cannot check out the workspace repo. Behavior tests live with this copy.
Baseline: tools/lint-tests-baseline.json. Counts may only go down.

    python3 tools/lint_tests.py
    python3 tools/lint_tests.py --seed          # first baseline only
    python3 tools/lint_tests.py --update        # lower counts; never raises them
    python3 tools/lint_tests.py --raise KEY --reason TEXT

Three checks, all against a committed baseline:

  names      a test file whose name carries an id fails. Families live in the baseline (`idFamilies`):
             a whole segment is family + digits, optionally with trailing letters (`uxh22b`). Also a
             TODO id (`13w30`, `57h10d`), `pr` + digits, a one-letter slug+digit segment (`h6`, `p1`),
             a trailing slug+digit on the stem (`composersteer1`), and a `-<digits>` stem suffix.
             Real tokens (`v2`, `utf8`, `i18n`, …) are not ids. `names` is today's matches: --update
             only removes a name that is gone, and never adds one.
  modules    test files per directory may only go down. A directory that is not in the baseline fails
             until --update records it (dupcheck never adds a key; this one does, or a new module could
             never gain its first test file). --update never raises a count. --raise does, for one key,
             and appends {key, from, to, reason} to `raises`.
  workflows  direct list items under each `steps:` key, summed per workflow file, may only go down.
             A dash that is not a step (a port, a cron, a service) is not counted. A new workflow file
             is recorded by --update, the same way a new module is. --raise is the same one-key rise.
             --seed writes the first baseline and refuses a non-empty one.

"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from collections import Counter
from pathlib import Path

WS = Path(__file__).resolve().parents[1]
DEFAULT_BASELINE = Path(__file__).resolve().parent / "lint-tests-baseline.json"
CMD = f"python3 {Path(__file__).resolve().parent.name}/{Path(__file__).name}"
# Card-id prefixes. A repo copy replaces this list in its baseline (`idFamilies`).
DEFAULT_FAMILIES = (
    "aud", "cmp", "ctx", "cue", "hbd", "pul", "rui", "rz", "skl", "uxh", "vid", "ws",
)
# Whole tokens that look like ids and are not: protocol, codec, hash, arch, version, locale.
DEFAULT_TOKENS = frozenset({
    "e2e", "oauth2", "utf8", "s3", "h264", "sha256", "x86", "v2", "i18n", "l10n",
})
FAMILY_RE = re.compile(r"^[A-Za-z][A-Za-z0-9]*$")
TOKEN_RE = re.compile(r"^[A-Za-z0-9]+$")
COMMENT = (
    "SKL-V ratchet. Counts may only go down, except one key via --raise, "
    "which appends {key, from, to, reason} to raises. --update may add a new module or workflow "
    "key and never raises one. Id-named files may only leave `names`. Families live in idFamilies."
)
TEST_SUFFIXES = (
    ".test.mjs", ".test.js", ".test.ts", ".test.tsx",
    ".spec.mjs", ".spec.js", ".spec.ts", ".spec.tsx",
)
DEFAULT_IGNORE = (re.compile(r"(^|/)(node_modules|vendor|dist|\.ws)/"),)
WORKFLOW_RE = re.compile(r"^\.github/workflows/[^/]+\.ya?ml$")


class Usage(Exception):
    pass


def is_test(name: str) -> bool:
    if name.startswith("test_") and name.endswith(".py"):
        return True
    if name.endswith("_test.py"):
        return True
    return name.endswith(TEST_SUFFIXES)


def ignored(rel: str) -> bool:
    return any(p.search(rel) for p in DEFAULT_IGNORE)


def stem_of(name: str) -> str:
    for suf in TEST_SUFFIXES:
        if name.endswith(suf):
            return name[: -len(suf)]
    if name.endswith("_test.py"):
        return name[: -len("_test.py")]
    if name.endswith(".py"):
        return name[:-3]
    return name


def segments_of(stem: str) -> list[str]:
    return [seg for seg in re.split(r"[-_.]+", stem) if seg]


def id_match(name: str, families: list[str] | None = None, tokens: set[str] | None = None) -> str | None:
    """Why `name` is an id, or None. `families` None uses the built-in list; an empty list uses none."""
    fams = list(DEFAULT_FAMILIES if families is None else families)
    toks = DEFAULT_TOKENS if tokens is None else {t.lower() for t in tokens}
    stem = stem_of(name)
    if re.search(r"-\d+$", stem):
        return "-\\d+ suffix"
    fams.sort(key=len, reverse=True)
    for seg in segments_of(stem):
        if seg.lower() in toks:
            continue
        for fam in fams:
            if re.fullmatch(rf"{re.escape(fam)}\d+[A-Za-z]*", seg, re.IGNORECASE):
                return f"family {fam}"
            # A family that already contains a digit (`l5`) is that segment, not only `l5` + more digits.
            if any(ch.isdigit() for ch in fam) and seg.lower() == fam.lower():
                return f"family {fam}"
        if re.fullmatch(r"\d+[A-Za-z]+\d*[A-Za-z]*", seg):
            return "todo id"
        if re.fullmatch(r"pr\d+[A-Za-z]*", seg, re.IGNORECASE):
            return "pr id"
        if re.fullmatch(r"[A-Za-z]\d+", seg):
            return f"slug+digit segment {seg}"
    trailing = re.search(r"([A-Za-z]{2,}\d+)$", stem)
    if trailing and trailing.group(1).lower() not in toks:
        return "trailing slug+digit"
    return None


def git_files(repo: Path) -> list[str]:
    """Tracked and untracked (not git-ignored) paths. -z so a quote in a name stays literal."""
    res = subprocess.run(["git", "-C", str(repo), "ls-files", "-z", "-co", "--exclude-standard"],
                         check=True, capture_output=True)
    raw = res.stdout.decode("utf-8", errors="surrogateescape")
    return sorted({p for p in raw.split("\0") if p and not ignored(p)})


def test_files(repo: Path, rels: list[str]) -> list[str]:
    """Test paths that are still files. `git ls-files -c` keeps a tracked path after a plain delete,
    so a deletion that is not staged yet must not hold the module count up."""
    return [r for r in rels if is_test(Path(r).name) and (repo / r).is_file()]


def module_of(rel: str) -> str:
    parent = Path(rel).parent.as_posix()
    return "." if parent == "." else parent


def _flow_feed():
    """Reader whose quote and comment state carries across chunks.

    A quoted scalar (`'...'`, `"..."`, with `''` and backslash escapes) and a `#`
    comment (YAML: only when whitespace precedes `#`) contribute no brackets.
    """
    quote = ""
    escape = False
    comment = False
    prev = ""  # last non-space character outside quotes; a quote opens only after it

    def feed(chunk: str, emit) -> None:
        nonlocal quote, escape, comment, prev
        i = 0
        n = len(chunk)
        while i < n:
            ch = chunk[i]
            if comment:
                if ch == "\n":
                    comment = False
                i += 1
                continue
            if quote == "'":
                if ch == "'" and i + 1 < n and chunk[i + 1] == "'":
                    i += 2
                    continue
                if ch == "'":
                    quote = ""
                    prev = "q"
                i += 1
                continue
            if quote == '"':
                if escape:
                    escape = False
                elif ch == "\\":
                    escape = True
                elif ch == '"':
                    quote = ""
                    prev = "q"
                i += 1
                continue
            if ch in "'\"" and (prev == "" or prev in "[{,:?"):
                # The opener is content (a quoted scalar is an item). Its interior is not.
                emit(ch)
                quote = ch
                i += 1
                continue
            if ch == "#" and (i == 0 or chunk[i - 1].isspace()):
                comment = True
                i += 1
                continue
            emit(ch)
            if not ch.isspace():
                prev = ch
            i += 1

    return feed


def flow_end(lines: list[str], start: int, first: str) -> tuple[str, int]:
    """Join a flow sequence from the `steps:` line through the `]` that closes it.

    Brackets inside a quoted scalar or a `#` comment do not close it.
    """
    buf = first
    depth = 0
    closed = False
    feed = _flow_feed()

    def emit(ch: str) -> None:
        nonlocal depth, closed
        if closed:
            return
        if ch == "[":
            depth += 1
        elif ch == "]":
            depth -= 1
            if depth == 0:
                closed = True

    feed(buf, emit)
    i = start + 1
    while not closed and i < len(lines):
        buf += "\n" + lines[i]
        feed("\n" + lines[i], emit)
        i += 1
    return buf, i


def flow_items(rest: str) -> int:
    """Top-level items of a flow sequence (`steps: [a, b]` or a `[` closed on a later line).

    The same quote and comment rules as `flow_end`: a `]` or a comma inside them is not structure.
    """
    depth = 0
    items = 0
    seen = False

    def emit(ch: str) -> None:
        nonlocal depth, items, seen
        if ch in "[{":
            if depth == 1:
                seen = True
            depth += 1
        elif ch in "]}":
            depth -= 1
        elif ch == "," and depth == 1 and seen:
            items += 1
            seen = False
        elif depth == 1 and not ch.isspace():
            seen = True

    _flow_feed()(rest, emit)
    return items + (1 if seen else 0)


def step_count(text: str) -> int:
    """Direct children of every `steps:` key. Nested dashes (ports, services) stay inside a step."""
    lines = text.splitlines()
    total = 0
    i = 0
    while i < len(lines):
        raw = lines[i]
        if raw.lstrip().startswith("#"):
            i += 1
            continue
        m = re.match(r"^([ ]*)steps:[ ]*(.*)$", raw)
        if not m:
            i += 1
            continue
        indent = len(m.group(1))
        rest = m.group(2).strip()
        if rest.startswith("["):
            blob, i = flow_end(lines, i, rest)
            total += flow_items(blob)
            continue
        i += 1
        child: int | None = None
        while i < len(lines):
            line = lines[i]
            if not line.strip() or line.lstrip().startswith("#"):
                i += 1
                continue
            cur = len(line) - len(line.lstrip(" "))
            if cur <= indent:
                break
            if child is None:
                child = cur
            if cur == child and re.match(r"[ ]*-[ ]+\S", line):
                total += 1
            i += 1
    return total


def snapshot(repo: Path) -> tuple[list[str], dict[str, int], dict[str, int]]:
    rels = git_files(repo)
    tests = test_files(repo, rels)
    modules = dict(sorted(Counter(module_of(r) for r in tests).items()))
    workflows: dict[str, int] = {}
    for rel in rels:
        if WORKFLOW_RE.match(rel) and (repo / rel).is_file():
            workflows[rel] = step_count((repo / rel).read_text(encoding="utf-8", errors="replace"))
    return tests, modules, dict(sorted(workflows.items()))


def _strings(path: Path, data: dict, key: str, pattern: re.Pattern[str]) -> list[str]:
    raw = data.get(key, [])
    if not isinstance(raw, list) or not all(isinstance(item, str) and pattern.fullmatch(item) for item in raw):
        raise Usage(f"{path} {key} must be a list of tokens")
    return list(raw)


def load_baseline(path: Path) -> dict:
    if not path.exists():
        raise Usage(f"{path} is missing; run {CMD} --seed on an empty baseline")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise Usage(f"{path} is not an object")
    if "names" not in data:
        data["names"] = []
    if "modules" not in data:
        data["modules"] = {}
    if "workflows" not in data:
        data["workflows"] = {}
    if not isinstance(data["names"], list) or not isinstance(data["modules"], dict) or not isinstance(data["workflows"], dict):
        raise Usage(f"{path} names must be a list, and modules and workflows objects")
    if "idFamilies" not in data:
        data["idFamilies"] = list(DEFAULT_FAMILIES)
    else:
        data["idFamilies"] = _strings(path, data, "idFamilies", FAMILY_RE)
    if "idTokens" not in data:
        data["idTokens"] = sorted(DEFAULT_TOKENS)
    else:
        data["idTokens"] = _strings(path, data, "idTokens", TOKEN_RE)
    if "raises" not in data:
        data["raises"] = []
    if not isinstance(data["raises"], list):
        raise Usage(f"{path} raises must be a list")
    return data


def nonempty(data: dict) -> bool:
    return bool(data.get("names") or data.get("modules") or data.get("workflows") or data.get("raises"))


def token_set(data: dict) -> set[str]:
    """Built-in tokens plus any the baseline adds. A copy cannot drop `v2` by omitting it."""
    return set(DEFAULT_TOKENS) | {t.lower() for t in data.get("idTokens") or []}


def write_baseline(path: Path, names: list[str], modules: dict[str, int], workflows: dict[str, int],
                   families: list[str], tokens: list[str], raises: list[dict]) -> None:
    out = {
        "$comment": COMMENT,
        "names": names,
        "idFamilies": families,
        "idTokens": tokens,
        "raises": raises,
        "modules": modules,
        "workflows": workflows,
    }
    path.write_text(json.dumps(out, indent=2) + "\n", encoding="utf-8")


def tighten(old: dict, current: dict) -> dict[str, int]:
    """Keep min(old, current) for a known key, including 0 so a later rise still fails. Add a key the
    baseline has never seen. Never raise a key."""
    out: dict[str, int] = {}
    for key, value in old.items():
        out[key] = min(int(value), int(current.get(key, 0)))
    for key, value in current.items():
        if key not in old:
            out[key] = int(value)
    return dict(sorted(out.items()))


def name_id(data: dict, name: str) -> str | None:
    return id_match(name, list(data.get("idFamilies") or []), token_set(data))


def check(data: dict, tests: list[str], modules: dict[str, int], workflows: dict[str, int]) -> list[str]:
    allow = set(data.get("names") or [])
    errs: list[str] = []
    live_bad = set()
    for rel in tests:
        pat = name_id(data, Path(rel).name)
        if not pat:
            continue
        live_bad.add(rel)
        if rel not in allow:
            errs.append(f"{rel}: test file name carries a TODO or PR id ({pat})")
    for rel in sorted(allow - live_bad):
        errs.append(f"{rel}: baseline name for a test file that no longer matches (run {CMD} --update)")
    base_modules = data.get("modules") or {}
    for rel, count in modules.items():
        if rel not in base_modules:
            errs.append(f"{rel}: {count} test files in a module with no baseline (run {CMD} --update)")
        elif count > int(base_modules[rel]):
            errs.append(f"{rel}: {count} test files (baseline {base_modules[rel]})")
    for rel in sorted(set(base_modules) - set(modules)):
        if int(base_modules[rel]) != 0:
            errs.append(f"{rel}: baseline key for a module that no longer has test files (run {CMD} --update)")
    base_flows = data.get("workflows") or {}
    for rel, count in workflows.items():
        if rel not in base_flows:
            errs.append(f"{rel}: {count} steps in a workflow with no baseline (run {CMD} --update)")
        elif count > int(base_flows[rel]):
            errs.append(f"{rel}: {count} steps (baseline {base_flows[rel]})")
    for rel in sorted(set(base_flows) - set(workflows)):
        if int(base_flows[rel]) != 0:
            errs.append(f"{rel}: baseline key for a workflow that no longer exists (run {CMD} --update)")
    return errs


def parse(argv: list[str] | None) -> argparse.Namespace:
    # dupcheck: allow SKL-V copies the dupcheck command shape (--repo, --baseline, --update, --seed)
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--repo", type=Path, default=WS)
    ap.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    mode = ap.add_mutually_exclusive_group()
    mode.add_argument("--update", action="store_true", help="lower counts; record a new module or workflow; drop gone id names")
    mode.add_argument("--seed", action="store_true", help="write a first baseline; refuses a non-empty one")
    mode.add_argument("--raise", dest="raise_key", metavar="KEY",
                      help="raise one module or workflow key to its measured count")
    ap.add_argument("--reason", default="", help="why that key may rise; required with --raise")
    return ap.parse_args(argv)


def measured(key: str, modules: dict[str, int], workflows: dict[str, int]) -> tuple[str, int] | None:
    if key in modules and key in workflows:
        raise Usage(f"--raise: {key} is both a module and a workflow")
    if key in modules:
        return "modules", int(modules[key])
    if key in workflows:
        return "workflows", int(workflows[key])
    return None


def apply_raise(data: dict, tests: list[str], modules: dict[str, int], workflows: dict[str, int],
                key: str, reason: str) -> None:
    reason = reason.strip()
    if not reason:
        raise Usage("--raise needs a --reason")
    if name_id(data, Path(key).name):
        raise Usage(f"--raise refused for an id-named file: {key}")
    found = measured(key, data.get("modules") or {}, data.get("workflows") or {})
    if found is None:
        raise Usage(f"--raise: unknown key {key}")
    bucket, old = found
    current = modules if bucket == "modules" else workflows
    if key not in current:
        raise Usage(f"--raise: {key} is not in the measured tree")
    new = int(current[key])
    if new <= old:
        raise Usage(f"--raise: {key} is {new}, baseline {old}; a raise needs a higher measured count")
    if bucket == "modules":
        bad = [rel for rel in tests if module_of(rel) == key and name_id(data, Path(rel).name)
               and rel not in set(data.get("names") or [])]
        if bad:
            raise Usage(f"--raise refused for an id-named file: {bad[0]}")
    data[bucket][key] = new
    raises = list(data.get("raises") or [])
    raises.append({"key": key, "from": old, "to": new, "reason": reason})
    data["raises"] = raises


def persist(path: Path, data: dict, names: list[str], modules: dict[str, int], workflows: dict[str, int]) -> None:
    families = list(data.get("idFamilies") if "idFamilies" in data else DEFAULT_FAMILIES)
    tokens = sorted(token_set(data))
    write_baseline(path, names, modules, workflows, families, tokens, list(data.get("raises") or []))


def run(args: argparse.Namespace) -> int:
    if args.reason.strip() and not args.raise_key:
        raise Usage("--reason is only used with --raise")
    if args.seed and args.baseline.exists() and nonempty(load_baseline(args.baseline)):
        raise Usage(f"{args.baseline} already has counts; --seed never overwrites them (use --update)")
    tests, modules, workflows = snapshot(args.repo)
    if args.seed:
        fresh = {"idFamilies": list(DEFAULT_FAMILIES), "idTokens": sorted(DEFAULT_TOKENS), "raises": []}
        bad = sorted(r for r in tests if name_id(fresh, Path(r).name))
        write_baseline(args.baseline, bad, modules, workflows, fresh["idFamilies"], fresh["idTokens"], [])
        print(f"lint tests: baseline seeded ({len(tests)} test files, {len(bad)} id-named, {len(workflows)} workflows)")
        return 0
    data = load_baseline(args.baseline)
    bad = [r for r in tests if name_id(data, Path(r).name)]
    if args.update:
        kept = sorted(r for r in (data.get("names") or []) if r in bad)
        persist(args.baseline, data, kept, tighten(data.get("modules") or {}, modules),
                tighten(data.get("workflows") or {}, workflows))
        print(f"lint tests: baseline tightened ({len(tests)} test files, {len(kept)} id-named kept)")
        return 0
    if args.raise_key:
        apply_raise(data, tests, modules, workflows, args.raise_key, args.reason)
        persist(args.baseline, data, list(data.get("names") or []), data.get("modules") or {},
                data.get("workflows") or {})
        last = data["raises"][-1]
        print(f"lint tests: raised {last['key']} {last['from']} -> {last['to']}")
        return 0
    errs = check(data, tests, modules, workflows)
    for err in errs:
        print(f"lint tests: {err}")
    rc = 1 if errs else 0
    print(f"lint tests {'OK' if rc == 0 else 'FAILED'}: {len(tests)} test files, {len(modules)} modules, "
          f"{len(workflows)} workflows")
    return rc


def main(argv: list[str] | None = None) -> int:
    try:
        return run(parse(argv))  # dupcheck: allow same Usage / git-failure exits as bin/dupcheck.py
    except Usage as exc:
        print(f"lint tests: {exc}", file=sys.stderr)
        return 2
    except subprocess.CalledProcessError as exc:
        print(f"lint tests: git failed: {' '.join(map(str, exc.cmd))}\n{(exc.stderr or b'').decode(errors='replace')}",
              file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
