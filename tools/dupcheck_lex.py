"""SKL-P: the lexer behind tools/dupcheck.py.

Razy copy of oaao-platform bin/dupcheck_lex.py (TODO 13w12c).
Normalised tokens per language, and the comments kept apart.

Identifiers become ID (keywords stay), strings STR, numbers NUM; comments are dropped from the token stream and
returned separately, so an allow marker counts only as a real comment, never inside a string.

  py    comments, all string prefixes and triple quotes
  js    // and /* */ comments; a template literal is one STR plus the code inside each ${ } as code
  php   only code between <?php / <?= and ?> (the rest is markup); // and # comments end at ?>; heredoc and nowdoc
        are one STR; #[Attribute] is code
  sh    # comments (not $# or ${#x}); heredocs (<<EOF, <<-EOF, <<'EOF') are one STR

Import / use / require lines (and the rest of a multi-line import) are blanked before tokenizing: every file
starts with similar ones, and they are not code anyone could share.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path

LANG = {".py": "py", ".php": "php", ".js": "js", ".mjs": "js", ".cjs": "js", ".ts": "js", ".sh": "sh"}
KEYWORDS = set("""
and as assert async await break case catch class const continue def default del delete do elif else
except export extends finally for from function global if import in instanceof is lambda let new nonlocal
not null or pass raise return static switch this throw try typeof var void while with yield echo fi then
done esac local true false True False None self public private protected namespace use""".split())

_N, _P = r"(?P<n>\b\d[\w.]*)", r"(?P<p>[^\s\w])"
_ID_JS, _ID = r"(?P<i>[A-Za-z_$][\w$]*)", r"(?P<i>[A-Za-z_]\w*)"
TOKEN_RES = {
    "js": re.compile(r"""(?P<c>//[^\n]*|/\*.*?\*/)|(?P<s>'(?:\\.|[^'\\\n])*'|"(?:\\.|[^"\\\n])*")|(?P<t>`)|"""
                     + "|".join((_N, _ID_JS, _P)), re.S),
    "php": re.compile(r"""(?P<c>//(?:(?!\?>)[^\n])*|\#(?!\[)(?:(?!\?>)[^\n])*|/\*.*?\*/)|(?P<x>\?>)"""
                      r"""|(?P<h><<<[ \t]*(?P<hq>['"]?)(?P<hid>[A-Za-z_]\w*)(?P=hq)\n.*?\n[ \t]*(?P=hid)\b)"""
                      r"""|(?P<s>'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*")|""" + "|".join((_N, _ID_JS, _P)), re.S),
    "py": re.compile(r"""(?P<c>\#[^\n]*)|(?P<s>[rbfuRBFU]{0,2}(?:'''.*?'''|\"\"\".*?\"\"\"|'(?:\\.|[^'\\\n])*'|"""
                     r""""(?:\\.|[^"\\\n])*"))|""" + "|".join((_N, _ID, _P)), re.S),
    "sh": re.compile(r"""(?P<c>(?<![\w$\{])\#[^\n]*)"""
                     r"""|(?P<h><<-?[ \t]*(?P<hq>['"]?)(?P<hid>[A-Za-z_]\w*)(?P=hq)[^\n]*\n.*?\n[ \t]*(?P=hid)[ \t]*(?=\n|\Z))"""
                     r"""|(?P<s>'[^']*'|"(?:\\.|[^"\\])*")|""" + "|".join((_N, _ID, _P)), re.S),
}
IMPORT_RES = {
    "py": re.compile(r"^\s*(import\s|from\s+\S+\s+import\b)|importlib\.util\.|\bloader\.exec_module\(|"
                     r"^\s*sys\.modules\[[^\]]+\]\s*=\s*\w+\s*$"),
    "js": re.compile(r"^\s*(import(?!\s*\()\b|export\s+(\*|\{)[^;]*?\bfrom\b|export\s*\{\s*$|(const|let|var)\s+[\w{},\s]+=\s*require\()"),
    "php": re.compile(r"^\s*(use\s+[\\\w]|require(_once)?\b|include(_once)?\b|namespace\s)"),
    "sh": re.compile(r"^\s*(source|\.)\s+\S"),
}


@dataclass
class Source:
    tokens: list[tuple[str, int]]      # (normalised kind, line)
    comments: list[tuple[int, str]]    # (line, text): allow markers count only here


_SQ, _DQ = r"'(?:\\.|[^'\\])*'", r'"(?:\\.|[^"\\])*"'
LINE_NOISE = {  # strings and comments on one line, removed before brackets are counted
    "py": re.compile(r"\#.*$|" + _SQ + "|" + _DQ),
    "js": re.compile(r"//.*$|/\*.*?\*/|" + _SQ + "|" + _DQ + r"|`(?:\\.|[^`\\])*`"),
    "php": re.compile(r"//.*$|\#.*$|/\*.*?\*/|" + _SQ + "|" + _DQ),
    "sh": re.compile(r"(?<![\w$\{])\#.*$|'[^']*'|" + _DQ),
}


def mask_imports(text: str, lang: str) -> str:
    """Blank import / use / require lines, and the continuation lines of a multi-line one, keeping line numbers.
    Brackets are counted in code only, so a `(` in a comment or string never extends the mask."""
    rx, noise, out, depth = IMPORT_RES[lang], LINE_NOISE[lang], [], 0
    for line in text.split("\n"):
        if depth > 0 or rx.search(line):
            code = noise.sub("", line)
            depth = max(0, depth + code.count("(") + code.count("{") + code.count("[")
                        - code.count(")") - code.count("}") - code.count("]"))
            out.append("")
        else:
            out.append(line)
    return "\n".join(out)


def _php_open(text: str, pos: int) -> int:
    """Index just after the next <?php / <?= / <? opening tag, or len(text)."""
    nxt = text.find("<?", pos)
    if nxt < 0:
        return len(text)
    return nxt + (5 if text.startswith("<?php", nxt) else 3 if text.startswith("<?=", nxt) else 2)


def tokenize(text: str, lang: str, line: int = 1) -> Source:
    """Normalised tokens and comments. PHP outside <?php ... ?> is markup and is skipped."""
    src = Source([], [])
    rx, pos, n, inside = TOKEN_RES[lang], 0, len(text), lang != "php"
    while pos < n:
        if not inside:
            stop = _php_open(text, pos)
            line, pos, inside = line + text.count("\n", pos, stop), stop, True
            continue
        m = rx.search(text, pos)
        if not m:
            break
        line += text.count("\n", pos, m.start())
        kind = m.lastgroup
        if kind == "t":
            pos = template_literal(text, m.end(), line, src)
            line += text.count("\n", m.start(), pos)
            continue
        if kind == "x":
            inside = False
        else:
            emit(src, kind, m.group(), line)
        line += text.count("\n", m.start(), m.end())
        pos = m.end()
    return src


def emit(src: Source, kind: str, value: str, line: int) -> None:
    if kind == "c":
        src.comments.append((line, value))
    elif kind == "i":
        src.tokens.append((value if value in KEYWORDS else "ID", line))
    elif kind in ("s", "h"):
        src.tokens.append(("STR", line))
    elif kind == "n":
        src.tokens.append(("NUM", line))
    else:
        src.tokens.append((value, line))


def template_literal(text: str, pos: int, line: int, src: Source) -> int:
    """A JS template: one STR token, and the code inside each ${ } tokenized as code. Returns the end index."""
    src.tokens.append(("STR", line))
    i = pos
    while i < len(text):
        if text[i] == "\\":
            i += 2
            continue
        if text[i] == "`":
            return i + 1
        if text.startswith("${", i):
            i = template_expr(text, i + 2, line + text.count("\n", pos, i + 2), src)
            continue
        i += 1
    return i


def template_expr(text: str, pos: int, line: int, src: Source) -> int:
    """The code of one ${ }, read with the JS lexer (a } inside a string or a nested template does not end it).
    Returns the index after the closing }."""
    rx, depth = TOKEN_RES["js"], 1
    while pos < len(text):
        m = rx.search(text, pos)
        if not m:
            return len(text)
        line += text.count("\n", pos, m.start())
        kind, value = m.lastgroup, m.group()
        if kind == "t":
            pos = template_literal(text, m.end(), line, src)
            line += text.count("\n", m.start(), pos)
            continue
        if kind == "p" and value in "{}":
            depth += 1 if value == "{" else -1
            if depth == 0:
                return m.end()
        emit(src, kind, value, line)
        line += text.count("\n", m.start(), m.end())
        pos = m.end()
    return pos


def load(text: str, rel: str) -> Source:
    lang = LANG[Path(rel).suffix]
    return tokenize(mask_imports(text, lang), lang)
