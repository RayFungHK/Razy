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
- SQL parity vectors `tests/fixtures/apps/sql-parity.json` (106 cases: expression, blank inputs,
  expected) with `tests/AppsSqlParityTest.php`; the SQL translation of filters and row rules
  (RZA-C) reuses the file. Blank means null or empty text everywhere (comparisons, `IN`,
  `COALESCE`, `ISBLANK`, `MIN` / `MAX`, text functions).

## Changed
- `phpstan.modules.neon` analyses `modules/apps`; CI's static-analysis job now also runs the
  module config (`phpstan analyse -c phpstan.modules.neon`).

## Known limits
- Package files are JSON: Razy's YAML parser drops keys in lists of maps, so `.yaml` files are
  refused until it is fixed.
- No Unicode normalisation in canonical JSON (no `intl` dependency); authors' text is hashed
  as written.
- Numbers in the PHP evaluator are int / float; exact decimals come from SQL (RZA-C).
