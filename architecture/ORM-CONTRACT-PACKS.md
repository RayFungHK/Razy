# ORM Contract & Packs — Evidence Map and Convergence Design

Research against `C:\Users\RayFung\VSCode-Projects\Razy` (branch working tree, 2026-07). Prime directive: code beats docs; every claim cites `path/File.php:line`.

## 1. Layer inventory — what the ORM surface actually is

There is **no `src/library/Razy/ORM.php`** — "the ORM" is the `Razy\ORM\*` namespace
(10 files). The user's "two overlapping layers" intuition is real but the overlap is
**stacked, not parallel**: `Razy\Database` + `Database\Statement` is the SQL-builder
layer; `Razy\ORM\*` is an Active Record layer **built on top of it** — `ModelQuery`
assembles `$db->prepare()->select()->from()->where()->assign()` calls
(`src/library/Razy/ORM/ModelQuery.php:717-738`). There is no Entity/DTO third layer
(no `Model/` dir, no `Entity` class anywhere).

### 1.1 The layers and their seams

| Layer | Namespace / entry point | Role | Evidence |
|---|---|---|---|
| A — statement layer | `Razy\Database::prepare()` → `Razy\Database\Statement` | Fluent SQL builder over a Simple-Syntax DSL (WHERE / TableJoin / Update syntax), named-param binding via `assign()`, executor + lazy result sets | `Database.php:559`, `Database/Statement.php:101`, `Database/WhereSyntax.php:18-44`, `Database/TableJoinSyntax.php:18-45` |
| A′ — `Database` convenience shims | `Database::insert / update / delete / getMaxStatement` | One-line wrappers that return a `Statement` | `Database.php:547 / 598 / 612 / 633` |
| B — Active Record layer | `Razy\ORM\{Model, ModelQuery, ModelCollection, Paginator, SoftDeletes}` + `ORM\Relation\{Relation, HasOne, HasMany, BelongsTo, BelongsToMany}` | Table-mapped records, mass-assignment guards, casts, dirty tracking, events, global/local scopes, relations, eager loading, pagination, serialization | `src/library/Razy/ORM/` (all 10 files); seam `ModelQuery.php:717-738` |

Statement-layer DSL worth knowing (it is what RZ-003 compliance rides on):
WHERE operators `= != > < >= <= *= ^= $= #= ~= &= @= := |= >< <>`, `,`=AND `|`=OR
(`WhereSyntax.php:21-44`); JSON path operators via `col->>'$.path'` column syntax
(`Statement::standardizeColumn` `Statement.php:147-165`, `WhereSyntax::REGEX_COLUMN`
`:35`); join operators `< << > >> - *` with `[cols]` / `[?where-syntax]` conditions and
**aliased sub-query statements** (`TableJoinSyntax.php:30-83`, `Statement::alias`
`Statement.php:175-178`).

### 1.2 How models are defined today

- One **anonymous class per file** in the module's `model/` dir, loaded through the
  `Controller::loadModel()` helper, which `require`s `model/<Name>.php`, expects the
  file to `return new class extends Model {…}` and caches its FQCN
  (`Controller.php:574-601`; same pattern documented in `manual/04-database.md:228-248`).
- The entire definition surface is **static props**: `$table` (`Model.php:70`),
  `$primaryKey` (`:75`), `$fillable` (`:83`), `$guarded` (`:90`), `$casts` ∈
  `{int,float,bool,string,array|json,datetime}` (`:92-99`), `$timestamps`
  (`created_at`/`updated_at`, `:104`, stamped in `performInsert`/`performUpdate`
  `:1355-1358 / :1393-1396`), `$hidden` (`:111`), `$visible` (`:119`).
- Relations are **conventional method calls**: `hasOne / hasMany / belongsTo /
  belongsToMany` (`Model.php:1255-1303`); FK name inferred as
  `strtolower(ShortClassName).'_id'` (`Model.php:1423-1428`). `BelongsToMany` takes
  pivot table + keys (`:1303`). No `HasManyThrough` / polymorphic relations exist.
- **Missing** for the user's vision: no column schema (no declared column list, no
  types beyond per-attribute `$casts` value mappers), no nullability, no declared FK
  constraints, no JSON sub-schema, no DDL. `$casts` is a read/write value transformer
  (`castGet`/`castSet` `Model.php:1476-1515`), not a schema.

### 1.3 Public surface (key methods)

| Class | Static / entry | Instance / execution |
|---|---|---|
| `Model` (`ORM/Model.php`) | `query($db)` `:306`, `find` `:460`, `findOrFail` `:473`, `all` `:487`, `create` `:497`, `destroy` `:514`, `firstOrCreate` `:547`, `updateOrCreate` `:592`, `newFromRow` `:285`, global scopes `addGlobalScope/removeGlobalScope` `:336-344`, event hooks `creating…restored` `:376-452`, `resolveTable` `:633` | `save` `:1011`, `delete` `:1039`, `refresh` `:1076`, `increment/decrement` `:778/:833`, `replicate` `:847`, `touch` `:876`, dirty tracking `isDirty/getDirty/getOriginal` `:961-993`, `fill` `:924`, serialization `toArray` `:1116` / `toJson` `:1141`, relation access `setRelation/relationLoaded` `:1188-1198` |
| `ModelQuery` (`ORM/ModelQuery.php`) | ctor `($db, $modelClass)` `:109`; local scopes via `scope{Name}` magic `__call` `:133-147` | `where` (raw syntax + named params) `:195`, `orWhere` `:222`, `whereIn/NotIn/Between/NotBetween/whereNull/whereNotNull` `:160-299`, `orderBy` (Simple Syntax `<col`/`>col`) `:316`, `limit/offset/select` `:329-353`, `with(...$relations)` eager-load `:411`, `withoutGlobalScope(s)` `:371-389`, `get/first/find/count` `:425-488`, `paginate/simplePaginate` `:512/:539`, `chunk/cursor` `:578/:618`, `create/bulkUpdate/bulkDelete` `:639-683` |
| `Statement` (`Database/Statement.php`) | `getSearchTextSyntax` `:122` (⚠ RZ-003 forbids the raw-input form), `standardizeColumn` `:147` | `select` `:780`, `from` `:230`, `where` `:933`, `assign` `:212`, `order` `:722`, `limit($position,$len)` `:704`, `group` `:531`, `insert` `:559`, `update` `:871`, `delete` `:822`, `collect` `:764`, `lazy` `:610` / `lazyGroup` `:672` / `lazyKeyValuePair` `:689`, `query` `:625`, `createViewTable` `:641`, `builder()` plugin hook `:191`, `alias()` sub-query `:175` |
| `Relation\*` | — | `resolve()` abstract (`Relation.php:47`); `BelongsToMany` eager-load via pivot `whereIn` (`BelongsToMany.php:261,288`) |

### 1.4 Where the two layers duplicate (verified)

| Capability | Layer A form | Layer B form |
|---|---|---|
| where / orderBy / limit / offset / select | `Statement::where` `:933`, `order` `:722`, `limit` `:704`, `select` `:780` | `ModelQuery` re-exposes a narrower where/order/limit API, then compiles it back into Statement syntax strings (`ModelQuery.php:50-79, 717-779`) |
| insert | `$db->insert($table,$cols)` `Database.php:547` | `Model::save→performInsert` `Model.php:1349-1376`; `ModelQuery::create` `:639` |
| update | `$db->update` `Database.php:598` | `performUpdate` `Model.php:1381`, `ModelQuery::bulkUpdate` `:665` |
| delete | `$db->delete` `Database.php:612` | `Model::delete` `:1039`, `ModelQuery::bulkDelete` `:683` |
| pagination | `Statement::limit($position,$len)` `:704` | `ModelQuery::paginate/simplePaginate` `:512/:539` + `ORM/Paginator` |
| joins | full join DSL (`TableJoinSyntax`) | **absent** — ORM cannot join; eager-load runs N+1 pivot/key queries instead (see §5) |
| aggregates (`MAX`) | `Max` plugin builder + `Database::getMaxStatement` | absent (only `count()` `ModelQuery.php:488`) — leaks down to A; see §2 |

**Duplication verdict.** The real friction is (a) two where/order/limit APIs where B
re-parses into A's syntax strings; (b) ORM has **no join and no aggregate** story, so
anything non-trivial drops back to raw `Statement` chains; (c) dead duplicates at A —
see next paragraph.

**Dead / broken duplicate evidence.** `Database::getMaxStatement` (`Database.php:633`)
has **zero callers repo-wide** (grep: only its own definition) *and* is broken: it
validates `$tableName` (`:636`) then **overwrites it with `StringUtil::guid()`**
(`:664-665`), and `alias('latest')` at `:667` can never bind because the FROM syntax
built at `:666` names its aliases with the two different GUIDs, not `latest`. The
`Max` statement-builder plugin (`src/plugins/Statement/Max.php`) likewise has zero
callers (`->builder(` appears nowhere outside `Statement.php:191`).
Changelog `changelog/v0.5.0.md:66` advertises the Max builder as a shipped feature —
**doc-drift**: advertised, unreachable from repo code, while the older `getMaxStatement`
shim was never removed nor fixed.

## 2. Plugin-builder duplication — statement sugar vs ORM concepts

The "plugin builders" the user remembers are a real, narrow mechanism:
`Statement::builder($name, ...$args)` resolves a **Statement Builder plugin** closure
(`Database/Statement.php:191-202`) loaded from registered plugin folders
(`PluginTrait.php:41-44` → `PluginManager`; global folder
`src/system/bootstrap.inc.php:161-162`, per-module folder via `Controller.php:706`).
The base contract is `Database/Statement/Builder.php` (`init()` `:45`, `build($table)`
`:61`). **Exactly one builder ships: `Max`** (`src/plugins/Statement/Max.php`,
greatest-N-per-group via LEFT-JOIN self-join, `build()` `:67-119`). There are **no
`min/avg/sum/latest` statement builders anywhere** — grep of `AVG(|SUM(|MIN(|MAX(`
across `src/` hits only the broken `getMaxStatement` inlining (`Database.php:667`) and
an internal raw `MAX(batch)` in `MigrationManager.php:455`. The remembered "latest" is
the **alias string** inside `getMaxStatement` (`Database.php:667`), not an API.

### 2.1 Every "aggregator-ish" capability, per layer

| Capability | Where it exists | Pure SQL sugar or ORM concept? | Evidence |
|---|---|---|---|
| greatest-N-per-group | (a) `Max` builder plugin — LEFT JOIN self-join, toggle-column filtering | **SQL sugar** — belongs at statement layer | `src/plugins/Statement/Max.php:67-119`; zero callers |
| ″ | (b) `Database::getMaxStatement` — sub-select alias MAX() | SQL sugar, **duplicate of (a)**, broken + dead | `Database.php:633-670`; see §1.4 |
| ″ | (c) Pipeline action `FetchGreatest` — `ORDER >col LIMIT 1`-style single-row fetch inside validation flows | **ORM-level concept leaking** into validation (it fetches domain records, but by raw `$tableName`, not a model) | `src/plugins/Pipeline/FetchGreatest.php:64-89` |
| fetch one record by key | (a) `Statement::from()->where()->lazy()` by hand; (b) `Model::find` / `ModelQuery::first`; (c) Pipeline action `Fetch` — again by raw `$tableName` | same concept at **three sites**; (c) is ORM semantics in a plugin | `Fetch.php:64-88` vs `Model.php:460` vs `ModelQuery.php:450` |
| uniqueness / exists check | (a) hand-built `Statement` count/where; (b) `ModelQuery::count` `:488`; (c) Pipeline action `Unique` — raw table name + toggle columns | **ORM concept leaking** into validation plugin | `Unique.php:43-75` vs `ModelQuery.php:488` |
| min/avg/sum/max aggregate | **in-memory only** on `ModelCollection` (`array_reduce`/`array_map` over hydrated rows) — no SQL aggregate at either layer except raw `MAX()` strings | neither layer owns SQL aggregates | `ModelCollection.php:232-295` |
| SQL `count()` | `ModelQuery::count` builds `COUNT(*)` over Statement | ORM, done right | `ModelQuery.php:488` |
| ordering | `Statement::order` (Simple Syntax `col,<col2`, `Statement.php:722`) vs `ModelQuery::orderBy` (`:316`, feeds the same syntax) | sugar duplicated at both layers | §1.4 |
| limit/offset | `Statement::limit($position,$len)` `:704` vs `ModelQuery::limit/offset` `:329/:341` | sugar duplicated | §1.4 |
| pagination | `Statement::limit` pairs vs `ModelQuery::paginate/simplePaginate` + `Paginator` | ORM concept (statement layer has no page object) | `ModelQuery.php:512-575` |
| soft-delete filtering | statement layer: none; ORM: `SoftDeletes` global scope (`ORM/SoftDeletes.php:60-65`); **and** `Max` builder's `$toggleColumn` + `Unique`'s toggle columns re-invent "exclude inactive rows" per call site | ORM concept leaking as ad-hoc parameters in three places | `Max.php:80-118`, `Unique.php:52-61` |

### 2.2 Verdict per builder name the user asked about

| Name | Status in code | Classification |
|---|---|---|
| `max` | `Max` statement plugin (live contract, dead usage) + broken dead `getMaxStatement` | statement-layer SQL sugar; keep plugin mechanism, delete the `Database` shim |
| `latest` | does not exist as builder — string alias inside dead `getMaxStatement` (`Database.php:667`) | **myth** born of the dead helper; a true "latest per group" is exactly what `Max` with `compareColumn=created_at` does |
| `min` / `avg` / `sum` | statement builders: absent; `ModelCollection::min/avg/sum/max`: in-memory row aggregation only; `documentation/pages/orm.html:601` documents the **collection** `max` (not SQL) | SQL aggregates simply don't exist → open slot for ORM-level `aggregate()` sugar (see §6) |
| ordering / limit | full Simple Syntax at A, thin re-wrap at B | sugar duplicated; B already delegates correctly — acceptable, but B must gain what A has that modules keep needing (§5) |

**Doc-drift:** `documentation/pages/plugins.html:76-77` demos `$statement->builder('search', …)`
— no `search` builder ships in core (only `Max`); treat as illustrative-only.
`changelog/v0.5.0.md:66` ships `Max` as a headline feature while nothing in-repo
calls it and the older equivalent was left broken (§1.4).

## 3. Schema / migration state — can a DB be generated from a definition today?

Yes — at the **migration** level, with a real column-DSL that already includes FKs;
**no** — nothing derives DDL from the ORM model definitions. The two halves exist
side by side and are not wired together.

### 3.1 The DDL machinery (exists, tested)

| Piece | What it does | Evidence |
|---|---|---|
| `Database\Column` | Column definition mini-DSL parsed from config syntax (`'id=type(int),auto'`, `'email=type(text),nullable'`): params `type,length,nullable,charset,collation,zerofill,create,key,oncreate,onupdate,default,reference(table,col)` — **FK references are first-class** (`getForeignKeySyntax` emits `FOREIGN KEY … REFERENCES`, `setReference` `:833`) | `Database/Column.php:51-130` (ctor + configure `:92`), `:585-600`, `:655-675` |
| `Database\Table` | Declarative table: `addColumn($syntax)` `:162`, `groupIndexing` `:656`, charset/collation, PK; `getSyntax()` emits full CREATE TABLE incl. keys + FK constraints `:465`; `exportConfig()` `:536` round-trips the definition to config syntax | `Database/Table.php` (header `:25` "…foreign key references") |
| `Table::commit($alter)` | **Diff-ALTER generator** — after first commit, diffs columns/FKs against the in-memory committed snapshot and emits `ALTER TABLE … ADD/MODIFY/DROP FOREIGN KEY` (`:298-369`) | `Database/Table.php:298-369` |
| `Table\TableHelper` | Imperative ALTER builder: `rename/charset/engine/comment`, `addColumn/modifyColumn/renameColumn/dropColumn`, `addIndex/addPrimaryKey/addUniqueIndex/addFulltextIndex/dropIndex/dropPrimaryKey`, `addForeignKey/dropForeignKey` | `Database/Table/TableHelper.php:103-499` |
| `Database\SchemaBuilder` | Migration-facing facade: `create($table, fn(Table))` → `Table::getSyntax()`, `table($name, fn(TableHelper))` → ALTER, `drop/dropIfExists/rename/hasTable/raw` | `Database/SchemaBuilder.php:64-162` |
| `Database\Migration` | Abstract `up/down(SchemaBuilder)` + `getDescription()`; migration files are anonymous classes `return new class extends Migration` named `YYYY_MM_DD_HHMMSS_Desc.php` | `Database/Migration.php:52-79` |
| `Database\MigrationManager` | Per-module `migration/` folder discovery, tracking table (`ensureTrackingTable` `:111`), `discover/getApplied/getPending/migrate/rollback/reset/getStatus` | `Database/MigrationManager.php:85-439`; wiring `Controller::getMigrationManager` `Controller.php:670-672` |
| Driver FK hygiene | SQLite turns `PRAGMA foreign_keys = ON` at connect | `Database/Driver/SQLite.php:55-56` |

### 3.2 What is *not* there (verified)

- **No schema introspection**: no `DESCRIBE` / `SHOW COLUMNS` / `SHOW CREATE TABLE`
  anywhere in `src/library/Razy/Database/` (only the Postgres `hasTable` uses
  `information_schema.tables`, `Driver/PostgreSQL.php:85`). `Table::commit($alter)`
  diffs against **its own in-process snapshot**, not the live DB → no drift detection,
  no "compare model↔DB" story.
- **No ORM→DDL bridge**: `SchemaBuilder`/`Table` are referenced nowhere under
  `src/library/Razy/ORM/` (grep). Models carry no column schema to generate from (§1.2).
- `SchemaBuilder` DDL runs as raw SQL through `$db->prepare($sql)` (values are DDL
  identifiers, prefix-quoted — RZ-003 applies to *input-derived* names; migration
  files are module code, not user input).
- SQLite/PostgreSQL portability of generated `ALTER` syntax is untested territory:
  `commit()`/`TableHelper` emit MySQL-shaped SQL (backticks, `DROP FOREIGN KEY`).

### 3.3 Migration-docblock dead code (repo-history claim) — verified, with correction

The claim in `manual/04-database.md:209-212` ("docblocks show
`$table->integer('id')->primary()->autoIncrement()` — those builder methods do not
exist") is **still true today, but only for `Controller.php`**: the current
`Controller::getMigrationManager` docblock shows exactly that dead API
(`Controller.php:628-631`), and grep confirms `Table` has no `integer()/string()/
primary()/autoIncrement()/unique()` methods (only `TableHelper::addPrimaryKey`
`:361`, `Table::addColumn` `:162`, and `ColumnHelper::float/timestamp/autoIncrement`
`ColumnHelper.php:212/273/468`). **Correction:** `Database/Migration.php:34-50` has
since been fixed — its example now uses `$schema->raw('CREATE TABLE …')` +
`dropIfExists()`, both of which exist. The manual's drift note itself cites
`Migration.php:35-47` and is now stale. Verified-working usage lives in tests:
`'id=type(int),auto'` (`tests/MigrationTest.php:196`), positioning
`AFTER/FIRST` (`tests/TableHelperTest.php:96-116`).

**Closest existing thing to "DB generated from a definition":** the
`Column`/`Table` config-syntax DSL (`'name=type(…),flags,reference(table,col)'` +
`Table::getSyntax`/`commit`) — it *is* a declarative schema language with FK support
and CREATE/ALTER generation, but it speaks **strings**, lives one layer below the
models, and no tool connects a `Model` to it. The convergence design (§6) makes the
Contract the single source that both feeds DDL into `SchemaBuilder` and types the
runtime.

## 4. Serialization & visibility today — model output and access control

### 4.1 The one output path

`Model::toArray()` (`ORM/Model.php:1116-1130`) is the single serialization funnel:
per attribute → accessor `get{Studly}Attribute()` if defined, else `castGet()`;
then `filterAttributes()` (`:1460-1471`). `toJson()` just json_encodes it
(`:1141-1144`). `ModelCollection`/`Paginator` inherit/delegate into it, so everything
a controller emits through them passes this one filter.

### 4.2 Field-level access control today — one static profile, no principals

| Fact | Evidence |
|---|---|
| Two static class props only: `$visible` (whitelist, wins if non-empty) and `$hidden` (blacklist) | `Model.php:111-119`, filter `:1460-1471`, precedence pinned by `tests/AttributeSerializationTest.php:249-254` |
| Read-only static getters `getHidden()/getVisible()` — no instance-, request-, or caller-scoped variant | `Model.php:611-623` |
| **Zero** role/permission/principal awareness anywhere in the ORM: grep `role|permission` over `src/library/Razy/` hits only FTP/SFTP chmod, `CommandRegistry` API gates (`Module/CommandRegistry.php:188-204`), and docblock examples (`ModelQuery.php:211-214`) | full-namespace grep |
| No per-field modes (redact/derive), no per-audience profiles, no way to say "super admin sees `email`, `noauth` sees `id,name`" — the user's exact scenario is **not expressible** today: one model class = one serialization profile for all callers | synthesis of `Model.php:111-119,1460-1471` |

### 4.3 Where a principal's identity already lives (the seam packs must hook)

The auth stack is complete enough to resolve "who is asking" at serialization time —
it just isn't consulted by the ORM:

| Surface | Role in the proposal | Evidence |
|---|---|---|
| `Razy\Auth\AuthManager` — multi-guard, `user(): ?AuthenticatableInterface`, `id()` | resolve current principal | `Auth/AuthManager.php:99,183,193` |
| `Razy\Contract\AuthenticatableInterface` + `GenericUser` (attribute bag with `getAttribute`) | the principal object; carries arbitrary attrs (e.g. `role`) | `Auth/GenericUser.php:26-93` |
| `Razy\Auth\Gate` — `define(ability, fn(user,…))`, `policy($modelClass,$policyClass)`, `allows/denies/check/any/none/authorize`, `before/after`, `forUser()` | sanctioned per-model authorization primitive; **policy class per model class already maps "model → who-may-see" hooks** | `Auth/Gate.php:103-332`, usage doc `:28-52` |
| `AuthorizeMiddleware` | route-level enforcement upstream | `Auth/AuthorizeMiddleware.php` |
| Sessions (File/Database/Redis/Array drivers) + `SessionMiddleware` | where principal survives requests | `Session/Session.php`, `Session/Driver/*` |
| 2FA | `Razy\Authenticator` TOTP/HOTP (RFC 6238/4226) — a *second factor*, not an authorization layer; correct to ignore for visibility | `Authenticator.php:6-41` |

**Key structural fact for §6:** RZ-005 forbids resolving `Application/Container` via
DI, so packs must not be a container service the model fetches. The sanctioned seam is
**controller helpers / module bindings** (`Controller.php` pattern of `loadModel`,
`getMigrationManager` at `:574/:670`) feeding an explicit
`$model->forPack($pack)` / serializer context — plus `Gate::policy()` as the
"who" oracle when a pack is role-derived. `PORTING-VALUE.md:29` already flags
roles/permissions (spatie-style) as an unbuilt Tier-1 port; packs should sit *under*
it, not wait for it: pack resolution by name today, Gate-backed resolution when roles
land.

## 5. JSON sub-datasets, FKs, relations, and how demos/tests actually read data

### 5.1 JSON columns — two disjoint stories, neither typed

| Layer | What exists | Limits |
|---|---|---|
| ORM | `$casts` `'array'/'json'`: whole-column `json_decode` on read (`Model.php:1487`), whole-column `json_encode` on write (`:1509`) | **no sub-field addressability, no sub-schema, no partial read/write**; a JSON column is one opaque blob per attribute |
| Statement | A JSON operator DSL in Where Syntax: `@=` key-exists (`JSON_KEYS`/`JSON_OVERLAPS`), `:=` `JSON_EXTRACT … IS NULL`, `~=` `JSON_CONTAINS`, `&=` `JSON_SEARCH`, plus path form `col->'$.a.b'` parsed inside column references | **MySQL-specific SQL emitted** (`WhereSyntax.php:425-426, 561-672`; `JSON_OVERLAPS` even flagged "MySQL 8.0+" `:599`); path grammar only in `WhereSyntax::REGEX_COLUMN` (`:35`) and `Statement::standardizeColumn` (`:153`) |
| Coverage | **no tests**: `grep JSON tests/WhereSyntaxTest.php` → zero; the JSON DSL is unexercised | risk (§7) |

So "addressable sub-fields like `profile.city`" exists today **only** as hand-written
MySQL JSON SQL at the statement layer — unreachable from the ORM, unportable, untested.

### 5.2 Relations & FKs — real but join-free

- Declarations: `hasOne/hasMany/belongsTo/belongsToMany` on `Model` (`:1255-1303`);
  FK inferred `strtolower(ShortName).'_id'` (`:1423-1428`). Lazy access via `__get`
  → `resolveRelation()` (`:220-247`, `:1433-1448`) — **one query per model** without
  `with()`.
- Eager: `with(...$relations)` (`ModelQuery.php:411`) → `get()` calls
  `loadEagerRelations()` (`:440-442`), which dispatches **per-relation `WHERE key IN
  (…)` batch queries** and groups in PHP (`:832-999`); `BelongsToMany` runs pivot
  `SELECT … WHERE key IN (…)` then related `whereIn` (`:1098-1134`,
  `Relation/BelongsToMany.php:261,288`). **No SQL JOIN anywhere in the ORM** (grep
  `join` in `ModelQuery.php` → only prose matches).
- **Behavioural gap found**: `first()` — hence `find()` — ignores `with()`
  (eager-load only runs in `get()` `:440`; `first()` `:450-469` never calls
  `loadEagerRelations`; `find()` delegates to `first()` `:476-483`). Documented
  behaviour matches code; silently surprising, recorded for §7.
- Missing: `hasManyThrough`/"through" relations, polymorphic relations, join-based
  eager loading, `whereHas`, select-count-with-relation. These are the "manual join"
  holes the user feels.

### 5.3 What demos actually do (cross-table reads = manual joins)

`grep loadModel|Razy\\ORM demo_modules demos` → **zero matches**: no demo module uses
the ORM at all. The canonical cross-table artifact is the joins demo, which hand-builds
`TableJoinSyntax` strings on raw statements — the exact "tedious manual joins" the
user wants gone:

```php
// demo_modules/data/database_demo/default/controller/demo/joins.php:128-131
->from('lh.membership_share_history<l.membership_share[share_id]<b.minter_license[minter_id]<c.company[company_id]')
```

(4-table chain, marked "Real production query"; earlier cases at `:40-124` show
LEFT/INNER/RIGHT/CROSS/USING/ON/self-join — all statement-layer, no models, no
eager loading, results as raw row arrays `json_encode`d at `:136`.)

### 5.4 What tests actually pin (real usage patterns)

| Test file | Pins |
|---|---|
| `tests/ORMTest.php` | core CRUD + relations |
| `tests/ConvenienceMethodsTest.php` | `firstOrCreate/updateOrCreate`, `increment/decrement`, `replicate`, `touch` (`:32-444`) |
| `tests/ModelEventsTest.php` | model event hooks |
| `tests/EagerLoadingTest.php` | `with()` batch loading per relation type |
| `tests/QueryScopesTest.php` | `scope*` local scopes (`:106-142`), global scope removal (`:365-414`) |
| `tests/SoftDeletesTest.php` | `withTrashed/onlyTrashed/forceDelete`, bulk ops under scope |
| `tests/QueryBuilderEnhancedTest.php` | `whereIn/Between/Null` via named params (`:39-334`) |
| `tests/BelongsToManyTest.php` | pivot eager load (`:822-873` fixture models) |
| `tests/AttributeSerializationTest.php` | `$hidden/$visible` semantics + precedence (`:175-254`) |
| `tests/ControllerLoadModelTest.php`, `ControllerMigrationTest.php`, `MigrationTest.php` (`:196`), `TableHelperTest.php` (`:96-116`) | `loadModel`, migration/DDL DSL |
| `tests/BuilderTest.php` | Builder base only (`init` binding `:21-104`) — **no test exercises the `Max` plugin, and no test anywhere uses max/latest builders** (grep `builder('max'|->builder(` repo-wide → none) |

**Reading:** the ORM's *declared* surface is well-tested, but real application code in
this repo (demos) still bypasses it for anything multi-table — the ORM is exercised
in tests and documented in the manual, yet the demos prove it can't yet express the
production read pattern (4-table join) without dropping to syntax strings.

## 6. CONVERGENCE PROPOSAL — Contract + Visibility Pack + Relations

**PROPOSAL — grounded in §1–§5.** One concept replaces the "split too finely" feeling:
the **Contract** is the single declarative source (fields, types, nullability, FKs,
JSON sub-schema, relations); the **Pack** is the Contract's per-audience field-visibility
lens resolved at serialization; everything else (Statement, migrations, serializers,
validation plugins) *derives* from the Contract instead of re-declaring it.

### 6.1 Contract — the skeleton

New `Razy\ORM\Contract` value object, declared in the same module `model/` file
alongside (not replacing) the anonymous class:

```
table        = 'users'            primary_key = 'id'    timestamps = true
fields:
  id         type(int), auto, pk
  name       type(varchar(100))
  email      type(varchar(255)), nullable, hide-default? → pack decides
  role       type(varchar(32)), default('member')
  profile    type(json) with sub-fields: profile.city type(varchar), profile.bio type(text)
  author_id  …declared via relation, FK emitted
relations:
  hasMany(Post, fk: author_id) as posts        belongsTo(Team, fk: team_id) as team
  belongsToMany(Role, pivot: role_user) as roles
  hasManyThrough(Post→Comment) as comments     ← new 'through' type (§5.2 gap)
```

- **Field grammar = the existing Column simple-syntax.** §3.3 proved `type/length/
  nullable/default/key/reference(table,col)` already parses (`Column.php:92`) and emits
  CREATE/ALTER incl. FKs (`:585-600`). Contract fields reuse that grammar verbatim —
  no new DSL — and add only `json(sub-schema)` and pack modes as *sibling* annotations.
- Existing static props (`$fillable/$casts/$hidden/$visible`) become **generated views
  of the Contract**; the old props keep working (§6.5 BC). A Model with no Contract
  behaves exactly as today.
- JSON sub-fields are **first-class addresses** (`profile.city`): reads hydrate the
  sub-tree, packs can hide `profile.bio` while showing `profile.city`, DDL still stores
  one `json` column (sub-schema is validation/visibility, not extra columns). The
  statement-layer MySQL operators (`~=` etc., §5.1) stay the way to *filter* on them;
  the Contract gives them names, casts, and portability checks.
- **FK 1:1 for generation**: every `belongsTo/hasMany` declaration names its FK
  (`inferred` by the current convention `Model.php:1423-1428` if omitted), so the
  compiler can emit real `FOREIGN KEY` constraints (§3.1 building blocks exist).

### 6.2 Contract → DDL (honest scope)

`ContractCompiler` maps Contract → `Table` (columns via `addColumn` grammar +
`groupIndexing`) → `SchemaBuilder::create()` / `TableHelper` ALTERs → the module's
existing `MigrationManager` pipeline (`Controller.php:670`). Per-module, not global —
modules stay legal entities (RZ-001/008: each module ships and migrates its own
Contract; no cross-module schema registry).

| Milestone | Scope | Depends on |
|---|---|---|
| C1 create-generate | `contract:diff --create` writes a normal migration whose `up()` is generated `SchemaBuilder::create()`; `down()` = drop | nothing new (all §3.1 pieces) |
| C2 drift report | compile Contract → expected `Table`, compare with previous generated `exportConfig()` snapshot (`Table.php:536`) | snapshot discipline |
| C3 diff-migrations | true ALTER diff needs **live introspection** which does not exist today (§3.2): per-driver `describeTable()` (MySQL `SHOW CREATE TABLE`, SQLite `PRAGMA table_info`, PG `information_schema.columns`) | new driver methods, MySQL-flavored ALTERs must be de-flavored (`Table.php:301-368`, `TableHelper.php:639-698`) |

Ship C1–C2 as the feature ("the DB can be generated from the definition"); state C3
as the follow-up, do not promise diff-migrations with zero introspection code.

### 6.3 Visibility pack — "which attributes are visible to whom"

Named packs per Contract, e.g.:

```
pack super_admin : *            → full            (plus posts, team expanded)
pack staff       : id,name,role→full;  email→redacted('*@**.com'); profile→hidden
pack noauth      : id,name→full; *→hidden
```

- **Modes per field**: `full` (cast value), `redacted` (mask spec or closure), `hidden`
  (key absent), `derived` (named accessor/closure, computed not stored — replaces the
  implicit `get{X}Attribute` story with an explicit one), plus `expand` for relations
  (embed the related model rendered through *its* pack, recursively depth-capped).
- **Resolution at serialization only** — new terminal beside the existing funnel
  (§4.1): `Model::render(pack)` / `ModelCollection::render(pack)`; `toArray()` stays
  untouched, with `$visible/$hidden` documented as the *default pack* (their
  whitelist-over-blacklist rule `Model.php:1462-1468` becomes the `default` pack's
  semantics, keeping `AttributeSerializationTest` green).
- **Who→pack binding lives in controllers, not models** (RZ-005: no container/DI
  inside core objects): Controller gets `getPackContext()` alongside `loadModel()`;
  it composes from module bindings and the already-shipped auth stack (§4.3):
  principal = `AuthManager::user()`, role = `GenericUser::getAttribute('role')` or a
  `Gate::allows('view:email')` check per field-group. `Gate::policy($modelClass,…)`
  (`Gate.php:121`) is the oracle when packs grow per-row conditions ("owner sees own
  email"). No roles package is required to start — name-based packs and
  attribute-derived names cover the super-admin/noauth example today (§4.2's exact
  gap).
- **Module boundary**: packs are module-local; a published `addAPICommand` returns
  *already-rendered* pack output, so "person A sees nearly all parameters, noauth
  sees id+name" never leaks raw attributes across module lines (RZ-001/002 clean).

### 6.4 Relations — killing manual joins without killing Statement

- Both sides declare (`User.hasMany(Post, 'author_id')` + `Post.belongsTo(User,'author_id')`);
  compiler validates FK symmetry and emits the constraint (§6.1) — the user's
  "two definitions declare relationships directly".
- Runtime: existing lazy `__get` + `with()` batch loading stay (§5.2); **fix** the
  `first()/find()` eager-load gap (`ModelQuery.php:440 vs :450-469`), add `through`
  relations, add `whereHas(name, fn(query))` (compiles to a parameterized sub-query —
  RZ-003 named params preserved), and optional `withConstrainted(name, fn)` closures.
- `Statement` + join Simple Syntax **remain** the sanctioned escape hatch for the
  joins-demo class of reads (§5.3): contract-generated reads cover the 90 % tree/
  parent-child cases; 4-table analytics chains stay at layer A — but now they can be
  *named* (`Contract::joinPath('user.post.comment')` returns the validated syntax
  string instead of hand-typed table chains).

### 6.5 Overlap resolution — per builder from §2

| Capability (§2) | Decision | Path |
|---|---|---|
| `Max` statement plugin | **KEEP** at statement layer (pure SQL sugar, well-written self-join) | keep; add real usage docs + one integration test; note zero current callers (§1.4) |
| `Database::getMaxStatement` | **DEPRECATE + delete** (broken `:664-667`, dead) | `@deprecated` notice in next minor; remove in next major (RZ-012) |
| `latest` "builder" | **never existed** — nothing to keep | document `Max` w/ `compareColumn=created_at` as the answer |
| `min/avg/sum` SQL | **PROMOTE sugar to ORM**: `ModelQuery::aggregate('max'|'min'|'avg'|'sum', col)` compiling `MAX()` etc. through Statement with Standardized column names | new minor; kills the `ModelCollection` in-memory workaround habit for large tables (keep the collection methods for hydrated rows) |
| `ModelCollection::max/min/avg/sum` | **KEEP** (in-memory over hydrated models — different job) | unchanged; docs clarify boundary |
| Pipeline `Fetch/FetchGreatest/Unique` | **KEEP names** (published plugin surface, RZ-012) — re-target internals to accept a model/Contract *name* instead of raw `$tableName` (removes table-name duplication inside validation flows) | additive: existing `$tableName` ctor arg stays accepted |
| where/order/limit double API | **KEEP both** — ModelQuery is the RZ-003-safe facade, Statement the escape hatch; Contract adds `where`-generation for FK/JSON fields so most hand-written syntax disappears | docs + Contract helpers, no removals |
| pagination | **KEEP** ORM-only (`Paginator`) | — |
| `createViewTable` | KEEP statement-layer materialized-view helper (no ORM equivalent requested) | — |

### 6.6 Convergence steps, smallest first (from the named classes today)

| # | Step | Touches | Ships |
|---|---|---|---|
| 1 | `first()/find()` honour `with()` (bug-sized, makes relation story coherent) | `ModelQuery.php:450-469` | next patch/minor |
| 2 | Delete `getMaxStatement` after deprecation cycle | `Database.php:617-670` | deprecate minor → remove major |
| 3 | `Razy\ORM\Contract` + `ContractCompiler` (Contract → `Table`/`Column` → `SchemaBuilder`); `loadModel()` also compiles+validates the Contract (catch FK typos at load); generated-create migration command | new files under `ORM/Contract/`; `Controller.php` helper beside `:574`; reuses §3.1 machinery | minor (additive) |
| 4 | Packs: `Contract::pack(name, spec)`, `Model/ModelCollection::render()`, `Controller::getPackContext()`; `$hidden/$visible` become default-pack sugar (tests pinned by `AttributeSerializationTest` keep passing) | `ORM/`, `Controller.php` | minor |
| 5 | Relation declarations on Contracts (symmetry check), `through` type, `whereHas`, constrained eager loads | `ORM/`, `Relation/` | minor |
| 6 | C2 drift report + `describeTable()` per driver, then diff-migrations | `Database/Driver/*`, `Table.php` | follow-up major-adjacent feature |
| 7 | Aggregate sugar on ModelQuery; `joinPath()` named joins | `ModelQuery.php` | minor |

Everything is **additive inside the single `src/library/Razy` library** (the library is
one artifact; "package" here = its own changelog minor/major entry + tests per RZ-014,
not a Composer split; autoload needs no manual edits — RZ-007). No released signature
changes until the deprecation step (2), which is itself removal-only of dead code.

## 7. Risks & open questions for the maintainer

### 7.1 Risks

| # | Risk | Evidence / mitigation |
|---|---|---|
| R1 | **Packs guard output, not the process.** `__get`, `getRawAttribute` (`Model.php:902`), `getDirty()` (`:975`) still expose raw attributes to any module code; redaction exists only in the render funnel (§4.1). Logs, queue payloads, XHR `json_encode`s of hand-built arrays bypass packs entirely | docs + convention: serialize only via `render(pack)`; consider a "sealed" mode later that blanks hidden attrs on hydrate |
| R2 | **Real FK constraints change ops.** SQLite already forces `PRAGMA foreign_keys=ON` (`Driver/SQLite.php:55-56`); generating `FOREIGN KEY`s (C1) turns orphan deletes/updates into hard errors on installs that relied on app-level integrity | per-field `onDelete/onUpdate` spec (Column grammar lacks it — extend flags); default `RESTRICT`, opt-in per contract |
| R3 | **Driver portability.** The JSON filter DSL emits MySQL functions incl. `JSON_OVERLAPS … MySQL 8.0+` (`WhereSyntax.php:599`), and `Table::commit`/`TableHelper` ALTERs are MySQL-shaped (`Table.php:301-368`, `TableHelper.php:639-698`); Contract DDL promises must match this floor | state "MySQL-first" honestly; Contract compile-time check for portable features |
| R4 | **Executor inlines values.** `execute()` prepares the final SQL and calls `execute()` with no bound array — assigned values are inlined quoted literals by the builder (`Database.php:306-327`, acknowledged in `manual/04-database.md:267-273`) — so every Contract-generated code path must route values through `assign()` named params only, never string-build them (RZ-003) | review gate; keep RZ-003 lint (`tools/lint-module-discipline.php`) |
| R5 | **`Max` plugin inlines toggle-column values into join-clause syntax** (`Max.php:100` — `preg_quote`'d then quoted into the `[?…]` string, only array values are bound) | audit the Simple-Syntax quoting path for `"`-bearing values before promoting the builder into examples/docs |
| R6 | **Deprecation reach.** `getMaxStatement` has zero *repo* callers but is public core API — external modules may call it | RZ-012: deprecate notice ≥1 minor before removal |
| R7 | **Drift-report governance.** C2 snapshots (`Table::exportConfig` `Table.php:536`) only stay honest if schema changes flow through generated migrations; hand-written `SchemaBuilder::raw()` migrations (`SchemaBuilder.php:159-162`) silently poison drift | warn on `raw()` usage in contract-managed tables |
| R8 | **Eager-load scale.** `WHERE key IN (…)` per relation (`ModelQuery.php:832-999`) → huge IN lists for big parents; no join-based loading (§5.2) | cap/alias batching; later join-based `withJoin` |
| R9 | **Untested JSON DSL surfaces first.** Contract will expose `profile.city` filtering whose engine has zero test coverage (§5.1) | pin `WhereSyntax` JSON operator tests *before* step 3 ships |

### 7.2 Open questions

1. **Ergonomics of the declaration site.** Model files currently *return an anonymous
   class instance* (`Controller.php:592-596`); where does the Contract live — a second
   returned object, a `contract()` method on the class, or a sibling `schema/` file?
   (Ergonomics matters doubly given this repo's AI-scaffolding workflows, `LLM-CAS.md:703`.)
2. **Relation cycles / depth.** `expand`-ing self-referential relations (the employees
   self-join case, `joins.php:115-124`) needs a depth cap or cycle guard — value?
3. **Should the Contract also feed validation?** `Fetch/FetchGreatest/Unique` plugins
   re-declare `$tableName + $indexColumn` today (§2.1); pointing them at a Contract
   would unify a *third* surface — desirable scope, or leave Pipeline alone?
4. **Roles prerequisite.** Role-derived packs need the unbuilt roles/permissions port
   (`architecture/PORTING-VALUE.md:29` "auth hook-site survey not yet verified"). Start
   packs name-only (works today via `GenericUser::getAttribute`), or wait for roles?
5. **Semver slotting.** Steps §6.6-1..7 are additive minors except the removal (step 2) —
   confirm the deprecation window length (RZ-012).
6. **Cross-driver `describeTable`.** Which drivers must C3 support at day one — MySQL
   only, or SQLite too (dev/test default in this repo's tests)?

### 7.3 Doc-drift ledger (collected; code is authoritative)

| Artifact | Drift | Truth |
|---|---|---|
| `Controller.php:628-631` | migration example uses `$table->integer()->primary()->autoIncrement()` | none of those methods exist on `Table` (grep; real API `addColumn('x=type(…),flags')` `Table.php:162`) |
| `manual/04-database.md:209-212` | drift warning also cites `Migration.php:35-47` | that docblock was fixed — now shows valid `raw()`/`dropIfExists()` (`Migration.php:34-50`); warning is stale |
| `changelog/v0.5.0.md:66` | headlines `Max` builder as shipped feature | zero repo callers; companion `getMaxStatement` broken (`Database.php:664-667`) |
| `documentation/pages/plugins.html:76-77` | demos `$statement->builder('search', …)` | only `Max` ships (`src/plugins/Statement/`); illustrative-only |
| `documentation/pages/orm.html:601` | table row `max` could read as SQL aggregation | it's `ModelCollection::max` — in-memory (`ModelCollection.php:284`) |
| `joins.php:10-16` header table | describes `<<`/`>>` as "Exclude matched" | they are LEFT/RIGHT OUTER JOIN per `TableJoinSyntax.php:38-45` |

---

*End of evidence map. Every file:line above was opened and verified in this pass
(2026-07 working tree); no docs were trusted over code, and the two places where even
the drift ledger itself had drifted are recorded (§3.3, §7.3).*
