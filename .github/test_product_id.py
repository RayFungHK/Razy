"""WS-34: a tracked file that names the consumer fails unless its path is allow-listed.

The same bytes run from tests/ws/ (the workspace copy) and from .github/ in each
framework repo (that repo's CI cannot check out the workspace).
"""
from __future__ import annotations

import importlib.util
import subprocess
import tempfile
import unittest
from pathlib import Path

NEEDLE = "oa" + "ao"


def load():
    here = Path(__file__).resolve()
    sibling = here.with_name("product_id.py")
    path = sibling if sibling.is_file() else here.parents[2] / "bin" / "product_id.py"
    spec = importlib.util.spec_from_file_location("product_id_check", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


product_id = load()


def git_repo(root: Path, files: dict[str, str]) -> None:
    for rel, text in files.items():
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    subprocess.run(["git", "init", "-q", str(root)], check=True)
    subprocess.run(["git", "-C", str(root), "add", "-A"], check=True)


class ProductIdTest(unittest.TestCase):
    def test_checker_and_allow_lists_omit_the_needle(self) -> None:
        self.assertNotIn(NEEDLE, Path(product_id.__file__).read_text(encoding="utf-8").lower())
        if Path(__file__).parent.name == ".github":
            text = Path(__file__).with_name("consumer-id-allow.txt").read_text(encoding="utf-8")
            self.assertNotIn(NEEDLE, text.lower())
            product_id.parse_allow(text, "consumer-id-allow.txt")
            return
        allow_dir = Path(product_id.__file__).resolve().parent / "consumer-id-allow"
        paths = sorted(allow_dir.glob("*.txt"))
        self.assertEqual([p.name for p in paths], ["Razy.txt", "razy-razyui.txt", "razyui-dev.txt"])
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertNotIn(NEEDLE, text.lower(), path.name)
            product_id.parse_allow(text, path.name)

    def test_markdown_vendor_and_odd_extensions_count_until_the_exact_path_is_listed(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            allow = root / "allow.txt"
            allow.write_text("# none\n\n", encoding="utf-8")
            git_repo(root, {
                "docs/notes.md": NEEDLE + " in prose\n",
                "vendor/lib.php": "x" + NEEDLE.upper() + "y\n",
                "notes.txt": "Oa" + "Ao\n",
                "src/kept.js": NEEDLE + "\n",
                "src/split.py": 'needle = "oa" + "ao"\n',
            })
            (root / "sneak.md").write_text(NEEDLE + "\n", encoding="utf-8")
            found = product_id.hits(root, allow)
            self.assertEqual(found, {
                "docs/notes.md": 1,
                "vendor/lib.php": 1,
                "notes.txt": 1,
                "src/kept.js": 1,
            })
            allow.write_text("src/kept.js\n", encoding="utf-8")
            self.assertNotIn("src/kept.js", product_id.hits(root, allow))
            allow.write_text("docs\nvendor\n", encoding="utf-8")
            still = product_id.hits(root, allow)
            self.assertIn("docs/notes.md", still)
            self.assertIn("vendor/lib.php", still)
            allow.write_text("# none\n", encoding="utf-8")
            self.assertIn("docs/notes.md", product_id.hits(root, allow))

    def test_bytes_count_when_the_file_is_not_utf8_and_case_folds(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            allow = root / "allow.txt"
            allow.write_text("# none\n", encoding="utf-8")
            git_repo(root, {"a.txt": "clean\n"})
            blob = root / "blob.bin"
            raw = NEEDLE.upper().encode("ascii")
            blob.write_bytes(b"\xff" + raw + b"\xff" + NEEDLE.encode("ascii"))
            subprocess.run(["git", "-C", str(root), "add", "-A"], check=True)
            self.assertEqual(product_id.hits(root, allow), {"blob.bin": 2})

    def test_listing_the_allow_file_does_not_exempt_it(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            rel = ".github/consumer-id-allow.txt"
            path = root / rel
            path.parent.mkdir(parents=True)
            path.write_text(rel + "\n" + NEEDLE + "\n", encoding="utf-8")
            subprocess.run(["git", "init", "-q", str(root)], check=True)
            subprocess.run(["git", "-C", str(root), "add", "-A"], check=True)
            self.assertEqual(product_id.hits(root, path), {rel: 1})

    def test_bad_allow_lines_name_the_line_and_crlf_comments_are_ignored(self) -> None:
        self.assertEqual(product_id.parse_allow("notes.md\r\n# hi\r\n\n", "list.txt"), {"notes.md"})
        samples = [
            "../secret", "/etc/passwd", "docs/", "a//b", "./rel",
            "a\\b", "vendor/*", "has space", "a\x00b",
        ]
        for sample in samples:
            with self.subTest(sample=sample):
                with self.assertRaises(product_id.Usage) as caught:
                    product_id.parse_allow("ok.py\n" + sample + "\n", "list.txt")
                message = str(caught.exception)
                self.assertIn("list.txt:2:", message)
                self.assertIn(sample, message)

    def test_a_read_error_names_the_file(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            allow = root / "allow.txt"
            allow.write_text("# none\n", encoding="utf-8")
            git_repo(root, {"blocked.md": "clean\n"})
            real = Path.read_bytes

            def boom(self):
                if self.name == "blocked.md":
                    raise OSError(13, "Permission denied")
                return real(self)

            Path.read_bytes = boom
            try:
                with self.assertRaises(product_id.Usage) as caught:
                    product_id.hits(root, allow)
            finally:
                Path.read_bytes = real
            self.assertIn("blocked.md", str(caught.exception))
            self.assertIn("Permission denied", str(caught.exception))

    def test_main_exit_codes_and_it_does_not_write(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            allow = root / "allow.txt"
            allow.write_text("# none\n", encoding="utf-8")
            git_repo(root, {"clean.txt": "ok\n"})
            before = allow.read_bytes()
            self.assertEqual(product_id.main(["--root", str(root), "--allow", str(allow)]), 0)
            self.assertEqual(allow.read_bytes(), before)
            (root / "bad.md").write_text(NEEDLE + " " + NEEDLE + "\n", encoding="utf-8")
            subprocess.run(["git", "-C", str(root), "add", "bad.md"], check=True)
            self.assertEqual(product_id.main(["--root", str(root), "--allow", str(allow)]), 1)
            missing = product_id.main(["--root", str(root), "--allow", str(root / "nope.txt")])
            self.assertEqual(missing, 2)
        with self.assertRaises(SystemExit) as caught:
            product_id.main(["--no-such"])
        self.assertEqual(caught.exception.code, 2)

    def test_a_missing_git_repo_names_the_directory(self) -> None:
        with tempfile.TemporaryDirectory() as raw:
            root = Path(raw)
            allow = root / "allow.txt"
            allow.write_text("# none\n", encoding="utf-8")
            with self.assertRaises(product_id.Usage) as caught:
                product_id.hits(root, allow)
            self.assertIn(str(root), str(caught.exception))
            self.assertIn("git ls-files failed", str(caught.exception))

    def test_workspace_copy_requires_an_explicit_root(self) -> None:
        if Path(product_id.__file__).parent.name == ".github":
            return
        self.assertEqual(product_id.main([]), 2)

    def test_this_checkout_is_clean_when_the_script_sits_in_github(self) -> None:
        if Path(__file__).parent.name != ".github":
            return
        self.assertEqual(product_id.main([]), 0)

    def test_copies_match_when_this_is_the_workspace_suite(self) -> None:
        if Path(__file__).with_name("product_id.py").is_file():
            return
        root = Path(__file__).resolve().parents[2]
        canonical = (root / "bin" / "product_id.py").read_bytes()
        suite = Path(__file__).read_bytes()
        for name in ("Razy", "razyui-dev", "razy-razyui"):
            repo = root / name
            script = repo / ".github" / "product_id.py"
            if script.is_file():
                self.assertEqual(script.read_bytes(), canonical, name)
            test = repo / ".github" / "test_product_id.py"
            if test.is_file():
                self.assertEqual(test.read_bytes(), suite, name)
            allow = repo / ".github" / "consumer-id-allow.txt"
            if allow.is_file():
                want = root / "bin" / "consumer-id-allow" / f"{name}.txt"
                self.assertEqual(allow.read_bytes(), want.read_bytes(), name)


if __name__ == "__main__":
    unittest.main()
