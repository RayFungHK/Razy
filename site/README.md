# Razy Docs Site (`site/`)

Zero-build, zero-dependency documentation site. One self-contained
`index.html` (inline CSS + vanilla JS, no CDN, no npm, no build step, no
external fonts). It renders the repo's Markdown in the browser with a
built-in ~190-line escape-first renderer — no third-party libraries.

> **This site supersedes the legacy `documentation/` HTML tree.**
> Per the Documentation Map in `readme.md`, `documentation/` is the old
> static export and is deprecated; `site/` is the shell, content = `manual/`.

## Run it

The page fetches Markdown **from above `site/`**, so the server must expose
the whole repo root:

```bash
cd /path/to/Razy            # repo root, NOT site/
php -S 127.0.0.1:8080
# open http://127.0.0.1:8080/site/
```

Any static server serving the repo root works (`python -m http.server`,
Caddy `file_server`, nginx root, …). Opening `site/index.html` directly
via `file://` shows a clear instruction card instead of crashing —
browsers block `fetch()` on `file://`.

## What it renders

Hash routes (`#/<route>`) over a data-driven nav (see `NAV` below):

| Section | Routes |
|---|---|
| Overview | `#/readme` |
| Manual | `#/manual/README`, `#/manual/01-getting-started` … `#/manual/07-security-guide` |
| AI Guardrails | `#/agents`, `#/rules` |
| Demos | `#/demos/README`, `#/demos/anti-patterns` |
| Meta | `#/self-audit` (RAZY-ANALYSIS-REPORT.md), `#/changelog` |

Features: client-side full-text search (lazy prefetches all docs in the
background, then matches body text; jumps and highlights the first hit in
the doc), dark/light theme persisted in `localStorage`, relative `.md`
links rewritten to hash routes, `/` focuses search, responsive sidebar
(<900px hamburger), print-friendly CSS.

**Missing files never crash the site**: a 404 renders a dashed
“content pending” card (the manual/demos are authored in parallel — the
nav stays wired while those files land).

## Extension points

* **The nav is ONE array at the top of `index.html`: `const NAV = [...]`** —
  `{ route, title, file }` per entry, grouped by `section`. Add/remove
  entries there; nothing else references the document list. If the
  manual's final filenames differ from the placeholders here
  (`02-architecture`, `06-templates`, …), just edit `file:`/`route:` to
  match — unknown routes fall back to the README gracefully.
* **Markdown renderer**: `makeMarkdownRenderer()` — supports ATX h1–h4
  (with anchor ids), fenced code with language chips, inline code, bold/
  italic, links, nested lists, tables, blockquotes, `hr`, hard breaks.
  It escapes HTML *first*, so raw HTML in Markdown cannot inject script;
  non-`http(s)`/`mailto` link schemes are blocked. Extend inside that
  function only.
* **Theme tokens**: CSS custom properties under `:root[data-theme=…]`.
