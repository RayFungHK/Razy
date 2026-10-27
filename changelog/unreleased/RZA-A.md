# RZA-A: razymod/apps — definitions, expressions, compiler

## Added
- `modules/apps` (`razymod/apps` 0.1.0): app definitions as data. Manual: `manual/15-razy-apps.md`.
- Definition schema v1 (`apps_schema: 1`): manifest, entities with 30 closed field types,
  relations (many-to-one, one-to-many via a back reference, many-to-many), numbering patterns,
  indexes, list / form / board / detail views, workflows (states, nodes, edges), permission
  rules with row expressions, i18n keys.
- `Definition\Validator`: strict structure (unknown keys refused), codes by pattern and a
  reserved list (SQL keywords, system columns), ULID element ids unique and kind-stable across
  versions, references into the package and declared dependencies, every expression parsed and
  typed (filters and row rules must have a SQL form), views on real fields, workflow graph
  rules, `en` labels (and `zh-HK` for system templates), caps. Issues carry a JSON Pointer.
- `Caps`: engine ceilings that config can only lower.
- `Definition\Canonical`: canonical JSON and the `sha256:` content hash.
- `Definition\ElementIds`: ULID generation, assignment that reuses the previous version's ids,
  stability checks.
- `Expression\*`: Excel-like language (`=amount * stage.probability`), own parser to a typed
  AST, closed function list (logic, maths, text, dates and working days, principal), lookups
  along many-to-one relations, two-valued blank logic, evaluator with step / depth / string /
  list budgets that re-checks every node; `ExpressionError` with stable codes.
- `Compiler`: deterministic runtime plan (typed expressions, qualified relations, indexes,
  search / sortable columns, views, workflows, Gate ability names). No SQL, no physical names.
- `Weight`: the weight formula and workflow node count, coefficients passed in (module config
  `weight`, else the reference values).
- `Definition\PackageLoader`: reads a package folder of JSON files.
- API commands `validate`, `compile`, `hash`, `assign-ids`, `weight` behind an implemented
  `__onAPICall` allow-list.
- Fixture packages `tests/fixtures/apps/packages/crm-core` and `crm-deals`.
- SQL parity vectors `tests/fixtures/apps/sql-parity.json` (180 cases: expression, blank inputs,
  expected, type, blank policy, time zone) with `tests/AppsSqlParityTest.php`; the SQL
  translation of filters and row rules (RZA-C) reuses the file. Blank means null or empty text
  everywhere.
- Exact numbers: `Expression\Decimal` (own fixed-point, no dependency) for integer / decimal /
  money / percent / duration fields and literals; fields read at their scale; division at
  scale max(operand scales, 6), HALF_UP; `number` (float) only from `POWER()`.
- One blank policy per function in `Functions::LIST` (`propagate`, `skip`, `empty_text`,
  `false`, `condition_false`, `test`, `none`), applied in one place by the evaluator.
- A date meeting a datetime is its local midnight in the data space time zone.
- Element ids: with `previous`, `id.changed`, `id.moved` (another parent, or a swap) and
  `id.kind_changed` are errors; `assign()` never keeps a moved id. At most one created_at /
  updated_at / created_by per entity.
- API doors: `__onAPICall` admits only modules in config `callers` (default none); every
  command caps its input (`InputGuard`, definition / previous / dependencies,
  `dependencies_per_app`) before work; `weight` validates first; `assign-ids` returns
  `{ok, definition}`.
- Canonical JSON pins `serialize_precision` to -1 for the encode and keeps U+2028 / U+2029 raw.
- The evaluator turns any PHP warning into `expr.syntax` (no message or path) and re-checks
  literal types and sizes.

## Changed
- `phpstan.modules.neon` analyses `modules/apps`; CI's static-analysis job now also runs the
  module config (`phpstan analyse -c phpstan.modules.neon`).

## Known limits
- Package files are JSON: Razy's YAML parser drops keys in lists of maps, so `.yaml` files are
  refused until it is fixed.
- No Unicode normalisation in canonical JSON (no `intl` dependency); authors' text is hashed
  as written.
- Numbers in the PHP evaluator are int / float; exact decimals come from SQL (RZA-C).
