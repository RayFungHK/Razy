# 15 — Razy Apps: definitions, expressions, compiler (`razymod/apps`)

`razymod/apps` (module `modules/apps`, version 0.1.0) is the first piece of **Razy Apps**:
business apps described as data instead of code. A **definition** lists entities, fields,
relations, list / form / board / detail views, workflows, permissions and labels. This module
checks a definition, hashes it, gives its elements stable ids, parses its formulas and
compiles it into a runtime plan. It has no tables, no routes and no IO.

Not here yet: data spaces and physical tables (RZA-B, `apps-schema`), the record API and
SQL predicates (RZA-C), files and numbering (RZA-D), export (RZA-D2), the workflow engine
(RZA-E) and the package registry (RZA-F).

Code: `modules/apps/default/library/` (namespace `razymod\apps\…`, loaded by the
distributor's module autoloader, `ModuleScanner::autoload`). Tests:
`tests/Apps*Test.php`. Fixture packages: `tests/fixtures/apps/packages/{crm-core,crm-deals}`.

---

## 1. API (`api('razymod/apps')`)

Every command is a pure function of its arguments and the module config. Any module of the
distributor may call them; `__onAPICall` denies any command not on
`AppsController::API_ALLOW`.

| Command | Arguments | Returns |
|---|---|---|
| `validate` | `array $definition, array $options = []` | `['ok' => bool, 'issues' => [...]]` |
| `compile` | `array $definition, array $options = []` | `['ok' => bool, 'plan' => ?array, 'issues' => [...]]` |
| `hash` | `array $definition` | `['ok' => bool, 'hash' => 'sha256:…', 'bytes' => int]` |
| `assign-ids` | `array $definition, ?array $previous = null` | the definition, with every missing element id filled |
| `weight` | `array $definition` | `['weight', 'wf_nodes', 'counts', 'coefficients', 'coefficients_source']` |

`$options`: `dependencies` (package code → definition, for packages named in `depends_on`),
`previous` (the previous version's definition, for id stability) and `system` (`true` for
system templates: `zh-HK` labels required). Other keys are ignored.

An **issue** is `['severity' => 'error'|'warning', 'path' => JSON Pointer, 'code' => …,
'message' => …]`. A definition is accepted only with zero errors. Nothing is trimmed or
repaired: a definition over a cap is refused with the path of the element.

```php
$check = $this->api('razymod/apps')->validate($definition, ['dependencies' => ['crm-core' => $core]]);
if (!$check['ok']) {
    // show $check['issues'] to the author (paths like /entities/0/fields/3/expr)
}
```

Config (`config/<dist>/apps.php`):

```php
return [
    'caps' => ['fields_per_entity' => 120],   // narrowing only: values above the default are ignored
    'weight' => ['entity' => 10, 'field' => 1, 'relation' => 2, 'view' => 2, 'saved_view' => 1, 'row_rule_role' => 3],
];
```

Without `weight`, the `weight` command uses the reference values (`Weight::REFERENCE`) and says so
(`coefficients_source: 'reference'`). A `weight` config with a missing, negative or unknown
coefficient throws `InvalidArgumentException`: a broken rate card fails loudly.

---

## 2. Package layout

A package is a folder. `PackageLoader::load($dir)` reads it into one definition:

```
crm-deals/
  app.json              manifest: apps_schema, code, version, label, requires_engine, depends_on, lineage, roles, menu
  entities/<code>.json  one entity: id, code, label, numbering, fields, indexes
  views/<entity>.json   that entity's views: list, form, board, detail
  workflows/<code>.json one workflow
  permissions.json      {rules: [...]}
  i18n/<locale>.json    {key: text}
  fixtures/  tests/  CHANGELOG.md  README.md      not part of the definition
```

v1 package files are **JSON**. The plan writes them as YAML, but Razy's YAML parser does not
read lists of maps correctly yet, so a `.yaml` / `.yml` file is refused
(`package.yaml_unsupported`) rather than half read. Other rules: no unknown files, no
symlinks, file names match the code inside, at most 200 files of 512 KB and 4 MB in total;
entities and workflows are ordered by file name.

---

## 3. Schema v1

Codes (entities, fields, roles, views, workflows, states, nodes) match
`^[a-z][a-z0-9_]{0,39}$`. Entity and field codes may not be SQL keywords or system column
names (`id`, `uid`, `number`, `created_at`, `select`, `user`, `ctid`, … — `Schema::RESERVED`).
Package codes are lower-case words joined by hyphens (`crm-deals`). Unknown keys are errors
everywhere.

### 3.1 Manifest

| Key | Required | Value |
|---|---|---|
| `apps_schema` | yes | `1` |
| `code` | yes | package code |
| `version` | yes | semver `1.2.0` |
| `label`, `description` | no | i18n keys (default label `app.label`) |
| `requires_engine` | no | version constraint `">=1.0 <2"` (checked by the registry, RZA-F) |
| `depends_on` | no | `{package: constraint}` |
| `lineage` | no | `{base: null}` or `{base: {package, version}}` (derived packages) |
| `roles` | no | list of role codes (label key `role.<code>`) |
| `menu` | no | `[{entity, view, label?}]`, the view must exist |
| `entities` | yes | non-empty list |
| `workflows`, `permissions` | no | below |
| `i18n` | yes | `{locale: {key: text}}`, `en` always, `zh-HK` for system templates |

### 3.2 Entities and fields

Entity: `id`, `code`, `fields` (required), `label`, `help`, `numbering`, `indexes`, `views`.

Every field has `id`, `code`, `type` and may have `label`, `help`, `required`, `default`,
`search`, `pii`, `unique`. Types (closed list) and their own keys:

| Type | Keys |
|---|---|
| `text` / `long_text` | `max` (text: up to the `text_max` cap) |
| `rich_text`, `boolean`, `date`, `datetime`, `email`, `phone`, `url`, `user`, `group`, `department` | — |
| `integer`, `duration` | `min`, `max` (whole numbers) |
| `decimal` | `precision` (1-38), `scale`, `min`, `max` |
| `money` | exactly one of `currency_field` (a select field) or `currency` (ISO 4217), `scale`, `min`, `max` |
| `percent` | `scale`, `min`, `max` |
| `select` / `multi_select` | `options`: keys (`HKD`, `net_30`) or `{key, tone, label}`; multi: `max_selected` |
| `status` | `workflow`: a workflow of this entity with states |
| `relation` | `to` (`deal` or `crm-core.company`), `cardinality` (`many_to_one`, `one_to_many`, `many_to_many`), `on_delete` (`restrict`, `set_null`, `cascade_soft`; many-to-one only), `via` (one-to-many: the many-to-one field on the target that points back) |
| `file` / `files` | `accept` (MIME list), `max_size_mb`, `max_files` (files only) |
| `image` | `max_size_mb` |
| `computed` | `expr` (required), `stored` |
| `rollup` | `relation` (a to-many relation of this entity), `op` (`count`, `sum`, `min`, `max`), `field` |
| `sequence` | `pattern`, `reset` |
| `created_at`, `updated_at`, `created_by` | — (system values; add no weight) |

A `default` is a literal of the field's type or an `=expression` of that type (dates as
`2026-10-27`, datetimes as `2026-10-27T09:00:00+08:00`). Defaults run before the record
exists, so they cannot read fields (`=CURRENT_USER()`, `=TODAY() + 30`). User, group and
department values are always expressions, because ids belong to one data space while a
package is shared. Derived types, `status`, relations and files take no default;
`update_record` may set a many-to-one relation by expression (`=contact.company`).

**Numbering** (`numbering` on an entity fills the system `number` column; a `sequence` field
has its own): literal text with `{YYYY}`, `{YY}`, `{MM}`, exactly one `{seq:N}` (N 1-12) and
`{field:code}` of a select or text field; `reset`: `yearly`, `monthly`, `never`.

**Indexes**: `[[stage, close_date], [owner]]` or `{fields: [...], unique: true}`; fields must
be stored and indexable. The compiler adds an index per unique field and per many-to-one
relation.

### 3.3 Views

| View | Keys |
|---|---|
| `list` | `columns` (required), `default_sort` (`"-close_date"`), `filters`, `saved` (`[{id, code, filter, label?, sort?, columns?}]`), `page_size` (1-500), `bulk` (`export`, `retire`) |
| `form` | `sections`: `[{label, fields, visible?, readonly?, columns? (1-3)}]`; a field appears in one section only |
| `board` | `group_by` (a select or status field), `card` (fields), `wip_limits` (`{column: n}`) |
| `detail` | `header` (fields), `tabs`: `{related: "<entity>.<field>"}` (a many-to-one relation back to this entity, here or in a dependency), `{history: true}`, `{files: true}`, `{workflow: true}` |

System columns (`number` when the entity is numbered, `created_at`, `created_by`,
`updated_at`, `updated_by`) may be used in views.

### 3.4 Workflows

`{id, code, entity, label?, states?, nodes?, edges?}`. **States** (for `status` fields):
`{id, code, category, initial?, props?}` with `category` one of `open`, `active`, `done`,
`cancelled`, and `props` small scalar attributes (`probability: 0.7`) readable in expressions
as `stage.probability`.

**Nodes** (closed list): `trigger` (`on`: `created`, `updated`, `field_changed` + `field`,
`status_entered` + `state` [+ `field`, the status field watched], `schedule` + `cron`,
`manual`, `external` + `source`), `human` (`assign`: `{role}` or `{field}`, `verify`,
`form`, `due_days`, `applicable` expression, `bound`), `condition` (`expr`), `fork`, `join`,
`wait` (`days` or `until`), `set_status` (`state`), `update_record` (`set`: field → value or
expression), `notify` (`to`, `message`), `external` (`executor`: `namespace.action`,
`input`), `end`. **Edges** `{from, to, branch?}`; only edges out of a condition carry
`branch: true|false`.

Graph rules: exactly one trigger; every node reachable from it; a condition has one true and
one false edge; a fork two or more outgoing; a join two or more incoming and is reachable
from a fork; every other node has incoming edges and one outgoing; no loop unless it passes a
`human` or `wait` node. Which executors exist is the host's business (RZA-E).

### 3.5 Permissions

`{rules: [{role, entity, ops, row?, fields?, workflow?}]}`, one rule per role and entity.
`ops` from `create`, `read`, `update`, `delete`, `export`; `row` is `"all"` or a boolean
expression that must translate to SQL (`=owner = CURRENT_USER()`); `fields` maps a field to
`read`, `write` or `none`; `workflow` from `start`, `complete`, `verify`, `recall`,
`reassign`. The compiler turns rules into Gate ability names
`apps.<package>.<entity>.<op>` and `…workflow.<ability>`.

### 3.6 Labels

Every label is an i18n key (`^[A-Za-z][A-Za-z0-9_.-]{0,159}$`), never text. A missing label
uses a default key: entity `<entity>.label`, field `<entity>.<field>.label`, option
`<entity>.<field>.option.<key>`, saved view `<entity>.view.<code>`, role `role.<code>`,
workflow `workflow.<code>.label`, state `workflow.<code>.state.<state>`, node
`workflow.<code>.node.<node>`. Every key used must have `en` text (and `zh-HK` for system
templates). Label text is data: it is never parsed or run, and is escaped where it is shown.

### 3.7 Caps

`razymod\apps\Caps::DEFAULTS` (config can only lower them): definition 1,000,000 bytes;
50 entities; per entity 200 fields, 30 relations, 4 views, 30 saved views, 20 indexes (4
fields each), 30 form sections, 60 fields per view; 200 options per select; 30 roles; 500
permission rules; 50 menu items; 20 workflows of 100 nodes and 50 states; 20 locales, 5,000
i18n entries of 2,000 characters; 3 relation hops in a lookup; expressions 1,000 bytes, depth
24, 400 nodes, 32 arguments, 500-byte literals; evaluation 10,000 steps, 64 KB strings,
1,000 list items.

---

## 4. Element ids and the content hash

Entities, fields, saved views, workflows, states and nodes carry a **ULID** (26 characters,
Crockford base32). Merges and migrations follow the id, never the code: renaming a field keeps
its id and its column. The validator refuses a malformed or duplicate id, and with
`previous` it refuses an id that changes kind (`id.kind_changed`) and warns when a known
code gets a new id (`id.changed`: the old element is retired, a new one added).

`assign-ids` fills only missing ids, and an element whose kind, parent and code match one in
`previous` gets that id back, so a draft that dropped its ids does not re-create columns.

`hash` is `sha256:` + SHA-256 of the **canonical JSON** (`Canonical::json`): object keys
sorted, list order kept, null / empty members dropped, integral floats written as integers,
UTF-8 unescaped, no whitespace; NaN, infinity, invalid UTF-8, objects and nesting over 64
levels are refused. Equal content gives equal bytes whatever the key order.

---

## 5. Expressions

Formulas, defaults, visibility rules, conditions, saved filters and row rules use one small,
Excel-like language, parsed by our own parser into a **typed AST** (plain JSON). The compiled
plan stores the source and the AST.

```
=amount * stage.probability
=AND(owner = CURRENT_USER(), NOT(stage.category IN ('done', 'cancelled')))
=IF(ISBLANK(company), "—", company.name & " (" & TEXT(amount) & ")")
```

- Literals: numbers (`12`, `2.5`), text in `'…'` or `"…"` (a doubled quote is the only
  escape), `TRUE`, `FALSE`. Field paths: `amount`, `stage.probability`, `company.name`
  (many-to-one relations only, at most 3 hops, into this package or a declared dependency).
- Operators, lowest first: comparisons `= <> < <= > >=` and `IN` (`x IN ('a', 'b')` or
  `x IN list_field`; comparisons do not chain), `&` (join text), `+ -`, `* /`, unary `-`.
- Types: number, text, boolean, date, datetime, user, group, department, status, relation
  refs, lists, blank. `date ± number` is a date; `date - date` is days.
- **Blanks** are null and the empty text `''`, which are the same thing here. Logic is two-valued:
  - arithmetic and date functions with a blank give a blank;
  - text functions and `&` read a blank as `""`;
  - comparisons with a blank are FALSE, except `=` / `<>` (blank = blank);
  - `x IN (…)` with a blank `x` is FALSE;
  - AND / OR / IF / NOT read a blank as FALSE;
  - SUM / AVERAGE / MIN / MAX skip blanks (SUM of none is 0).
- Text `=` is case-sensitive and orders by byte; `CONTAINS` / `STARTSWITH` / `ENDSWITH` are
  not case-sensitive.

**Functions** (closed list, `Functions::LIST`; † = no SQL form, not allowed in saved filters
or row rules):

| Group | Functions |
|---|---|
| Logic | `IF`, `AND`, `OR`, `NOT`, `ISBLANK`, `COALESCE`, `BLANK` |
| Maths | `ABS`, `ROUND`, `FLOOR`, `CEILING`, `MOD`, `POWER`, `MIN`, `MAX`, `SUM`, `AVERAGE` |
| Text | `LEN`, `LEFT`, `RIGHT`, `MID`†, `UPPER`, `LOWER`, `TRIM`, `CONCAT`, `CONTAINS`, `STARTSWITH`, `ENDSWITH`, `SUBSTITUTE`†, `VALUE`†, `TEXT` |
| Dates | `TODAY`, `NOW`, `DATE`, `YEAR`, `MONTH`, `DAY`, `WEEKDAY` (1 = Monday), `DAYS`, `EDATE`†, `EOMONTH`†, `WORKDAY`†, `NETWORKDAYS`† (Mon-Fri minus the context's holidays) |
| Principal | `CURRENT_USER`, `MY_GROUPS`, `MY_DEPARTMENTS` |

No user-defined functions, no loops, no regex (ReDoS), no access to other records except
declared lookups, no access to other data spaces.

**Running an expression** (computed values, defaults, visibility):

```php
use razymod\apps\Expression\{Parser, TypeChecker, Evaluator, MapScope, ArrayValues, Context};

$ast = (new TypeChecker())->check((new Parser())->parse('=amount * 2'), new MapScope(['amount' => 'number']));
$value = (new Evaluator())->evaluate($ast, new ArrayValues(['amount' => 21]), new Context('user-7'));  // 42
```

`Context` carries the acting user, groups, departments, the clock, the data space time zone
and holidays; that is everything an expression can know. Filters and row rules are compiled
to bound SQL by the record runtime (RZA-C), with the same semantics.

### 5.1 SQL parity (for RZA-C)

Saved filters and row rules run as SQL in the record runtime (RZA-C), while computed values
run in the PHP evaluator. The two must agree on every row. The contract is the vector file
`tests/fixtures/apps/sql-parity.json`: 106 cases, each with an expression, field values (a
missing key is blank) and the expected result, plus a note on the SQL rule it pins.
`tests/AppsSqlParityTest.php` runs the PHP evaluator over it and also requires a vector for
every SQL-able function and operator. RZA-C must run the same file against Postgres. The rules
it pins:

- empty text is blank: read text columns as `NULLIF(col, '')`;
- `=` / `<>` are `IS NOT DISTINCT FROM` / `IS DISTINCT FROM`;
- wrap every comparison, `IN` and condition in `COALESCE(…, FALSE)`, so `NOT(n > 0)` is TRUE
  for a blank `n`;
- **`COALESCE(a, b)` is `COALESCE(NULLIF(a, ''), NULLIF(b, ''))`**: SQL's own COALESCE
  does not skip `''`;
- `ISBLANK(t)` is `t IS NULL OR t = ''`, and `ISBLANK` of a multi-select is "no values";
- `&` and the text functions use `COALESCE(x, '')`, and `LEN` counts characters;
- `CONTAINS` / `STARTSWITH` / `ENDSWITH` are case-insensitive, with `%`, `_` and `\`
  escaped in the needle;
- `/` is never integer division, `MOD` takes the divisor's sign, and `ROUND` rounds half
  away from zero;
- text compares as `COLLATE "C"`, and a date compares as midnight UTC;
- `TODAY()` and the date parts use the data space time zone.

### 5.2 Errors

Every failure is `razymod\apps\Expression\ExpressionError` with a stable code
(`getErrorCode()`) and a source offset (`getOffset()`, -1 at run time): `expr.encoding`,
`expr.length`, `expr.syntax`, `expr.depth`, `expr.nodes`, `expr.literal`,
`expr.unknown_function`, `expr.arity`, `expr.type`, `expr.unknown_field`,
`expr.unknown_option` (a text literal that is not an option or state), `expr.cycle`,
`expr.not_sql`, `expr.budget`, `expr.size`, `expr.div_zero`, `expr.value`. In a definition
the same codes appear as issue codes at the expression's path.

### 5.3 Security

Expressions come from users and from AI, so:

- No `eval`, no PHP callables built from input: the evaluator dispatches with a `match` on
  the closed list. A stored or cached AST is re-checked node by node (kind, operator,
  function, arity, literal types) before it runs; a tampered AST fails closed with a typed
  error.
- Every bound is enforced before the work: source length, literal length, AST depth (checked
  while parsing, so deep input fails at the cap instead of on the PHP stack), node count,
  arguments, evaluation steps (loops inside `WORKDAY` / `NETWORKDAYS` / `IN` / `SUBSTITUTE`
  pay steps), string size (checked before a string is built) and list size.
- Record values are data. The evaluator reads them as typed values and never parses them as
  expressions, so a record whose text starts with `=` is just text.
- `tests/AppsExpressionFuzzTest.php` feeds random token soup, generated expressions, mutated
  typed ASTs and mutated definitions through the pipeline and checks that only typed errors
  come out (`RAZY_APPS_FUZZ_ROUNDS=40000` for a long run).
- **Export (RZA-D2, not in this module):** values starting with `=`, `+`, `-`, `@`, tab or
  CR must be written to CSV / XLSX cells with a leading `'`, so a spreadsheet does not run
  a record value (or an expression's source text) as a formula.

---

## 6. Compiler and weight

`compile` validates and then builds a deterministic plan (no clock, no randomness), cacheable
by content hash: per entity the fields with their expression types, options, qualified
relation targets, defaults and computed expressions as typed ASTs, system columns, indexes,
search and sortable columns, views with typed expressions and the Gate abilities; workflows
with states, initial state, typed node expressions and edges; permission rules with typed row
expressions; the i18n keys used and the weight counts. The plan holds no SQL text and no
physical names: those come from the physical name map (RZA-B) and the record runtime (RZA-C).

`weight` (`razymod\apps\Weight`):

```
weight = c.entity × entities + c.field × fields + c.relation × relations
       + c.view × views + c.saved_view × saved views + c.row_rule_role × roles with row rules
```

Fields exclude `created_at` / `updated_at` / `created_by`; computed and rollup fields count;
a relation field counts as a field and as a relation; each defined list / form / board /
detail view counts; a role counts once if any of its rules has a row expression; i18n,
numbering, options and labels add nothing. `wf_nodes` counts every workflow node except
`trigger` and `end`. The fixtures weigh 85 (crm-core) and 56 + 3 nodes (crm-deals) with the
reference coefficients.
