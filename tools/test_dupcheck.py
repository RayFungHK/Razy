"""tools/dupcheck.py is the Razy copy: baseline beside the script, CI ref is origin/master.

The window algorithm is the workspace checker bin/dupcheck.py. These tests
cover the path this repo owns (src/ only, the baseline location, the update
hint, --base) and the edges a PR can hit.
Run: python3 -m unittest discover -s tools -p 'test_dupcheck.py'
"""
from __future__ import annotations

import importlib.util
import io
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / "dupcheck.py"
SPEC = importlib.util.spec_from_file_location("razy_dupcheck", SCRIPT)
dupcheck = importlib.util.module_from_spec(SPEC)
sys.modules["razy_dupcheck"] = dupcheck
SPEC.loader.exec_module(dupcheck)

# Long enough for the production window (70 matched tokens, 30-token seeds).
BODY = '''def summarise(rows, limit=10, unit="credits"):
    totals = {}
    for row in rows:
        if row.kind not in ("chat", "deck", "page") or row.amount <= 0:
            continue
        key = (row.owner, row.kind)
        totals[key] = totals.get(key, 0) + row.amount
    ranked = sorted(totals.items(), key=lambda kv: (-kv[1], kv[0]))
    lines = []
    for (owner, kind), amount in ranked[:limit]:
        lines.append({"owner": owner, "kind": kind, "amount": round(amount, 1), "unit": unit})
    if len(ranked) > limit:
        rest = sum(amount for _, amount in ranked[limit:])
        lines.append({"owner": "other", "kind": "mixed", "amount": round(rest, 1), "unit": unit})
    return lines
'''
COPY = BODY.replace("summarise", "report").replace("rows", "items").replace("row", "it")


def git(repo: Path, *args: str) -> None:
    subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True)


def repo_with(case: unittest.TestCase, files: dict[str, str]) -> Path:
    root = Path(tempfile.mkdtemp())
    case.addCleanup(shutil.rmtree, root, True)
    git(root, "init", "-q", "-b", "main")
    git(root, "config", "user.email", "t@example.invalid")
    git(root, "config", "user.name", "t")
    for rel, text in files.items():
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    git(root, "add", "-A")
    git(root, "commit", "-qm", "base")
    return root


def run(root: Path, *args: str, baseline: dict | None = None) -> tuple[int, str]:
    base = root / "tools" / "dupcheck-baseline.json"
    if baseline is not None:
        base.parent.mkdir(parents=True, exist_ok=True)
        base.write_text(json.dumps(baseline), encoding="utf-8")
    out, err = io.StringIO(), io.StringIO()
    with redirect_stdout(out), redirect_stderr(err):
        rc = dupcheck.main(["--repo", str(root), "--baseline", str(base), *args])
    return rc, out.getvalue() + err.getvalue()


class RazyCopyTest(unittest.TestCase):
    def test_an_allow_marker_inside_a_string_is_not_a_comment(self):
        import dupcheck_lex as lex

        src = lex.tokenize('s = "dupcheck: allow inside a string"  # real\n', "py")
        self.assertEqual(src.comments, [(1, "# real")])
        self.assertFalse(dupcheck.ALLOW_RE.search(src.comments[0][1]))

    def test_the_default_baseline_sits_beside_the_script(self):
        self.assertEqual(dupcheck.DEFAULT_BASELINE, SCRIPT.parent / "dupcheck-baseline.json")
        self.assertEqual(dupcheck.WS, SCRIPT.parent.parent)
        self.assertEqual(dupcheck.ROOTS, ("src/",))

    def test_the_copy_omits_the_product_id(self):
        # Split so this file does not itself contain the id the boundary counts.
        needle = "oa" + "ao"
        for name in ("dupcheck.py", "dupcheck_lex.py", "test_dupcheck.py"):
            text = (SCRIPT.parent / name).read_text(encoding="utf-8").lower()
            self.assertNotIn(needle, text, name)

    def test_only_src_is_scanned(self):
        root = repo_with(self, {
            "src/a.php": "<?php echo 1;\n",
            "src/b.js": "export const n = 1\n",
            "tools/c.py": BODY,
            "tests/d.php": BODY,
            "modules/e.php": BODY,
            "notes.txt": BODY,
            "src/widget.p": BODY,
        })
        rels = dupcheck.source_files(root, dupcheck.DEFAULT_IGNORE)
        self.assertEqual(rels, ["src/a.php", "src/b.js"])

    def test_a_clone_outside_src_does_not_fail(self):
        root = repo_with(self, {
            "tests/a.py": BODY,
            "tests/b.py": COPY,
            "modules/c.py": COPY,
            "src/ok.php": "<?php echo 1;\n",
        })
        rc, text = run(root, baseline={"files": {}})
        self.assertEqual(rc, 0, text)
        self.assertIn("1 files", text)
        self.assertNotIn("new clone", text)

    def test_seed_writes_counts_and_a_second_seed_refuses(self):
        root = repo_with(self, {"src/a.py": BODY, "src/b.py": COPY})
        rc, text = run(root, "--seed", baseline={})
        self.assertEqual(rc, 0, text)
        self.assertIn("seeded", text)
        data = json.loads((root / "tools" / "dupcheck-baseline.json").read_text(encoding="utf-8"))
        self.assertGreater(data["files"].get("src/a.py", 0), 0)
        self.assertIn("tools/dupcheck.py", data["$comment"])
        rc2, text2 = run(root, "--seed")
        self.assertEqual(rc2, 2, text2)
        self.assertIn("already has counts", text2)

    def test_a_new_clone_since_base_fails_and_an_allow_comment_does_not(self):
        root = repo_with(self, {"src/a.py": BODY})
        base = root / "tools" / "dupcheck-baseline.json"
        base.parent.mkdir(parents=True, exist_ok=True)
        base.write_text(json.dumps({"files": {}, "minTokens": 70}), encoding="utf-8")
        (root / "src" / "b.py").write_text(COPY, encoding="utf-8")
        git(root, "add", "-A")
        git(root, "commit", "-qm", "copy")
        rc, text = run(root, "--base", "HEAD~1")
        self.assertEqual(rc, 1, text)
        self.assertIn("new clone:", text)
        allowed = COPY.replace(
            "    return lines\n",
            "    return lines  # dupcheck: allow the report is the same sum\n",
        )
        (root / "src" / "b.py").write_text(allowed, encoding="utf-8")
        git(root, "add", "-A")
        git(root, "commit", "-qm", "allow")
        rc2, text2 = run(root, "--base", "HEAD~2")
        self.assertEqual(rc2, 0, text2)
        self.assertIn("allowed clone", text2)

    def test_an_unknown_base_ref_exits_2(self):
        root = repo_with(self, {"src/a.py": "x = 1\n"})
        rc, text = run(root, "--base", "origin/does-not-exist", baseline={"files": {}})
        self.assertEqual(rc, 2, text)
        self.assertIn("not found", text)
        self.assertNotIn("FAILED", text)

    def test_a_missing_file_names_the_update_command(self):
        root = repo_with(self, {"src/a.py": "x = 1\n"})
        rc, text = run(root, baseline={"files": {"src/gone.py": 1}})
        self.assertEqual(rc, 1, text)
        self.assertIn("python3 tools/dupcheck.py --update", text)
        self.assertNotIn("bin/ws", text)

    def test_zero_source_files_is_ok(self):
        root = repo_with(self, {
            "README.md": "hello\n",
            "tests/a.js": BODY,
            "modules/b.php": BODY,
        })
        rc, text = run(root, baseline={"files": {}})
        self.assertEqual(rc, 0, text)
        self.assertIn("0 files", text)

    def test_a_non_integer_min_tokens_is_refused_before_the_scan(self):
        root = repo_with(self, {"src/a.py": "x = 1\n", "src/b.py": "y = 2\n"})
        for raw in ("NaN", "1e309", ""):
            with (
                self.subTest(raw=raw),
                self.assertRaises(SystemExit),
                redirect_stderr(io.StringIO()),
            ):
                dupcheck.main([
                    "--repo", str(root),
                    "--baseline", str(root / "missing.json"),
                    "--min-tokens", raw,
                ])

    def test_min_tokens_zero_returns(self):
        root = repo_with(self, {"src/a.py": "x = 1\n", "src/b.py": "y = 2\n"})
        rc, text = run(root, "--min-tokens", "0", baseline={"files": {}})
        self.assertIn(rc, (0, 1), text)
        self.assertIn("dupcheck", text)
