# 05 — Templates

The shipped syntax, verified against `src/library/Razy/Template/Entity.php` and the working
views under `demo_modules/core/template_demo/default/view/`. Read §4 before putting any
user-supplied value in a template — the engine does **not** escape (RZ-004).

---

## 1. Data flow: controller → view → output

```php
return function (): void {
    /** @var Razy\Controller $this */
    $source = $this->loadTemplate('main');      // resolves <module>/view/main[.tpl]
                                                // (Controller.php:353; path+extension :382-390)
    $source->assign(['site_name' => 'My Site', 'tags' => ['php', 'mysql']]);
    echo $source->output();                     // parse + return string
};
```
(copied from `demo_modules/core/template_demo/default/controller/template_demo.main.php:17-21`)

There is also `$this->view(array $sources): void` — it echoes the **queued output** of
Source objects through the module's global template entity
(`Controller.php:524-527`). Use `loadTemplate()+output()` for direct control; `view()` when
you rely on the queued/global-template pipeline. The `Template` service itself is available
via `$this->getTemplate(): Template` (`Controller.php:439`).

## 2. Syntax — the real one

Verified parsing code: `Template/Entity.php` (tag regex `:388`, clip split `:389`,
fallback loop `:391-396`, empty default `:398`, literal parsing `:417-421`, modifier chain
`:633-641`).

| Feature | Syntax | Verified behaviour |
|---|---|---|
| Variable | `{$name}` | **raw output** — the parsed value is returned untouched (`Entity.php:392-394`) |
| Dot path | `{$user.profile.city}` | nested array keys / property paths (regex `:388`; value-by-path resolution in `Entity.php` `parseValue`) |
| Object access | `{$obj->prop}` / `{$obj->method:arg}` | property/method access per tag grammar (regex `:388` `->\w+(?::…)`) |
| **Fallback chain** | `{$missing\|"N/A"}` | `\|` splits alternatives (outside quotes, `:389`); the **first renderable clip wins** — scalar or `__toString` object (`:391-395`); all-empty renders `''` (`:398`) |
| Quoted fallbacks | `{$user.name\|$viewer\|'anon'}` | quoted/numeric/bool literals are valid clips (`:417-421`) — verified demo: `demo_variables_defaults.tpl:1` (`{$existing\|"N/A"}`) |
| **Modifier chain** | `{$name->upper->trim}` | `->name` suffix *within one clip*; regex `->(\w+)((?::…)*)` (`Entity.php:635`), applied left-to-right via plugins (`:636-640`) |
| Modifier args | `{$tags->join:', '}` | colon-separated args parsed into `process($value, ...$args)` (`Template/Plugin/TModifier.php:63-74, 110`) — use a shipped modifier name; there is **no `truncate`** in the built-in set |

**Built-in modifiers** (the shipped set, file listing of `src/plugins/Template/`):
`upper`, `lower`, `trim`, `capitalize`, `join`, `nl2br`, `alphabet`, `gettype`,
`addslashes`, `escape` (10 × `modifier.<name>.php`; `escape` added in v1.0.3-beta —
older installs: see §4c). Raw output remains the default for everything else — see §4.

Function plugins present: `if`, `each`, `repeat`, `def`, `template`
(`src/plugins/Template/function.<name>.php`), backing:

```html
{@if $logged_in}Welcome, {$username}!{@else}Please log in.{/if}
Role: {@if $role="admin"}Administrator{@else}Member ({$role}){/if}

{@each source=$colors as="c"}{$c.value}, {/each}          <!-- items arrive as {key, value} pairs -->
- {$u.value.name} (age {$u.value.age})

{@repeat length=5}{$star}{/repeat}
{@def "greeting" "Hello World"}{$greeting}
```
(copied verbatim from `demo_func_if.tpl:1-2`, `demo_func_each.tpl:1-6`,
`demo_func_repeat.tpl:1`, `demo_func_def.tpl:1`)

Block structures are HTML-comment tags (same verified source, `demo_blocks_*.tpl`):

```html
<!-- WRAPPER BLOCK: tag -->                       <!-- wrapper renders around repeated body -->
<!-- START BLOCK: tag --> <span>{$name}</span> <!-- END BLOCK: tag -->
<!-- END BLOCK: tag -->

<!-- TEMPLATE BLOCK: card_tpl --> <h4>{$title}</h4><p>{$desc}</p> <!-- END BLOCK: card_tpl -->
<!-- START BLOCK: card --><!-- USE card_tpl BLOCK: content --><!-- END BLOCK: card -->

<!-- INCLUDE BLOCK: include/alert.tpl -->         <!-- inline another template file -->
```
(`demo_blocks_wrapper.tpl:1-6`, `demo_blocks_template_use.tpl:1-10`,
`demo_adv_include.tpl:2`; the framework's own `src/asset/setup/sites.inc.php.tpl` uses the
same WRAPPER/START/END machinery)

## 3. What is NOT syntax (drift you will meet)

1. **`|` is a fallback chain, not a modifier pipe.**
   `{$text|upper}` does **not** uppercase: `upper` becomes a fallback variable clip and
   resolves to nothing — you get the raw `{$text}`. The demo views that show
   `{$text|upper}` / `{$raw_input|trim|capitalize}`
   (`demo_variables_modifiers.tpl:1`, `demo_variables_chain.tpl:1`) are **legacy examples**
   that no-op under the current engine (drift list in [README](README.md)).
   Correct: `{$text->upper}`, `{$raw_input->trim->capitalize}`.
2. **No auto-escaping anywhere** (§4).
3. **No auto-escaping is added for you.** `{$value}` stays raw; `{$value->escape}` is
   built-in since v1.0.3-beta — on older installs adopt the §4c file.
4. No `{`-tag `{@foreach}` / `{if}` C-style tags — the tags are `{@if}`-style function tags
   with `{/if}` closers, per the verified demo files.

## 4. XSS discipline (RZ-004) — the engine is raw by design

`parseText()` returns values unmodified (`Entity.php:392-394`); there is no global escape
hook. Every value with *any* chance of user content must be neutralised **before** the
template, or the app inherits an XSS hole (rules RZ-004 "Why"; the same finding is in
`RAZY-ANALYSIS-REPORT.md`). Three sanctioned routes:

### a) Escape in the controller (baseline)

Working demo pattern (`demo_modules/core/event_demo/default/controller/event_demo.order.php:13-15`):

```php
$product = htmlspecialchars($_GET['product'] ?? 'Unknown Product', ENT_QUOTES, 'UTF-8');
```

### b) DOM builder — auto-escaping by construction

The DOM/component builders run every text node and attribute through
`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` during construction
(`src/library/Razy/DOM.php:84-140`; components reuse it via
`src/library/Razy/DOM/Component.php`). Raw HTML requires an explicit raw/opt-out call
(`DOM.php:147-151`). If a page mixes trusted rich HTML with user data, build it through
the DOM layer.

### c) Use the `->escape` modifier (built-in since v1.0.3-beta)

Since v1.0.3-beta `src/plugins/Template/modifier.escape.php` ships with the framework —
just write `{$comment.body->escape}`. The shipped file additionally uses
`ENT_SUBSTITUTE` and renders arrays/non-`__toString` objects as empty string (contract:
`tests/TemplateModifierEscapeTest.php`). On **older** installs, drop this minimal file
in yourself (rules pack Appendix C has the same reference, matching the built-ins'
factory format):

```php
<?php
// src/plugins/Template/modifier.escape.php
/**
 * Template Modifier Plugin: escape
 * HTML-escapes values for safe output (ENT_QUOTES, UTF-8).
 * Usage: {$variable->escape}
 */
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
    };
};
```

Then `templates/comment.tpl` writes `{$comment.body->escape}`.
*(Upstream integration is tracked as a security patch — until merged, copy it per RZ-004;
the copy is not "inventing an API": the factory shape is the one `modifier.addslashes.php`
ships.)*

Decision rule (from RZ-004's checklist): user input → escape (a/b/c). Rich HTML you own →
sanitise at write time. Attribute contexts → prefer the DOM builder. Static provably-trusted
config → raw is fine.

## 5. Template plugin system

Plugin files are **factory closures returning anonymous class instances**, named
`modifier.<name>.php` / `function.<name>.php`, living in a plugin folder that the
`Template` engine searches (`Block::loadPlugin`, used by the modifier chain at
`Entity.php:637`). The built-in `addslashes` plugin, verbatim, is the canonical shape
(`src/plugins/Template/modifier.addslashes.php:32-48`):

```php
<?php
use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            return addslashes($value);
        }
    };
};
```

Contract (`Template/Plugin/TModifier.php`): override `process(mixed $value, string
...$args): ?string` (`:110`); colon args (`->mod:a:b`) are parsed before dispatch
(`modify()`, word/number/quoted forms supported — `:"Y-m-d H:i"` keeps spaces; note: until
the 2026-07 plugin round this parser was broken and every modifier argument silently
arrived empty — pinned now by `tests/TModifierParamParsingTest.php`); plugins can grab the
bound controller via `->bind(Controller)` (`:48-53`).

Built-in catalogue (unreleased additions marked ★): modifiers `upper, lower, trim, join,
nl2br, capitalize, alphabet, gettype, addslashes, escape, truncate★, date★, number★,
json★, strip_tags★`; functions `def, each, if, repeat, template, paginate★`.
`truncate` counts UTF-8 characters with the suffix inside the budget (`{$t->truncate:80}`,
`{$t->truncate:120:'…':1}` = word-boundary); `json` is `<script>`/attribute-safe via
`JSON_HEX_*`; `paginate` renders an escaped windowed nav (`{@paginate page=$p pages=$n
base=$url}` — quote parameter values; the engine's grammar accepts only `$vars`, numbers,
`true/false`, and quoted strings).

### Module-local plugins (recommended)

Ship plugins inside your module and load them explicitly:

```php
public function __onInit(Agent $agent): bool
{
    $this->registerPluginLoader(Controller::PLUGIN_TEMPLATE);   // loads <module>/plugins/Template
    return true;
}
```

- `registerPluginLoader(int $flag)` — `Controller.php:693-699` (constant
  `PLUGIN_TEMPLATE = 0b0001`, `Controller.php:45`; also `PLUGIN_ALL`, and
  Collection/Pipeline/Statement siblings `:42-54`).
- Folder: **`<module>/plugins/Template/modifier.escape.php`** — resolved under
  `getModuleSystemPath()/plugins/…` (verified loader usage: `Template::addPluginFolder`).

This keeps your escape patch (or any custom modifier) inside module boundaries — RZ-006/008
— instead of forking the framework plugin dir.

Name-collision semantics (verified `PluginManager::getPlugin` `:101-134`): registered
folders are searched in registration order, first match wins, and core folders register at
bootstrap (`src/system/bootstrap.inc.php:155-165`) **before** any module's
`registerPluginLoader` — so a module plugin named `escape` is simply unreachable, core
plugins cannot be shadowed. Treat plugin names as your module's public vocabulary: once
templates use them, renaming is an API break (RZ-012). `PluginManager` also rejects
traversal in plugin names (`..`, slashes).

Custom validation rules follow the same open-surface spirit outside the view layer:
`extends ValidationRule` + `Validator::field($name)->rule(new YourRule())` — the rule
returns the (optionally transformed) value, calls `$this->fail()` to reject, and
`defaultMessage()` may interpolate `:field` (see `tests/ValidationCustomRuleTest.php`).

## 6. User-facing strings (i18n)

`Razy\Translation\Translator` is the dictionary layer for view/controller strings —
Traditional Chinese is the first-class default (`zh-Hant`), English the fallback:

```php
$lang = new Razy\Translation\Translator('/app/lang');           // per-locale folders
echo $lang->get('messages.welcome', ['name' => $user->name]);   // '歡迎, :name！'
echo $lang->choice('messages.cart.items', $n);                  // plural segments
```

- Dictionaries are PHP files: `lang/{locale}/{group}.php` returning arrays (opcache-friendly,
  matching the framework's `return []` config idiom).
- **Modules own their dictionaries** (RZ-001): register the module path under a namespace —
  `$lang->addNamespace('vendor/mod', $modulePath . '/lang')` — then keys read
  `vendor/mod::group.key`. Never read another module's language files directly.
- Placeholders use `:name` with smart case (`:Name` → ucfirst, `:NAME` → uppercase);
  numeric values get thousand separators outside CJK locales.
- Missing keys return the key itself — a visible TODO in the UI, never an exception.
- Plurals: `one|other` positional segments, explicit `{0}…|[2,*]…` selectors, and CJK
  single-category handling (zh/ja/ko/vi/th always take the last segment) —
  see `Razy\Translation\Pluralizer`.

Scope note (unreleased): the Translator is a library subsystem — wire it in your bootstrap
and pass it where needed (or bind it in your module's container). An `Agent`-level helper
is roadmap, not shipped.

## 7. Checklist before merging a view change

- [ ] No `|` used as a pipe (§3.1); fallbacks only, with quotes for literals
- [ ] Every non-static value escaped by route (a)/(b)/(c) or provably trusted — RZ-004
- [ ] Attributes/JS contexts built through DOM layer where possible
- [ ] Custom modifiers go in `<module>/plugins/Template/` + `registerPluginLoader`, never
      framework-dir edits — RZ-006
- [ ] Block tags balanced (`START/END`, `TEMPLATE/END`, wrapper pairs)

Next: [06-packages-and-deployment.md](06-packages-and-deployment.md).
