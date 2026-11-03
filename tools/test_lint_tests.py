"""SKL-V: lint_tests.py rejects a TODO or PR id in a test file name, and ratchets files per module
and direct steps per workflow. Run: python3 -m unittest discover -s tools -p 'test_lint_tests.py'
"""
from __future__ import annotations

import atexit
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

SCRIPT = Path(__file__).resolve().parent / "lint_tests.py"
SPEC = importlib.util.spec_from_file_location("lint_tests_copy", SCRIPT)
lint_tests = importlib.util.module_from_spec(SPEC)
sys.modules["lint_tests_copy"] = lint_tests
SPEC.loader.exec_module(lint_tests)


def run(repo: Path, *args: str) -> None:
    subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True)


def make_repo(files: dict[str, str]) -> Path:
    # dupcheck: allow the temp-repo harness is the one in test_dupcheck.py
    repo = Path(tempfile.mkdtemp())
    atexit.register(shutil.rmtree, repo, True)
    run(repo, "init", "-q", "-b", "main")
    run(repo, "config", "user.email", "t@example.invalid")
    run(repo, "config", "user.name", "t")
    write(repo, files)
    run(repo, "add", "-A")
    run(repo, "commit", "-qm", "base", "--allow-empty")
    return repo


def write(repo: Path, files: dict[str, str]) -> None:
    for rel, text in files.items():
        (repo / rel).parent.mkdir(parents=True, exist_ok=True)
        (repo / rel).write_text(text, encoding="utf-8")


def scan(repo: Path, *args: str, baseline: dict | None = None) -> tuple[int, str]:
    # dupcheck: allow capture of main() is the same shape as test_dupcheck.scan
    base = repo / "baseline.json"
    if baseline is not None:
        base.write_text(json.dumps(baseline), encoding="utf-8")
    out, err = io.StringIO(), io.StringIO()
    with redirect_stdout(out), redirect_stderr(err):  # dupcheck: allow same stdout capture as test_dupcheck.scan
        rc = lint_tests.main(["--repo", str(repo), "--baseline", str(base), *args])
    return rc, out.getvalue() + err.getvalue()


WF = """name: ci
on: push
jobs:
  app:
    runs-on: ubuntu-latest
    services:
      db:
        image: postgres
        ports:
          - 5434:5432
    steps:
      - uses: actions/checkout@v0
      - name: unit
        run: python3 -m unittest
"""

WF_FLOW = "jobs:\n  a:\n    steps: [{uses: actions/checkout@v0}, {run: python3 -m unittest}]\n"
WF_FLOW_MULTI = (
    "jobs:\n  a:\n    steps: [\n"
    "      {uses: actions/checkout@v0},\n"
    "      {run: python3 -m unittest}\n"
    "    ]\n"
)
WF_FLOW_QUOTED = (
    "jobs:\n  a:\n    steps: [\n"
    "      {run: \"echo ]\"},\n"
    "      {run: \"test\"}\n"
    "    ]\n"
)
WF_FLOW_COMMENT = (
    "jobs:\n  a:\n    steps: [\n"
    "      {run: echo}, # ] not a step\n"
    "      {run: test}\n"
    "    ]\n"
)


class LintTests(unittest.TestCase):
    def test_good_names_match_a_seeded_baseline(self) -> None:
        repo = make_repo({
            "tests/ws/test_send.py": "def test_send():\n    pass\n",
            "tests/web/composer-thread.test.mjs": "test('open', () => {})\n",
            ".github/workflows/ci.yml": WF,
        })
        rc, text = scan(repo, "--seed", baseline={})
        self.assertEqual(rc, 0, text)
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)
        self.assertIn("OK", text)

    def test_id_names_fail_and_a_dash_digit_suffix_fails(self) -> None:
        names = [
            "tests/web/composer-thread-cmp05.test.mjs",
            "tests/web/composersteer1.test.mjs",
            "tests/web/composerundo1.test.mjs",
            "tests/web/composertwoline1.test.mjs",
            "tests/web/composer-pills-mindscroll8.test.mjs",
            "tests/web/something-pr712.test.mjs",
            "tests/web/thing-13w30.test.mjs",
            "tests/web/bot-uxh40.test.mjs",
            "tests/web/pulse-pul3.test.mjs",
            "tests/web/chat-rui107.test.mjs",
            "tests/web/test_ctx_h6_topics.py",
            "tests/web/test_aud_p1_21_backup.py",
            "tests/web/composer-thread-2.test.mjs",
        ]
        for rel in names:
            repo = make_repo({rel: "test('x', () => {})\n", ".github/workflows/ci.yml": WF})
            rc, text = scan(repo, "--seed", baseline={})
            self.assertEqual(rc, 0, text)
            # A fresh baseline with the module recorded and the id name not allowed.
            data = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
            data["names"] = []
            rc, text = scan(repo, baseline=data)
            self.assertEqual(rc, 1, rel + " " + text)
            self.assertIn(rel, text)
            self.assertIn("TODO or PR id", text)

    def test_allowed_name_passes_and_update_never_adds_one(self) -> None:
        rel = "tests/web/composer-thread-cmp05.test.mjs"
        repo = make_repo({rel: "test('x', () => {})\n", "tests/web/composer-thread.test.mjs": "test('y', () => {})\n"})
        base = {"names": [rel], "modules": {"tests/web": 2}, "workflows": {}}
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 0, text)
        base["names"] = []
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["names"], [])
        rc, text = scan(repo)
        self.assertEqual(rc, 1, text)
        self.assertIn("cmp05", text)

    def test_update_drops_a_gone_id_name_and_lowers_a_count(self) -> None:
        rel = "tests/mod/thing-13w30.test.mjs"
        repo = make_repo({rel: "test('x', () => {})\n", "tests/mod/test_ok.py": "def test_ok():\n    pass\n"})
        base = {"names": [rel], "modules": {"tests/mod": 2}, "workflows": {}}
        (repo / rel).unlink()
        # Not staged: ls-files still lists it, and the count must still fall.
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["names"], [])
        self.assertEqual(saved["modules"]["tests/mod"], 1)
        run(repo, "add", "-A")  # dupcheck: allow this drop/update sequence mirrors the rise test below
        run(repo, "commit", "-qm", "drop")
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 1, text)
        self.assertIn("no longer matches", text)
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["names"], [])
        self.assertEqual(saved["modules"]["tests/mod"], 1)
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)

    def test_a_rise_fails_and_update_does_not_raise_it(self) -> None:
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n"})
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {}}
        write(repo, {"tests/ws/test_b.py": "def test_b():\n    pass\n"})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "add")
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["modules"]["tests/ws"], 1)
        rc, text = scan(repo)
        self.assertEqual(rc, 1, text)
        self.assertIn("2 test files (baseline 1)", text)

    def test_update_records_a_new_module_and_keeps_zero(self) -> None:
        repo = make_repo({"tests/old/test_a.py": "def test_a():\n    pass\n"})
        base = {"names": [], "modules": {"tests/old": 1}, "workflows": {}}
        write(repo, {"tests/new/test_b.py": "def test_b():\n    pass\n"})
        (repo / "tests/old/test_a.py").unlink()
        run(repo, "add", "-A")  # dupcheck: allow this move/update sequence mirrors the drop test above
        run(repo, "commit", "-qm", "move")
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 1, text)
        self.assertIn("tests/new", text)
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))  # dupcheck: allow the zero-key read is the same shape as the raise/update test below
        self.assertEqual(saved["modules"]["tests/new"], 1)
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)
        write(repo, {"tests/old/test_c.py": "def test_c():\n    pass\n"})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "back")
        rc, text = scan(repo)
        self.assertEqual(rc, 1, text)
        self.assertIn("tests/old: 1 test files (baseline 0)", text)

    def test_seed_refuses_a_non_empty_baseline(self) -> None:
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n"})
        rc, text = scan(repo, "--seed", baseline={"names": [], "modules": {"tests/ws": 1}, "workflows": {}})
        self.assertEqual(rc, 2, text)
        self.assertIn("never overwrites", text)

    def test_steps_count_direct_children_and_a_rise_fails(self) -> None:
        self.assertEqual(lint_tests.step_count(WF), 2)
        self.assertEqual(lint_tests.step_count(WF_FLOW), 2)
        self.assertEqual(lint_tests.step_count(WF_FLOW_MULTI), 2)
        flat = (
            "jobs:\n  a:\n    steps:\n"
            "    - uses: actions/checkout@v5\n"
            "    - run: make\n"
        )
        self.assertEqual(lint_tests.step_count(flat), 2)
        self.assertEqual(
            lint_tests.step_count(flat + "    name: next\n  b:\n    steps:\n      - run: one\n"),
            3,
        )
        self.assertEqual(lint_tests.flow_items("[]"), 0)
        multi = make_repo({".github/workflows/ci.yml": WF_FLOW_MULTI})
        rc, text = scan(multi, "--update", baseline={
            "names": [], "modules": {}, "workflows": {".github/workflows/ci.yml": 2},
        })
        self.assertEqual(rc, 0, text)
        saved = json.loads((multi / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["workflows"][".github/workflows/ci.yml"], 2)
        repo = make_repo({".github/workflows/ci.yml": WF})
        base = {"names": [], "modules": {}, "workflows": {".github/workflows/ci.yml": 2}}
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 0, text)
        raised = WF.replace("        run: python3 -m unittest\n",
                            "        run: python3 -m unittest\n      - name: extra\n        run: true\n")
        write(repo, {".github/workflows/ci.yml": raised})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "step")
        self.assertEqual(lint_tests.step_count(raised), 3)
        rc, text = scan(repo, "--update", baseline=base)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["workflows"][".github/workflows/ci.yml"], 2)
        rc, text = scan(repo)
        self.assertEqual(rc, 1, text)
        self.assertIn("3 steps (baseline 2)", text)

    def test_flow_skips_quoted_brackets_and_comments(self) -> None:
        samples = (
            WF_FLOW_QUOTED,
            WF_FLOW_COMMENT,
            'jobs:\n  a:\n    steps: [{run: "echo ]"}, {run: test}]\n',
            "jobs:\n  a:\n    steps: [{run: 'echo ]'}, {run: next}]\n",
            'jobs:\n  a:\n    steps: [{run: "echo \\" ]"}, {run: test}]\n',
            'jobs:\n  a:\n    steps: ["echo ]", "test"]\n',
            "jobs:\n  a:\n    steps: ['a, b', 'c']\n",
            "jobs:\n  a:\n    steps: [\n      \"echo ]\n      still\",\n      {run: test}\n    ]\n",
            "jobs:\n  a:\n    steps: [{run: echo#still}, {run: next}]\n",
            "jobs:\n  a:\n    steps: [{run: echo it's}, {run: next}]\n",
        )
        for sample in samples:
            self.assertEqual(lint_tests.step_count(sample), 2, sample)
        later = (
            "jobs:\n  a:\n    steps: [{run: echo it's}, {run: next}]\n"
            "  b:\n    steps:\n      - run: one\n"
        )
        self.assertEqual(lint_tests.step_count(later), 3)
        repo = make_repo({".github/workflows/ci.yml": WF_FLOW_QUOTED})
        base = {"names": [], "modules": {}, "workflows": {".github/workflows/ci.yml": 2}}
        rc, text = scan(repo, "--update", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["workflows"][".github/workflows/ci.yml"], 2)

    def test_hostile_names_stay_data(self) -> None:
        rel = "tests/ws/__proto__.test.mjs"
        repo = make_repo({rel: "test('x', () => {})\n", "tests/ws/constructor.test.mjs": "test('y', () => {})\n"})
        rc, text = scan(repo, "--seed", baseline={})
        self.assertEqual(rc, 0, text)
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)
        self.assertNotIn("Traceback", text)

    def test_checkout_matches_the_seeded_baseline(self) -> None:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            rc = lint_tests.main([])
        self.assertEqual(rc, 0, out.getvalue() + err.getvalue())
        self.assertIn("OK", out.getvalue())

    def test_family_list_and_real_tokens(self) -> None:
        self.assertIsNotNone(lint_tests.id_match("rz22b-chat-list.test.mjs"))
        self.assertIsNone(lint_tests.id_match("uxh22b-chat-list.test.mjs", families=[]))
        self.assertIsNotNone(lint_tests.id_match("uxh22b-chat-list.test.mjs", families=["uxh"]))
        self.assertEqual(lint_tests.id_match("l5-1-look.test.mjs", families=["l5"], tokens=set()), "family l5")
        self.assertIn("slug+digit", lint_tests.id_match("l5-1-look.test.mjs", families=[], tokens=set()))
        self.assertIsNotNone(lint_tests.id_match("test_57h10d_hybrid_s2.py", families=[]))
        self.assertIsNotNone(lint_tests.id_match("something-pr712.test.mjs", families=[]))
        bare = set()
        for tok in lint_tests.DEFAULT_TOKENS:
            self.assertIsNone(lint_tests.id_match(f"{tok}.test.mjs"), tok)
        for tok in ("v2", "s3", "h264", "x86", "oauth2", "utf8", "sha256"):
            self.assertIsNotNone(lint_tests.id_match(f"notes-{tok}.test.mjs", families=[], tokens=bare), tok)
        rel = "tests/web/uxh22b-chat-list.test.mjs"
        repo = make_repo({rel: "test('x', () => {})\n"})
        base = {"names": [], "idFamilies": [], "modules": {"tests/web": 1}, "workflows": {}}
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 0, text)
        base["idFamilies"] = ["uxh"]
        rc, text = scan(repo, baseline=base)
        self.assertEqual(rc, 1, text)
        self.assertIn("family uxh", text)
        files = {f"tests/web/{tok}.test.mjs": "test('x', () => {})\n" for tok in sorted(lint_tests.DEFAULT_TOKENS)}
        files[".github/workflows/ci.yml"] = WF
        repo = make_repo(files)
        rc, text = scan(repo, "--seed", baseline={})
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["names"], [])
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)

    def test_raise_with_a_reason(self) -> None:
        repo = make_repo({
            "tests/ws/test_a.py": "def test_a():\n    pass\n",
            ".github/workflows/ci.yml": WF,
        })
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {".github/workflows/ci.yml": 2}}
        write(repo, {"tests/ws/test_b.py": "def test_b():\n    pass\n"})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "add")
        rc, text = scan(repo, "--raise", "tests/ws", "--reason", "sub-contract split past the line limit", baseline=base)
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["modules"]["tests/ws"], 2)  # dupcheck: allow the raised-count read is the same shape as the update-after-raise test
        self.assertEqual(saved["raises"], [{
            "key": "tests/ws", "from": 1, "to": 2, "reason": "sub-contract split past the line limit",
        }])
        rc, text = scan(repo)
        self.assertEqual(rc, 0, text)
        raised = WF.replace("        run: python3 -m unittest\n",
                            "        run: python3 -m unittest\n      - name: extra\n        run: true\n")
        write(repo, {".github/workflows/ci.yml": raised})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "step")
        rc, text = scan(repo, "--raise", ".github/workflows/ci.yml", "--reason", "new toolchain step")
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["workflows"][".github/workflows/ci.yml"], 3)
        self.assertEqual(saved["raises"][-1]["key"], ".github/workflows/ci.yml")
        self.assertEqual(saved["raises"][-1]["from"], 2)
        self.assertEqual(saved["raises"][-1]["to"], 3)

    def test_raise_without_a_reason(self) -> None:
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n"})
        write(repo, {"tests/ws/test_b.py": "def test_b():\n    pass\n"})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "add")
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {}}
        rc, text = scan(repo, "--raise", "tests/ws", baseline=base)
        self.assertEqual(rc, 2, text)
        self.assertIn("--reason", text)
        rc, text = scan(repo, "--raise", "tests/ws", "--reason", "   ", baseline=base)
        self.assertEqual(rc, 2, text)

    def test_raise_unknown_key(self) -> None:
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n"})
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {}}  # dupcheck: allow the one-file repo is the same harness as the id-named raise refusal
        rc, text = scan(repo, "--raise", "tests/missing", "--reason", "no such module", baseline=base)
        self.assertEqual(rc, 2, text)
        self.assertIn("unknown key", text)

    def test_raise_refuses_an_id_named_file(self) -> None:
        rel = "tests/ws/thing-13w30.test.mjs"
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n", rel: "test('x', () => {})\n"})
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {}}  # dupcheck: allow the one-file repo is the same harness as the unknown-key raise
        rc, text = scan(repo, "--raise", "tests/ws", "--reason", "split", baseline=base)
        self.assertEqual(rc, 2, text)
        self.assertIn(rel, text)
        rc, text = scan(repo, "--raise", rel, "--reason", "split", baseline=base)
        self.assertEqual(rc, 2, text)
        self.assertIn("id-named file", text)

    def test_update_after_a_raise_does_not_raise_again(self) -> None:
        repo = make_repo({"tests/ws/test_a.py": "def test_a():\n    pass\n"})
        base = {"names": [], "modules": {"tests/ws": 1}, "workflows": {}, "raises": []}
        write(repo, {"tests/ws/test_b.py": "def test_b():\n    pass\n"})
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "add")
        rc, text = scan(repo, "--raise", "tests/ws", "--reason", "sub-contract split", baseline=base)
        self.assertEqual(rc, 0, text)
        write(repo, {"tests/ws/test_c.py": "def test_c():\n    pass\n"})  # dupcheck: allow the third-file add repeats the move test's return-to-zero setup
        run(repo, "add", "-A")
        run(repo, "commit", "-qm", "again")
        rc, text = scan(repo, "--update")
        self.assertEqual(rc, 0, text)
        saved = json.loads((repo / "baseline.json").read_text(encoding="utf-8"))
        self.assertEqual(saved["modules"]["tests/ws"], 2)  # dupcheck: allow the raised-count read is the same shape as the first raise test
        self.assertEqual(saved["raises"], [{
            "key": "tests/ws", "from": 1, "to": 2, "reason": "sub-contract split",
        }])
        rc, text = scan(repo)
        self.assertEqual(rc, 1, text)
        self.assertIn("3 test files (baseline 2)", text)

if __name__ == "__main__":
    unittest.main()
