# RZ-28c: `.rzx` in Razy (namespaces, functions, layout, deploy, vocabularies)

Third of three parts (28a parser, 28b compiler, 28c integration). After this part a module can
ship `.rzx` components and screens. Everything is additive: `.tpl` pages render exactly as before.

## Added
- **Components from package.php.** Packages declare them with
  `'rzx' => ['namespace' => 'Kit', 'path' => 'rzx', 'vocabulary' => 'vocabulary.json', 'profile' => 'composition']`.
  - `path` defaults to `rzx`. Paths must stay inside the module.
  - `ModuleInfo::getRzx()` returns the declaration.
  - `rzx` joins `ModuleInfo::PACKAGE_KEYS`, so `validate` accepts it.
- **`.rzx` screens in `Controller::render()`.**
  - `render('admin/Members', $props)` renders `view/admin/Members.rzx` when that file exists; the
    `.rzx` extension may also be written out. The props are checked against the screen's
    `<props>`.
  - The screen goes into the dist layout like a `.tpl` page.
  - The new optional third argument `$layout` holds the layout's props. An `.rzx` layout
    (`'layout' => '@layout/site.rzx'`) gets the page as its default `<Slot />`.
  - A view that exists as both `.tpl` and `.rzx` is an error.
  - `<push>` and `<Anchor>` use the same `Razy\View\Anchors` as `{@push}` / `{@anchor}` (RZ-27),
    with the same nonce, inline-script check and dedupe.
- **`{@rzx name="Kit.Card" props=$card}`** renders a component inside a `.tpl` page. Its pushes go
  to the page's anchors. Slot fills are not available from a `.tpl`.
- **Razy's expression functions:**
  - `asset('js/x.js')` (or `asset('alias:css/a.css')`): `Assets::url()`, returned as a trusted
    `SafeUrl`, so it is accepted on `<script src>` even when assets live on a CDN (`url()` too);
  - `importmap()`: `{html(importmap())}`, once per response, with the nonce;
  - `nonce()`;
  - `url('path')`;
  - `nl2br(text)`: escaped text with `<br>`, as SafeHtml;
  - every template modifier except `raw`, `escape` and `json` (built in): `upper`, `lower`,
    `capitalize`, `trim`, `truncate`, `date`, `number`, `join`, `alphabet`, `strip_tags`,
    `addslashes`, `gettype`. These run the same plugin as `->name:arg`.
  - Apps add their own with `Controller::addRzxFunction('t', $fn)` in `__onInit`.
- **dist.php `'rzx'`:**
  - `mode`: `dev` (the default) compiles on first use and recompiles on change. `compiled` never
    compiles at request time.
  - `check_props`: `true`, `false` or `'dev'` (the default, on in dev mode).
  - `screen_profile`: default `screen`.
  - `layout_profile`: default `layout`.
  - The cache lives in `DATA_FOLDER/compiled/rzx/<dist>`.
  - In worker mode the dev freshness check runs again on every request.
- **Deploy.** `php Razy.phar compile <dist> --rzx` compiles every registered component and every
  module `view/` screen, and fails on any compile error. A normal `compile <dist>` runs the same
  step when the dist has `.rzx` files.
- **Vocabularies and profiles: data, not names in Razy.**
  - A package's vocabulary JSON declares:
    - element prefixes, plus elements and their attributes (types or enums);
    - attribute prefixes to check;
    - attributes that are never allowed;
    - hook names;
    - a slot-marker attribute, added to each direct child of a named fill;
    - attribute mappings such as `motion="rise"` → `data-…-motion="rise"`;
    - named profiles (allowed plain elements, forbidden attributes, hooks on/off, mappings on/off).
  - The compiler checks what is registered (RZX150–RZX157). With nothing registered, no
    vocabulary check runs.
  - A vocabulary change recompiles every component.
- **Runtime and cache API.** `Razy\Rzx\Host` is the per-dist setup: `Distributor::rzx()`,
  `Module::getRzxHost()`, `Host::current()`. `Runtime::renderFile()` and
  `CompileCache::loadFile()` render screens and layouts by path.

## Compile error codes added
| Code | Meaning |
|---|---|
| RZX150 | element not allowed by the file's profile |
| RZX151 | unknown element under a registered prefix |
| RZX152 | attribute the vocabulary forbids |
| RZX153 | attribute, hooks or mapping the profile forbids |
| RZX154 | unknown attribute on a known element |
| RZX155 | value outside the allowed list, or a string given to a boolean attribute |
| RZX156 | a mapped attribute given an expression |
| RZX157 | unknown hook name |

## Notes
- Standalone apps: `.rzx` needs a dist; `Controller::render()` keeps its `.tpl` behaviour there.
- Components are namespaced (`Kit.Card`); a bare `<Card>` has no namespace to resolve in.
