# RZ-28b: `.rzx` compiler, runtime and build cache

Second of three parts (28a parser, 28b compiler, 28c Razy integration). After this part,
`.rzx` components compile to PHP and render. Wiring them into Razy comes in 28c: the
module and package namespaces, Razy's asset/i18n/modifier functions, `Controller::render()`
and `compile --rzx`. Additive only; no existing API changes.

## Added
- **Compiler.** `Razy\Rzx\Compiler` turns a parsed component into a PHP file that
  returns a `Razy\Rzx\Runtime\Compiled` (the render closure, the props schema and a
  line map).
  - No `eval`.
  - Every value from the source reaches PHP through `var_export()`, and names are
    validated identifiers.
- **Props.** `Razy\Rzx\Prop` is the props schema:
  - types: `string`, `int`, `float`, `bool`, `html`, `any`, `list`, `map`, `shape`, `enum`;
  - modifiers: `required`, `optional`, `default`, `min`, `max`.

  `Razy\Rzx\PropsReader` reads the `<props>` block by interpreting the literal subset
  itself (literals, arrays, `Prop::` chains, comments). PHP in the block is never run.
- **Compile-time checks:**
  - every name is a prop or a loop variable (RZX101);
  - a field read on a declared shape exists (RZX102);
  - component uses: unknown props, missing required props, and constant values of the
    wrong type, outside an enum or out of range (RZX110–RZX114);
  - fills: an unknown slot, children outside the slot's `accepts`, more than its `max`,
    a slot filled twice, misplaced `<Fill>` (RZX120–RZX126);
  - markup safety:
    - `<style>`, `style=""` (use `hooks={{…}}`), `on*` handlers, `srcdoc` and inline
      JavaScript are rejected;
    - `<script>` may only be `src` or a JSON/importmap holding one expression;
    - literal URLs with an unsafe scheme are rejected, after decoding entities
      (RZX130–RZX137);
  - a nested `<push>` (RZX140).
- **Escaping by context and by default**, in `Razy\Rzx\Runtime\Escape`:
  - text and attributes use `htmlspecialchars` with quotes;
  - a list or map in an attribute is JSON with `JSON_HEX_*`;
  - URL attributes allow only `http`, `https`, `mailto` and relative URLs; anything else
    becomes `#` with a warning. `srcset` / `imagesrcset` are parsed as HTML does and each
    candidate URL is checked;
  - every check on a literal attribute value runs on the value as a browser decodes it
    (numeric references with or without `;`, then named references);
  - `<base>` and pragma `<meta http-equiv … content>` take literals only (a named
    `<meta name content={…}>` is unaffected). A refresh must be exactly
    `<seconds>[;|, [url=]<url>]` with a safe URL; other pragmas are an allowlist (content-type,
    content-language, default-style, x-ua-compatible);
  - SVG animation elements take literal `attributeName`, `to`, `from`, `values` and `by`, and
    may only target presentational attributes on an allowlist (opacity, transform, fill,
    stroke-*, x, y, width, height, r, cx, cy, d, points, offset, stop-color, …);
  - JSON scripts use `JSON_HEX_TAG|AMP|APOS|QUOT`;
  - raw HTML only through `{html(x)}`, with `x` a `Razy\Rzx\SafeHtml`.
  - There is no other way to print unescaped.
- **Runtime.** `Razy\Rzx\Runtime\Runtime` provides one render's helpers:
  - `get`, which reads array keys and public properties only, never `__get` or a method;
  - truthiness (`'0'` is true, `[]` is false);
  - strict `==`, typed `<`;
  - component calls with defaults and a development props check at the boundary;
  - loop-key uniqueness in development;
  - `hooks` values limited to plain CSS tokens;
  - render errors mapped to the `.rzx` file and line.
- **Slots and fills.** `Razy\Rzx\Runtime\SlotBag` holds fills as closures over the
  caller's scope. Conditional fills and loops of fills with computed slot names stay
  real control flow. Components can call components, and a component can render itself
  (trees).
- **Anchors.** `<push>` and `<Anchor>` go through `Razy\Rzx\Runtime\AnchorSink`
  (`push(anchor, html, key, order)` and `marker(name)`). 28c binds it to
  `Razy\View\Anchors` (RZ-27).
- **Build cache.** `Razy\Rzx\CompileCache` keeps one file per component and hash, plus
  `index.php` (schema `rzx-1`).
  - The hash is sha256 of the compiler version, the source sha256, the function-registry
    signature and the dependency hashes, so editing a component recompiles its users.
  - Writes are atomic: a temp file, then rename and `opcache_invalidate`.
  - Freshness is decided by content (sha256 of each source), never by mtime: a deploy that
    restores an old mtime on new content is still seen. `dev` mode recompiles; `compiled` mode
    also verifies every dependency.
  - The index is updated under an exclusive lock (re-read, merge, write), so concurrent builds
    keep each other's entries. The directory is 0755 and files are 0644, whatever the umask.
    Only `<Name>.<hash>.php` files inside the cache directory are ever included.
  - `compiled` mode never compiles. A missing or stale component is an error that
    names `php Razy.phar compile --rzx`.
  - `build()` compiles a list of components for deploy and throws on any compile error.
- **Lookup and functions.** `Razy\Rzx\DirectoryResolver` maps namespaces to directories
  (`'' => app/rzx`, `'Kit' => …`). `Razy\Rzx\FunctionRegistry` holds the expression
  functions: `count`, `html` and `json` are built in, and the rest are registered by
  the host.

## Error codes (compile)
| Code | Meaning |
|---|---|
| RZX060–064 | `<props>` is not `return [ … ];` / bad name or value / not a literal or `Prop::` chain / unknown `Prop::` type or modifier / invalid arguments |
| RZX100 | unsupported construct |
| RZX101 | unknown name |
| RZX102 | unknown shape field |
| RZX103 | wrong built-in arity |
| RZX104 | loop variable shadows a name |
| RZX110 | unknown component |
| RZX111 | unknown prop |
| RZX112 | missing required prop |
| RZX113 | invalid constant prop value |
| RZX114 | component cycle |
| RZX120 | unknown slot, or no default slot |
| RZX121 | child not accepted |
| RZX122 | over `max` |
| RZX123 | slot filled twice |
| RZX124 | misplaced `<Fill>` |
| RZX125 | `<for>` into a slot with `max` |
| RZX126 | slot declared twice |
| RZX130 | `<style>` |
| RZX131 | `style` attribute |
| RZX132 | inline event handler |
| RZX133 | inline or misused `<script>` |
| RZX134 | unsafe literal URL |
| RZX135 | `srcdoc` |
| RZX136 | bad `hooks` |
| RZX137 | `html()` of a non-SafeHtml |
| RZX138 | SVG animation (`<animate>`, `<set>`, …) with a dynamic target or value, or a target outside the presentational allowlist |
| RZX139 | `<base>` attribute, `<meta http-equiv>`, or a pragma `<meta>`'s `content` given an expression; a pragma outside the allowlist |
| RZX140 | nested `<push>` |

## Notes
- Not in this part:
  - the vocabulary file and the screen/composition profiles (§2.6);
  - `motion=` mapping and the slot-marking attribute on slot children.

  These are UI-kit conventions. They come with the integration package, as vocabulary
  data rather than names hard-coded in Razy.
