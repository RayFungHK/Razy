#!/usr/bin/env python3
"""Fail when a tracked file names the consumer, outside an explicit allow list.

The allow list is one repo-relative path per line. A path is exact: a directory
does not cover the files under it, and a glob character is rejected. Blank lines
and lines whose first non-space character is # are comments. The needle is split
so this file can be copied into a framework tree.

bin/boundary.py loads this module for the three framework checkouts. Each
framework repo commits the same bytes at .github/product_id.py and runs it in
CI, because that CI does not check out the workspace. Keep the copies identical.
The framework allow list is .github/consumer-id-allow.txt. The workspace copies
are bin/consumer-id-allow/<repo>.txt and must match those bytes.

The allow file is always scanned when it sits inside the checkout. Listing its
own path does not exempt it.

Usage: python3 product_id.py [--root DIR] [--allow FILE]
       Exit 0 when every tracked file outside the list is clean.
       Exit 1 and print the paths when one is not.
       Exit 2 when the allow list or the git listing cannot be read.
"""
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path

# Split so a byte-identical copy of this file stays free of the contiguous name.
NEEDLE = ("oa" + "ao").encode("ascii")
_GLOB = set("*?[]\\")


class Usage(Exception):
    """The allow list or the git listing cannot be read."""


def default_root() -> Path:
    """Framework copies live in <repo>/.github/. The workspace copy must be given --root."""
    here = Path(__file__).resolve()
    if here.parent.name == ".github":
        return here.parents[1]
    raise Usage("pass --root DIR (a framework checkout)")


def _bad_path(line: str) -> bool:
    if "\x00" in line or any(ch.isspace() for ch in line):
        return True
    if any(ch in line for ch in _GLOB):
        return True
    return any(part in ("", ".", "..") for part in line.split("/"))


def parse_allow(text: str, name: str) -> set[str]:
    """Exact repo-relative paths. A bad line names the file and the line number."""
    found: set[str] = set()
    for n, raw in enumerate(text.splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if _bad_path(line):
            raise Usage(f"{name}:{n}: not an exact repo-relative path: {line}")
        found.add(line)
    return found


def load_allow(path: Path) -> set[str]:
    if not path.is_file():
        raise Usage(f"allow list missing: {path}")
    try:
        text = path.read_text(encoding="utf-8")
    except (OSError, UnicodeDecodeError) as exc:
        raise Usage(f"allow list unreadable: {path}: {exc}") from exc
    return parse_allow(text, str(path))


def _allow_rel(repo: Path, allow_file: Path) -> str | None:
    """Path of the allow file inside the checkout, or None when it lives outside."""
    try:
        return allow_file.resolve().relative_to(repo.resolve()).as_posix()
    except ValueError:
        return None


def tracked(repo: Path) -> list[str]:
    try:
        out = subprocess.run(
            ["git", "-C", str(repo), "ls-files", "-z"],
            check=True,
            capture_output=True,
        )
    except FileNotFoundError as exc:
        raise Usage("git is not on PATH") from exc
    except subprocess.CalledProcessError as exc:
        err = exc.stderr.decode("utf-8", "replace").strip()
        raise Usage(f"git ls-files failed in {repo}: {err}") from exc
    return [raw.decode("utf-8", "surrogateescape") for raw in out.stdout.split(b"\0") if raw]


def hits(repo: Path, allow_file: Path) -> dict[str, int]:
    """Counts of the consumer name in tracked files that are not on the allow list."""
    allowed = load_allow(allow_file)
    own = _allow_rel(repo, allow_file)
    found: dict[str, int] = {}
    for rel in tracked(repo):
        if rel != own and rel in allowed:
            continue
        path = repo / rel
        try:
            data = path.read_bytes()
        except (FileNotFoundError, IsADirectoryError):
            continue
        except OSError as exc:
            raise Usage(f"cannot read {rel}: {exc.strerror}") from exc
        n = data.lower().count(NEEDLE)
        if n:
            found[rel] = n
    return found


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", type=Path, default=None)
    ap.add_argument("--allow", type=Path, default=None)
    args = ap.parse_args(argv)
    try:
        root = args.root if args.root is not None else default_root()
        allow = args.allow if args.allow is not None else root / ".github" / "consumer-id-allow.txt"
        found = hits(root, allow)
    except Usage as exc:
        print(str(exc), file=sys.stderr)
        return 2
    if found:
        print(
            f"tracked files name the consumer outside the allow list ({allow}):",
            file=sys.stderr,
        )
        for rel, n in sorted(found.items()):
            print(f"  {rel} ({n})", file=sys.stderr)
        return 1
    print("consumer id ok")
    return 0


if __name__ == "__main__":
    sys.exit(main())
