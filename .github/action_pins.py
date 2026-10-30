#!/usr/bin/env python3
"""Fail when a GitHub Actions `uses:` is not pinned to a full commit SHA.

A tag or branch (`actions/checkout@v5`) moves under the runner. A 40-hex
commit does not. A same-repo path (`./.github/workflows/release.yml`) has
no remote ref, so it stays.

`bin/ws lint actions` runs this file. The other four workspace repos commit
the same bytes at `.github/action_pins.py`, because their CI does not check
out this repo. Keep those copies identical. Leave the workspace product id
out of this file: the framework boundary ratchet counts it in Razy and
razyui-dev source.

Usage: python3 action_pins.py [PATH ...]
       No PATH scans <repo>/.github for .yml and .yaml.
       Exit 0 when every uses: is a 40-hex pin or a same-repo ./ ref.
       Exit 1 and print file:line when one is not.
       Exit 2 on a bad argument or a missing path.
"""
from __future__ import annotations

import argparse
import os
import re
import sys
from pathlib import Path

# A moving ref is anything after @ that is not 40 lowercase hex digits.
_SHA_ONLY = re.compile(r"^[0-9a-f]{40}$")


class Usage(Exception):
    pass


def _skip_ws(body: str, i: int) -> int:
    while i < len(body) and body[i] in " \t":
        i += 1
    return i


def _match_uses_key(body: str, i: int) -> int | None:
    """Index just after the colon when a uses key starts at i. Quoted or bare."""
    if i >= len(body):
        return None
    if body[i] in "'\"":
        quote = body[i]
        end = body.find(quote, i + 1)
        if end < 0 or body[i + 1:end] != "uses":
            return None
        j = _skip_ws(body, end + 1)
    else:
        if not body.startswith("uses", i):
            return None
        j = i + 4
        if j < len(body) and (body[j].isalnum() or body[j] in "_-"):
            return None
        j = _skip_ws(body, j)
    if j < len(body) and body[j] == ":":
        return j + 1
    return None


def _normalize(raw: str) -> str:
    """The ref text. A second word after a quoted value stays, so it cannot pass as a pin."""
    rest = raw.strip()
    if rest == "" or rest[0] in ">|#":
        return ""
    if rest[0] in "'\"":
        quote = rest[0]
        end = rest.find(quote, 1)
        if end < 0:
            return rest[1:]
        after = rest[end + 1:].strip()
        if after and not after.startswith("#"):
            return rest
        return rest[1:end]
    head = rest.split("#", 1)[0].strip()
    if not head:
        return ""
    if len(head.split()) != 1:
        return head
    return head


def _value_span(body: str, i: int) -> tuple[str, int]:
    """One flow-mapping value. Stops before a comma or a closer at depth zero. Quotes hide both."""
    i = _skip_ws(body, i)
    if i >= len(body):
        return "", i
    if body[i] in ">|#":
        return body[i:], len(body)
    start = i
    if body[i] in "'\"":
        quote = body[i]
        end = body.find(quote, i + 1)
        if end < 0:
            return body[i:], len(body)
        return body[i:end + 1], end + 1
    brace = brack = 0
    while i < len(body):
        ch = body[i]
        if ch in "'\"":
            end = body.find(ch, i + 1)
            if end < 0:
                return body[start:], len(body)
            i = end + 1
            continue
        if ch == "{":
            brace += 1
        elif ch == "}":
            if brace == 0:
                break
            brace -= 1
        elif ch == "[":
            brack += 1
        elif ch == "]":
            if brack == 0:
                break
            brack -= 1
        elif ch == "," and brace == 0 and brack == 0:
            break
        elif ch == "#" and brace == 0 and brack == 0 and (i == start or body[i - 1] in " \t"):
            break
        i += 1
    return body[start:i], i


def _skip_key(body: str, i: int) -> int | None:
    if i < len(body) and body[i] in "'\"":
        end = body.find(body[i], i + 1)
        if end < 0:
            return None
        return end + 1
    while i < len(body) and body[i] not in ":,{}[]":
        i += 1
    return i


def _flow_uses(body: str, i: int) -> str | None:
    """The uses value inside one `{...}` step. Text inside a quoted scalar is not a key."""
    i += 1
    found: str | None = None
    while i < len(body):
        i = _skip_ws(body, i)
        if i >= len(body) or body[i] == "}":
            break
        key_end = _match_uses_key(body, i)
        if key_end is None:
            nxt = _skip_key(body, i)
            if nxt is None:
                return found
            i = _skip_ws(body, nxt)
            if i < len(body) and body[i] == ":":
                i += 1
            _raw, i = _value_span(body, i)
        else:
            raw, i = _value_span(body, key_end)
            found = _normalize(raw)
        i = _skip_ws(body, i)
        if i < len(body) and body[i] == ",":
            i += 1
    return found


def parse_uses(line: str) -> str | None:
    """Return the uses ref, '' when the key has no value, or None when this is not a uses key.

    The key must be this line's key, a quoted `uses` key, or the `uses` key of a flow
    mapping on this line. A later word (`permission-statuses:`) is not a uses, and
    `uses:` inside a quoted scalar is not a key. Space or a tab before the colon is
    still the key (`uses :`), which YAML accepts.
    """
    body = line.rstrip("\r\n")
    i = _skip_ws(body, 0)
    if body.startswith("- ", i) or body.startswith("-\t", i):
        i += 2
        i = _skip_ws(body, i)
    if i < len(body) and body[i] == "{":
        return _flow_uses(body, i)
    end = _match_uses_key(body, i)
    if end is None:
        return None
    return _normalize(body[end:])


def ref_problem(ref: str) -> str | None:
    """None when `ref` is allowed. Otherwise a message that quotes the ref."""
    if ref.startswith("./") and "@" not in ref and ".." not in ref.split("/"):
        return None
    shown = ref if ref else "(empty)"
    if "@" not in ref or _SHA_ONLY.fullmatch(ref.rsplit("@", 1)[1]) is None:
        return f"{shown} is not a full commit SHA"
    return None


def file_problems(text: str) -> list[tuple[int, str]]:
    if text.startswith("\ufeff"):
        text = text[1:]
    found: list[tuple[int, str]] = []
    for lineno, line in enumerate(text.splitlines(), 1):
        ref = parse_uses(line)
        if ref is None:
            continue
        problem = ref_problem(ref)
        if problem is not None:
            found.append((lineno, problem))
    return found


def uses_count(text: str) -> int:
    if text.startswith("\ufeff"):
        text = text[1:]
    return sum(1 for line in text.splitlines() if parse_uses(line) is not None)


def yaml_files(path: Path) -> list[Path]:
    if path.is_symlink() and path.is_dir():
        raise Usage(f"refusing to follow a directory symlink: {path}")
    if not path.exists():
        raise Usage(f"no such path: {path}")
    if path.is_file():
        if path.suffix not in {".yml", ".yaml"}:
            raise Usage(f"not a workflow file: {path}")
        return [path]
    if not path.is_dir():
        raise Usage(f"no such path: {path}")
    found: list[Path] = []

    def walk_error(exc: OSError) -> None:
        target = exc.filename or path
        raise Usage(f"cannot scan {target}: {exc.strerror}") from exc

    for dirpath, dirnames, filenames in os.walk(path, followlinks=False, onerror=walk_error):
        for name in list(dirnames):
            child = Path(dirpath) / name
            if child.is_symlink():
                raise Usage(f"refusing to follow a directory symlink: {child}")
        for name in filenames:
            child = Path(dirpath) / name
            if child.suffix in {".yml", ".yaml"}:
                found.append(child)
    return sorted(found)


def default_github() -> Path:
    # bin/action_pins.py and .github/action_pins.py both sit one level under the repo.
    return Path(__file__).resolve().parents[1] / ".github"


def display(path: Path) -> str:
    try:
        return str(path.resolve().relative_to(Path.cwd().resolve()))
    except ValueError:
        return str(path)


def read_text(path: Path) -> str:
    try:
        data = path.read_bytes()
    except OSError as exc:
        raise Usage(f"cannot read {path}: {exc.strerror}") from exc
    if data.startswith(b"\xef\xbb\xbf"):
        data = data[3:]
    return data.decode("utf-8", errors="replace")


def scan(paths: list[Path]) -> tuple[int, int, int]:
    files = uses = bad = 0
    for path in paths:
        for file in yaml_files(path):
            files += 1
            text = read_text(file)
            uses += uses_count(text)
            for lineno, problem in file_problems(text):
                bad += 1
                print(f"{display(file)}:{lineno}: {problem}")
    print(f"action pins: {files} files, {uses} uses, {bad} violations")
    return files, uses, bad


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="action_pins",
        description="Fail when a GitHub Actions uses: ref is not a full commit SHA.",
    )
    parser.add_argument(
        "paths",
        nargs="*",
        help="workflow files or directories (default: this repo's .github)",
    )
    try:
        args = parser.parse_args(argv)
    except SystemExit as exc:
        code = exc.code
        return 2 if code is None else int(code)
    try:
        if args.paths:
            targets = [Path(p) for p in args.paths]
        else:
            root = default_github()
            if not root.is_dir():
                raise Usage(f"no workflow directory: {root}")
            targets = [root]
        _files, _uses, bad = scan(targets)
    except Usage as exc:
        print(f"action pins: {exc}", file=sys.stderr)
        return 2
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
