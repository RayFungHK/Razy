# Razy style reference

This is the one place where Razy's conventions are written down. Each rule says what is checked and by which tool;
a rule a tool cannot check says so, and review checks it. When the code and this file disagree, fix one of them in
the same PR. Rule IDs `RZ-0xx` are the Golden Rules in [`skills/RAZY-AI-RULES.md`](../skills/RAZY-AI-RULES.md);
`STY-*` are the rules of [`tools/lint-style.php`](../tools/lint-style.php).

## 0. The founding rule: one owner per module, its API doc is the contract

Razy was built on one principle: **a module is owned by one team or one person, and its published API and the
document that describes it are the contract.** Everything else in this file serves that rule.

- **One owner.** `module.php` names it in `'owner'`: the team or person who decides changes to the module and to
  its API doc. `'author'` says who wrote it; `'owner'` says who decides now.
- **The API doc is the contract.** `API.md` beside `module.php` lists every published surface: each API command
  (`addAPICommand`), bridge command (`addBridgeCommand`) and emitted event (`trigger`), with its signature, its
  return shape and the gate that admits callers. What is not in `API.md` is not public, even if it is reachable.
- **Nobody edits another owner's module files.** A consumer needs a change? It asks the owner (an issue or a PR
  the owner reviews). It never patches the files, copies them, includes them (RZ-001), imports their classes
  (RZ-017) or reaches their state (RZ-008). Modules talk only through the documented surface: `api()`, events,
  and gated bridge commands (RZ-002, RZ-010).
- **A change to the contract is versioned.** Adding a command is a minor change; changing or removing one is a new
  major version folder (RZ-012, §5).

Checked: **RZ-018** in `tools/lint-module-discipline.php` fails a module whose `module.php` has no non-empty
`'owner'`, that has no `API.md`, or that registers a command or event its `API.md` does not name as `` `name` ``.
A `#command` (published and bound internally) is documented without the `#`; the batch form
`addAPICommand(['name' => 'path', ...])` counts too. Commented-out registrations publish nothing and need no entry.
The lint sees names written as literals; a name built at runtime (`trigger($name)`) is documented by hand and
checked in review.

`API.md` shape (the first-party modules under `modules/` are the reference):

```markdown
# vendor/module — API

Owner: <team or person>. This file is the contract: only what is listed here is public;
change it through the owner (docs/STYLE.md §0).

## Gate
Who `__onAPICall` / `__onBridgeCall` admits, and on what config.

## Commands
| Name | Signature | Returns |
|---|---|---|
| `doThing` | `(string $id, array $options = []): array` | `['ok' => bool, ...]` |

## Events
- `thing.done` — fired as `vendor/module:thing.done`, payload `['id' => string]`.
```

## 1. Naming (decided once)

PSR-1 everywhere, which the tooling and nearly all of the code already follow:

| Symbol | Case | Example |
|---|---|---|
| Class, interface, trait, enum | PascalCase | `WhereSyntax`, `ModelQuery` |
| Method and function, static or not | camelCase | `getSyntax()`, `isRawSafeOperand()` |
| Lifecycle hook | `__on<Event>` | `__onInit`, `__onAPICall` |
| Property and variable | camelCase | `$paramSeq` |
| Constant | UPPER_SNAKE | `REGEX_COLUMN` |
| Module code, API command | as registered; a command name matches `/^#?[a-z]\w*$/i` (`Agent::addAPICommand`) | `razymod/apps`, `findUser` |
| Plugin file | `<type>.<name>.php`, lower case (§4) | `modifier.upper.php` |

The pre-PSR-1 rule "static methods are PascalCase" is retired. Nine PascalCase methods remain (`Lock`,
`UnlockForWorker`, `VerifySyntax`, `GetPlugin`, `ParseContent`, `GetValueByPath`, `ParseValue`, `Format`,
`WriteLine`); they are in the baseline and are renamed with a camelCase alias kept for one release.

Checked: **STY-NAME** (`tools/lint-style.php`) on `src/library/Razy`. The fixer has no method-case rule.

## 2. One operator table, every DSL

Every Razy string language (Where and Update Simple Syntax, template `{@if}`, `ArrayUtil::comparison()`) uses
these tokens with these meanings. A new DSL or operator reuses this table or amends it in the same PR.

| Token | Meaning |
|---|---|
| `,` | AND |
| `\|` | OR (in a template value `{$a\|$b}`: or-else fallback) |
| `!` | NOT (prefix) |
| `( )` | grouping |
| `=` `!=` `<` `>` `<=` `>=` | comparison |
| `^=` | starts with |
| `$=` | ends with |
| `*=` | contains |
| `#=` | regular expression |
| `\|=` | in a list |
| `><` / `<>` | between / not between |
| `~=` `&=` `@=` `:=` | JSON: contains, search, key exists, path exists (Where) |
| `++` `--` `+=` `-=` `*=` `/=` | arithmetic on the column (Update) |

Known drift, to be fixed against this table:

- WhereSyntax `^=` / `$=` put the `%` on the wrong side (`name^=john` is `LIKE '%john'`): RZ-44.
- Update Syntax `&=` concatenates while Where `&=` is JSON search: one token, two meanings.

Checked by review: a new operator in a diff must appear in this table.

## 3. Values only through `:name`

A Simple Syntax string (`where`, `orWhere`, `having`, `order`, `orderBy`, `group`, `groupBy`, `select`, `from`,
`update` entries, the `delete` syntax argument) is parsed by the engine. Anything glued into the text becomes syntax.

- Values enter as `:name` placeholders plus `assign()` (or the ORM's named parameters). Never interpolate or
  concatenate a value into the syntax string.
- Identifiers (columns, tables) come from your own allow-list, never from input. Where the code splices an
  identifier from its own configuration, the line carries `// lint-allow: RZ-019 <reason>`.
- The engines refuse what they do not recognise: an unknown Where or Update operand throws
  `InvalidOperandException`. Only numbers, bare identifiers and the zero-argument functions in
  `WhereSyntax::SQL_FUNCTIONS` stay raw (RZ-41).
- ORM helpers (`whereIn`, `whereBetween`, ...) take one identifier and bind under the reserved `_mq_` prefix.

Checked: **RZ-019** in both lints (modules and `src`), patterns in `tools/lint/rz019.php`; **RZ-003** for raw SQL
and PDO in modules.

## 4. Plugin file contract

- Core plugins live in `src/plugins/<Kind>/`; a module's live under `plugins/<Kind>/` in the module's own
  folder (`getModuleSystemPath()`) and are registered in `__onInit` with `registerPluginLoader()`.
- The file name is the plugin id: `<type>.<name>.php` (`modifier.upper.php`, `function.if.php`,
  `processor.trim.php`).
- The file returns a factory closure that returns an instance of the kind's base class (`TModifier`,
  `TFunction`, ...). One registry (`PluginManager`); lookup is first folder wins, so core plugins cannot be
  shadowed: pick a new name.
- An unknown plugin name raises an error that names it; it is never skipped.

Known drift: Collection processors and filters are bare closures, `Collection\Processor::__call()` skips an unknown
processor silently, and Pipeline / Statement plugin files are PascalCase class names (`Fetch.php`, `Max.php`).

Checked by review.

## 5. Module layout and version folders

```text
vendor/module/
  module.php            module_code, name, author, owner, description, version
  API.md                the contract (§0)
  default/              a version folder: default, dev, or a semver number (1.4.0)
    package.php         version, api_name, require, ...
    controller/
```

- A distributor pins the version it loads in `dist.php` `'modules'`: a folder name, `'*'` (highest) or a semver
  range; `default/` is the fallback when nothing is pinned (RZ-35). One PHP process loads one version of a module.
- `default` and `dev` are the only non-numeric version folders (`ModuleInfo`).
- A breaking change to the API doc is a new major folder; the old one keeps serving its pinned distributors
  (RZ-012).
- Dependencies are declared in `package.php` (`'require'`), never hand-edited into `autoload/` (RZ-007).

Checked: `ModuleInfo` refuses a malformed version at boot; RZ-012 / RZ-007 by review and the module lint.

## 6. Errors

- Throw a typed exception from `Razy\Exception` (or a subclass of the nearest one): `QueryException`,
  `InvalidOperandException`, `ModuleConfigException`, `ConfigurationException`, ... Never `\Exception` with a
  message to parse.
- A message carries no SQL, no HTML and no input. Put details on the exception (`QueryException::getSql()`,
  `InvalidOperandException::getOperand()`) for logs and the debug page.
- Constructors have no side effects beyond storing what they were given.
- Fail loud: a wrong name through dynamic dispatch (plugin, command, route, config key) raises an error that names
  it. No silent no-op.
- The public error page shows a generic message and an error id; file, line, SQL and trace only in debug (RZ-01).

Checked by review; PHPStan level 5 catches undeclared calls (§9).

## 7. Size caps

| Unit | Cap |
|---|---|
| File | 400 lines |
| Class, trait, interface or enum body | 400 lines |
| Named function or method | 60 lines |

Code over a cap before this rule is listed in [`tools/style-baseline.json`](../tools/style-baseline.json) with its
size. It may stay and may not grow; splitting it is ordinary refactoring, after which the stale entry is dropped
(`php tools/lint-style.php --generate-baseline`). New code meets the caps.

Checked: **STY-FILE**, **STY-CLASS**, **STY-FUNC**.

## 8. File hygiene

- PSR-12 with four-space indentation, enforced by `composer cs-fix` / `cs-check` (`.php-cs-fixer.dist.php`).
- `declare(strict_types=1);` in every new file. Checked: **STY-STRICT** (older files are in the baseline).
- The license header without a version number.
- Comments say why, not when: no plan-phase notes ("Phase 2", "step 3"). An item ID next to the reason it explains
  (`// RZ-41: ...`) is fine.

## 9. Static analysis

PHPStan level 5 on `src/library/Razy` (`phpstan.neon`) and on the first-party modules (`phpstan.modules.neon`).
Neither config ignores "undefined method" or "undefined property" wholesale. Dynamic dispatch is declared
(`@method`, `@property`, a stub), or the one call site is listed in `phpstan-baseline.neon`. That list only
shrinks: fix the call or declare it, then regenerate with `vendor/bin/phpstan analyse --generate-baseline
phpstan-baseline.neon`.

## 10. What checks what

| Rule | Tool | Where it runs |
|---|---|---|
| §0 owner + API doc (RZ-018) | `php tools/lint-module-discipline.php <module tree>` | CI `Module Discipline`, on `modules`, `demos`, `demo_modules` |
| §1 naming (STY-NAME), §7 sizes, §8 strict_types | `php tools/lint-style.php` | `composer quality`, CI `Module Discipline` |
| §3 values through `:name` (RZ-019) | both lints | as above |
| §8 formatting | `composer cs-check` | `composer quality`, CI `Code Style` |
| §9 static analysis | `composer phpstan`, `phpstan -c phpstan.modules.neon` | `composer quality`, CI `Static Analysis` |
| Golden Rules RZ-001..017 | `php tools/lint-module-discipline.php` | CI `Module Discipline` |
| The lints themselves | `php tools/lint-module-discipline.php --self-test`, `tests/LintStyleTest.php` | CI |

Before a PR: `composer quality` (cs, PHPStan, style lint, tests) and the module lint on every module tree you
touched.
