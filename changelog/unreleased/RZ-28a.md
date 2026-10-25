# RZ-28a: `.rzx` component parser (lexer, AST, expression grammar)

First of three parts of the `.rzx` server-component compiler (28a parser, 28b compiler,
28c runtime integration). This part only parses. Nothing renders `.rzx` yet and no existing
API changes.

## Added
- `Razy\Rzx\Parser` turns a `.rzx` file into a `Razy\Rzx\Node\DocumentNode`. It is a
  hand-written recursive-descent parser in PHP, with no ext-dom, no `eval` and no `include`.
  A file has:
  - an optional raw `<props>` block first. It is kept as text here; 28b checks and evaluates it.
  - exactly one root element or component.
  - zero or more `<push to>` blocks after the root, each optionally inside `<if>`.
- **Markup (JSX subset):**
  - lowercase elements (HTML void elements may omit `/>`) and capitalised components with
    dotted namespaces (`<Pkg.Name>`).
  - literal, boolean and `{expression}` attributes.
  - text with JSX whitespace rules, `{expr}` output, and `{/* comment */}`.
  - control flow: `<if test>` / `<elseif test>` / `<else>`, and
    `<for each as key index>` with `<empty>`.
  - `<Slot name accepts max>`, `<Fill slot>`, `<push to key order>` and `<Anchor name>`.
    Anchor names follow `Razy\View\Anchors` (RZ-27); pushing to `content` is an error.
- **Expressions** (`Razy\Rzx\Expr\*`) are a fixed grammar, not PHP:
  - paths `a.b`, `a[0]`;
  - string, number, `true`/`false`/`null`, list `[…]` and map `{k: v, 'k': v, [expr]: v}` literals;
  - operators `! - && || ?? == != < <= > >= ~ + -` and `? :`;
  - calls to bare function names only, checked against a function list when one is given.
- `Razy\Rzx\CompileError` (a `TemplateException`) carries a stable code, `file:line:col`
  (columns count UTF-8 characters) and a three-line code frame.

## Parse error codes
| Code | Meaning |
|---|---|
| RZX000 | file cannot be read |
| RZX001 | unterminated tag |
| RZX002 | bare `<` in text (write `&lt;`) |
| RZX003 | bare `}` in text |
| RZX004 | unterminated attribute value |
| RZX005 | unterminated `{/* */}` comment |
| RZX006 | HTML comment or `<!…>` declaration |
| RZX007 | malformed closing tag |
| RZX008 | `<props>` never closed |
| RZX009 | `/` without `>` |
| RZX010 | bad attribute syntax |
| RZX011 | spread or nameless `{…}` attribute |
| RZX012 | mismatched closing tag (names where the open tag is) |
| RZX013 | duplicate attribute |
| RZX014 | element never closed |
| RZX015 | closing tag on a void element |
| RZX016 | closing tag with nothing open |
| RZX017 | invalid tag or component name |
| RZX019 | nesting deeper than 256 |
| RZX020 | `<props>` not first, or with attributes |
| RZX021 | text or an expression outside the root |
| RZX022 | the root is not an element or a component |
| RZX023 | a second root, or a non-`<push>` after the root |
| RZX024 | no root |
| RZX030 | unterminated expression |
| RZX031 | unexpected character |
| RZX032 | PHP spelling: `$x`, `->`, `::`, backtick, `;` |
| RZX033 | unsupported operator: `=`, `===`, `!==`, `&`, `\|`, `*`, `/`, `%` |
| RZX034 | unterminated string |
| RZX035 | unknown string escape |
| RZX040 | empty expression |
| RZX041 | unexpected token |
| RZX042 | chained comparison |
| RZX043 | bad property name after `.` |
| RZX044 | call on something other than a function name |
| RZX045 | unknown function |
| RZX046 | bad map key |
| RZX047 | expression nested deeper than 64 |
| RZX050 | bad or missing attribute on a control tag |
| RZX051 | unknown attribute on a control tag |
| RZX052 | `<elseif>`/`<else>` not after `<if>` |
| RZX053 | misplaced or second `<empty>` |

## Notes
- Quoted attribute values and text keep entities as written (`&amp;` stays `&amp;`). How
  they are escaped on output is decided by the compiler (28b).
