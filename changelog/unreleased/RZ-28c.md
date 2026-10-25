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
  - The `$layout` props also reach a `.tpl` layout, for `.tpl` pages and `.rzx` screens alike.
    `Template::renderPage()` gains an optional `$layoutParameters` argument (additive).
- `Template::abortRender()` (internal, used by `renderPage()` and the `.rzx` path) leaves a failed
  render. At the outermost level it discards the response's anchor state instead of finalizing it
  on empty HTML.
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
  - A vocabulary only adds restrictions; it can never relax a built-in check (`on*`, `style`,
    `srcdoc`, inline script, URL rules). The names it makes the compiler emit (a mapping's target,
    the slot marker) must be `data-*` attributes.
  - A vocabulary change recompiles every component.
- **Runtime and cache API.** `Razy\Rzx\Host` is the per-dist setup: `Distributor::rzx()`,
  `Module::getRzxHost()`, `Host::current()`. `Runtime::renderFile()` and
  `CompileCache::loadFile()` render screens and layouts by path.

## Security (template engine, every plugin)
- A function plugin's output is final: `Entity::parseText()` substitutes `{$…}` only in the template
  source. Each plugin's return value is held out of that pass and put back afterwards, so a value a
  plugin printed is never read as a parameter tag. Example: a component's text, a URL, a JSON
  `<script>` body, or `{@template}` output containing `{$csrf}` stays that text. This is the RZ-30
  class of bug, closed for all plugins, including `{@rzx}`.
- `{@if}`, `{@repeat}` and `{@each}` (its RZ-31 placeholders) keep returning template text for the caller's single substitution. They
  declare it with the new `TFunction::$templateOutput = true` (also on `TFunctionCustom`).

## Migration
- A custom plugin that relied on the caller substituting `{$…}` inside its *output* must set
  `protected bool $templateOutput = true;`. Its returned text is then expanded (nested function
  tags) and substituted once, like template code. Plugins that return finished markup need no change.
- **Only opt in for template source.** A `$templateOutput` plugin must return text taken from the
  template (its enclosed body, a branch), never a user value or anything built from one: that would
  be treated as template code, and a value `{$secret}` would print the secret.

## Hardening (review of #36)
- `asset()` refuses a path with `..`, `.` or control characters, and its result is trusted
  (`SafeUrl`) only when it resolves under `/webassets/<alias>/`.
- `url()` takes a path on this site only (absolute and protocol-relative are errors; `\` reads as
  `/`). Its result is trusted only when the site URL is a plain http(s) origin, or there is none and
  the result is a same-origin path.
- `Controller::render()` resolves `.rzx` views inside the module's `view/` folder (realpath). The
  `.tpl`/`.rzx` clash check also applies to explicit `.rzx` and `.tpl` suffixes.
- `Prop::string()` accepts strings only (not SafeHtml or SafeUrl objects).
- A profile named explicitly (dist.php `screen_profile` / `layout_profile`, package.php
  `rzx.profile`) must exist once a vocabulary loads; a typo is a ConfigurationException.
- Worker mode: `WorkerLifecycleManager::requestFinished()` clears `Host::current()`. Functions
  registered at boot with `addRzxFunction()` live for the whole worker, so they must not capture
  per-request state.

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
