# 13 — Layouts and anchors

RZ-27. One layout per site, and named **anchors** that pages and components push markup into.
It replaces the habit of splitting a page shell into `head.tpl` and `foot.tpl` and of
special-casing "this page needs that script" in the footer.

Code: `src/library/Razy/View/Anchors.php`, `Template::renderPage()`, `Controller::render()`,
`src/plugins/Template/function.anchor.php`, `function.push.php`.

## 1. The layout

Set the site layout in `dist.php`:

```php
'layout' => 'site',            // = @layout/site.tpl in the dist's layout folder
// or 'layout' => '@layout/app/shell.tpl'
```

The layout is an ordinary template. It prints anchors where markup goes, and the page itself at
the reserved anchor `content`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$title}</title>
  {@anchor name="head.meta"}
  {@anchor name="head.styles"}
  {@importmap}
</head>
<body>
  {@anchor name="content"}
  {@anchor name="body.overlays"}
  {@anchor name="body.scripts"}
</body>
</html>
```

Render a page into it from a controller:

```php
$this->render('members', ['rows' => $rows]);   // module view/members.tpl
```

`render()` loads the view as `loadTemplate()` does, assigns the values, renders the page, and then
renders the layout around it. Without a `'layout'` the page is printed alone. Values for the layout
itself (`title`, …) go on the global template scope (`$this->getTemplate()->assign(...)`).
`loadTemplate()`, `queue()` and `view()` work as before.

## 2. Pushing to an anchor

```html
{@push to="head.styles" key="members-css"}<link rel="stylesheet" href="{@asset file='css/members.css'}">{/push}
{@push to="body.scripts" order=10}<script type="module" src="{@asset file='js/members.js'}"></script>{/push}
{@if $canEdit}
  {@push to="body.overlays" key="add-member"}<rui-dialog id="add-member">…</rui-dialog>{/push}
{/if}
```

- **Prints nothing where it stands.** The body is rendered with the current values and added to the
  anchor.
- **Conditional.** A `{@push}` inside a false `{@if}` branch never runs (since RZ-30, only the chosen
  branch is expanded).
- **Dedupe.** A second push with the same `key` is dropped. Without a key, the first `src` / `href`
  in the markup is the key, else the markup itself. A stylesheet that ten components push prints
  once.
- **Order.** Lower `order` first; equal orders keep push order.
- **Anywhere in the response.** Anchors are filled when the outermost render finishes
  (`Source::output()`, `outputQueued()`, `render()`), so a `head.styles` anchor printed before
  the page body still receives the body's pushes. This also works with the older
  `INCLUDE BLOCK: @layout/head.tpl` style and with queued sections.
- **Printed twice.** If an anchor is printed twice, its content goes to the first one.

## 3. CSP and the importmap

- Every `<script>` and `<link rel="modulepreload">` that enters through a push gets
  `nonce="…"` with this request's CSP nonce (unless it already has one).
- An **inline** script cannot be pushed, except `type="importmap"` and
  `type="application/json"` (data). Load code with `src`.
- `{@importmap}` prints once per response; a second one prints nothing. Put it in the layout.

## 4. Markers and safety

`{@anchor}` prints a placeholder comment with a random token made for each response
(`<!--rz-anchor:<token>:<name>-->`). Only that exact token is replaced. Markup that only looks like
a marker, such as user content or a cached fragment from another response, stays literal text.
The anchors are reset at the start of every request, including each request a long-lived worker
serves, so nothing carries over.

Content pushed to an anchor that no template printed is not shown.
`$this->getTemplate()->anchors()->unresolved()` lists such anchors (for example, a component that
pushes to `body.overlays` on a site whose layout has no such anchor).

## 5. Rule: a function tag cannot wrap a block or an include (RZ-26)

```html
{@if $show}
<!-- INCLUDE BLOCK: part.tpl -->     ← TemplateException
{/if}
```

Blocks (`START` / `END`, `TEMPLATE`, `WRAPPER`, `RECURSION`, `USE`) and `INCLUDE BLOCK` lines are
resolved while the file is **parsed**, before any `{@if}` runs. The include was always printed,
and the `{/if}` was silently dropped. This is now a parse error. The message names the line of the
block comment and the line of the open tag:

```
'INCLUDE BLOCK: part.tpl' at …/page.tpl:3 is inside {@if} opened at …/page.tpl:2. …
```

Fixes, in order of preference:

1. Move the condition into the included file.
2. Make the markup a block and render it from the controller (`newBlock()`).
3. Push it: `{@if $show}{@push to="body.overlays"}…{/push}{/if}`.

Only tags whose plugin encloses content count (`{@if}`, `{@push}`, `{@repeat}`, …). An unknown
`{@tag}` prints as text, as before.

## 6. API

| API | Purpose |
|---|---|
| `dist.php 'layout'` | The site layout (`'site'` or `'@layout/…'`) |
| `Controller::render(string $view, array $parameters = [])` | Render a view inside the layout and print it |
| `Template::renderPage(Source $page): string` | The same, returned as a string |
| `Template::anchors(): Razy\View\Anchors` | This request's anchors |
| `Anchors::push(string $anchor, string $html, ?string $key = null, int $order = 0)` | Push from PHP |
| `Anchors::marker(string $name): string` | The placeholder `{@anchor}` prints |
| `Anchors::unresolved(): array` | Anchors holding content nothing printed |

Anchor names are lowercase letters, digits, `.`, `_` and `-`, starting with a letter. `content` is
reserved for the page body.

## 7. Rendering a fixture (`ui render`, RZ-29)

A **fixture** renders one piece of UI on its own, with injected values, in named states, as a
standalone HTML file. A screenshot harness turns those files into pixel goldens.

Put a `*.fixture.php` next to the template:

```php
<?php // view/card.fixture.php
use Razy\View\Fixture;

return Fixture::template('card.tpl')          // relative to this file
    ->themes('light', 'dark')                 // sets <html data-theme>
    ->widths(1280, 360)                       // read by the screenshot harness only
    ->assets(css: ['../webassets/card.css'])  // local files; copied next to the HTML
    ->layouts('../../layout')                 // where @layout/… includes resolve (optional)
    ->base(['title' => 'Members', 'rows' => $rows])
    ->state('default')
    ->state('empty', ['rows' => []])
    ->state('zh', ['title' => '成員'], lang: 'zh-HK');
```

```
php Razy.phar ui list [<root>…] [--json]
php Razy.phar ui render <name|file.fixture.php> [<state>] [--theme=<t>] [--root=<dir>]… [--out=<dir>]
```

- **`ui list`** finds fixtures under the roots (default: the current folder; `vendor/`,
  `node_modules/` and dot folders are skipped). `--json` prints each fixture's name, file, states,
  themes and widths for the harness.
- **`ui render`** without `--out` prints one state in one theme to stdout. With `--out` it writes
  `<out>/<name>/<state>-<theme>.html` for every state and theme (or only the given ones) and copies
  the assets to `<out>/<name>/assets/`.
- **The frame.** Each state renders into a built-in frame: a doctype, `<html lang data-theme>`, the
  anchors `head.styles`, `head.scripts`, `body.overlays`, `body.scripts`, and the template at
  `content` inside `<main id="ui-fixture">`. Values are always escaped (auto-escape on), and pushes,
  the CSP nonce and dedupe behave as on a real page.
- **Self-contained.** A render fails if the HTML loads anything over the network (`src`, `href`,
  `action`, `poster`, `data` pointing at `http(s)://` or `//`), or if the template pushes to an
  anchor the frame does not have. The command exits 1 on any error.
- **No width option.** The HTML is the same at every width; the harness sets the viewport from
  `widths()`.
