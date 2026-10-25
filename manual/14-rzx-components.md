# 14 — `.rzx` components

RZ-28. A `.rzx` file is a server component: a JSX-like markup file with a typed props schema. It
is **compiled to PHP** ahead of time (or on first use in development), and it escapes every value
by default. It runs next to `.tpl`. A page is either `.tpl` or `.rzx`, never both.

Code:
- `src/library/Razy/Rzx/`: Lexer, Parser, `Compile/*`, Compiler, CompileCache, `Runtime/*`, Host
- `Controller::render()`
- `src/plugins/Template/function.rzx.php`
- `src/system/terminal/compile.inc.php` (`--rzx`)

## 1. A component

```tsx
{/* module rzx/Card.rzx: used as <Kit.Card> */}
<props>
return [
    'title' => Prop::string()->required(),
    'tone'  => Prop::enum('plain', 'warn')->default('plain'),
    'items' => Prop::list(Prop::shape(['id' => Prop::int(), 'name' => Prop::string()]))->default([]),
];
</props>
<section data-tone={tone}>
  <h2>{title}</h2>
  <Slot name="actions" accepts="button" max="2" />
  <ul>
    <for each={items} as="item" key="id"><li>{item.name}</li><empty><li>None yet</li></empty></for>
  </ul>
  <Slot />
</section>
<push to="body.scripts"><script type="module" src={asset('js/card.js')} /></push>
```

The parts:
- **`<props>`.** A PHP array of `Prop::` chains.
  - Types: `string`, `int`, `float`, `bool`, `html`, `any`, `list`, `map`, `shape`, `enum`.
  - Modifiers: `required`, `optional`, `default`, `min`, `max`.
  - The block is *read*, not run (`PropsReader`): anything but literals and `Prop::` chains is a
    compile error.
- **Markup.** Exactly one root element, then optional `<push>` blocks.
  - Lowercase tags are HTML or custom elements. Capitalised, dotted tags are components.
  - Control flow: `<if test={…}>`, `<elseif>`, `<else>`, and `<for each as key index>` with
    `<empty>`.
  - Comments: `{/* … */}`.
- **Expressions** are a fixed grammar, not PHP:
  - paths `a.b` and `a[0]`, which read arrays and public properties only;
  - literals, lists `[…]`, and maps `{k: v}`;
  - `! && || ?? == != < <= > >= ~ + -` and `? :`;
  - calls to registered functions.

## 2. Escaping

| Where | What happens |
|---|---|
| `{value}` in text | HTML-escaped; `null` and booleans print nothing |
| `attr={value}` | escaped; `null`/`false` drop the attribute, `true` prints it bare, a list or map becomes JSON |
| `href`, `src`, `action`, … | `http`, `https`, `mailto` or relative, else `#` (and a warning) |
| `<script type="application/json">{data}</script>` | JSON with `JSON_HEX_*`, so `</script>` cannot close it |
| `{html(x)}` | raw HTML, only when `x` is a `Razy\Rzx\SafeHtml` built by trusted code |

Resource attributes (`<script src>`, `<iframe src>`, `<object data>`, `<embed src>`, `<base href>`,
a stylesheet or preload `<link href>`) take only a same-origin path, or a URL from trusted code
such as `asset()`. Anything else, `//host` and `\\host` included, renders as `#`. `<script src>`
is a literal or `asset(…)`.

An ordinary `<a href={url}>` may point to any `http`/`https` site, `//host` included. That is the
caller's choice of destination, not script. If the URL comes from request input, check it against
your own allowlist first, or it is an open redirect.

There is no `raw` and no other escape hatch. The compiler rejects:
- `<style>` and `style=""` (use `hooks={{ '--name': value }}`);
- `on*` handlers and `srcdoc`;
- inline JavaScript;
- literal unsafe URLs.

## 3. Registering components (package.php)

```php
'rzx' => [
    'namespace'  => 'Kit',              // components are <Kit.Name>, files rzx/Name.rzx
    'path'       => 'rzx',              // default
    'vocabulary' => 'vocabulary.json',  // optional, §6
    'profile'    => 'composition',      // optional, §6
],
```

Components are always namespaced. A bare `<Card>` has no namespace to resolve in.

## 4. Screens and the layout

A screen is a `.rzx` file in the module's `view/` folder. `Controller::render()` picks it when it
exists:

```php
$this->render('admin/Members', ['members' => $rows], ['title' => 'Members']);
// view/admin/Members.rzx; the third argument holds the layout's props
```

- The screen renders into the dist layout (manual 13) and shares its anchors. `<push to>` and
  `<Anchor name>` are the `.rzx` spellings of `{@push}` and `{@anchor}`.
- An `.rzx` layout (`'layout' => '@layout/site.rzx'`) receives the page as its default `<Slot />`.
- A `.tpl` page can embed one component with `{@rzx name="Kit.Card" props=$card}`. Slot fills are
  not available from a `.tpl`.

**Standalone apps:** `.rzx` needs a dist. In a standalone app, `Controller::render()` renders
`.tpl` views only (a `.rzx` view throws a clear error), and `addRzxFunction()` throws.

## 5. Functions

Built in:
- `count(x)`
- `json(x)`
- `html(x)`

Razy:
- `asset('js/x.js')`, or `asset('alias:css/a.css')` for another module's assets;
- `importmap()`, printed with `{html(importmap())}`, once per response;
- `nonce()`;
- `url('path')`;
- `nl2br(text)`;
- every template modifier except `raw`, `escape` and `json`: `upper`, `lower`, `capitalize`,
  `trim`, `truncate`, `date`, `number`, `join`, `alphabet`, `strip_tags`, `addslashes`, `gettype`.

App functions are registered in `__onInit`:

```php
$this->addRzxFunction('t', fn (string $key, array $vars = []): string => $this->translate($key, $vars));
```

Function results are escaped like any value. In worker mode a function registered at boot lives for the whole worker, so it must
not capture per-request state; read the request inside the call.

## 6. Vocabularies and profiles

Razy knows no UI kit. A package can register a vocabulary JSON file. It declares:
- element prefixes, the elements, and their attributes (types or enums);
- attributes that are never allowed;
- hook names;
- a slot-marker attribute, added to each direct child of a named fill;
- attribute mappings, such as `motion="rise"` → the kit's data attribute;
- named **profiles**.

A profile limits one kind of file. For example, a `screen` profile might allow a few plain
elements, with no `class`, no hooks and no mappings. Which profile applies:
- components: the namespace's `profile` in package.php;
- screens: dist.php `'rzx' => ['screen_profile' => 'screen']`;
- `.rzx` layouts: `'rzx' => ['layout_profile' => 'layout']`.

With no vocabulary registered, none of these checks run (codes RZX150–157).

## 7. Compile, cache, deploy

dist.php:

```php
'rzx' => ['mode' => 'dev', 'check_props' => 'dev'],
```

Compiled files and `index.php` live in `DATA_FOLDER/compiled/rzx/<dist>`.

- **Dev mode:**
  - the first render in a request checks each source and its dependencies (mtime and size, then
    sha256);
  - changed sources are recompiled, together with every component that uses them;
  - a change to the function set or the vocabulary recompiles everything.
- **`'mode' => 'compiled'`** never compiles at request time. A missing or stale component is an
  error naming the deploy step:

  ```
  php Razy.phar compile <dist> --rzx     # every component and screen; fails on any compile error
  ```

  `compile <dist>` runs the same step at its end.

**Errors:**
- Compile errors are `Razy\Rzx\CompileError`. Each has a stable code (`RZX0nn` parse, `RZX1nn`
  compile), `file:line:col` and a code frame. Example: `RZX112 <Kit.Card> needs the required prop
  title`.
- Render errors (`Razy\Rzx\Runtime\RenderError`) name the `.rzx` file and line.

The code tables are in `changelog/unreleased/RZ-28a.md`, `RZ-28b.md` and `RZ-28c.md`.

## 8. Fixtures

A component is tested on its own with a `*.fixture.php` next to it. `php Razy.phar ui render` turns
it into standalone HTML for pixel goldens (manual 13 §7). Its states are props:

```php
return Fixture::component('Kit.List')            // Kit = this folder
    ->namespaces(['App' => '../app'])             // other namespaces it uses
    ->vocabulary('vocabulary.json')
    ->rzxFunction('t', fn (string $key): string => $key)
    ->themes('light', 'dark')
    ->base(['label' => 'Members'])
    ->state('default', ['rows' => $rows])
    ->state('empty', ['rows' => []])
    ->fill('end', '<x-button label="Add"></x-button>');
```

The component renders through the same compiler, functions and anchors as a site page. A prop
that fails its schema fails the render.

